#!/usr/bin/env php
<?php
/**
 * Translate YACC/Bison grammar rules to CPEG format.
 *
 * Usage: php tools/yacc-to-cpeg.php [path/to/grammar.y] [rule_name...]
 *
 * If no rule names specified, lists all available rules.
 * Specify one or more rule names to translate those rules.
 *
 * Examples:
 *   php tools/yacc-to-cpeg.php php.y              # List rules
 *   php tools/yacc-to-cpeg.php php.y expr         # Translate expr rule
 *   php tools/yacc-to-cpeg.php php.y expr primary # Translate multiple
 */

$file = $argv[1] ?? __DIR__ . '/../php.y';

if (!file_exists($file)) {
    fwrite(STDERR, "Error: File not found: $file\n");
    exit(1);
}

$requestedRules = array_slice($argv, 2);
$content = file_get_contents($file);

// Remove #if/#endif blocks (keep PHP8 by default)
$content = preg_replace('/#if PHP7.*?#endif/s', '', $content);
$content = preg_replace('/#if PHP8\s*\n/', '', $content);
$content = preg_replace('/#endif\s*\n/', '', $content);

// Extract rules section (after %%)
if (preg_match('/%%\s*\n(.+?)(?:%%|$)/s', $content, $m)) {
    $rulesSection = $m[1];
} else {
    fwrite(STDERR, "Error: Could not find rules section (between %% markers)\n");
    exit(1);
}

// Parse rules - handle multi-line actions with nested braces
$rules = [];
$currentRule = null;
$currentAlts = [];
$inAction = false;
$actionDepth = 0;
$actionBuffer = '';
$patternBuffer = '';

foreach (explode("\n", $rulesSection) as $line) {
    $rawLine = $line;
    $line = rtrim($line);

    // Skip pure comment lines
    if (preg_match('/^\s*\/\*.*\*\/\s*$/', $line)) {
        continue;
    }

    // If we're in a multi-line action, collect it
    if ($inAction) {
        for ($i = 0; $i < strlen($rawLine); $i++) {
            $ch = $rawLine[$i];
            if ($ch === '{') {
                $actionDepth++;
                $actionBuffer .= $ch;
            } elseif ($ch === '}') {
                $actionDepth--;
                if ($actionDepth === 0) {
                    // End of action
                    $inAction = false;
                    $currentAlts[] = ['pattern' => trim($patternBuffer), 'action' => trim($actionBuffer)];
                    $patternBuffer = '';
                    $actionBuffer = '';
                    break;
                }
                $actionBuffer .= $ch;
            } else {
                $actionBuffer .= $ch;
            }
        }
        if ($inAction) {
            $actionBuffer .= "\n";
        }
        continue;
    }

    // Skip empty lines
    if (trim($line) === '') {
        continue;
    }

    // Rule definition: "rule_name:"
    if (preg_match('/^([a-z_]+):/', $line, $m)) {
        // Save previous rule
        if ($currentRule !== null) {
            $rules[$currentRule] = $currentAlts;
        }
        $currentRule = $m[1];
        $currentAlts = [];
        $line = substr($line, strlen($m[0]));
    }

    // Rule terminator
    if (trim($line) === ';') {
        if ($currentRule !== null) {
            $rules[$currentRule] = $currentAlts;
            $currentRule = null;
            $currentAlts = [];
        }
        continue;
    }

    // Alternative (starts with | or continues rule)
    $line = preg_replace('/^\s*\|?\s*/', '', $line);
    if (trim($line) === '') continue;

    // Check if line contains an action (brace not inside quotes)
    $bracePos = findActionBrace($line);
    if ($bracePos !== false) {
        $linePattern = substr($line, 0, $bracePos);
        // If there's pattern text on this line, use it; otherwise use buffered pattern
        if (trim($linePattern) !== '') {
            $patternBuffer = trim($linePattern);
        }
        $rest = substr($line, $bracePos + 1);

        // Count braces in rest of line
        $actionDepth = 1;
        $actionBuffer = '';
        for ($i = 0; $i < strlen($rest); $i++) {
            $ch = $rest[$i];
            if ($ch === '{') {
                $actionDepth++;
                $actionBuffer .= $ch;
            } elseif ($ch === '}') {
                $actionDepth--;
                if ($actionDepth === 0) {
                    // Complete action on same line
                    $currentAlts[] = ['pattern' => trim($patternBuffer), 'action' => trim($actionBuffer)];
                    $patternBuffer = '';
                    $actionBuffer = '';
                    break;
                }
                $actionBuffer .= $ch;
            } else {
                $actionBuffer .= $ch;
            }
        }
        if ($actionDepth > 0) {
            $inAction = true;
            $actionBuffer .= "\n";
        }
    } else {
        // No action on this line - check if it might be a pattern-only line
        // that has action on next line. We'll store it and look for action.
        $patternBuffer = trim($line);
    }
}

// Handle trailing pattern with no action
if ($patternBuffer !== '' && !$inAction) {
    $currentAlts[] = ['pattern' => $patternBuffer, 'action' => null];
}

// Save last rule
if ($currentRule !== null) {
    $rules[$currentRule] = $currentAlts;
}

// If no rules requested, list them
if (empty($requestedRules)) {
    echo "Available rules in $file:\n\n";
    $i = 0;
    foreach (array_keys($rules) as $name) {
        printf("  %-35s", $name);
        if (++$i % 3 === 0) echo "\n";
    }
    if ($i % 3 !== 0) echo "\n";
    echo "\nTotal: " . count($rules) . " rules\n";
    echo "\nUsage: php tools/yacc-to-cpeg.php $file <rule_name> [rule_name...]\n";
    exit(0);
}

// Convert requested rules to CPEG
foreach ($requestedRules as $ruleName) {
    if (!isset($rules[$ruleName])) {
        fwrite(STDERR, "Warning: Rule '$ruleName' not found\n");
        continue;
    }

    $cpegName = snakeToPascal($ruleName);
    echo "# Translated from: $ruleName\n";
    echo "$cpegName\n";

    $alts = $rules[$ruleName];
    foreach ($alts as $i => $alt) {
        $prefix = $i === 0 ? '    = ' : '    | ';
        $pattern = translatePattern($alt['pattern']);
        $action = translateAction($alt['action']);

        if ($action) {
            echo "$prefix$pattern  $action\n";
        } else {
            echo "$prefix$pattern\n";
        }
    }
    echo "\n";
}

/**
 * Find the position of an action-starting brace, skipping quoted braces
 */
function findActionBrace(string $line): int|false {
    $inSingleQuote = false;
    $inDoubleQuote = false;

    for ($i = 0; $i < strlen($line); $i++) {
        $ch = $line[$i];

        // Handle escape sequences
        if ($ch === '\\' && $i + 1 < strlen($line)) {
            $i++; // Skip next char
            continue;
        }

        if ($ch === "'" && !$inDoubleQuote) {
            $inSingleQuote = !$inSingleQuote;
        } elseif ($ch === '"' && !$inSingleQuote) {
            $inDoubleQuote = !$inDoubleQuote;
        } elseif ($ch === '{' && !$inSingleQuote && !$inDoubleQuote) {
            return $i;
        }
    }

    return false;
}

/**
 * Convert snake_case to PascalCase
 */
function snakeToPascal(string $name): string {
    return str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
}

/**
 * Translate YACC pattern to CPEG
 */
function translatePattern(string $pattern): string {
    // Handle /* empty */
    if (preg_match('/\/\*\s*empty\s*\*\//', $pattern)) {
        return '/* empty */';
    }

    $tokens = preg_split('/\s+/', trim($pattern));
    $result = [];

    foreach ($tokens as $token) {
        if ($token === '') continue;

        // Quoted char: 'x' -> "x"
        if (preg_match("/^'(.)'$/", $token, $m)) {
            $result[] = '"' . $m[1] . '"';
        }
        // Quoted string: '...' -> "..."
        elseif (preg_match("/^'(.+)'$/", $token, $m)) {
            $result[] = '"' . $m[1] . '"';
        }
        // Token constant: T_FOO stays as T_FOO
        elseif (preg_match('/^T_[A-Z_0-9]+$/', $token)) {
            $result[] = $token;
        }
        // Rule reference: snake_case -> PascalCase
        elseif (preg_match('/^[a-z_]+$/', $token)) {
            $result[] = snakeToPascal($token);
        }
        // Other (keep as-is)
        else {
            $result[] = $token;
        }
    }

    return implode(' ', $result);
}

/**
 * Translate YACC action to CPEG snippet
 */
function translateAction(?string $action): ?string {
    if ($action === null) return null;

    // Check if multi-line or complex
    $isMultiline = strpos($action, "\n") !== false;

    // Translate $$ = to return
    $action = preg_replace('/\$\$\s*=\s*/', 'return ', $action);

    // Translate $N to $_N-1
    $action = preg_replace_callback('/\$(\d+)/', function($m) {
        return '$_' . ($m[1] - 1);
    }, $action);

    // Translate #N (line/position refs) to special syntax
    $action = preg_replace('/#(\d+)/', 'attributes(#$1)', $action);

    if ($isMultiline) {
        // Multi-line action - format nicely
        $lines = explode("\n", trim($action));
        $formatted = implode("\n        ", $lines);
        return "<?\n        $formatted\n    ?>";
    }

    // Single line - keep compact
    $action = rtrim(trim($action), ';');
    return "<?  $action;  ?>";
}
