#!/usr/bin/env php
<?php
/**
 * Test the generated php.cpeg grammar
 */

require __DIR__ . '/../../../vendor/autoload.php';

use Charm\Parsing\GrammarParser;

echo "Generating php.cpeg from php.y...\n";
passthru('php ' . __DIR__ . '/generate-php-cpeg.php > ' . __DIR__ . '/../php.cpeg');

$content = file_get_contents(__DIR__ . '/../php.cpeg');
echo "Generated " . substr_count($content, "\n") . " lines\n\n";

echo "Parsing grammar...\n";
$gp = new GrammarParser();
try {
    $grammar = $gp->parseFile(__DIR__ . '/../php.cpeg', 'php');
    echo "Grammar parsed successfully!\n";
    echo "Rules: " . count(iterator_to_array($grammar)) . "\n";
    echo "Lexer: " . ($grammar->getLexerClass() ?? "none") . "\n";
    echo "Has precedence table: " . ($grammar->hasPrecedenceTable() ? "yes" : "no") . "\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}
