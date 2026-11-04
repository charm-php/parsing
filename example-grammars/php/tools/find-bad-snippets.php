#!/usr/bin/env php
<?php
/**
 * Find snippets in php.cpeg that have syntax errors
 */

require __DIR__ . '/../../../vendor/autoload.php';

$content = file_get_contents(__DIR__ . '/../php.cpeg');

// Find all snippets
preg_match_all('/<\?([^?]+)\?>/', $content, $matches, PREG_OFFSET_CAPTURE);

echo "Found " . count($matches[0]) . " snippets\n\n";

$errors = 0;
foreach ($matches[1] as $i => $match) {
    $snippetCode = trim($match[0]);

    try {
        $snippet = new Charm\Parsing\Clause\Snippet($snippetCode, 0, null, '');
    } catch (\Throwable $e) {
        $lineNum = substr_count(substr($content, 0, $match[1]), "\n") + 1;
        echo "Error at line $lineNum:\n";
        echo "  Snippet: $snippetCode\n";
        echo "  Error: " . $e->getMessage() . "\n\n";
        $errors++;

        if ($errors >= 20) {
            echo "... and more errors\n";
            break;
        }
    }
}

echo "\nTotal errors: $errors\n";
