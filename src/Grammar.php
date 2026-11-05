<?php
namespace Charm\Parsing;

use Traversable;
use Countable;
use IteratorAggregate;
use ArrayAccess;
use Charm\Vector;
use Charm\Vector\TypedVector;
use Charm\Parsing\Clause\{
    Identifier,
    Nothing,
    Regex,
    Text,
    Sequence,
    Snippet,
    Start,
    OrderedChoice,
    EOF,
    SOF,
    Any
};
use Charm\Parsing\Error\CustomError;

/**
 * Represents a Parsing Expression Grammar, which is a formal grammar for describing
 * a context free language.
 *
 * PEGs have the form:
 *
 * $symbol <- $ordered_set_of_rules
 *
 * Where $symbol is a non-terminal (reference to another symbol) or a terminal (string
 * matching the source).
 *
 * A mathematical expression in PEG could be described like this:
 *
 * Expr     <- Sum                                      # Expr is a tree node which contains a Sum node
 * Sum      <- Product (('+' / '-') Product)*           # Sum is a tree node which contains a sequence of additions and subtractions
 * Product  <- Power (('*' / '/') Power)*               # Product is a tree node which contains a sequence of multiplications and divisions
 * Power    <- Value ('^' Power)?                       # Power node is a tree node which contains an exponent
 * Value    <- [0-9]+ / '(' Expr ')'                    # A Value is a node which matches the regular expression /[0-9]+ or a parenthesized expression.
 *
 * The structure of the above grammar would be:
 *
 * Grammar[
 *   Rule[ "Expr", Clause[] ],
 *   Rule[ "Sum", Clause[] ],
 *   ...
 * ]
 *
 * Grammar Prefix Operators
 *
 *      !<term>             Do not match the term
 *      &
 * Grammar parsing giant regex
 *
 */
class Grammar implements IteratorAggregate, GrammarInterface, \JSONSerializable {

    /**
     * The filename for the grammar, if available
     */
    public $filename;

    /**
     * Associative array of rules
     */
    private $ruleMap = [];

    /**
     * Has the grammar been optimized?
     */
    private $optimized = false;

    /**
     * Registry of all snippets in the grammar, indexed by name.
     * Names follow the pattern: RuleName_1, RuleName_2, etc.
     * @var array<string, Snippet>
     */
    private array $snippets = [];

    /**
     * Lexer class for token mode parsing.
     * Null means string mode (scannerless parsing).
     */
    private ?string $lexerClass = null;

    /**
     * Precedence table from @prec directive.
     * Maps token/literal to ['level' => int, 'assoc' => string].
     * @var array<string, array{level: int, assoc: string}>
     */
    private array $precedenceTable = [];

    public function __construct(iterable $rules, string $filename=null, array $precedenceTable=[]) {
        $this->filename = $filename;
        // must be known before prepare(): left-recursive rules read it there
        $this->precedenceTable = $precedenceTable;
        foreach ($rules as $rule) {
            if (isset($this->ruleMap[$rule->getRuleName()])) {
                throw new CustomError("Rule '".$rule->getRuleName()."' is already declared in this grammar. Use the Choice operator `/` to add multiple rules to a grammar");
            }
            $this->ruleMap[$rule->getRuleName()] = $rule;
        }
        $this->prepare();
    }

    protected function prepare() {
        foreach ($this->ruleMap as $ruleName => $rule) {
            $rule->prepare($this);
            $this->collectSnippets($ruleName, $rule->getClause());
        }
    }

    /**
     * Recursively collect all snippets from a clause tree and register them.
     */
    private function collectSnippets(string $ruleName, Clause $clause): void {
        static $counters = [];

        if ($clause instanceof Snippet) {
            if (!isset($counters[$ruleName])) {
                $counters[$ruleName] = 0;
            }
            $snippetName = $ruleName . '_' . (++$counters[$ruleName]);
            $clause->setSnippetName($snippetName);
            $this->snippets[$snippetName] = $clause;
        }

        // Recurse into child clauses
        if ($clause instanceof \Traversable) {
            foreach ($clause as $child) {
                $this->collectSnippets($ruleName, $child);
            }
        }
    }

    /**
     * Get a snippet by name.
     */
    public function getSnippet(string $name): ?Snippet {
        return $this->snippets[$name] ?? null;
    }

    /**
     * Get all registered snippets.
     * @return array<string, Snippet>
     */
    public function getSnippets(): array {
        return $this->snippets;
    }

    /**
     * Set the lexer class for token mode parsing.
     */
    public function setLexerClass(string $lexerClass): void {
        $this->lexerClass = $lexerClass;
    }

    /**
     * Get the lexer class for token mode parsing.
     * Returns null for string mode (scannerless) grammars.
     */
    public function getLexerClass(): ?string {
        return $this->lexerClass;
    }

    /**
     * Check if this grammar uses token mode parsing.
     */
    public function isTokenMode(): bool {
        return $this->lexerClass !== null;
    }

    /**
     * Set the precedence table from @prec directive.
     * @param array<string, array{level: int, assoc: string}> $table
     */
    public function setPrecedenceTable(array $table): void {
        $this->precedenceTable = $table;
    }

    /**
     * Get the precedence table.
     * @return array<string, array{level: int, assoc: string}>
     */
    public function getPrecedenceTable(): array {
        return $this->precedenceTable;
    }

    /**
     * Check if this grammar has precedence declarations.
     */
    public function hasPrecedenceTable(): bool {
        return !empty($this->precedenceTable);
    }

    /**
     * Get precedence info for a token/literal.
     * Returns ['level' => int, 'assoc' => string] or null if not declared.
     */
    public function getPrecedence(string $token): ?array {
        return $this->precedenceTable[$token] ?? null;
    }

    /**
     * Resolve the precedence and associativity of one alternative of a left-recursive rule. Both the
     * interpreter and the compiler use this, so that they agree:
     *
     * 1. `%prec=N` is used as-is; `%prec=NAME` looks NAME up in the `@prec` table (level and associativity).
     * 2. Otherwise, with a `@prec` table, the alternative's operator (the first literal, string or
     *    token that is not a reference to the rule itself) is looked up in the table.
     * 3. Otherwise the position decides: the first alternative binds tightest.
     *
     * `%assoc` overrides the associativity found in the table (default `left`; `none` groups like `left`).
     *
     * @return array{0: int, 1: string} precedence and associativity (`left`, `right` or `none`)
     */
    public function resolveAlternativePrecedence(Clause $choice, string $ruleName, int $index, int $numAlternatives): array {
        $prec = null;
        $assoc = 'left';

        $explicitPrec = $choice->pragma('prec');
        if ($explicitPrec !== null) {
            $precValue = $explicitPrec[0];
            if (is_numeric($precValue)) {
                $prec = (int) $precValue;
            } elseif (($precInfo = $this->getPrecedence((string) $precValue)) !== null) {
                $prec = $precInfo['level'];
                $assoc = $precInfo['assoc'];
            }
        }

        if ($prec === null && $this->hasPrecedenceTable()) {
            $operator = $this->extractOperator($choice, $ruleName);
            if ($operator !== null && ($precInfo = $this->getPrecedence($operator)) !== null) {
                $prec = $precInfo['level'];
                $assoc = $precInfo['assoc'];
            }
        }

        if ($prec === null) {
            $prec = $numAlternatives - $index;
        }

        $explicitAssoc = $choice->pragma('assoc');
        if ($explicitAssoc !== null) {
            $assoc = $explicitAssoc[0];
        }
        return [$prec, $assoc];
    }

    /**
     * The operator of an alternative like `Expr "+" Expr`: the first literal, string or token in the
     * sequence that is not a reference to the rule itself or a snippet.
     */
    private function extractOperator(Clause $clause, string $ruleName): ?string {
        if (!($clause instanceof Sequence)) {
            return null;
        }
        foreach ($clause as $part) {
            if (($part instanceof Clause\Identifier && $part->getIdentifier() === $ruleName) || $part instanceof Snippet) {
                continue;
            }
            if ($part instanceof Clause\Text) {
                return $part->getText();
            }
            if ($part instanceof Clause\Literal) {
                return $part->getLiteral();
            }
            if ($part instanceof Clause\Token) {
                return $part->getTokenName();
            }
        }
        return null;
    }

    public function count() {
        return count($this->ruleMap);
    }

    public function offsetGet($offset) {
        return $this->ruleMap[$offset] ?? null;
    }

    public function offsetSet($offset, $value) {
        throw new Exception("Can't set rules on grammar");
    }

    public function offsetUnset($offset) {
        throw new Exception("Can't set rules on grammar");
    }

    public function offsetExists($offset) {
        return array_key_exists($offset, $this->ruleMap);
    }

    public function getIterator() {
        yield from $this->ruleMap;
    }

    public function jsonSerialize() {
        return iterator_to_array($this);
    }

    public function __toString() {

        $arr = [];

        foreach ($this as $rule) {
            $arr[] = (string) $rule;
        }

        return implode("\n", $arr);

        foreach ($this as $rule) {
            $row = [ $rule->getRuleName(), trim($rule->getClause()) ];
            $arr[] = $row;
        }
        return (string) new Terminal\Table($arr);
    }

}
