#!/usr/bin/env php
<?php
/**
 * Generate php.cpeg from php.y
 */

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/yacc-to-cpeg-transformer.php';

use Charm\Parsing\GrammarParser;
use Charm\Parsing\CPEGParser;

// Parse yacc
$gp = new GrammarParser();
$grammar = $gp->parseFile(__DIR__ . '/../../yacc.cpeg', 'yacc');
$parser = new CPEGParser($grammar);

$content = file_get_contents(__DIR__ . '/../php.y');
$ast = $parser->parse($content, 'yacc');

// Transform Yacc AST for PEG compatibility (reorder prefix alternatives)
$ast = transformYaccAst($ast);

/**
 * Get the priority of an alternative for PEG ordering.
 * Lower priority = comes first. Higher = comes last.
 *
 * Priority:
 * 0: starts with literal (", T_)
 * 1: starts with non-recursive non-terminal
 * 2: starts with potentially recursive non-terminal (Variable/Dereferenceable chain)
 */
function getAlternativePriority(array $symbols): int {
    if (empty($symbols)) return 1;
    $first = $symbols[0];

    // %prec directive - check next symbol
    if (is_array($first)) {
        $first = $symbols[1] ?? null;
        if ($first === null) return 1;
    }

    // Single char literal - highest priority
    if (strlen($first) === 1) return 0;

    // T_ token constant - highest priority
    if (str_starts_with($first, 'T_')) return 0;

    // Priority 1: Non-terminals that can match directly without deep recursion
    $directMatchNonterminals = [
        'function_call', 'simple_variable', 'name', 'class_name',
        'callable_expr', 'static_member',
        'class_constant', 'new_dereferenceable', 'dereferenceable_scalar',
        'constant'  // Constant can match identifiers without recursion
    ];
    foreach ($directMatchNonterminals as $nt) {
        if ($first === $nt) {
            return 1;
        }
    }

    // Priority 2: Non-terminals involved in the indirect recursion cycle
    // These should be tried after direct-match alternatives
    $recursiveNonterminals = [
        'variable', 'callable_variable',
        'array_object_dereferenceable', 'fully_dereferenceable',
        'new_variable'
    ];
    foreach ($recursiveNonterminals as $nt) {
        if ($first === $nt) {
            return 2;
        }
    }

    // Priority 3: Scalar - lowest priority (tried last after Variable)
    if ($first === 'scalar') {
        return 3;
    }

    return 1;
}

/**
 * Reorder alternatives for PEG: literal-starting first, recursive non-terminals last.
 * Uses stable sort to preserve original YACC order within each priority group.
 * The yacc-to-cpeg-transformer handles prefix relationships separately.
 */
function reorderForPeg(array $alternatives): array {
    // Add original index for stable sorting
    foreach ($alternatives as $i => &$alt) {
        $alt['_origIndex'] = $i;
    }
    unset($alt);

    usort($alternatives, function($a, $b) {
        $priorityA = getAlternativePriority($a[1] ?? []);
        $priorityB = getAlternativePriority($b[1] ?? []);
        if ($priorityA !== $priorityB) {
            return $priorityA <=> $priorityB;
        }
        // Preserve original order within same priority (stable sort)
        return $a['_origIndex'] <=> $b['_origIndex'];
    });

    // Remove temporary index
    foreach ($alternatives as &$alt) {
        unset($alt['_origIndex']);
    }

    return $alternatives;
}

function snakeToPascal(string $name): string {
    return str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
}

/**
 * Transform AST constructor syntax: ClassName[$args] -> new ClassName($args)
 * Handles nested brackets properly.
 */
function transformAstConstructors(string $code): string {
    $result = '';
    $i = 0;
    $len = strlen($code);

    while ($i < $len) {
        $ch = $code[$i];

        // Check if this could be start of a class name (uppercase or backslash for namespaced)
        if (ctype_upper($ch)) {
            // Extract the full class name
            $classStart = $i;
            while ($i < $len && (ctype_alnum($code[$i]) || $code[$i] === '_' || $code[$i] === '\\')) {
                $i++;
            }
            $className = substr($code, $classStart, $i - $classStart);

            // Skip whitespace
            while ($i < $len && ($code[$i] === ' ' || $code[$i] === "\t")) {
                $i++;
            }

            // Check if followed by [ (but not if preceded by ::)
            $precededByDoubleColon = strlen($result) >= 2 && substr($result, -2) === '::';
            if (!$precededByDoubleColon && $i < $len && $code[$i] === '[') {
                // Find matching ]
                $bracketStart = $i;
                $depth = 1;
                $j = $bracketStart + 1;
                while ($j < $len && $depth > 0) {
                    if ($code[$j] === '[') $depth++;
                    elseif ($code[$j] === ']') $depth--;
                    $j++;
                }

                if ($depth === 0) {
                    // Found matching bracket - transform
                    $args = substr($code, $bracketStart + 1, $j - $bracketStart - 2);
                    // Recursively transform nested constructors
                    $args = transformAstConstructors($args);
                    $result .= 'new ' . $className . '(' . $args . ')';
                    $i = $j;
                    continue;
                }
            }

            // Not a constructor - output the class name as-is
            $result .= $className;
            continue;
        }

        // Not a class name start - copy character
        $result .= $ch;
        $i++;
    }

    return $result;
}

/**
 * Handle $this->method() calls in action code.
 * - Supported methods (attributes, init, push, etc.): keep as-is
 * - Unsupported statement-level calls (ending with ;): remove entirely
 * - Unsupported expression-level calls: replace with first argument
 */
function translateThisMethodCalls(string $code): string {
    // Methods we support in our template - keep these as-is
    $supportedMethods = [
        'attributes', 'init', 'push', 'pushNormalizing', 'toBlock', 'parseVar', 'makeNop', 'getAttributes',
        'parseLNumber', 'parseNumString', 'parseDocString', 'parseEncapsed'
    ];
    $result = '';
    $i = 0;
    $len = strlen($code);

    while ($i < $len) {
        // Look for $this->
        if (substr($code, $i, 7) === '$this->') {
            // Find the method name
            $methodStart = $i + 7;
            $j = $methodStart;
            while ($j < $len && (ctype_alnum($code[$j]) || $code[$j] === '_')) {
                $j++;
            }
            $methodName = substr($code, $methodStart, $j - $methodStart);

            // Check if this is a supported method - if so, keep the entire call
            if (in_array($methodName, $supportedMethods)) {
                // Copy $this->methodName and continue
                $result .= substr($code, $i, $j - $i);
                $i = $j;
                continue;
            }

            // Check if followed by (
            if ($j < $len && $code[$j] === '(') {
                // Find the first argument (handle nested parens)
                $argStart = $j + 1;
                $depth = 1;
                $k = $argStart;
                $firstArgEnd = null;
                while ($k < $len && $depth > 0) {
                    if ($code[$k] === '(') $depth++;
                    elseif ($code[$k] === ')') {
                        $depth--;
                        if ($depth === 0) break;
                    }
                    elseif ($code[$k] === ',' && $depth === 1 && $firstArgEnd === null) {
                        $firstArgEnd = $k;
                    }
                    $k++;
                }

                // k now points to the closing paren
                $callEnd = $k + 1;

                // Check if this is a standalone statement (not part of return/assignment)
                // Look back for 'return' or '=' before $this->
                $beforeThis = trim(substr($result, -10)); // check last 10 chars
                $isPartOfReturn = preg_match('/return\s*$/', $beforeThis);
                $isPartOfAssignment = preg_match('/=\s*$/', $beforeThis);

                // Check if followed by ; or end
                $afterCall = $callEnd;
                while ($afterCall < $len && ($code[$afterCall] === ' ' || $code[$afterCall] === "\n" || $code[$afterCall] === "\t")) {
                    $afterCall++;
                }
                $isAtEnd = $afterCall >= $len || $code[$afterCall] === ';';

                // Only treat as statement if it's standalone (not part of return/assignment)
                $isStandaloneStatement = $isAtEnd && !$isPartOfReturn && !$isPartOfAssignment;

                if ($isStandaloneStatement) {
                    // Skip the entire statement including ; and whitespace
                    while ($afterCall < $len && ($code[$afterCall] === ';' || $code[$afterCall] === ' ' || $code[$afterCall] === "\n" || $code[$afterCall] === "\t")) {
                        $afterCall++;
                    }
                    $i = $afterCall;
                } else {
                    // Replace with first argument
                    if ($firstArgEnd === null) {
                        $firstArgEnd = $k; // No comma, entire content is first arg
                    }
                    $firstArg = trim(substr($code, $argStart, $firstArgEnd - $argStart));
                    $result .= $firstArg;
                    $i = $callEnd;
                }
                continue;
            }
        }

        $result .= $code[$i];
        $i++;
    }

    return $result;
}

function translatePattern(array $symbols): string {
    $result = [];
    foreach ($symbols as $symbol) {
        if (is_array($symbol) && $symbol[0] === '%prec') {
            $token = $symbol[1];
            $result[] = strlen($token) === 1 ? '%prec="' . $token . '"' : '%prec=' . $token;
        } elseif (is_string($symbol)) {
            if (preg_match('/^T_[A-Z_0-9]+$/', $symbol) || preg_match('/^[A-Z_][A-Za-z0-9_]*$/', $symbol)) {
                $result[] = $symbol;
            } elseif (preg_match('/^[a-z_][a-z0-9_]*$/', $symbol)) {
                $result[] = snakeToPascal($symbol);
            } elseif (strlen($symbol) === 1) {
                $result[] = '"' . addslashes($symbol) . '"';
            } else {
                $result[] = $symbol;
            }
        }
    }
    return implode(' ', $result);
}

function translateAction(?string $action): ?string {
    if ($action === null || trim($action) === '') return null;
    $action = trim($action);

    // Skip trivial $$ = $1
    if (preg_match('/^\s*\$\$\s*=\s*\$1\s*;?\s*$/', $action)) return null;

    // Translate $N to $_N-1 (do this first to avoid conflicts)
    $action = preg_replace_callback('/\$(\d+)/', fn($m) => '$_' . ($m[1] - 1), $action);

    // For multi-line/multi-statement/control-flow actions, simplify to just the first $$ = statement
    $isComplex = strpos($action, "\n") !== false
        || preg_match('/;\s*[a-zA-Z${}]/', $action)  // multiple statements
        || preg_match('/\b(if|for|foreach|while|switch)\s*\(/', $action);  // control flow
    if ($isComplex) {
        // Extract just the first $$ = ... ; statement
        if (preg_match('/\$\$\s*=\s*([^;]+);/', $action, $m)) {
            $action = '$$ = ' . $m[1] . ';';
        } else {
            // No $$ = found, skip this action
            return null;
        }
    }

    // Translate $$ = at start to return
    $action = preg_replace('/^\s*\$\$\s*=\s*/', 'return ', $action);

    // Translate nikic/php-parser AST constructor syntax: Class\Name[$args] -> new ClassName($args)
    $action = transformAstConstructors($action);

    // Translate stackAttributes(#N) and #N to $this->attributes()
    $action = preg_replace('/stackAttributes\(#\d+\)/', '$this->attributes()', $action);
    $action = preg_replace('/#(\d+)/', '$this->attributes()', $action);

    // Replace $this->phpVersion->method() calls with sensible defaults
    // allowsInvalidOctals() - false (strict mode)
    // supportsUnicodeEscapes() - true (PHP 7+)
    $action = preg_replace('/\$this->phpVersion->allowsInvalidOctals\s*\(\)/', 'false', $action);
    $action = preg_replace('/\$this->phpVersion->supportsUnicodeEscapes\s*\(\)/', 'true', $action);

    // Handle makeNop($$) - this is a special macro that assigns to $$
    // makeNop($$) -> return $this->makeNop()
    $action = preg_replace('/makeNop\s*\(\s*\$\$\s*\)\s*;?/', 'return $this->makeNop();', $action);

    // Translate standalone helper functions to method calls that return values
    // init(...) -> return $this->init(...)
    // push(...) -> return $this->push(...)
    // These macros in YACC expand to $$ = ... so they need returns
    $action = preg_replace('/^\s*(init|push|pushNormalizing)\s*\(/', 'return $this->$1(', $action);
    // Also handle mid-expression uses (not at start) - these also need returns when standalone
    // Use negative lookbehind to avoid double-prefixing already-prefixed calls
    $action = preg_replace('/(?<!\$this->)\b(toBlock|parseVar|parseLNumber|parseNumString|parseDocString|parseEncapsed)\s*\(/', '$this->$1(', $action);

    // Translate bare attributes() calls to $this->attributes()
    // Use negative lookbehind to avoid matching already-prefixed $this->attributes()
    $action = preg_replace('/(?<!\$this->)\battributes\s*\(\)/', '$this->attributes()', $action);

    // Handle $this-> method calls - keep supported ones, extract first arg from others
    $action = translateThisMethodCalls($action);

    $action = trim($action);
    if ($action === '' || $action === ';') return null;

    // If action is just a $this->method() call without return, add return
    if (preg_match('/^\$this->(init|push|pushNormalizing|toBlock|makeNop)\s*\([^)]*\)\s*;?\s*$/', $action)) {
        $action = 'return ' . rtrim($action, '; ') . ';';
    }

    $isMultiline = strpos($action, "\n") !== false;
    if ($isMultiline) {
        $lines = array_map('trim', explode("\n", $action));
        return "<?\n        " . implode("\n        ", $lines) . "\n    ?>";
    }
    return '<?  ' . rtrim(trim($action), ';') . ';  ?>';
}

// Output header
echo "# PHP Grammar (Generated from php.y)\n";
echo "# Auto-generated - do not edit\n\n";
echo "@lexer Charm\\Parsing\\Lexers\\PhpLexer\n\n";

// Output @prec table
echo "@prec {\n";
foreach ($ast['declarations'] as $decl) {
    if ($decl === null) continue;
    if ($decl[0] === 'prec') {
        $tokens = implode(' ', array_map(fn($t) => strlen($t) === 1 ? '"' . $t . '"' : $t, $decl[2]));
        echo "    {$decl[1]} $tokens\n";
    } elseif ($decl[0] === 'if' && $decl[1] === 'PHP8') {
        foreach ($decl[2] as $inner) {
            if ($inner !== null && $inner[0] === 'prec') {
                $tokens = implode(' ', array_map(fn($t) => strlen($t) === 1 ? '"' . $t . '"' : $t, $inner[2]));
                echo "    {$inner[1]} $tokens\n";
            }
        }
    }
}
echo "}\n\n";

// Output rules - the first rule (start) will be the goal rule
$errorRuleEmitted = false;
foreach ($ast['rules'] as $ruleIndex => $rule) {
    // Add Error rule after the first rule
    if ($ruleIndex === 1 && !$errorRuleEmitted) {
        echo "# Dummy error rule - error recovery not supported in PEG\n";
        echo "Error\n";
        echo "    = \"__ERROR_RECOVERY_NOT_SUPPORTED__\"\n\n";
        $errorRuleEmitted = true;
    }
    $name = $rule[1];
    $cpegName = snakeToPascal($name);

    echo "# $name\n";
    echo "$cpegName\n";

    // Flatten alternatives (expand conditional blocks)
    $flatAlts = [];
    foreach ($rule[2] as $alt) {
        if (is_array($alt) && isset($alt[0]) && $alt[0] === 'if') {
            if ($alt[1] === 'PHP8') {
                foreach ($alt[2] as $condAlt) {
                    $flatAlts[] = $condAlt;
                }
            }
        } else {
            $symbols = $alt[1] ?? [];
            // Skip error-only alternatives (YACC error recovery)
            if (count($symbols) === 1 && $symbols[0] === 'error') {
                continue;
            }
            $flatAlts[] = $alt;
        }
    }

    // Reorder for PEG: literal-starting alternatives first
    $flatAlts = reorderForPeg($flatAlts);

    $altIndex = 0;
    foreach ($flatAlts as $alt) {
        $symbols = $alt[1] ?? [];
        $prefix = $altIndex === 0 ? '    = ' : '    | ';
        $isEmpty = empty($symbols);
        $pattern = $isEmpty ? '""' : translatePattern($symbols);
        $action = translateAction($alt[2] ?? null);
        echo $action ? "$prefix$pattern  $action\n" : "$prefix$pattern\n";
        $altIndex++;
    }

    // If all alternatives were skipped, add a failing rule
    if ($altIndex === 0) {
        echo "    = \"__NO_VALID_ALTERNATIVES__\"\n";
    }
    echo "\n";
}
