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
    Snippet
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
        $this->emitter = new CodeEmitter(1);

        // Detect left-recursive rules
        $this->detectLeftRecursion();

        // Load template
        $templatePath = $this->options['template'] ?? __DIR__ . '/parser.template.php';
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

        // Replace placeholders
        $code = strtr($template, [
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
            '{{CLASS_NAME}}' => $className,
            '{{DESCRIPTIONS}}' => $descriptions,
        ]);

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
        return ucfirst(preg_replace('/[^a-zA-Z0-9]/', '_', $ruleName));
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
            foreach ($clause as $choice) {
                if ($this->isLeftRecursive($choice, $ruleName, $visited)) {
                    return true;
                }
            }
        }

        return false;
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
            $this->emitter->line("private function {$method['name']}({$method['params']}): mixed");
            $this->emitter->openBlock();
            // Make $state available as alias to $this->globals
            $this->emitter->line('$state = &$this->globals;');
            $this->emitter->line($method['code']);
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
            // Check for explicit %prec pragma, otherwise use position-based precedence
            $explicitPrec = $choice->pragma('prec');
            if ($explicitPrec !== null) {
                $prec = (int) $explicitPrec[0];
            } else {
                // First alternative gets highest precedence (binds tightest)
                // This matches standard operator precedence convention
                $prec = $numAlts - $index;
            }
            if ($this->isLeftRecursive($choice, $ruleName)) {
                $operatorAlts[] = ['clause' => $choice, 'precedence' => $prec];
            } else {
                $primaryAlts[] = ['clause' => $choice, 'precedence' => $prec];
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
     * Generate code for a prefix operator sequence (non-LR with recursive reference)
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
        $savedCommit = '$_seqCommit' . $seqId;
        $this->emitter->line("$tempOffset = \$this->offset;");
        $this->emitter->line("$savedCommit = \$this->commitOffset;");
        $this->emitter->line("$resultVar = [];");

        $context = [];
        $contextNames = [];
        $hasSnippet = false;
        $methodName = 'parse' . $this->toMethodName($ruleName);

        foreach ($clause as $i => $subClause) {
            if ($subClause instanceof Snippet) {
                $hasSnippet = true;
                $this->generateSnippetCode($subClause, $context, $contextNames, $resultVar);
                continue;
            }

            $itemVar = '$_seq' . $seqId . '_' . $i;

            // Check if this is a recursive reference - pass precedence
            if ($subClause instanceof Identifier && $subClause->getIdentifier() === $ruleName && $subClause->getModifier() === Clause::NONE) {
                $this->emitter->line("$itemVar = \$this->$methodName($precedence);");
            } else {
                $this->generateClauseCode($subClause, $itemVar);
            }

            $this->emitter->line("if ($itemVar === " . $this->signalMiss() . ") {");
            $this->emitter->indent();
            $this->emitter->line("if (\$this->commitOffset === $savedCommit || $tempOffset >= \$this->commitOffset) {");
            $this->emitter->indent();
            $this->emitter->line("\$this->offset = $tempOffset;");
            $this->emitter->closeBlock();
            $this->emitter->line("$resultVar = " . $this->signalMiss() . ";");
            $this->emitter->line("goto _seqEnd{$seqId};");
            $this->emitter->closeBlock();

            // Add to result/context
            $this->emitter->line("if ($itemVar !== " . $this->signalHit() . ") {");
            $this->emitter->indent();
            $isSilent = $this->isSilentReference($subClause);
            if ($name = $subClause->getName()) {
                $this->emitter->line("{$resultVar}[" . var_export($name, true) . "] = $itemVar;");
                $contextNames[$name] = $itemVar;
                $context[] = $itemVar;
            } elseif (!$isSilent) {
                $this->emitter->line("{$resultVar}[] = $itemVar;");
            }
            if (!$this->alwaysReturnsHit($subClause) && !$isSilent) {
                $context[] = $itemVar;
            }
            $this->emitter->closeBlock();
        }

        if (!$hasSnippet) {
            $this->emitter->line("if ($resultVar === []) { $resultVar = null; }");
        }

        $this->emitter->line("_seqEnd{$seqId}:");
    }

    private function generatePrattOperatorCase(array $alt, string $ruleName, bool $first): void
    {
        $clause = $alt['clause'];
        $precedence = $alt['precedence'];

        // Get right-associativity from pragma if present
        $rightAssoc = false;
        if ($clause->pragma('assoc') !== null && $clause->pragma('assoc')[0] === 'right') {
            $rightAssoc = true;
        }

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
        if ($name = $parts[0]->getName()) {
            $contextNames[$name] = '$_left';
        }

        // Save commitOffset - commits inside this Pratt alternative shouldn't leak out if parsing fails
        $counter = ++$this->tempVarCounter;
        $savedCommit = '$_prattCommit' . $counter;
        $this->emitter->line("$savedCommit = \$this->commitOffset;");

        $this->emitter->line('do {');
        $this->emitter->indent();

        // Parse middle elements (operators, etc.)
        $hasSnippet = false;
        for ($i = 1; $i < count($parts); $i++) {
            $part = $parts[$i];

            // Check if this is a recursive reference to the same rule
            if ($part instanceof Identifier && $part->getIdentifier() === $ruleName && $part->getModifier() === Clause::NONE) {
                // Right operand - recursive call with precedence
                // For right-associativity, use lower precedence to allow same-precedence operators to bind
                $nextPrec = $rightAssoc ? ($precedence - 1) : $precedence;
                $methodName = 'parse' . $this->toMethodName($ruleName);
                $varName = '$_r' . $i;
                $this->emitter->line("$varName = \$this->$methodName($nextPrec);");
                $this->emitter->line("if ($varName === " . $this->signalMiss() . ") { \$this->offset = \$_offset; \$this->commitOffset = $savedCommit; break; }");
                $context[] = $varName;
                if ($name = $part->getName()) {
                    $contextNames[$name] = $varName;
                }
            } elseif ($part instanceof Snippet) {
                // Execute snippet code - assign result to $_left
                $hasSnippet = true;
                $this->generateSnippetCode($part, $context, $contextNames, '$_left');
            } else {
                // Regular clause
                $varName = '$_m' . $i;
                $this->generateClauseCode($part, $varName);
                $this->emitter->line("if ($varName === " . $this->signalMiss() . ") { \$this->offset = \$_offset; \$this->commitOffset = $savedCommit; break; }");
                // Add to context unless it's a predicate or %silent rule
                $isPredicate = $part->hasAndPredicate() || $part->hasNotPredicate();
                $isSilent = $this->isSilentReference($part);
                if (!$isPredicate && !$isSilent) {
                    $context[] = $varName;
                    if ($name = $part->getName()) {
                        $contextNames[$name] = $varName;
                    }
                } elseif ($name = $part->getName()) {
                    // Named elements are always captured
                    $contextNames[$name] = $varName;
                }
            }
        }

        // Success - update $_left (only if no snippet already set it)
        if (!$hasSnippet) {
            $this->emitter->line('$_left = [' . implode(', ', $context) . '];');
        }
        // Restore commitOffset on success - commits only matter if this alternative fails
        $this->emitter->line("\$this->commitOffset = $savedCommit;");
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
        } elseif ($clause instanceof Any) {
            $this->generateAnyCode($clause, $resultVar);
        } elseif ($clause instanceof EOF) {
            $this->generateEOFCode($clause, $resultVar);
        } elseif ($clause instanceof SOF) {
            $this->generateSOFCode($clause, $resultVar);
        } elseif ($clause instanceof Nothing) {
            $this->generateNothingCode($clause, $resultVar);
        } elseif ($clause instanceof Commit) {
            // Commit: set commit marker to prevent backtracking past this point
            $this->emitter->line("\$this->commitOffset = \$this->offset;");
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

    private function generateTextCode(Text $clause, string $resultVar): void
    {
        $text = (string) $clause;
        // Extract the actual text value from the grammar string representation
        $textValue = trim($text, '"\'');
        $length = strlen($textValue);
        $escaped = var_export($textValue, true);

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
        $this->emitter->indent()->line("$resultVar = " . $this->signalMiss() . ";");
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
        $this->emitter->indent()->line("$resultVar = " . $this->signalMiss() . ";");
        $this->emitter->closeBlock();
    }

    private function generateAnyCode(Any $clause, string $resultVar): void
    {
        $this->emitter->line('if ($this->offset < $this->length) {');
        $this->emitter->indent();
        $this->emitter->line("$resultVar = \\mb_substr(\\substr(\$this->source, \$this->offset, 4), 0, 1);");
        $this->emitter->line("\$this->offset += \\strlen($resultVar);");
        $this->emitter->dedent()->line('} else {');
        $this->emitter->indent()->line("$resultVar = " . $this->signalMiss() . ";");
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
        $this->emitter->line("$resultVar = (\$this->offset === 0) ? " . $this->signalHit() . " : " . $this->signalMiss() . ";");
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

            if ($minByteLen > 0) {
                $firstBytes = $targetClause->getFirstBytes();
                $checks = ["\$this->length - \$this->offset < $minByteLen"];
                if ($firstBytes !== null) {
                    $checks[] = $this->generateByteNotInCheck('$this->source[$this->offset]', $firstBytes);
                }
                $guard = implode(' || ', $checks);

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

    private function generateSequenceCode(Sequence $clause, string $resultVar, bool $isTopLevel = false): void
    {
        $seqId = ++$this->tempVarCounter;
        $tempOffset = '$_seqOffset' . $seqId;
        $savedCommit = '$_seqCommit' . $seqId;
        $this->emitter->line("$tempOffset = \$this->offset;");
        $this->emitter->line("$savedCommit = \$this->commitOffset;");
        $this->emitter->line("$resultVar = [];");

        $context = [];
        $contextNames = [];
        $hasSnippet = false;

        foreach ($clause as $i => $subClause) {
            if ($subClause instanceof Snippet) {
                $hasSnippet = true;
                $this->generateSnippetCode($subClause, $context, $contextNames, $resultVar);
                continue;
            }

            $itemVar = '$_seq' . $seqId . '_' . $i;
            $this->generateClauseCode($subClause, $itemVar);

            $this->emitter->line("if ($itemVar === " . $this->signalMiss() . ") {");
            $this->emitter->indent();
            // Only backtrack if we haven't committed IN THIS SEQUENCE past the sequence start
            // Use savedCommit to ignore commits from successful sub-parses
            $this->emitter->line("if (\$this->commitOffset === $savedCommit || $tempOffset >= \$this->commitOffset) {");
            $this->emitter->indent();
            $this->emitter->line("\$this->offset = $tempOffset;");
            $this->emitter->closeBlock();
            if ($isTopLevel) {
                $this->emitter->line("return " . $this->signalMiss() . ";");
            } else {
                $this->emitter->line("$resultVar = " . $this->signalMiss() . ";");
                $this->emitter->line("goto _seqEnd{$seqId};");
            }
            $this->emitter->closeBlock();

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
                $context[] = $itemVar;
            } else {
                // Check if this is a reference to a %silent rule
                $isSilent = $this->isSilentReference($subClause);
                if (!$isSilent) {
                    $this->emitter->line("{$resultVar}[] = $itemVar;");
                }
                // Only add to snippet context if clause produces a value
                // (not SOF/EOF/Nothing, and not %silent rules)
                if (!$this->alwaysReturnsHit($subClause) && !$isSilent) {
                    $context[] = $itemVar;
                }
            }
            $this->emitter->closeBlock();
        }

        // If result array is empty, return null or the sequence result
        if (!$hasSnippet) {
            $this->emitter->line("if ($resultVar === []) { $resultVar = null; }");
        }

        if (!$isTopLevel) {
            $this->emitter->line("_seqEnd{$seqId}:");
        }
    }

    private function generateOrderedChoiceCode(OrderedChoice $clause, string $resultVar): void
    {
        // Try switch-based dispatch if alternatives have disjoint first bytes
        if ($this->canUseSwitchDispatch($clause)) {
            $this->generateSwitchDispatchCode($clause, $resultVar);
            return;
        }

        // Fall back to sequential alternatives with commit checking
        $counter = ++$this->tempVarCounter;
        $tempOffset = '$_choiceOffset' . $counter;
        $savedCommit = '$_savedCommit' . $counter;

        $this->emitter->line("$savedCommit = \$this->commitOffset;");
        $this->emitter->line("$tempOffset = \$this->offset;");

        $numChoices = count(iterator_to_array($clause));
        $i = 0;
        foreach ($clause as $choice) {
            $choiceVar = '$_choice' . $counter . '_' . $i;
            $this->generateClauseCode($choice, $choiceVar);

            $this->emitter->line("if ($choiceVar !== " . $this->signalMiss() . ") {");
            $this->emitter->indent();
            $this->emitter->line("$resultVar = $choiceVar;");
            // Restore commitOffset on success - commits only matter if parsing fails after them
            $this->emitter->line("\$this->commitOffset = $savedCommit;");
            $this->emitter->line("goto _choiceEnd{$counter};");
            $this->emitter->closeBlock();

            // Check if commit happened - if so, don't try other alternatives
            $this->emitter->line("if (\$this->commitOffset !== $savedCommit) {");
            $this->emitter->indent();
            $this->emitter->line("$resultVar = " . $this->signalMiss() . ";");
            $this->emitter->line("goto _choiceEnd{$counter};");
            $this->emitter->closeBlock();

            // Restore offset for next alternative (if not last)
            if ($i < $numChoices - 1) {
                $this->emitter->line("\$this->offset = $tempOffset;");
            }
            $i++;
        }

        // All choices failed without commit
        $this->emitter->line("\$this->offset = $tempOffset;");
        $this->emitter->line("$resultVar = " . $this->signalMiss() . ";");
        $this->emitter->line("_choiceEnd{$counter}:");
    }

    private function canUseSwitchDispatch(OrderedChoice $clause): bool
    {
        $allBytes = '';
        foreach ($clause as $choice) {
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
        $this->emitter->line("$resultVar = " . $this->signalMiss() . ";");
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
        $this->emitter->line("$resultVar = " . $this->signalMiss() . ";");
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
        $inner = clone $clause;
        $inner->setModifier(Clause::NONE);

        $loopVar = (++$this->tempVarCounter);
        $itemVar = '$_rep' . $loopVar;

        $this->emitter->line("$resultVar = [];");
        $this->emitter->line('do {');
        $this->emitter->indent();

        $this->generateDirectClauseCode($inner, $itemVar);
        $this->emitter->line("if ($itemVar !== " . $this->signalMiss() . ") {");
        $this->emitter->indent()->line("{$resultVar}[] = $itemVar;");
        $this->emitter->closeBlock();

        $this->emitter->dedent()->line("} while ($itemVar !== " . $this->signalMiss() . ");");
        $this->emitter->line("if ($resultVar === []) { $resultVar = " . $this->signalMiss() . "; }");
    }

    private function generateZeroOrMoreCode(Clause $clause, string $resultVar): void
    {
        $inner = clone $clause;
        $inner->setModifier(Clause::NONE);

        $loopVar = (++$this->tempVarCounter);
        $itemVar = '$_rep' . $loopVar;

        $this->emitter->line("$resultVar = [];");
        $this->emitter->line('do {');
        $this->emitter->indent();

        $this->generateDirectClauseCode($inner, $itemVar);
        $this->emitter->line("if ($itemVar !== " . $this->signalMiss() . ") {");
        $this->emitter->indent()->line("{$resultVar}[] = $itemVar;");
        $this->emitter->closeBlock();

        $this->emitter->dedent()->line("} while ($itemVar !== " . $this->signalMiss() . ");");
    }

    private function generatePredicateCode(Clause $clause, string $resultVar): void
    {
        $tempOffset = '$_predOffset' . (++$this->tempVarCounter);
        $this->emitter->line("$tempOffset = \$this->offset;");

        // Create a copy without predicate flags for matching
        $inner = clone $clause;
        $inner->setModifier($clause->getModifier() & ~(Clause::AND | Clause::NOT));

        $innerVar = '$_pred' . $this->tempVarCounter;
        $this->generateClauseCode($inner, $innerVar);

        // Restore offset (predicates don't consume)
        $this->emitter->line("\$this->offset = $tempOffset;");

        if ($clause->hasNotPredicate()) {
            $this->emitter->line("$resultVar = ($innerVar === " . $this->signalMiss() . ") ? " . $this->signalHit() . " : " . $this->signalMiss() . ";");
        } else {
            $this->emitter->line("$resultVar = ($innerVar !== " . $this->signalMiss() . ") ? " . $this->signalHit() . " : " . $this->signalMiss() . ";");
        }
    }

    private function generateSnippetCode(Snippet $snippet, array $context, array $contextNames, string $resultVar = null): void
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
            $params = implode(', ', array_values($paramNames));
            $args = implode(', ', array_keys($paramNames));

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
        } else {
            // Just inline the code (trimming semicolons and adding proper ones)
            $code = rtrim(trim($code), ';');
            if ($code !== '') {
                $this->emitter->line($code . ';');
            }
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
}
