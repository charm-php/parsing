#!/usr/bin/env php
<?php
/**
 * Extract precedence declarations from a YACC/Bison grammar file (like php.y)
 * and output them in CPEG @prec format.
 *
 * Usage: php tools/yacc-extract-precedence.php [path/to/grammar.y]
 *
 * Default: php.y
 */

$file = $argv[1] ?? __DIR__ . '/../php.y';

if (!file_exists($file)) {
    fwrite(STDERR, "Error: File not found: $file\n");
    exit(1);
}

$content = file_get_contents($file);

// Extract precedence lines: %left, %right, %nonassoc
// Format: %assoc TOKEN TOKEN 'char' ...
preg_match_all('/^%(left|right|nonassoc)\s+(.+)$/m', $content, $matches, PREG_SET_ORDER);

if (empty($matches)) {
    fwrite(STDERR, "No precedence declarations found in $file\n");
    exit(1);
}

echo "# Precedence table extracted from: $file\n";
echo "# Generated: " . date('Y-m-d H:i:s') . "\n";
echo "#\n";
echo "# In YACC, later declarations have higher precedence.\n";
echo "# This format matches that convention.\n";
echo "\n";
echo "@prec {\n";

foreach ($matches as $match) {
    $assoc = $match[1];
    $tokensStr = trim($match[2]);

    // Map YACC associativity to our names
    $assocMap = [
        'left' => 'left',
        'right' => 'right',
        'nonassoc' => 'none',
    ];
    $ourAssoc = $assocMap[$assoc];

    // Parse tokens - can be T_FOO or 'x' or "x"
    $tokens = [];

    // Match token names (T_FOO) and quoted chars ('x' or "x")
    preg_match_all('/T_[A-Z_0-9]+|\'([^\']+)\'|"([^"]+)"/', $tokensStr, $tokenMatches);

    foreach ($tokenMatches[0] as $i => $token) {
        if (str_starts_with($token, 'T_')) {
            $tokens[] = $token;
        } elseif ($token[0] === "'" || $token[0] === '"') {
            // Convert to our string format
            $char = $tokenMatches[1][$i] ?: $tokenMatches[2][$i];
            $tokens[] = '"' . addslashes($char) . '"';
        }
    }

    if (!empty($tokens)) {
        echo "    $ourAssoc " . implode(' ', $tokens) . "\n";
    }
}

echo "}\n";
