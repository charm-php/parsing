<?php
namespace Charm\Parsing\Compiler;

use Charm\Parsing\Grammar;
use Charm\Parsing\Rule;
use Charm\Parsing\Clause;
use Charm\Parsing\Clause\{
    Text,
    Regex,
    Any,
    EOF,
    SOF,
    Nothing,
    Commit,
    Sequence,
    OrderedChoice,
    Identifier,
    Snippet,
    Token,
    Literal
};

/**
 * Compiles a Grammar into a standalone PHP parser class.
 *
 * The generated parser has no runtime dependencies on the Charm\Parsing library.
 */
class GrammarCompiler
{
    private Grammar $grammar;
    private array $options;
    private CodeEmitter $emitter;
    private int $tempVarCounter = 0;
    private array $leftRecursiveRules = [];
    private string $className;
    private array $snippetMethods = []; // Collected snippet methods to generate
    private ?string $currentRuleName = null; // Track current rule for snippet naming
    private array $snippetCounters = []; // Per-rule snippet counters
    /** @var array<string, string> Rule name to generated method name (without the `parse` prefix) */
    private array $methodNames = [];
    private bool $hasCommit = false; // Whether any rule contains a commit (`@`)
    private bool $isTokenMode = false; // Whether we're compiling for token-mode parsing

    /**
     * Compile a grammar to PHP code
     *
     * @param Grammar $grammar The grammar to compile
     * @param string $className The name for the generated class
     * @param array $options Compilation options:
     *   - namespace: PHP namespace for the class
     *   - implements: array of interfaces to implement
     *   - baseClass: parent class to extend
     *   - template: custom template file path
     *   - globals: initial state/globals array
     * @return string The generated PHP code
     */
    public function compile(Grammar $grammar, string $className, array $options = []): string
    {
        $this->grammar = $grammar;
        $this->className = $className;
        $this->options = array_merge([
            'namespace' => null,
            'implements' => [],
            'baseClass' => null,
            'template' => null,
            'globals' => [],
        ], $options);
        $this->tempVarCounter = 0;
        $this->leftRecursiveRules = [];
        $this->snippetMethods = [];
        $this->currentRuleName = null;
        $this->snippetCounters = [];
        $this->hasCommit = false;
        foreach ($grammar as $rule) {
            $this->hasCommit = $this->hasCommit || $this->containsCommit($rule->getClause());
        }
        $this->emitter = new CodeEmitter(1);

        // PHP method names are case-insensitive, so rules that only differ in case (or in a character
        // that becomes `_`) get a numeric suffix; the first rule keeps the plain name
        $this->methodNames = [];
        $taken = [];
        foreach ($grammar as $ruleName => $rule) {
            $name = ucfirst(preg_replace('/[^a-zA-Z0-9]/', '_', $ruleName));
            for ($n = 2, $candidate = $name; isset($taken[strtolower($candidate)]); $n++) {
                $candidate = $name . '__' . $n;
            }
            $taken[strtolower($candidate)] = true;
            $this->methodNames[$ruleName] = $candidate;
        }

        // Detect left-recursive rules
        $this->detectLeftRecursion();

        // Detect token mode and select appropriate template
        $this->isTokenMode = $grammar->isTokenMode();
        if ($this->options['template']) {
            $templatePath = $this->options['template'];
        } else {
            $templatePath = $this->isTokenMode
                ? __DIR__ . '/parser-token.template.php'
                : __DIR__ . '/parser.template.php';
        }
        $template = file_get_contents($templatePath);

        // Generate components
        $namespace = $this->generateNamespace();
        $useStatements = $this->generateUseStatements();
        $classDeclaration = $this->generateClassDeclaration($className);
        $properties = $this->generateProperties();
        $initCode = $this->generateInitCode();
        $parseMethods = $this->generateParseMethods();
        $descriptions = $this->generateDescriptions();

        // First rule is the goal
        [$goalRule, $goalDescription] = $this->getGoalRuleInfo();

        // Generate local enum for signals
        $signalEnum = "enum {$className}Signal { case HIT; case MISS; }\n";

        // Generated parsers are standalone, so each carries its own exception class
        $parseErrorClass = <<<PHP
/** Thrown by {$className}::parse(). `\$committed` is true if a commit (`@`) had been passed, so no alternative could recover. */
class {$className}ParseError extends \\RuntimeException
{
    public function __construct(
        string \$message,
        public readonly bool \$committed,
        public readonly int \$sourceLine,
        public readonly int \$sourceColumn,
        public readonly int \$offset,
        public readonly string \$expected,
        public readonly string \$found,
    ) {
        parent::__construct(\$message);
    }
}

PHP;

        // Build replacements
        $replacements = [
            '{{GRAMMAR_NAME}}' => $grammar->filename ?? $className,
            '{{NAMESPACE}}' => $namespace,
            '{{USE_STATEMENTS}}' => $useStatements,
            '{{CLASS_DECLARATION}}' => $classDeclaration,
            '{{PROPERTIES}}' => $properties,
            '{{INIT_CODE}}' => $initCode,
            '{{GOAL_RULE}}' => $goalRule,
            '{{GOAL_DESCRIPTION}}' => $goalDescription,
            '{{PARSE_METHODS}}' => $parseMethods,
            '{{SIGNAL_ENUM}}' => $signalEnum,
            '{{PARSE_ERROR_CLASS}}' => $parseErrorClass,
            '{{CLASS_NAME}}' => $className,
            '{{DESCRIPTIONS}}' => $descriptions,
        ];

        // Add token-mode specific replacements
        if ($this->isTokenMode) {
            $replacements['{{LEXER_CLASS}}'] = '\\' . ltrim($grammar->getLexerClass(), '\\');
        }

        $code = strtr($template, $replacements);

        return $code;
    }

    /**
     * Compile and write directly to a file
     */
    public function compileToFile(Grammar $grammar, string $className, string $filePath, array $options = []): void
    {
        $code = $this->compile($grammar, $className, $options);
        file_put_contents($filePath, $code);
    }

    private function getGoalRuleInfo(): array
    {
        foreach ($this->grammar as $ruleName => $rule) {
            $methodName = $this->toMethodName($ruleName);
            $description = $rule->getDescription() ?? $ruleName;
            return [$methodName, $description];
        }
        throw new \RuntimeException("Grammar has no rules");
    }

    private function toMethodName(string $ruleName): string
    {
        return $this->methodNames[$ruleName] ?? ucfirst(preg_replace('/[^a-zA-Z0-9]/', '_', $ruleName));
    }

    private function signalMiss(): string
    {
        return $this->className . 'Signal::MISS';
    }

    private function signalHit(): string
    {
        return $this->className . 'Signal::HIT';
    }

    private function detectLeftRecursion(): void
    {
        foreach ($this->grammar as $ruleName => $rule) {
            $clause = $rule->getClause();
            if ($clause instanceof OrderedChoice) {
                foreach ($clause as $choice) {
                    if ($this->isLeftRecursive($choice, $ruleName)) {
                        $this->leftRecursiveRules[$ruleName] = true;
                        break;
                    }
                }
            }
        }
    }

    /**
     * Check if a clause references a rule marked with %silent pragma
     */
    private function isSilentReference(Clause $clause): bool
    {
        if (!($clause instanceof Identifier)) {
            return false;
        }
        $targetRule = $this->grammar[$clause->getIdentifier()] ?? null;
        return $targetRule !== null && $targetRule->getClause()->pragma('silent') !== null;
    }

    /**
     * Check if a clause always returns Signal::HIT (no value)
     * SOF (^), EOF ($), and Nothing (\nothing) always return HIT
     */
    private function alwaysReturnsHit(Clause $clause): bool
    {
        return $clause instanceof SOF
            || $clause instanceof EOF
            || $clause instanceof Nothing;
    }

    private function isLeftRecursive(Clause $clause, string $ruleName, array $visited = []): bool
    {
        if (in_array($clause, $visited, true)) {
            return false;
        }
        $visited[] = $clause;

        if ($clause instanceof Identifier) {
            if ($clause->getIdentifier() === $ruleName) {
                return true;
            }
            // Check through referenced rule
            $targetRule = $this->grammar[$clause->getIdentifier()];
            if ($targetRule) {
                return $this->isLeftRecursive($targetRule->getClause(), $ruleName, $visited);
            }
        } elseif ($clause instanceof Sequence) {
            // Only check first element for left recursion
            foreach ($clause as $first) {
                return $this->isLeftRecursive($first, $ruleName, $visited);
            }
        } elseif ($clause instanceof OrderedChoice) {
            // For detecting if a rule needs Pratt parsing: ANY LR choice means LR
            foreach ($clause as $choice) {
                if ($this->isLeftRecursive($choice, $ruleName, $visited)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if a clause MUST be left-recursive (all paths lead to LR).
     * Used for classifying Pratt alternatives - if true, it's an operator.
     * If false, it can be a primary (has a non-LR exit path).
     */
    private function mustBeLeftRecursive(Clause $clause, string $ruleName, array $visited = []): bool
    {
        if (in_array($clause, $visited, true)) {
            return false; // Cycle without hitting target - not LR on this path
        }
        $visited[] = $clause;

        if ($clause instanceof Identifier) {
            if ($clause->getIdentifier() === $ruleName) {
                return true;
            }
            $targetRule = $this->grammar[$clause->getIdentifier()];
            if ($targetRule) {
                return $this->mustBeLeftRecursive($targetRule->getClause(), $ruleName, $visited);
            }
        } elseif ($clause instanceof Sequence) {
            foreach ($clause as $first) {
                return $this->mustBeLeftRecursive($first, $ruleName, $visited);
            }
        } elseif ($clause instanceof OrderedChoice) {
            // ALL choices must be LR for the OrderedChoice to be necessarily LR
            foreach ($clause as $choice) {
                if (!$this->mustBeLeftRecursive($choice, $ruleName, $visited)) {
                    return false; // Found a non-LR exit
                }
            }
            return true;
        }

        return false; // Terminals are not LR
    }

    private function generateNamespace(): string
    {
        if ($this->options['namespace']) {
            return "namespace {$this->options['namespace']};\n";
        }
        return '';
    }

    private function generateUseStatements(): string
    {
        return '';
    }

    private function generateClassDeclaration(string $className): string
    {
        $decl = "class $className";
        if ($this->options['baseClass']) {
            $decl .= " extends {$this->options['baseClass']}";
        }
        if (!empty($this->options['implements'])) {
            $decl .= " implements " . implode(', ', $this->options['implements']);
        }
        return $decl;
    }

    private function generateProperties(): string
    {
        $props = '';
        if (!empty($this->options['globals'])) {
            $props .= "    private array \$globals = " . var_export($this->options['globals'], true) . ";\n";
        } else {
            $props .= "    private array \$globals = [];\n";
        }
        return $props;
    }

    private function generateInitCode(): string
    {
        return '';
    }

    private function generateDescriptions(): string
    {
        $descriptions = [
            '$' => 'end of input',  // EOF marker
        ];
        foreach ($this->grammar as $ruleName => $rule) {
            $desc = $rule->getDescription();
            if ($desc !== null) {
                $descriptions[$ruleName] = $desc;
            }
        }
        return var_export($descriptions, true);
    }

    private function generateParseMethods(): string
    {
        $this->emitter->reset()->setIndent(1);

        foreach ($this->grammar as $ruleName => $rule) {
            $this->generateRuleMethod($ruleName, $rule);
            $this->emitter->line();
        }

        // Generate collected snippet methods
        foreach ($this->snippetMethods as $method) {
            // No return type - snippets may use return; without value
            $this->emitter->line("private function {$method['name']}({$method['params']})");
            $this->emitter->openBlock();
            // Make $state available as alias to $this->globals
            $this->emitter->line('$state = &$this->globals;');
            if (strpos($method['code'], '$MISS') !== false) {
                $this->emitter->line('$MISS = ' . $this->signalMiss() . ';');
            }
            if (strpos($method['code'], '$HIT') !== false) {
                $this->emitter->line('$HIT = ' . $this->signalHit() . ';');
            }
            // Like the interpreter, report anything thrown by a snippet as a SnippetError
            $this->emitter->line('try {');
            $this->emitter->indent();
            $this->emitter->line($method['code']);
            $this->emitSnippetCatch();
            $this->emitter->closeBlock();
            $this->emitter->line();
        }

        return $this->emitter->getCode();
    }

    private function generateRuleMethod(string $ruleName, Rule $rule): void
    {
        $this->currentRuleName = $ruleName;
        $methodName = 'parse' . $this->toMethodName($ruleName);
        $clause = $rule->getClause();
        $isLeftRecursive = isset($this->leftRecursiveRules[$ruleName]);

        if ($isLeftRecursive && $clause instanceof OrderedChoice) {
            $this->generatePrattMethod($ruleName, $rule);
        } else {
            $this->emitter->line("private function $methodName(): mixed");
            $this->emitter->openBlock();
            $this->emitter->line('$_offset = $this->offset;');
            // Only track expectations for rules with descriptions that must consume input
            if ($rule->getDescription() !== null && $clause->getMinByteLength() > 0) {
                $this->emitter->line("\$this->expect(" . var_export($ruleName, true) . ");");
            }
            $this->emitter->line();
            $this->generateClauseCode($clause, '$_result', true);
            $this->emitter->line();
            $this->emitter->line('return $_result;');
            $this->emitter->closeBlock();
        }
    }

    /**
     * Generate Pratt parser for left-recursive rules
     */
    private function generatePrattMethod(string $ruleName, Rule $rule): void
    {
        $methodName = 'parse' . $this->toMethodName($ruleName);
        $clause = $rule->getClause();

        // Collect primary (non-left-recursive) and operator (left-recursive) alternatives
        $primaryAlts = [];
        $operatorAlts = [];
        $numAlts = count($clause);

        foreach ($clause as $index => $choice) {
            [$prec, $assoc] = $this->grammar->resolveAlternativePrecedence($choice, $ruleName, $index, $numAlts);

            // Use mustBeLeftRecursive to check if ALL paths are LR
            // If not, it can serve as a primary (has non-LR exit path)
            if ($this->mustBeLeftRecursive($choice, $ruleName)) {
                $operatorAlts[] = ['clause' => $choice, 'precedence' => $prec, 'assoc' => $assoc];
            } else {
                $primaryAlts[] = ['clause' => $choice, 'precedence' => $prec, 'assoc' => $assoc];
            }
        }

        // Main Pratt method
        $this->emitter->line("private function $methodName(int \$_minPrec = 0): mixed");
        $this->emitter->openBlock();
        if ($rule->getDescription() !== null && $clause->getMinByteLength() > 0) {
            $this->emitter->line("\$this->expect(" . var_export($ruleName, true) . ");");
        }

        // Parse primary (left-hand side)
        $this->emitter->line('$_left = $this->' . $methodName . 'Primary();');
        $this->emitter->line('if ($_left === ' . $this->signalMiss() . ') {');
        $this->emitter->indent()->line('return ' . $this->signalMiss() . ';');
        $this->emitter->closeBlock();
        $this->emitter->line();

        // Pratt loop
        $this->emitter->line('while (true) {');
        $this->emitter->indent();
        $this->emitter->line('$_offset = $this->offset;');
        $this->emitter->line('$_matched = false;');
        $this->emitter->line();

        foreach ($operatorAlts as $i => $alt) {
            $this->generatePrattOperatorCase($alt, $ruleName, $i === 0);
            // After each operator check, if we matched, skip remaining operators
            $this->emitter->line('if ($_matched) continue;');
            $this->emitter->line();
        }

        // If no operator matched, exit the Pratt loop
        $this->emitter->line('if (!$_matched) {');
        $this->emitter->indent()->line('break;');
        $this->emitter->closeBlock();
        $this->emitter->closeBlock();
        $this->emitter->line();
        $this->emitter->line('return $_left;');
        $this->emitter->closeBlock();
        $this->emitter->line();

        // Primary method (non-left-recursive alternatives)
        $this->emitter->line("private function {$methodName}Primary(): mixed");
        $this->emitter->openBlock();
        $this->emitter->line('$_offset = $this->offset;');
        $this->emitter->line();

        foreach ($primaryAlts as $i => $alt) {
            if ($i > 0) {
                $this->emitter->line('$this->offset = $_offset;');
            }
            // For prefix operators (non-LR clauses containing recursive reference),
            // generate specialized code that passes precedence
            if ($this->containsRecursiveReference($alt['clause'], $ruleName)) {
                $this->generatePrattPrimarySequence($alt['clause'], $ruleName, $alt['precedence'], '$_result');
            } else {
                $this->generateClauseCode($alt['clause'], '$_result');
            }
            $this->emitter->line('if ($_result !== ' . $this->signalMiss() . ') {');
            $this->emitter->indent()->line('return $_result;');
            $this->emitter->closeBlock();
            $this->emitter->line();
        }

        $this->emitter->line('$this->offset = $_offset;');
        $this->emitter->line('return ' . $this->signalMiss() . ';');
        $this->emitter->closeBlock();
    }

    /**
     * Check if a clause contains a recursive reference to the given rule
     */
    private function containsRecursiveReference(Clause $clause, string $ruleName): bool
    {
        if ($clause instanceof Identifier && $clause->getIdentifier() === $ruleName) {
            return true;
        }
        if ($clause instanceof Sequence) {
            foreach ($clause as $sub) {
                if ($this->containsRecursiveReference($sub, $ruleName)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Generate code for a prefix operator or bracketed sequence (non-LR alternative that mentions the
     * rule itself). A reference to the rule that ends the sequence is open on the right (a prefix
     * operator), so it only binds operators tighter than this alternative: it is parsed with the
     * alternative's precedence. A reference that is followed by something else ("(" Expr ")") is
     * delimited, so it is a full expression.
     */
    private function generatePrattPrimarySequence(Clause $clause, string $ruleName, int $precedence, string $resultVar): void
    {
        if (!($clause instanceof Sequence)) {
            // Single clause - just generate normal code
            $this->generateClauseCode($clause, $resultVar);
            return;
        }

        $seqId = ++$this->tempVarCounter;
        $tempOffset = '$_seqOffset' . $seqId;
        $this->emitter->line("$tempOffset = \$this->offset;");
        $this->emitter->line("$resultVar = [];");

        $context = [];
        $contextNames = [];
        $hasInlineSnippet = false;
        $committed = false;
        $methodName = 'parse' . $this->toMethodName($ruleName);

        // The last element that is not a snippet or a commit
        $lastIndex = -1;
        foreach ($clause as $i => $subClause) {
            if (!($subClause instanceof Snippet) && !($subClause instanceof Commit)) {
                $lastIndex = $i;
            }
        }

        foreach ($clause as $i => $subClause) {
            if ($subClause instanceof Commit) {
                $committed = true;
                continue;
            }
            if ($subClause instanceof Snippet) {
                $hasInlineSnippet = !$this->generateSequenceSnippet($subClause, $context, $contextNames, $resultVar, $tempOffset, $seqId, $i, $committed, false) || $hasInlineSnippet;
                continue;
            }

            $itemVar = '$_seq' . $seqId . '_' . $i;

            if ($subClause instanceof Identifier && $subClause->getIdentifier() === $ruleName && $subClause->getModifier() === Clause::NONE) {
                $minPrec = $i === $lastIndex ? $precedence : 0;
                $this->emitter->line("$itemVar = \$this->$methodName($minPrec);");
            } else {
                $this->generateClauseCode($subClause, $itemVar);
            }

            $this->emitter->line("if ($itemVar === " . $this->signalMiss() . ") {");
            $this->emitter->indent();
            $this->emitSequenceMiss($resultVar, $tempOffset, $seqId, $committed, false);
            $this->emitter->closeBlock();

            $this->emitSequenceItem($subClause, $itemVar, $resultVar, $context, $contextNames);
        }

        if (!$hasInlineSnippet) {
            $this->emitter->line("if ($resultVar === []) { $resultVar = " . $this->signalHit() . "; }");
        }

        $this->emitter->line("_seqEnd{$seqId}:");
    }

    private function generatePrattOperatorCase(array $alt, string $ruleName, bool $first): void
    {
        $clause = $alt['clause'];
        $precedence = $alt['precedence'];
        $assoc = $alt['assoc'] ?? 'left';

        // `none` groups like `left` (see docs/precedence-and-pragmas.md)
        $rightAssoc = ($assoc === 'right');

        $this->emitter->line("// Precedence $precedence" . ($rightAssoc ? ' (right-associative)' : ''));
        $this->emitter->line("if (\$_minPrec < $precedence) {");
        $this->emitter->indent();

        // For a sequence like: Expr "+" Expr { snippet code }
        // We need to skip the first Expr (already parsed as $_left), match the middle parts,
        // then recursively parse the right Expr
        if ($clause instanceof Sequence) {
            $this->generatePrattSequence($clause, $ruleName, $precedence, $rightAssoc);
        } else {
            // Single clause that is somehow left-recursive (edge case)
            $this->emitter->line('// Single left-recursive clause');
            $this->generateClauseCode($clause, '$_opResult');
            $this->emitter->line('if ($_opResult !== ' . $this->signalMiss() . ') {');
            $this->emitter->indent();
            $this->emitter->line('$_left = $_opResult;');
            $this->emitter->line('$_matched = true;');
            $this->emitter->closeBlock();
        }

        $this->emitter->closeBlock();
        $this->emitter->line();
    }

    private function generatePrattSequence(Sequence $seq, string $ruleName, int $precedence, bool $rightAssoc): void
    {
        $parts = iterator_to_array($seq);
        $firstIsLR = $this->isLeftRecursive($parts[0], $ruleName);

        if (!$firstIsLR) {
            $this->emitter->line('// ERROR: Expected left-recursive first element');
            return;
        }

        // Skip first element (already have $_left)
        $context = ['$_left'];
        $contextNames = [];
        $items = []; // the value of the alternative when it has no snippet
        if ($name = $parts[0]->getName()) {
            $contextNames[$name] = '$_left';
            $items[] = var_export($name, true) . ' => $_left';
        } else {
            $items[] = '$_left';
        }

        $committed = false; // passed an `@`: a later failure is a parse error

        $this->emitter->line('do {');
        $this->emitter->indent();

        // Parse middle elements (operators, etc.)
        $hasSnippet = false;
        $miss = function () use (&$committed) {
            if ($committed) {
                $this->emitter->line('$this->fail(' . var_export($this->currentRuleName ?? 'input', true) . ', true);');
            } else {
                $this->emitter->line('$this->offset = $_offset; break;');
            }
        };
        for ($i = 1; $i < count($parts); $i++) {
            $part = $parts[$i];

            if ($part instanceof Commit) {
                $committed = true;
                continue;
            }

            // Check if this is a recursive reference to the same rule
            if ($part instanceof Identifier && $part->getIdentifier() === $ruleName && $part->getModifier() === Clause::NONE) {
                // Right operand - recursive call with precedence
                // For right-associativity, use lower precedence to allow same-precedence operators to bind
                $nextPrec = $rightAssoc ? ($precedence - 1) : $precedence;
                $methodName = 'parse' . $this->toMethodName($ruleName);
                $varName = '$_r' . $i;
                $this->emitter->line("$varName = \$this->$methodName($nextPrec);");
                $this->emitter->line("if ($varName === " . $this->signalMiss() . ") {");
                $this->emitter->indent();
                $miss();
                $this->emitter->closeBlock();
                $context[] = $varName;
                if ($name = $part->getName()) {
                    $contextNames[$name] = $varName;
                    $items[] = var_export($name, true) . " => $varName";
                } else {
                    $items[] = $varName;
                }
            } elseif ($part instanceof Snippet) {
                // Execute snippet code - assign result to $_left
                $snVar = '$_sn' . $i;
                if ($this->generateSnippetCode($part, $context, $contextNames, $snVar)) {
                    $hasSnippet = true;
                    $this->emitter->line("if ($snVar instanceof \\UnitEnum && {$snVar}->name === 'MISS') {");
                    $this->emitter->indent();
                    $miss();
                    $this->emitter->closeBlock();
                    $this->emitter->line("if ($snVar instanceof \\Charm\\Parsing\\SequenceIgnore) { $snVar = " . $this->signalHit() . "; }");
                    $this->emitter->line("if (!($snVar instanceof \\Charm\\Parsing\\SequenceResume)) { \$_left = $snVar; }");
                }
            } else {
                // Regular clause
                $varName = '$_m' . $i;
                $this->generateClauseCode($part, $varName);
                $this->emitter->line("if ($varName === " . $this->signalMiss() . ") {");
                $this->emitter->indent();
                $miss();
                $this->emitter->closeBlock();
                // Predicates and valueless terminals produce no value, %silent references are skipped,
                // and a named element is always captured
                if ($name = $part->getName()) {
                    $contextNames[$name] = $varName;
                    $items[] = var_export($name, true) . " => $varName";
                } elseif ($this->takesPositionalSlot($part)) {
                    $context[] = $varName;
                    $items[] = $varName;
                }
            }
        }

        // Success - update $_left (only if no snippet already set it)
        if (!$hasSnippet) {
            $this->emitter->line('$_left = [' . implode(', ', $items) . '];');
        }
        $this->emitter->line('$_matched = true;');
        $this->emitter->line('continue;');
        $this->emitter->closeBlock(' while (false);');
    }

    private function generateClauseCode(Clause $clause, string $resultVar, bool $isTopLevel = false): void
    {
        // Handle predicates (& and !)
        if ($clause->hasAndPredicate() || $clause->hasNotPredicate()) {
            $this->generatePredicateCode($clause, $resultVar);
            return;
        }

        // Handle modifiers - use bitwise checks since SPREAD/CASE_INSENSITIVE may also be set
        $isOptional = $clause->isOptional();
        $isRepeating = $clause->isRepeating();

        if ($isOptional && !$isRepeating) {
            $this->generateOptionalCode($clause, $resultVar);
        } elseif ($isRepeating && !$isOptional) {
            $this->generateOneOrMoreCode($clause, $resultVar);
        } elseif ($isOptional && $isRepeating) {
            $this->generateZeroOrMoreCode($clause, $resultVar);
        } else {
            // No quantifier modifier - direct match
            $this->generateDirectClauseCode($clause, $resultVar, $isTopLevel);
        }
    }

    private function generateDirectClauseCode(Clause $clause, string $resultVar, bool $isTopLevel = false): void
    {
        if ($clause instanceof Text) {
            $this->generateTextCode($clause, $resultVar);
        } elseif ($clause instanceof Regex) {
            $this->generateRegexCode($clause, $resultVar);
        } elseif ($clause instanceof Token) {
            $this->generateTokenCode($clause, $resultVar);
        } elseif ($clause instanceof Literal) {
            $this->generateLiteralCode($clause, $resultVar);
        } elseif ($clause instanceof Any) {
            $this->generateAnyCode($clause, $resultVar);
        } elseif ($clause instanceof EOF) {
            $this->generateEOFCode($clause, $resultVar);
        } elseif ($clause instanceof SOF) {
            $this->generateSOFCode($clause, $resultVar);
        } elseif ($clause instanceof Nothing) {
            $this->generateNothingCode($clause, $resultVar);
        } elseif ($clause instanceof Commit) {
            // Commit outside a sequence has nothing to commit to; sequences handle it themselves
            $this->emitter->line("$resultVar = " . $this->signalHit() . ";");
        } elseif ($clause instanceof Identifier) {
            $this->generateIdentifierCode($clause, $resultVar);
        } elseif ($clause instanceof Sequence) {
            $this->generateSequenceCode($clause, $resultVar, $isTopLevel);
        } elseif ($clause instanceof OrderedChoice) {
            $this->generateOrderedChoiceCode($clause, $resultVar);
        } elseif ($clause instanceof Snippet) {
            $this->emitter->line("$resultVar = true; // Snippet handled separately");
        } else {
            $this->emitter->line("$resultVar = false; // Unknown clause type: " . get_class($clause));
        }
    }

    /**
     * Emit the failure branch of a terminal: the result is MISS, and the furthest failure offset is
     * remembered so that error positions match the interpreter's.
     */
    private function emitTerminalMiss(string $resultVar, string $expected): void
    {
        $this->emitter->line("$resultVar = " . $this->signalMiss() . ";");
        $this->emitter->line('if ($this->offset > $this->maxOffset) { $this->maxOffset = $this->offset; $this->expected = ' . var_export($expected, true) . '; }');
    }

    private function generateTextCode(Text $clause, string $resultVar): void
    {
        $textValue = $clause->getText();
        $length = strlen($textValue);
        $escaped = var_export($textValue, true);

        // In token mode, empty string means "match zero tokens" = always succeed
        if ($length === 0 && $this->isTokenMode) {
            $this->emitter->line("$resultVar = " . $this->signalHit() . "; // Empty match in token mode");
            return;
        }

        // In token mode, literal strings match single-character tokens (operators, punctuation)
        if ($this->isTokenMode && $length === 1) {
            // Single char literal - check token array for this character
            $this->emitter->line("if (\$this->offset < \$this->length && \$this->tokens[\$this->offset] === $escaped) {");
            $this->emitter->indent();
            $this->emitter->line("$resultVar = \$this->tokenData->getValue(\$this->offset);");
            $this->emitter->line("\$this->offset++;");
            $this->emitter->dedent()->line('} else {');
            $this->emitter->indent();
            $this->emitTerminalMiss($resultVar, json_encode($textValue));
            $this->emitter->closeBlock();
            return;
        }

        $isAscii = preg_match('/^[\x00-\x7F]*$/', $textValue);
        $isCaseInsensitive = $clause->isCaseInsensitive();

        // Optimize single-character checks with array access
        if ($length === 1 && !$isCaseInsensitive) {
            $this->emitter->line("if (\$this->offset < \$this->length && \$this->source[\$this->offset] === $escaped) {");
            $this->emitter->indent();
            $this->emitter->line("$resultVar = \$this->source[\$this->offset++];");
        } elseif ($length === 1 && $isCaseInsensitive && $isAscii) {
            // ASCII single-char case-insensitive: check both cases
            $lower = strtolower($textValue);
            $upper = strtoupper($textValue);
            $this->emitter->line("if (\$this->offset < \$this->length && (\$this->source[\$this->offset] === '$lower' || \$this->source[\$this->offset] === '$upper')) {");
            $this->emitter->indent();
            $this->emitter->line("$resultVar = \$this->source[\$this->offset++];");
        } elseif ($isCaseInsensitive && !$isAscii) {
            // Unicode case-insensitive: use preg_match for correct Unicode case folding
            $pattern = var_export('/\G' . preg_quote($textValue, '/') . '/iu', true);
            $this->emitter->line("if (\\preg_match($pattern, \$this->source, \$m, 0, \$this->offset)) {");
            $this->emitter->indent();
            $this->emitter->line("$resultVar = \$m[0];");
            $this->emitter->line("\$this->offset += \\strlen(\$m[0]);");
        } elseif ($isCaseInsensitive) {
            // ASCII case-insensitive: substr_compare with case flag is fast
            $this->emitter->line("if (\\substr_compare(\$this->source, $escaped, \$this->offset, $length, true) === 0) {");
            $this->emitter->indent();
            $this->emitter->line("$resultVar = \\substr(\$this->source, \$this->offset, $length);");
            $this->emitter->line("\$this->offset += $length;");
        } else {
            // Case-sensitive: substr_compare is fastest
            $this->emitter->line("if (\\substr_compare(\$this->source, $escaped, \$this->offset, $length) === 0) {");
            $this->emitter->indent();
            $this->emitter->line("$resultVar = \\substr(\$this->source, \$this->offset, $length);");
            $this->emitter->line("\$this->offset += $length;");
        }
        $this->emitter->dedent()->line('} else {');
        $this->emitter->indent();
        $this->emitTerminalMiss($resultVar, json_encode($textValue));
        $this->emitter->closeBlock();
    }

    private function generateRegexCode(Regex $clause, string $resultVar): void
    {
        $pattern = $clause->asString();
        // Add 'A' flag for anchoring at offset, 'u' for unicode
        $flags = 'uA';
        if ($clause->isCaseInsensitive()) {
            $flags .= 'i';
        }
        $fullPattern = var_export($pattern . $flags, true);

        $this->emitter->line("if (\\preg_match($fullPattern, \$this->source, \$_m, 0, \$this->offset) === 1) {");
        $this->emitter->indent();
        $this->emitter->line("$resultVar = \$_m[0];");
        $this->emitter->line('$this->offset += \\strlen($_m[0]);');
        $this->emitter->dedent()->line('} else {');
        $this->emitter->indent();
        $this->emitTerminalMiss($resultVar, $pattern);
        $this->emitter->closeBlock();
    }

    private function generateTokenCode(Token $clause, string $resultVar): void
    {
        $tokenName = $clause->getTokenName();

        // Use the PHP constant directly - no need for symbol map indirection
        $this->emitter->line("if (\$this->offset < \$this->length && \$this->tokens[\$this->offset] === \\$tokenName) {");
        $this->emitter->indent();
        $this->emitter->line("$resultVar = \$this->tokenData->getValue(\$this->offset);");
        $this->emitter->line('$this->offset++;');
        $this->emitter->dedent()->line('} else {');
        $this->emitter->indent();
        $this->emitTerminalMiss($resultVar, $tokenName);
        $this->emitter->closeBlock();
    }

    private function generateLiteralCode(Literal $clause, string $resultVar): void
    {
        $literalValue = $clause->getLiteral();
        $literal = var_export($literalValue, true);

        // Empty literal in token mode means "match zero tokens" = always succeed
        if ($literalValue === '' && $this->isTokenMode) {
            $this->emitter->line("$resultVar = " . $this->signalHit() . "; // Empty literal in token mode");
            return;
        }

        // Direct comparison - works for both token mode (strings like ";") and char mode
        $this->emitter->line("if (\$this->offset < \$this->length && \$this->tokens[\$this->offset] === $literal) {");
        $this->emitter->indent();
        $this->emitter->line("$resultVar = $literal;");
        $this->emitter->line('$this->offset++;');
        $this->emitter->dedent()->line('} else {');
        $this->emitter->indent();
        $this->emitTerminalMiss($resultVar, json_encode($literalValue));
        $this->emitter->closeBlock();
    }

    private function generateAnyCode(Any $clause, string $resultVar): void
    {
        $this->emitter->line('if ($this->offset < $this->length) {');
        $this->emitter->indent();
        $this->emitter->line("$resultVar = \\mb_substr(\\substr(\$this->source, \$this->offset, 4), 0, 1);");
        $this->emitter->line("\$this->offset += \\strlen($resultVar);");
        $this->emitter->dedent()->line('} else {');
        $this->emitter->indent();
        $this->emitTerminalMiss($resultVar, 'any character');
        $this->emitter->closeBlock();
    }

    private function generateEOFCode(EOF $clause, string $resultVar): void
    {
        $this->emitter->line("if (\$this->offset === \$this->length) {");
        $this->emitter->indent();
        $this->emitter->line("$resultVar = " . $this->signalHit() . ";");
        $this->emitter->dedent()->line("} else {");
        $this->emitter->indent();
        $this->emitter->line("\$this->expect('$');");
        $this->emitter->line("$resultVar = " . $this->signalMiss() . ";");
        $this->emitter->closeBlock();
    }

    private function generateSOFCode(SOF $clause, string $resultVar): void
    {
        $this->emitter->line("if (\$this->offset === 0) {");
        $this->emitter->indent()->line("$resultVar = " . $this->signalHit() . ";");
        $this->emitter->dedent()->line("} else {");
        $this->emitter->indent();
        $this->emitTerminalMiss($resultVar, 'start of input');
        $this->emitter->closeBlock();
    }

    private function generateNothingCode(Nothing $clause, string $resultVar): void
    {
        $this->emitter->line("$resultVar = " . $this->signalHit() . ";");
    }

    private function generateIdentifierCode(Identifier $clause, string $resultVar): void
    {
        $methodName = 'parse' . $this->toMethodName($clause->getIdentifier());
        $targetRule = $this->grammar[$clause->getIdentifier()] ?? null;

        // Generate guarded call with fast-fail check
        // Only guard when minByteLen > 0 - if rule can match empty, we can't short-circuit
        if ($targetRule) {
            $targetClause = $targetRule->getClause();
            $minByteLen = $targetClause->getMinByteLength();
            $hasDescription = $targetRule->getDescription() !== null;

            $firstBytes = $minByteLen > 0 ? $targetClause->getFirstBytes() : null;
            if ($firstBytes !== null) {
                // Only the first byte is checked here: if the rule would fail further in, it has to run
                // so that the furthest failure offset (reported by fail()) is the same as when interpreted
                $guard = '$this->offset >= $this->length || ' . $this->generateByteNotInCheck('$this->source[$this->offset]', $firstBytes);

                // Always track expectation when guard fails (uses rule name if no description)
                $ruleName = var_export($clause->getIdentifier(), true);
                $this->emitter->line("if ($guard) {");
                $this->emitter->indent();
                $this->emitter->line("\$this->expect($ruleName);");
                $this->emitter->line("$resultVar = " . $this->signalMiss() . ";");
                $this->emitter->dedent()->line("} else {");
                $this->emitter->indent();
                $this->emitter->line("$resultVar = \$this->$methodName();");
                $this->emitter->closeBlock();
                return;
            }
        }

        $this->emitter->line("$resultVar = \$this->$methodName();");
    }

    /**
     * Does the clause tree (not following rule references) contain a commit (`@`)?
     */
    private function containsCommit(Clause $clause): bool
    {
        if ($clause instanceof Commit) {
            return true;
        }
        if ($clause instanceof Sequence || $clause instanceof OrderedChoice) {
            foreach ($clause as $sub) {
                if ($this->containsCommit($sub)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Does a clause contribute a positional slot ($_0, $_1 ...) to a following snippet?
     * Named captures, predicates, valueless terminals and %silent references do not.
     */
    private function takesPositionalSlot(Clause $clause): bool
    {
        return $clause->getName() === null
            && !$clause->hasAndPredicate()
            && !$clause->hasNotPredicate()
            && !$clause instanceof Commit
            && !$this->alwaysReturnsHit($clause)
            && !$this->isSilentReference($clause);
    }

    /**
     * Emit the code that makes the enclosing sequence fail. After a commit (`@`) the failure is
     * a parse error that no alternative can recover from; otherwise the sequence rewinds and
     * reports MISS so that the caller can try something else.
     */
    private function emitSequenceMiss(string $resultVar, string $tempOffset, int $seqId, bool $committed, bool $isTopLevel, bool $hasPredicate = false): void
    {
        if ($hasPredicate) {
            // A predicate that fails leaves no terminal failure behind, but the interpreter still
            // reports the sequence's start as a failure position
            $this->emitter->line("if ($tempOffset > \$this->maxOffset) { \$this->maxOffset = $tempOffset; \$this->expected = null; }");
        }
        if ($committed) {
            $this->emitter->line('$this->fail(' . var_export($this->currentRuleName ?? 'input', true) . ', true);');
            return;
        }
        $this->emitter->line("\$this->offset = $tempOffset;");
        if ($isTopLevel) {
            $this->emitter->line("return " . $this->signalMiss() . ";");
        } else {
            $this->emitter->line("$resultVar = " . $this->signalMiss() . ";");
            $this->emitter->line("goto _seqEnd{$seqId};");
        }
    }

    /**
     * Emit the code that adds a matched sequence item to the sequence's result array, and register it
     * as a snippet argument.
     */
    private function emitSequenceItem(Clause $subClause, string $itemVar, string $resultVar, array &$context, array &$contextNames): void
    {
        // Don't add HIT values to result array (from terminals like \nothing, $, ^)
        $this->emitter->line("if ($itemVar !== " . $this->signalHit() . ") {");
        $this->emitter->indent();
        if ($subClause->hasSpread()) {
            // Spread: skip null, flatten arrays into result
            $this->emitter->line("if ($itemVar !== null) {");
            $this->emitter->indent();
            $this->emitter->line("if (is_array($itemVar)) {");
            $this->emitter->indent();
            $this->emitter->line("foreach ($itemVar as \$_spreadItem) {");
            $this->emitter->indent();
            $this->emitter->line("if (is_array(\$_spreadItem)) {");
            $this->emitter->indent();
            $this->emitter->line("foreach (\$_spreadItem as \$_subItem) { {$resultVar}[] = \$_subItem; }");
            $this->emitter->dedent()->line("} else {");
            $this->emitter->indent();
            $this->emitter->line("{$resultVar}[] = \$_spreadItem;");
            $this->emitter->closeBlock();
            $this->emitter->closeBlock();
            $this->emitter->dedent()->line("} else {");
            $this->emitter->indent();
            $this->emitter->line("{$resultVar}[] = $itemVar;");
            $this->emitter->closeBlock();
            $this->emitter->closeBlock();
            // Spread clauses still need to be in snippet context
            $context[] = $itemVar;
        } elseif ($name = $subClause->getName()) {
            $this->emitter->line("{$resultVar}[" . var_export($name, true) . "] = $itemVar;");
            $contextNames[$name] = $itemVar;
        } else {
            // Check if this is a reference to a %silent rule
            if (!$this->isSilentReference($subClause)) {
                $this->emitter->line("{$resultVar}[] = $itemVar;");
            }
            if ($this->takesPositionalSlot($subClause)) {
                $context[] = $itemVar;
            }
        }
        $this->emitter->closeBlock();
    }

    /**
     * Emit a snippet inside a sequence and act on what it returned: `$MISS` fails the sequence,
     * SequenceIgnore ends it as a success without a value, SequenceResume carries on, anything else
     * is the value of the sequence. Returns false if the snippet had no `return` and was inlined.
     */
    private function generateSequenceSnippet(Snippet $snippet, array $context, array $contextNames, string $resultVar, string $tempOffset, int $seqId, int $index, bool $committed, bool $isTopLevel, bool $hasPredicate = false): bool
    {
        $snVar = '$_sn' . $seqId . '_' . $index;
        if (!$this->generateSnippetCode($snippet, $context, $contextNames, $snVar)) {
            return false;
        }
        $this->emitter->line("if ($snVar instanceof \\UnitEnum && {$snVar}->name === 'MISS') {");
        $this->emitter->indent();
        $this->emitSequenceMiss($resultVar, $tempOffset, $seqId, $committed, $isTopLevel, $hasPredicate);
        $this->emitter->closeBlock();
        $this->emitter->line("if ($snVar instanceof \\Charm\\Parsing\\SequenceIgnore) { $snVar = " . $this->signalHit() . "; }");
        $this->emitter->line("if (!($snVar instanceof \\Charm\\Parsing\\SequenceResume)) {");
        $this->emitter->indent();
        if ($isTopLevel) {
            $this->emitter->line("return $snVar;");
        } else {
            $this->emitter->line("$resultVar = $snVar;");
            $this->emitter->line("goto _seqEnd{$seqId};");
        }
        $this->emitter->closeBlock();
        return true;
    }

    private function generateSequenceCode(Sequence $clause, string $resultVar, bool $isTopLevel = false): void
    {
        $seqId = ++$this->tempVarCounter;
        $tempOffset = '$_seqOffset' . $seqId;
        $this->emitter->line("$tempOffset = \$this->offset;");
        $this->emitter->line("$resultVar = [];");

        $context = [];
        $contextNames = [];
        $hasInlineSnippet = false;
        $committed = false; // passed an `@` in this sequence: a later failure is a parse error
        $hasPredicate = false;
        foreach ($clause as $subClause) {
            $hasPredicate = $hasPredicate || $subClause->hasAndPredicate() || $subClause->hasNotPredicate();
        }

        foreach ($clause as $i => $subClause) {
            if ($subClause instanceof Commit) {
                $committed = true;
                continue;
            }
            if ($subClause instanceof Snippet) {
                $hasInlineSnippet = !$this->generateSequenceSnippet($subClause, $context, $contextNames, $resultVar, $tempOffset, $seqId, $i, $committed, $isTopLevel, $hasPredicate) || $hasInlineSnippet;
                continue;
            }

            $itemVar = '$_seq' . $seqId . '_' . $i;
            $this->generateClauseCode($subClause, $itemVar);

            $this->emitter->line("if ($itemVar === " . $this->signalMiss() . ") {");
            $this->emitter->indent();
            $this->emitSequenceMiss($resultVar, $tempOffset, $seqId, $committed, $isTopLevel, $hasPredicate);
            $this->emitter->closeBlock();

            $this->emitSequenceItem($subClause, $itemVar, $resultVar, $context, $contextNames);
        }

        // If nothing was collected the sequence is value-less (HIT)
        if (!$hasInlineSnippet) {
            $this->emitter->line("if ($resultVar === []) { $resultVar = " . $this->signalHit() . "; }");
        }

        if (!$isTopLevel) {
            $this->emitter->line("_seqEnd{$seqId}:");
        }
    }

    private function generateOrderedChoiceCode(OrderedChoice $clause, string $resultVar): void
    {
        // The dispatch optimizations skip alternatives that cannot match; with a commit (`@`) in the
        // grammar an alternative that cannot match may still be a parse error, so they are off then
        if (!$this->hasCommit) {
            // Try single-variance-point optimization (combined regex + dispatch)
            $analysis = $this->analyzeSingleVariancePattern($clause);
            if ($analysis !== null) {
                $this->generateSingleVarianceCode($clause, $analysis, $resultVar);
                return;
            }

            // Try switch-based dispatch if alternatives have disjoint first bytes
            if ($this->canUseSwitchDispatch($clause)) {
                $this->generateSwitchDispatchCode($clause, $resultVar);
                return;
            }
        }

        // Fall back to sequential alternatives. The first alternative that succeeds wins, even
        // when it succeeded without consuming anything.
        $counter = ++$this->tempVarCounter;
        $tempOffset = '$_choiceOffset' . $counter;

        $this->emitter->line("$tempOffset = \$this->offset;");

        $numChoices = count(iterator_to_array($clause));
        $i = 0;
        foreach ($clause as $choice) {
            $choiceVar = '$_choice' . $counter . '_' . $i;
            $this->generateClauseCode($choice, $choiceVar);

            $this->emitter->line("if ($choiceVar !== " . $this->signalMiss() . ") {");
            $this->emitter->indent();
            $this->emitter->line("$resultVar = $choiceVar;");
            $this->emitter->line("goto _choiceEnd{$counter};");
            $this->emitter->closeBlock();

            // Restore offset for next alternative (if not last)
            if ($i < $numChoices - 1) {
                $this->emitter->line("\$this->offset = $tempOffset;");
            }
            $i++;
        }

        // All choices failed
        foreach ($clause as $choice) {
            if ($choice->hasAndPredicate() || $choice->hasNotPredicate()) {
                // see emitSequenceMiss(): a failed predicate leaves no terminal failure behind
                $this->emitter->line("if ($tempOffset > \$this->maxOffset) { \$this->maxOffset = $tempOffset; \$this->expected = null; }");
                break;
            }
        }
        $this->emitter->line("\$this->offset = $tempOffset;");
        $this->emitter->line("$resultVar = " . $this->signalMiss() . ";");
        $this->emitter->line("_choiceEnd{$counter}:");
    }

    private function canUseSwitchDispatch(OrderedChoice $clause): bool
    {
        $allBytes = '';
        foreach ($clause as $choice) {
            // An alternative that can succeed without consuming input (optional, `*`, a predicate,
            // a nullable rule) must be tried in order, so it can't be dispatched on a byte
            if ($choice->isOptional() || $choice->hasAndPredicate() || $choice->hasNotPredicate() || $choice->getMinByteLength() < 1) {
                return false;
            }
            $firstBytes = $choice->getFirstBytes();
            if ($firstBytes === null || $firstBytes === '') {
                return false; // Unknown or empty first bytes
            }
            // Check for overlap with previous alternatives
            for ($i = 0; $i < strlen($firstBytes); $i++) {
                if (strpos($allBytes, $firstBytes[$i]) !== false) {
                    return false; // Overlap detected
                }
            }
            $allBytes .= $firstBytes;
        }
        return true;
    }

    private function generateSwitchDispatchCode(OrderedChoice $clause, string $resultVar): void
    {
        $counter = ++$this->tempVarCounter;

        // Build byte-to-alternative mapping (use ord() as key to avoid PHP's numeric string conversion)
        $byteToAlt = [];
        foreach ($clause as $i => $choice) {
            $firstBytes = $choice->getFirstBytes();
            for ($j = 0; $j < strlen($firstBytes); $j++) {
                $byteToAlt[ord($firstBytes[$j])] = [$i, $firstBytes[$j]];
            }
        }

        $this->emitter->line("if (\$this->offset >= \$this->length) {");
        $this->emitter->indent();
        $this->emitTerminalMiss($resultVar, $clause->toGrammarString());
        $this->emitter->dedent()->line('} else {');
        $this->emitter->indent();

        $this->emitter->line("switch (\$this->source[\$this->offset]) {");
        $this->emitter->indent();

        // Group bytes by alternative for cleaner output
        $altToBytes = [];
        foreach ($byteToAlt as $ord => [$altIndex, $byte]) {
            $altToBytes[$altIndex][] = $byte;
        }

        foreach ($altToBytes as $altIndex => $bytes) {
            foreach ($bytes as $byte) {
                $escaped = var_export($byte, true);
                $this->emitter->line("case $escaped:");
            }
            $this->emitter->indent();
            $choiceVar = '$_sw' . $counter . '_' . $altIndex;
            $this->generateClauseCode($clause[$altIndex], $choiceVar);
            $this->emitter->line("$resultVar = $choiceVar;");
            $this->emitter->line("break;");
            $this->emitter->dedent();
        }

        $this->emitter->line("default:");
        $this->emitter->indent();
        $this->emitTerminalMiss($resultVar, $clause->toGrammarString());
        $this->emitter->dedent();

        $this->emitter->dedent()->line('}');
        $this->emitter->closeBlock();
    }

    private function generateOptionalCode(Clause $clause, string $resultVar): void
    {
        // Clone with no modifier for the inner match
        $inner = clone $clause;
        $inner->setModifier(Clause::NONE);

        $innerVar = '$_opt' . (++$this->tempVarCounter);
        $this->generateDirectClauseCode($inner, $innerVar);

        $this->emitter->line("$resultVar = ($innerVar !== " . $this->signalMiss() . ") ? $innerVar : null;");
    }

    private function generateOneOrMoreCode(Clause $clause, string $resultVar): void
    {
        $this->generateRepetitionCode($clause, $resultVar, true);
    }

    private function generateZeroOrMoreCode(Clause $clause, string $resultVar): void
    {
        $this->generateRepetitionCode($clause, $resultVar, false);
    }

    /**
     * `+` and `*`. An iteration that consumes nothing ends the repetition (it would repeat forever);
     * it is not collected, except that the first one still counts as the match `+` requires. A value-less
     * iteration (HIT) advances the loop but contributes nothing to the result.
     */
    private function generateRepetitionCode(Clause $clause, string $resultVar, bool $atLeastOne): void
    {
        $inner = clone $clause;
        $inner->setModifier(Clause::NONE);

        $loopVar = (++$this->tempVarCounter);
        $itemVar = '$_rep' . $loopVar;
        $startVar = '$_repOffset' . $loopVar;

        // The interpreter doesn't see the attempt that ends a repetition of a plain terminal (it calls the
        // terminal directly), so that failure isn't remembered either - the clause after the loop is
        // tried at the same offset and reports it if it matters
        $quiet = $inner->isTerminal();
        if ($quiet) {
            $this->emitter->line("{$itemVar}Max = \$this->maxOffset; {$itemVar}Exp = \$this->expected;");
        }
        $countVar = $itemVar . 'Count';
        $hit = $this->signalHit();
        $this->emitter->line("$resultVar = []; $countVar = 0;");
        $this->emitter->line('do {');
        $this->emitter->indent();
        $this->emitter->line("$startVar = \$this->offset;");

        $this->generateDirectClauseCode($inner, $itemVar);
        $this->emitter->line("if ($itemVar !== " . $this->signalMiss() . ") {");
        $this->emitter->indent();
        $this->emitter->line("if (\$this->offset === $startVar) {");
        $this->emitter->indent();
        if ($atLeastOne) {
            $this->emitter->line("if ($countVar === 0) { $countVar++; if ($itemVar !== $hit) { {$resultVar}[] = $itemVar; } }");
        }
        $this->emitter->line('break;');
        $this->emitter->closeBlock();
        // a value-less iteration (HIT) advances the loop but is not collected
        $this->emitter->line("$countVar++;");
        $this->emitter->line("if ($itemVar !== $hit) { {$resultVar}[] = $itemVar; }");
        $this->emitter->closeBlock();

        $this->emitter->dedent()->line("} while ($itemVar !== " . $this->signalMiss() . ");");
        if ($quiet) {
            $restore = "\$this->maxOffset = {$itemVar}Max; \$this->expected = {$itemVar}Exp;";
            $this->emitter->line($atLeastOne ? "if ($countVar !== 0) { $restore }" : $restore);
        }
        if ($atLeastOne) {
            $this->emitter->line("if ($countVar === 0) { $resultVar = " . $this->signalMiss() . "; }");
        }
    }

    private function generatePredicateCode(Clause $clause, string $resultVar): void
    {
        $tempOffset = '$_predOffset' . (++$this->tempVarCounter);
        $this->emitter->line("$tempOffset = \$this->offset;");

        // Create a copy without predicate flags for matching. A quantifier on a predicate (`&A*`) is
        // ignored, as in the interpreter: the predicate looks for one match of A.
        $inner = clone $clause;
        $inner->setModifier($clause->getModifier() & ~(Clause::AND | Clause::NOT | Clause::OPTIONAL | Clause::REPEATING));

        $innerVar = '$_pred' . $this->tempVarCounter;
        // A predicate on a plain terminal never counts as a failure position (the interpreter's trace
        // doesn't see it either), so the failure that makes `&"a"` miss isn't remembered
        $quiet = $inner->isTerminal();
        if ($quiet) {
            $this->emitter->line("{$innerVar}Max = \$this->maxOffset; {$innerVar}Exp = \$this->expected;");
        }
        if ($this->hasCommit) {
            // A failure after a commit inside a predicate just means the predicate didn't match
            $this->emitter->line('try {');
            $this->emitter->indent();
            $this->generateClauseCode($inner, $innerVar);
            $this->emitter->dedent()->line('} catch (' . $this->className . 'ParseError $_e) {');
            $this->emitter->indent();
            $this->emitter->line('if (!$_e->committed) { throw $_e; }');
            $this->emitter->line("$innerVar = " . $this->signalMiss() . ";");
            $this->emitter->closeBlock();
        } else {
            $this->generateClauseCode($inner, $innerVar);
        }

        // Restore offset (predicates don't consume)
        $this->emitter->line("\$this->offset = $tempOffset;");
        if ($quiet) {
            $this->emitter->line("\$this->maxOffset = {$innerVar}Max; \$this->expected = {$innerVar}Exp;");
        }

        if ($clause->hasNotPredicate()) {
            $this->emitter->line("$resultVar = ($innerVar === " . $this->signalMiss() . ") ? " . $this->signalHit() . " : " . $this->signalMiss() . ";");
        } else {
            $this->emitter->line("$resultVar = ($innerVar !== " . $this->signalMiss() . ") ? " . $this->signalHit() . " : " . $this->signalMiss() . ";");
        }
    }

    /**
     * Close a `try {` around snippet code: like the interpreter, report anything the snippet throws as a
     * SnippetError (when the library is around; a standalone parser rethrows the original).
     */
    private function emitSnippetCatch(): void
    {
        $this->emitter->dedent()->line('} catch (\\Throwable $_e) {');
        $this->emitter->indent();
        $this->emitter->line("throw \\class_exists('Charm\\Parsing\\Error\\SnippetError') ? new \\Charm\\Parsing\\Error\\SnippetError('Error in snippet: ' . \$_e->getMessage(), '', \$_e) : \$_e;");
        $this->emitter->closeBlock();
    }

    /**
     * Emit a call of the snippet (or its inlined code). Returns true if the call's return value was
     * assigned to $resultVar, false if the snippet has no `return` and was inlined.
     */
    private function generateSnippetCode(Snippet $snippet, array $context, array $contextNames, string $resultVar = null): bool
    {
        // Get the compiled source code from the snippet
        $compiledSource = $snippet->getCompiledSource();

        // Build parameter list - combine positional and named args
        $allArgs = [];
        foreach ($context as $i => $var) {
            $allArgs[$i] = $var;
        }
        foreach ($contextNames as $name => $var) {
            $allArgs[$name] = $var;
        }
        $allArgs = array_unique($allArgs);

        // Transform code: replace $_context references with parameter names
        $code = $compiledSource;
        $paramNames = [];
        $paramIndex = 0;

        foreach ($context as $i => $var) {
            $paramName = '$_p' . $paramIndex++;
            $code = str_replace("\$_context[$i]", $paramName, $code);
            $paramNames[$var] = $paramName;
        }

        foreach ($contextNames as $name => $var) {
            if (!isset($paramNames[$var])) {
                $paramName = '$_p' . $paramIndex++;
                $paramNames[$var] = $paramName;
            }
            $code = str_replace("\$_context['$name']", $paramNames[$var], $code);
            $code = str_replace("\$_context[\"$name\"]", $paramNames[$var], $code);
        }

        // Replace $state reference - $state is available as reference to $this->globals in snippet methods
        $code = str_replace("\$_context['state']", '$state', $code);

        // Transform remaining $_context['name'] references (user-defined variables) to local vars
        $code = preg_replace('/\$_context\[\'([a-zA-Z_][a-zA-Z0-9_]*)\'\]/', '\$$1', $code);
        $code = preg_replace('/\$_context\["([a-zA-Z_][a-zA-Z0-9_]*)"\]/', '\$$1', $code);

        // Handle the return value
        if (strpos($code, 'return') !== false) {
            // Use the snippet's registered name from Grammar, or generate a fallback
            $registeredName = $snippet->getSnippetName();
            if ($registeredName !== null) {
                $methodName = 'snippet_' . $registeredName;
            } else {
                // Fallback for snippets not registered (shouldn't happen normally)
                $ruleBaseName = $this->currentRuleName
                    ? $this->toMethodName($this->currentRuleName)
                    : 'anon';
                if (!isset($this->snippetCounters[$ruleBaseName])) {
                    $this->snippetCounters[$ruleBaseName] = 0;
                }
                $snippetNum = ++$this->snippetCounters[$ruleBaseName];
                $methodName = lcfirst($ruleBaseName) . '_snippet' . $snippetNum;
            }
            // Add $_offset as first parameter (provides start position of matched rule)
            $paramList = array_values($paramNames);
            array_unshift($paramList, '$_offset');
            $params = implode(', ', $paramList);

            $argList = array_keys($paramNames);
            array_unshift($argList, '$_offset');
            $args = implode(', ', $argList);

            $this->snippetMethods[] = [
                'name' => $methodName,
                'params' => $params,
                'code' => $code,
            ];

            if ($resultVar) {
                $this->emitter->line("$resultVar = \$this->$methodName($args);");
            } else {
                $this->emitter->line("\$this->$methodName($args);");
            }
            return (bool) $resultVar;
        } else {
            // Just inline the code (trimming semicolons and adding proper ones)
            $code = rtrim(trim($code), ';');
            if ($code !== '') {
                $this->emitter->line('try {');
                $this->emitter->indent();
                $this->emitter->line($code . ';');
                $this->emitSnippetCatch();
            }
            return false;
        }
    }

    /**
     * Generate an expression that checks if $byteExpr is NOT in the given byte set.
     * Optimizes for single bytes and small sets.
     */
    private function generateByteNotInCheck(string $byteExpr, string $bytes): string
    {
        $len = strlen($bytes);
        if ($len === 1) {
            // Single byte: direct comparison
            return "$byteExpr !== " . var_export($bytes, true);
        } elseif ($len <= 4) {
            // Small set: chained comparisons
            $checks = [];
            for ($i = 0; $i < $len; $i++) {
                $checks[] = "$byteExpr !== " . var_export($bytes[$i], true);
            }
            return '(' . implode(' && ', $checks) . ')';
        } else {
            // Larger set: use str_contains
            return "!\\str_contains(" . var_export($bytes, true) . ", $byteExpr)";
        }
    }

    /**
     * Analyze an OrderedChoice to detect the "single variance point" pattern.
     *
     * Pattern: alternatives differ only at one position (a terminal), with identical
     * prefix and suffix clauses. Each alternative may have a snippet at the end.
     *
     * Examples:
     *   Number = /hex/ <? s1 ?> | /oct/ <? s2 ?> | /dec/ <? s3 ?>
     *   AssignExpr = lhs:X _ "=" _ rhs:Y <? s1 ?> | lhs:X _ "+=" _ rhs:Y <? s2 ?> | ...
     *
     * @return array|null Analysis result with keys: prefix, suffix, terminals, snippets, fallback
     */
    private function analyzeSingleVariancePattern(OrderedChoice $clause): ?array
    {
        $alternatives = iterator_to_array($clause);
        if (count($alternatives) < 2) {
            return null;
        }

        // Decompose each alternative into: clauses[] + snippet?
        $decomposed = [];
        $fallbackIndex = null;

        foreach ($alternatives as $i => $alt) {
            $clauses = [];
            $snippet = null;

            // A quantified, named or predicated alternative group is not a plain sequence of its parts
            if (($alt instanceof Sequence || $alt instanceof Snippet)
                && ($alt->getModifier() !== Clause::NONE || $alt->getName() !== null)) {
                return null;
            }

            if ($alt instanceof Sequence) {
                foreach ($alt as $sub) {
                    if ($sub instanceof Snippet) {
                        $snippet = $sub;
                    } else {
                        $clauses[] = $sub;
                    }
                }
            } elseif ($alt instanceof Snippet) {
                // Bare snippet (unlikely but handle it)
                $snippet = $alt;
            } else {
                // Single clause (terminal or identifier)
                $clauses[] = $alt;
            }

            // If this alternative has no terminal and no snippet, it might be a fallback
            // (e.g., the last "| ConditionalExpr" in AssignExpr)
            // But only ONE fallback is allowed - multiple identifier alternatives break the optimization
            if (count($clauses) === 1 && $snippet === null && $clauses[0] instanceof Identifier) {
                if ($fallbackIndex !== null || $i !== count($alternatives) - 1) {
                    // Multiple identifier-only alternatives, or one that isn't tried last - can't optimize
                    return null;
                }
                $fallbackIndex = $i;
                continue;
            }

            $decomposed[] = [
                'index' => $i,
                'clauses' => $clauses,
                'snippet' => $snippet,
                'original' => $alt,
            ];
        }

        // Need at least 2 alternatives to optimize (excluding fallback)
        if (count($decomposed) < 2) {
            return null;
        }

        // Find the variance point - the position where clauses differ
        $firstClauses = $decomposed[0]['clauses'];
        $numClauses = count($firstClauses);

        // All alternatives must have the same number of non-snippet clauses
        foreach ($decomposed as $d) {
            if (count($d['clauses']) !== $numClauses) {
                return null;
            }
        }

        // Find which position varies
        $variancePos = null;
        for ($pos = 0; $pos < $numClauses; $pos++) {
            $allSame = true;
            $firstClauseStr = $this->clauseSignature($firstClauses[$pos]);

            foreach ($decomposed as $d) {
                if ($this->clauseSignature($d['clauses'][$pos]) !== $firstClauseStr) {
                    $allSame = false;
                    break;
                }
            }

            if (!$allSame) {
                if ($variancePos !== null) {
                    // Multiple variance points - pattern doesn't match
                    return null;
                }
                $variancePos = $pos;
            }
        }

        if ($variancePos === null) {
            // All positions are identical - nothing to optimize
            return null;
        }

        // Verify the varying clause is a terminal (Text or Regex) in all alternatives
        $terminals = [];
        foreach ($decomposed as $d) {
            $varying = $d['clauses'][$variancePos];
            if (!($varying instanceof Text) && !($varying instanceof Regex)) {
                return null;
            }
            // Must not have modifiers or predicates
            if ($varying->getModifier() !== Clause::NONE) {
                return null;
            }
            if ($varying->hasAndPredicate() || $varying->hasNotPredicate()) {
                return null;
            }
            // A terminal that can match the empty string always wins; the combined regex can't tell
            if ($varying->getMinByteLength() < 1) {
                return null;
            }
            $terminals[] = $varying;
        }

        // The combined regex commits to the first terminal that matches. That is only the same as trying
        // the alternatives in order if a failure after the terminal (in the suffix or in a snippet)
        // can't be rescued by a later terminal, i.e. if no two terminals can match at the same place.
        $hasSnippet = array_filter(array_column($decomposed, 'snippet')) !== [];
        if (count($firstClauses) > $variancePos + 1 || $hasSnippet) {
            $seen = '';
            foreach ($terminals as $terminal) {
                $first = $terminal->getFirstBytes();
                if ($first === null || $first === '' || strpbrk($seen, $first) !== false) {
                    return null;
                }
                $seen .= $first;
            }
        }

        // Extract prefix and suffix clauses (from first alternative, they're all identical)
        $prefix = array_slice($firstClauses, 0, $variancePos);
        $suffix = array_slice($firstClauses, $variancePos + 1);

        // Collect snippets
        $snippets = [];
        foreach ($decomposed as $d) {
            $snippets[] = $d['snippet'];
        }

        return [
            'prefix' => $prefix,
            'suffix' => $suffix,
            'terminals' => $terminals,
            'snippets' => $snippets,
            'decomposed' => $decomposed,
            'fallbackIndex' => $fallbackIndex,
            'fallback' => $fallbackIndex !== null ? $alternatives[$fallbackIndex] : null,
        ];
    }

    /**
     * Generate optimized code for single-variance-point pattern.
     */
    private function generateSingleVarianceCode(OrderedChoice $clause, array $analysis, string $resultVar): void
    {
        $counter = ++$this->tempVarCounter;
        $tempOffset = '$_svOffset' . $counter;

        $this->emitter->line("// Optimized: single-variance-point pattern");
        $this->emitter->line("$tempOffset = \$this->offset;");

        // Build context for snippets (prefix clauses contribute to context)
        $context = [];
        $contextNames = [];

        // Parse prefix clauses
        if (!empty($analysis['prefix'])) {
            $this->emitter->line("do { // prefix");
            $this->emitter->indent();

            foreach ($analysis['prefix'] as $i => $prefixClause) {
                $itemVar = '$_svPre' . $counter . '_' . $i;
                $this->generateClauseCode($prefixClause, $itemVar);

                $this->emitter->line("if ($itemVar === " . $this->signalMiss() . ") {");
                $this->emitter->indent();
                $this->emitter->line("\$this->offset = $tempOffset;");
                $this->emitter->line("$resultVar = " . $this->signalMiss() . ";");
                $this->emitter->line("break;");
                $this->emitter->closeBlock();

                // Track context
                if ($itemVar !== $this->signalHit()) {
                    $isSilent = $this->isSilentReference($prefixClause);
                    if ($name = $prefixClause->getName()) {
                        $contextNames[$name] = $itemVar;
                        $context[] = $itemVar;
                    } elseif (!$isSilent && !$this->alwaysReturnsHit($prefixClause)) {
                        $context[] = $itemVar;
                    }
                }
            }
        }

        // Build combined regex from terminals
        $patterns = [];
        foreach ($analysis['terminals'] as $i => $terminal) {
            if ($terminal instanceof Text) {
                $patterns[] = '(?P<g' . $i . '>' . preg_quote($terminal->getText(), '/') . ')';
            } elseif ($terminal instanceof Regex) {
                $inner = $terminal->getInnerPattern();
                // Wrap in non-capturing group if contains alternation
                if (strpos($inner, '|') !== false) {
                    $patterns[] = '(?P<g' . $i . '>(?:' . $inner . '))';
                } else {
                    $patterns[] = '(?P<g' . $i . '>' . $inner . ')';
                }
            }
        }
        $combinedPattern = '/(?:' . implode('|', $patterns) . ')/uA';

        // Match the varying terminal
        $matchVar = '$_svMatch' . $counter;
        $this->emitter->line("if (\\preg_match(" . var_export($combinedPattern, true) . ", \$this->source, $matchVar, 0, \$this->offset)) {");
        $this->emitter->indent();
        $this->emitter->line("\$this->offset += \\strlen({$matchVar}[0]);");

        // Track the matched terminal in context (as $_0 for snippets)
        $terminalVar = '$_svTerm' . $counter;
        $this->emitter->line("$terminalVar = {$matchVar}[0];");

        // Check if terminals have names - if so, add to context
        $terminalName = $analysis['terminals'][0]->getName();
        if ($terminalName !== null) {
            $contextNames[$terminalName] = $terminalVar;
        }
        $context[] = $terminalVar;

        // Parse suffix clauses
        if (!empty($analysis['suffix'])) {
            $suffixFailed = '$_svSuffixFailed' . $counter;
            $this->emitter->line("$suffixFailed = false;");
            $this->emitter->line("do { // suffix");
            $this->emitter->indent();

            foreach ($analysis['suffix'] as $i => $suffixClause) {
                $itemVar = '$_svSuf' . $counter . '_' . $i;
                $this->generateClauseCode($suffixClause, $itemVar);

                $this->emitter->line("if ($itemVar === " . $this->signalMiss() . ") {");
                $this->emitter->indent();
                $this->emitter->line("\$this->offset = $tempOffset;");
                $this->emitter->line("$suffixFailed = true;");
                $this->emitter->line("break;");
                $this->emitter->closeBlock();

                // Track context
                $isSilent = $this->isSilentReference($suffixClause);
                if ($name = $suffixClause->getName()) {
                    $contextNames[$name] = $itemVar;
                    $context[] = $itemVar;
                } elseif (!$isSilent && !$this->alwaysReturnsHit($suffixClause)) {
                    $context[] = $itemVar;
                }
            }

            $this->emitter->closeBlock(' while (false);');

            $this->emitter->line("if ($suffixFailed) {");
            $this->emitter->indent();
            $this->emitter->line("$resultVar = " . $this->signalMiss() . ";");
            $this->emitter->dedent()->line("} else {");
            $this->emitter->indent();
        }

        // Dispatch based on which group matched
        $this->emitter->line("// Dispatch based on matched terminal");
        foreach ($analysis['terminals'] as $i => $terminal) {
            $groupCheck = "isset({$matchVar}['g{$i}']) && {$matchVar}['g{$i}'] !== ''";
            $this->emitter->line(($i === 0 ? "if" : "} elseif") . " ($groupCheck) {");
            $this->emitter->indent();

            $snippet = $analysis['snippets'][$i] ?? null;
            if ($snippet !== null) {
                $this->generateSnippetCode($snippet, $context, $contextNames, $resultVar);
            } elseif (count($context) === 1) {
                // Single value - return directly (matches normal terminal behavior)
                $this->emitter->line("$resultVar = " . $context[0] . ";");
            } else {
                // Multiple values - return as array
                $this->emitter->line("$resultVar = [" . implode(', ', $context) . "];");
            }
            $this->emitter->dedent();
        }
        $this->emitter->line("}");

        // Close suffix else block if we had suffix
        if (!empty($analysis['suffix'])) {
            $this->emitter->closeBlock();
        }

        // Close regex match block
        $this->emitter->dedent()->line("} else {");
        $this->emitter->indent();
        $this->emitTerminalMiss($resultVar, implode(' or ', array_map(fn($t) => $t instanceof Text ? json_encode($t->getText()) : $t->asString(), $analysis['terminals'])));
        $this->emitter->line("\$this->offset = $tempOffset;");
        $this->emitter->closeBlock();

        // Close prefix do-while if we had prefix
        if (!empty($analysis['prefix'])) {
            $this->emitter->closeBlock(' while (false);');
        }

        // Handle fallback if present
        if ($analysis['fallback'] !== null) {
            $this->emitter->line("if ($resultVar === " . $this->signalMiss() . ") {");
            $this->emitter->indent();
            $this->emitter->line("\$this->offset = $tempOffset;");
            $fallbackVar = '$_svFallback' . $counter;
            $this->generateClauseCode($analysis['fallback'], $fallbackVar);
            $this->emitter->line("$resultVar = $fallbackVar;");
            $this->emitter->closeBlock();
        }
    }

    /**
     * Generate a signature string for a clause, used to compare structural equality.
     */
    private function clauseSignature(Clause $clause): string
    {
        // Include class, modifier, name, and string representation
        $sig = get_class($clause) . '|' . $clause->getModifier() . '|' . ($clause->getName() ?? '') . '|';

        if ($clause instanceof Identifier) {
            $sig .= $clause->getIdentifier();
        } elseif ($clause instanceof Text) {
            $sig .= $clause->getText();
        } elseif ($clause instanceof Regex) {
            $sig .= $clause->getPattern();
        } elseif ($clause instanceof Sequence) {
            $parts = [];
            foreach ($clause as $sub) {
                $parts[] = $this->clauseSignature($sub);
            }
            $sig .= '[' . implode(',', $parts) . ']';
        } elseif ($clause instanceof OrderedChoice) {
            $parts = [];
            foreach ($clause as $sub) {
                $parts[] = $this->clauseSignature($sub);
            }
            $sig .= '(' . implode('|', $parts) . ')';
        } else {
            $sig .= $clause->asString();
        }

        // Include predicates
        if ($clause->hasAndPredicate()) $sig .= '&';
        if ($clause->hasNotPredicate()) $sig .= '!';

        return $sig;
    }
}
