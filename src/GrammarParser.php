<?php
namespace Charm\Parsing;

use Charm\Vector;
use Charm\Parsing\Clause\{
    Any,
    Commit,
    Snippet,
    SOF,
    EOF,
    Literal,
    Nothing,
    Identifier,
    OrderedChoice,
    Sequence,
    Regex,
    Text,
    Token
};
use Charm\Parsing\Util\StringTools;
use Charm\Parsing\Error\State\ParseError;
use Charm\Parsing\Error\AbstractParseError;
use Charm\Parsing\Error\CustomError;

/**
 * This is a hand-written recursive descent parser for parsing grammars which can be
 * interpreted by `Charm\Parsing\CPEGParser`.
 *
 * In BNF form the grammmar for grammars is:
 *
 * ```
 * CPEG =               Rule* $
 * Rule =               RuleName RuleDescription? ("::=" | "::" | ":=" | "=" | "->") Clauses ";"?
 * RuleName =           IDENT
 * RuleDescription =    STRING
 * Clauses =            Clause+
 * Clause =             Label? Predicate? (IDENT | STRING | REGEX | SubExpression) Operator?
 * SubExpression =      "(" Clauses+ ")"
 * Label =              STRING ":"
 * Predicate =          "&" | "!"
 * Operator =           "*" | "+" | "?"
 * ```
 */
class GrammarParser extends RecursiveDescentParser {
    const T_IDENT       = 1;
    const T_STRING      = 2;
    const T_REGEX       = 3;
    const T_NUMBER      = 4;
    const T_SNIPPET     = 5;
    const T_COMMENT     = -6;
    const T_BEGIN       = 7;
    const T_END         = 8;
    const T_PREDICATE   = 9;
    const T_OPERATOR    = 10;
    const T_COMMIT      = 11;
    const T_COLON       = 12;
    const T_BRACE_OPEN  = 13;
    const T_BRACE_CLOSE = 14;
    const T_OR          = 15;
    const T_TOKEN       = 16;
    const T_WHITESPACE  = -17;
    const T_PRAGMA      = 18;
    const T_SPREAD      = 19;
    const T_NOTHING     = 20;
    const T_DIRECTIVE   = 21;

    protected $options;

    /**
     * Lexer class for token mode (set by @lexer directive).
     * Null means string mode (scannerless parsing).
     */
    private ?string $lexerClass = null;

    /**
     * Precedence table from @prec directive.
     * Maps token/literal to ['level' => int, 'assoc' => string].
     * @var array<string, array{level: int, assoc: string}>
     */
    private array $precedenceTable = [];

    public function __construct(GrammarParserOptions $options=null) {
        if ($options === null) {
            $options = new GrammarParserOptions();
        }
        $this->options = $options;
    }

    public function getLexer(): LexerInterface {
        return new Lexer([
            new LexerPattern(
                self::T_COMMENT, 'T_COMMENT',
                '(?<COMMENT>(\#|\/\/)[^\n]*+|\/\*((?!\*\/)[\s\S])*\*\/)'
                ),

            new LexerPattern(
                self::T_NOTHING, 'T_NOTHING',
                '\\\\nothing|ε'
                ),

            new LexerPattern(
                self::T_IDENT, 'T_IDENT',
                '[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+|\^|\$'
                ),

            new LexerPattern(
                self::T_STRING, 'T_STRING',
                <<<'RE'
                (?<STRING>"(\\[\\"]|[^"\\]+|[^"])*+"|'(\\[\\']|[^'\\]+|[^'])*+')
                RE,
                function($match): string {
                    $q = $match->text[0];
                    $str = substr($match->text, 1, -1);
                    // Handle common escape sequences
                    return strtr($str, [
                        "\\n" => "\n",
                        "\\r" => "\r",
                        "\\t" => "\t",
                        "\\0" => "\0",
                        "\\\\" => "\\",
                        "\\$q" => $q,
                    ]);
                }
                ),

            new LexerPattern(
                self::T_REGEX, 'T_REGEX',
                <<<'RE'
                \/(\\[\s\S]|[^\/\n])*+\/|\[(\\[\S\s]|[^]])*+\]
                RE,
                function($match) {
                    if ($match->text[0] === '[') {
                        // Character class syntax - don't use preg_quote as it breaks ranges
                        // Only escape the delimiter if present
                        $content = substr($match->text, 1, -1);
                        $content = str_replace('/', '\\/', $content);
                        return '/[' . $content . ']/';
                    }
                    return $match->text;
                }
                ),

            new LexerPattern(
                self::T_PRAGMA, 'T_PRAGMA',
                <<<'RE'
                %[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\-]*
                RE,
                function($match) {
                    return substr($match->text, 1);
                }
                ),

            new LexerPattern(
                self::T_DIRECTIVE, 'T_DIRECTIVE',
                <<<'RE'
                @[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*
                RE,
                function($match) {
                    return substr($match->text, 1);
                }
                ),

            new LexerPattern(
                self::T_NUMBER, 'T_NUMBER',
                '(0|[1-9][0-9]*+)(\.[0-9]++)?',
                function($match) {
                    if (is_int(strpos($match->text, '.'))) {
                        return (float) $match->text;
                    }
                    return (int) $match->text;
                }),

            new LexerPattern(
                self::T_SNIPPET, 'T_SNIPPET',
                <<<'RE'
                <\?(
                    \?>(*ACCEPT)|
                    (?=[\/"'#])(
                        (?&STRING)|
                        (?&COMMENT)
                    )|
                    [^'"#\/?]++|
                    [\s\S]
                )*+
                RE,
                function($match) {
                    return substr($match->text, 2, -2);
                }),

            new LexerPattern(self::T_BEGIN, 'T_BEGIN', '::=|:=|:=|->|='),
            new LexerPattern(self::T_END, 'T_END', ';'),
            new LexerPattern(self::T_PREDICATE, 'T_PREDICATE', '&|!'),
            new LexerPattern(self::T_OPERATOR, 'T_OPERATOR', '\*|\+|\?'),
            new LexerPattern(self::T_COLON, 'T_COLON', ':'),
            new LexerPattern(self::T_COMMIT, 'T_COMMIT', '@'),
            new LexerPattern(self::T_BRACE_OPEN, 'T_BRACE_OPEN', <<<'RE'
                [[({<]
                RE),
            new LexerPattern(self::T_BRACE_CLOSE, 'T_BRACE_CLOSE', <<<'RE'
                [\])}>]
                RE),

            new LexerPattern(self::T_OR, 'T_OR', '\||\/'),

            new LexerPattern(self::T_SPREAD, 'T_SPREAD', '\.\.\.'),
            new LexerPattern(self::T_TOKEN, 'T_TOKEN', '\.|,|\\\\'),

            new LexerPattern(self::T_WHITESPACE, 'T_WHITESPACE', '\\s+'),
        ]);
    }

    protected function start() {
        try {
            $grammar = $this->_CPEG();
        } catch (AbstractParseError $e) {
            $grammarOffset = $this->getByteOffsetAt($this->offset - 1);

            $st = new StringTools($this->getSourceString());
            $e->setFileInfo($this->filename, $st->getLineNumberAt($grammarOffset), $st->getColumnNumberAt($grammarOffset));
            throw $e;
        } catch (CustomError $e) {
            throw new ParseError($this, $e->getMessage().' on :location', $e->getCode(), $e);
        }

        if ($grammar === null) {
            return null;
        }

        if (!$this->eof()) {
            throw new ParseError($this, 'Expected end-of-file on :location, found :source_description');
        }

        return $grammar;
    }

    private function _CPEG() {
        // Reset state for each parse
        $this->lexerClass = null;
        $this->precedenceTable = [];

        // Parse directives at the start
        while ($this->typeIs(self::T_DIRECTIVE)) {
            $this->_Directive();
        }

        $rules = null;

        while (null !== ($rule = $this->_Rule())) {
            $rules[] = $rule;
        }

        if ($rules !== null) {
            $grammar = new Grammar($rules, $this->filename);
            if ($this->lexerClass !== null) {
                $grammar->setLexerClass($this->lexerClass);
            }
            if (!empty($this->precedenceTable)) {
                $grammar->setPrecedenceTable($this->precedenceTable);
            }
            return $grammar;
        }

        return null;
    }

    private function _Directive(): void {
        $directive = $this->accept();
        $name = $directive->getValue();

        switch ($name) {
            case 'lexer':
                // Expect a class name (can be namespaced: Foo\Bar\Baz)
                $className = '';
                if (!$this->typeIs(self::T_IDENT)) {
                    throw new ParseError($this, "Expected class name after @lexer on :location");
                }
                $className = $this->accept()->text;

                // Handle namespaced class names (Foo\Bar\Baz)
                while ($this->textIs('\\')) {
                    $this->accept(); // consume backslash
                    if (!$this->typeIs(self::T_IDENT)) {
                        throw new ParseError($this, "Expected identifier after \\ in class name on :location");
                    }
                    $className .= '\\' . $this->accept()->text;
                }

                $this->lexerClass = $className;
                break;

            case 'prec':
                $this->_PrecDirective();
                break;

            default:
                throw new ParseError($this, "Unknown directive @{$name} on :location");
        }
    }

    /**
     * Parse @prec { ... } block.
     *
     * Syntax:
     *   @prec {
     *       left "+" "-" "."
     *       left "*" "/" "%"
     *       right T_POW
     *       none "<" ">"
     *   }
     *
     * Each line has higher precedence than the previous.
     * Associativity: left, right, or none.
     */
    private function _PrecDirective(): void {
        if (!$this->textIs('{')) {
            throw new ParseError($this, "Expected '{' after @prec on :location");
        }
        $this->accept(); // consume {

        $level = 1;
        $assocKeywords = ['left', 'right', 'none'];

        // Parse lines until we hit }
        while (!$this->textIs('}') && !$this->eof()) {
            // Each line: <assoc> <token> <token> ...
            // Assoc is an identifier: left, right, none
            if (!$this->typeIs(self::T_IDENT)) {
                throw new ParseError($this, "Expected 'left', 'right', or 'none' on :location");
            }

            $assocToken = $this->accept();
            $assoc = $assocToken->text;

            if (!in_array($assoc, $assocKeywords, true)) {
                throw new ParseError($this, "Invalid associativity '$assoc' on :location. Expected 'left', 'right', or 'none'");
            }

            // Parse tokens on this line (strings like "+" or identifiers like T_POW)
            // Stop when we see an associativity keyword (start of next line) or }
            $tokens = [];
            while (!$this->textIs('}') && !$this->eof()) {
                if ($this->typeIs(self::T_STRING)) {
                    $tokens[] = $this->accept()->getValue(); // Get unquoted value
                } elseif ($this->typeIs(self::T_IDENT)) {
                    // Check if this is the start of a new line (assoc keyword)
                    if (in_array($this->text(), $assocKeywords, true)) {
                        break; // Start of new line
                    }
                    $tokens[] = $this->accept()->text;
                } else {
                    break;
                }
            }

            if (empty($tokens)) {
                throw new ParseError($this, "Expected at least one token after '$assoc' on :location");
            }

            // Add all tokens at this precedence level
            foreach ($tokens as $tokenName) {
                $this->precedenceTable[$tokenName] = [
                    'level' => $level,
                    'assoc' => $assoc,
                ];
            }

            $level++;
        }

        if (!$this->textIs('}')) {
            throw new ParseError($this, "Expected '}' to close @prec block on :location");
        }
        $this->accept(); // consume }
    }

    private function _RuleName() {
        if (!$this->typeIs(self::T_IDENT)) {
            return null;
        }
        if (!($this->typeIs(self::T_BEGIN, 1) || ($this->typeIs(self::T_STRING, 1) && $this->typeIs(self::T_BEGIN, 2)))) {
            // rule names MUST be followed by BEGIN or by STRING+BEGIN
            return null;
        }
        return $this->accept();
    }

    private function _Rule() {
        if (!($this->typeIs(self::T_BEGIN, 1) || ($this->typeIs(self::T_STRING, 1) && $this->typeIs(self::T_BEGIN, 2)))) {
            // rule names MUST be followed by BEGIN or by STRING+BEGIN
            return null;
        }
        $ruleName = $this->accept();

        $description = $this->typeIs(self::T_STRING) ? $this->accept() : null;

        $begin = $this->typeIs(self::T_BEGIN) ? $this->accept() : null;

        $clauses = $this->_Clauses();

        if ($clauses === null) {
            if ($begin !== null) {
                throw new ParseError($this, "Rules must contain clauses on :location");
            }
        }

        if ($this->eof()) {
            goto done;
        }

        if ($this->typeIs(self::T_IDENT)) {
            // this test makes the ';' optional
            goto done;
        }

        if (!$this->textIs(';')) {
            throw new ParseError($this, "Expected ';' or clauses on :location, got :source_description");
        }
        $this->accept();

        done:
        $rule = new Rule($ruleName->getValue(), $clauses);

        if ($description !== null) {
            $rule->setDescription($description->getValue());
        }

        return $rule;
        return [
            'rule' => $ruleName,
            'description' => $description,
            'clauses' => $clauses,
        ];
    }

    private function _Clauses() {

        $buildSequence = function(array $clauses) {
            $subs = [];
            $pragmas = [];
            foreach ($clauses as $clause) {
                if ($clause instanceof Pragma) {
                    $pragmas[] = $clause;
                } else {
                    $subs[] = $clause;
                }
            }

            // Validate: snippets must be the last clause in a sequence
            $lastSnippetIndex = -1;
            foreach ($subs as $i => $sub) {
                if ($sub instanceof Snippet) {
                    $lastSnippetIndex = $i;
                }
            }
            if ($lastSnippetIndex !== -1 && $lastSnippetIndex !== count($subs) - 1) {
                throw new Error\CustomError("Snippet must be the last clause in a sequence. Found snippet at position " . ($lastSnippetIndex + 1) . " of " . count($subs) . " clauses.");
            }

            if (count($subs) === 1) {
                foreach ($pragmas as $pragma) {
                    $subs[0]->addPragma($pragma);
                }
                return $subs[0];
            }

            $s = new Sequence(Clause::NONE, null);
            foreach ($pragmas as $pragma) {
                $s->addPragma($pragma);
            }
            foreach ($subs as $subClause) {
                $s[] = $subClause;
            }
            return $s;
        };
        $buildOrderedChoice = function(array $sequences) {
            $oc = new OrderedChoice(Clause::NONE, null);
            foreach ($sequences as $sequence) {
                $oc[] = $sequence;
            }
            return $oc;
        };

        $result = null;
        $groups = null;
        while (!$this->eof()) {
            if ($this->typeIs(self::T_OR)) {
                if ($result === null) {
                    throw new ParseError($this, "Expected '|' on :location, got :source_description");
                }
                $this->accept();
                $groups[] = $buildSequence($result);
                $result = [];
            } elseif ($this->typeIs(self::T_COMMIT)) {
                $this->accept();
                $result[] = new Commit(Clause::NONE, null);
            } elseif (null !== ($clause = $this->_FullClause())) {
                $result[] = $clause;
            } elseif (null !== ($pragma = $this->_Pragma())) {
                $result[] = $pragma;
            } else {
                break;
            }
        }
        if ($result === null) {
            return null;
        }
        if ($result === []) {
            throw new ParseError($this, "Expected clause on :location, got :source_description");
        }
        if ($groups === null) {
            return $buildSequence($result);
        }
        $groups[] = $buildSequence($result);
        return $buildOrderedChoice($groups);
    }

    private function _Pragma() {
        if (!$this->typeIs(self::T_PRAGMA)) {
            return null;
        }

        $pragmaToken = $this->accept();
        $params = [];
        if ($this->textIs('(')) {
            $this->accept();
            if (!$this->textIs(')')) {
                do {
                    if (!in_array($this->type(), [self::T_IDENT, self::T_STRING, self::T_NUMBER, self::T_REGEX])) {
                        throw new ParseError($this, "Expected identifier, string, number or regex on :location, got :source_description");
                    }
                    $params[] = $this->accept();
                } while ($this->textIs(',') && $this->accept());
            }
            if (!$this->textIs(')')) {
                throw new ParseError($this, "Expected ')' on :location, got :source_description");
            }
            $this->accept();
        } elseif ($this->textIs('=')) {
            $this->accept();
            if (!in_array($this->type(), [self::T_IDENT, self::T_STRING, self::T_NUMBER, self::T_REGEX])) {
                throw new ParseError($this, "Expected identifier, string, number or regex on :location, got :source_description");
            }
            $params[] = $this->accept();
        }

        return Pragma::create($pragmaToken->getValue(), $params);
    }

    private function _FullClause() {
        if (!in_array($this->type(), [self::T_IDENT, self::T_STRING, self::T_REGEX, self::T_SNIPPET, self::T_PREDICATE, self::T_SPREAD, self::T_NOTHING, self::T_BRACE_OPEN])) {
            return null;
        }
        $spread = $this->_Spread();
        $label = $this->_Label();

        // Check for invalid syntax: label:...clause (should be ...label:clause)
        if ($label !== null && $this->typeIs(self::T_SPREAD)) {
            throw new ParseError($this, "Invalid syntax 'name:...' on :location. Use '...name:clause' instead");
        }

        $predicate = $this->_Predicate();
        $clause = $this->_Clause();

        if ($clause === null && ($label !== null || $predicate !== null || $spread !== null)) {
            throw new ParseError($this, "Excpected clause on :location, got :source_description");
        } elseif ($clause === null) {
            return null;
        }

        $operator = $this->_Operator();

        $modifier = 0;

        if ($spread !== null) {
            $modifier |= Clause::SPREAD;
        }

        if ($predicate !== null) {
            switch ($predicate->text) {
                case '&': $modifier |= Clause::AND; break;
                case '!': $modifier |= Clause::NOT; break;
                default :
                    throw new ParseError($this, "Illegal predicate '{$predicate->text}' on :location");
            }
        }

        if ($operator !== null) {
            switch ($operator->text) {
                case '*': $modifier |= Clause::OPTIONAL | Clause::REPEATING; break;
                case '+': $modifier |= Clause::REPEATING; break;
                case '?': $modifier |= Clause::OPTIONAL; break;
                default :
                    throw new ParseError($this, "Illegal operator '{$operator->text}' on :location");
            }
        }
        $clause->setModifier($modifier);
        if ($label !== null) {
            switch ($label->type) {
                case self::T_IDENT: $label = $label->text; break;
                default:
                    throw new ParseError($this, "Expected identifier on :location, got :source_description");
            }
            $clause->setName($label);
        }
        return $clause;
    }

    private function _Label() {
        if (!$this->textIs(':', 1)) {
            return null;
        }
        if (in_array($this->type(), [self::T_IDENT, self::T_STRING])) {
            $label = $this->accept();
            $this->accept();
            return $label;
        }
        return null;
    }

    private function _Predicate() {
        if (!$this->typeIs(self::T_PREDICATE)) {
            return null;
        }
        $res = $this->accept();
        return $res;
    }

    private function _Spread() {
        if (!$this->typeIs(self::T_SPREAD)) {
            return null;
        }
        return $this->accept();
    }

    private function _Clause() {
        if ($this->typeIs(self::T_NOTHING)) {
            $this->accept();
            return new Nothing(Clause::NONE, null);
        }

        if (in_array($this->type(), [self::T_STRING, self::T_IDENT, self::T_REGEX, self::T_SNIPPET])) {
            if ($this->typeIs(self::T_BEGIN, 1)) {
                // look forward and avoid parsing a rule name as a clause
                return null;
            }
            if ($this->typeIs(self::T_STRING, 1) && $this->typeIs(self::T_BEGIN, 2)) {
                // look forward and avoid parsing a rule name as a clause
                return null;
            }

            $clause = $this->accept();
            $isTokenMode = $this->lexerClass !== null;

            switch ($clause->type) {
                case self::T_IDENT:
                    switch ($clause->text) {
                        case '^' : return new SOF(Clause::NONE, null);
                        case '$' : return new EOF(Clause::NONE, null);
                        case '.' : return new Any(Clause::NONE, null);
                        default :
                            // In token mode, identifiers starting with T_ are token type names
                            if ($isTokenMode && str_starts_with($clause->text, 'T_')) {
                                return new Token($clause->text, Clause::NONE, null);
                            }
                            return new Identifier($clause->text, Clause::NONE, null);
                    }

                case self::T_STRING:
                    // In token mode, strings match literal tokens; in string mode, they match substrings
                    if ($isTokenMode) {
                        return new Literal($clause->getValue(), Clause::NONE, null);
                    }
                    return new Text($clause->getValue(), Clause::NONE, null);

                case self::T_SNIPPET:
                    return new Snippet($clause->getValue(), Clause::NONE, null, $this->options->base_namespace);

                case self::T_REGEX:
                    if ($isTokenMode) {
                        throw new ParseError($this, "Regex patterns are not supported in token mode on :location");
                    }
                    return new Regex($clause->getValue(), Clause::NONE, null);

                default :
                    throw new ParseError($this, "Unexpected :source_description on :location");
            }
        }
        return $this->_BracedClause();
    }

    private function _BracedClause() {
        if (!$this->typeIs(self::T_BRACE_OPEN)) {
            return null;
        }

        $openOffset = $this->offset;
        $open = $this->accept();

        $clauses = $this->_Clauses();

        if ($clauses === null) {
            throw new ParseError($this, "Expected clause on :location, got :source_description");
        }

        if (!($clauses instanceof Sequence) && !($clauses instanceof OrderedChoice)) {
            /**
             * This was intentionally put inside braces, so we'll ensure it is a sequence
             */
            $newClauses = new Sequence(Clause::NONE, null);
            $newClauses[] = $clauses;
            $newClauses[] = new Snippet('return $_0;', Clause::NONE, null);
            $clauses = $newClauses;
        }

        $close = ['<' => '>', '(' => ')', '[' => ']', '{' => '}'][$open->text];
        if (!$this->typeIs(self::T_BRACE_CLOSE)) {
            $this->offset = $openOffset;
            throw new ParseError($this, "Expected $close on :location, found :source_description");
        }

        if (!$this->textIs($close)) {
            throw new ParseError($this, "Brace mismatch, expected '{$close}' on :location, found :source_description");
        }
        $this->accept();
        return $clauses;
    }

    private function _Operator() {
        if ($this->typeIs(self::T_OPERATOR)) {
            return $this->accept();
        }
        return null;
    }

}
