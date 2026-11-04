#!/usr/bin/env php
<?php
/**
 * Transform YACC AST to CPEG format.
 *
 * Takes the AST from yacc.cpeg and produces CPEG grammar output.
 *
 * Usage: php tools/yacc-ast-to-cpeg.php [path/to/grammar.y] [rule_name...]
 *
 * If no rule names specified, outputs the @prec table and lists rules.
 * Specify rule names to translate those rules to CPEG.
 */

require __DIR__ . '/../../../vendor/autoload.php';

use Charm\Parsing\GrammarParser;
use Charm\Parsing\CPEGParser;

$file = $argv[1] ?? __DIR__ . '/../php.y';

if (!file_exists($file)) {
    fwrite(STDERR, "Error: File not found: $file\n");
    exit(1);
}

$requestedRules = array_slice($argv, 2);

// Parse the YACC file using yacc.cpeg
$gp = new GrammarParser();
$grammar = $gp->parseFile(__DIR__ . '/../../yacc.cpeg', 'yacc');
$parser = new CPEGParser($grammar);

$content = file_get_contents($file);
$ast = $parser->parse($content, 'yacc');

if ($ast === null) {
    fwrite(STDERR, "Error: Failed to parse YACC file\n");
    exit(1);
}

// Build rule index for quick lookup
$ruleIndex = [];
foreach ($ast['rules'] as $rule) {
    $ruleIndex[$rule[1]] = $rule;
}

// Extract precedence table
$precTable = extractPrecedenceTable($ast['declarations']);

// If no rules requested, show summary
if (empty($requestedRules)) {
    echo "# Parsed: $file\n";
    echo "# Declarations: " . count($ast['declarations']) . "\n";
    echo "# Rules: " . count($ast['rules']) . "\n\n";

    // Output @prec table
    if (!empty($precTable)) {
        echo "@prec {\n";
        foreach ($precTable as $entry) {
            $tokens = implode(' ', array_map(function($t) {
                return strlen($t) === 1 ? '"' . $t . '"' : $t;
            }, $entry['tokens']));
            echo "    {$entry['assoc']} $tokens\n";
        }
        echo "}\n\n";
    }

    // List rules
    echo "# Available rules:\n";
    $i = 0;
    foreach (array_keys($ruleIndex) as $name) {
        printf("# %-35s", $name);
        if (++$i % 3 === 0) echo "\n";
    }
    if ($i % 3 !== 0) echo "\n";

    echo "\n# Usage: php tools/yacc-ast-to-cpeg.php $file <rule_name> [rule_name...]\n";
    exit(0);
}

// Translate requested rules
foreach ($requestedRules as $ruleName) {
    if (!isset($ruleIndex[$ruleName])) {
        fwrite(STDERR, "Warning: Rule '$ruleName' not found\n");
        continue;
    }

    echo translateRule($ruleIndex[$ruleName], $precTable);
    echo "\n";
}

/**
 * Extract precedence table from declarations.
 * Returns array of ['assoc' => string, 'tokens' => array, 'level' => int]
 */
function extractPrecedenceTable(array $declarations): array {
    $result = [];
    $level = 0;

    foreach ($declarations as $decl) {
        if ($decl === null) continue;

        if ($decl[0] === 'prec') {
            $result[] = [
                'assoc' => $decl[1],
                'tokens' => $decl[2],
                'level' => $level++,
            ];
        } elseif ($decl[0] === 'if') {
            // Handle conditional precedence (use PHP8 by default)
            if ($decl[1] === 'PHP8') {
                foreach ($decl[2] as $innerDecl) {
                    if ($innerDecl !== null && $innerDecl[0] === 'prec') {
                        $result[] = [
                            'assoc' => $innerDecl[1],
                            'tokens' => $innerDecl[2],
                            'level' => $level++,
                        ];
                    }
                }
            }
        }
    }

    return $result;
}

/**
 * Translate a YACC rule to CPEG format.
 */
function translateRule(array $rule, array $precTable): string {
    $name = $rule[1];
    $alts = $rule[2];

    $cpegName = snakeToPascal($name);
    $output = "# Translated from: $name\n";
    $output .= "$cpegName\n";

    $altIndex = 0;
    foreach ($alts as $alt) {
        if (is_array($alt) && isset($alt[0]) && $alt[0] === 'if') {
            // Conditional alternatives - include PHP8 by default
            if ($alt[1] === 'PHP8') {
                foreach ($alt[2] as $condAlt) {
                    $prefix = $altIndex === 0 ? '    = ' : '    | ';
                    $output .= translateAlternative($condAlt, $prefix);
                    $altIndex++;
                }
            } else {
                // Skip PHP7-only alternatives, add comment
                $output .= "    # [PHP7 only - " . count($alt[2]) . " alternatives skipped]\n";
            }
        } else {
            $prefix = $altIndex === 0 ? '    = ' : '    | ';
            $output .= translateAlternative($alt, $prefix);
            $altIndex++;
        }
    }

    return $output;
}

/**
 * Translate a single alternative.
 */
function translateAlternative(array $alt, string $prefix): string {
    $symbols = $alt[1];
    $action = $alt[2];

    // Check for empty alternative
    $isEmpty = empty($symbols) ||
               (count($symbols) === 1 && is_string($symbols[0]) &&
                preg_match('/^\/\*\s*empty\s*\*\/$/i', trim($symbols[0])));

    if ($isEmpty) {
        $pattern = '/* empty */';
    } else {
        $pattern = translatePattern($symbols);
    }

    $cpegAction = translateAction($action);

    if ($cpegAction) {
        return "$prefix$pattern  $cpegAction\n";
    }
    return "$prefix$pattern\n";
}

/**
 * Translate YACC symbols to CPEG pattern.
 */
function translatePattern(array $symbols): string {
    $result = [];

    foreach ($symbols as $symbol) {
        if (is_array($symbol) && $symbol[0] === '%prec') {
            // %prec tag
            $token = $symbol[1];
            if (strlen($token) === 1) {
                $result[] = '%prec="' . $token . '"';
            } else {
                $result[] = '%prec=' . $token;
            }
        } elseif (is_string($symbol)) {
            // Check if it's a token (uppercase) or rule reference (lowercase)
            if (preg_match('/^T_[A-Z_0-9]+$/', $symbol)) {
                // Token constant
                $result[] = $symbol;
            } elseif (preg_match('/^[A-Z_][A-Za-z0-9_]*$/', $symbol)) {
                // Other uppercase identifier (token)
                $result[] = $symbol;
            } elseif (preg_match('/^[a-z_][a-z0-9_]*$/', $symbol)) {
                // Rule reference
                $result[] = snakeToPascal($symbol);
            } elseif (strlen($symbol) === 1) {
                // Single character literal
                $result[] = '"' . addslashes($symbol) . '"';
            } else {
                // Keep as-is (might be multi-char literal or comment)
                $result[] = $symbol;
            }
        }
    }

    return implode(' ', $result);
}

/**
 * Translate YACC action to CPEG snippet.
 */
function translateAction(?string $action): ?string {
    if ($action === null || trim($action) === '') {
        return null;
    }

    $action = trim($action);

    // Skip trivial actions
    if (preg_match('/^\s*\$\$\s*=\s*\$1\s*;?\s*$/', $action)) {
        return null; // Default behavior in PEG
    }

    // Translate $$ = to return
    $action = preg_replace('/\$\$\s*=\s*/', 'return ', $action);

    // Translate $N to $_N-1
    $action = preg_replace_callback('/\$(\d+)/', function($m) {
        return '$_' . ($m[1] - 1);
    }, $action);

    // Translate #N (position attributes) to special syntax
    $action = preg_replace('/#(\d+)/', 'attributes(#$1)', $action);

    // Check if multi-line
    $isMultiline = strpos($action, "\n") !== false;

    if ($isMultiline) {
        $lines = explode("\n", $action);
        $formatted = implode("\n        ", array_map('trim', $lines));
        return "<?\n        $formatted\n    ?>";
    }

    // Single line - keep compact
    $action = rtrim(trim($action), ';');
    return "<?  $action;  ?>";
}

/**
 * Convert snake_case to PascalCase.
 */
function snakeToPascal(string $name): string {
    return str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
}
