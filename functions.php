<?php
namespace Charm;

function cpeg_parse(string $expression, string $subject, array $initialState=[]) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
    }

    $useCompiled = getenv('CHARM_COMPILED') !== false;
    $hash = md5($expression) . ($useCompiled ? '_compiled' : '');

    if (!isset($cache[$hash])) {
        $cache[$hash] = cpeg_create($expression);
        if (count($cache) > 20) {
            // we're apparently parsing a lot of different grammars, so avoid memory leaks
            array_shift($cache);
        }
    }

    $parser = $cache[$hash];

    if ($useCompiled) {
        // Compiled parser accepts optional initial state
        $result = $parser->parse($subject, $initialState);
    } else {
        // Interpreted parser needs more context for error messages
        foreach (debug_backtrace(0) as $t) {
            if ($t['file'] !== __FILE__) {
                break;
            }
        }
        $result = $parser->parse($subject, $t['file'], $t['line'], 0, $initialState);
    }

    // Signal::HIT means success with no value - normalize to null
    if ($result === Parsing\Signal::HIT) {
        return null;
    }
    return $result;
}

/**
 * Create a parser instance from an expression.
 *
 * Set environment variable CHARM_COMPILED=1 to use the grammar compiler
 * instead of the interpreter for testing compiled parser output.
 */
function cpeg_create(string $expression) {
    static $grammarParser = null;
    static $preprocessor = null;
    static $classCounter = 0;

    if ($grammarParser === null) {
        $grammarParser = new Parsing\GrammarParser();
        $preprocessor = new Parsing\GrammarPreprocessor();
    }

    foreach (debug_backtrace(0) as $t) {
        if ($t['file'] !== __FILE__) {
            break;
        }
    }
    $filename = $t['file'];
    $lineOffset = $t['line'];

    // Preprocess to expand templates
    $expression = $preprocessor->process($expression);

    $grammar = $grammarParser->parse($expression, $filename, $lineOffset);

    if (getenv('CHARM_COMPILED') !== false) {
        // Compile and eval the grammar
        $compiler = new Parsing\Compiler\GrammarCompiler();
        $className = 'CompiledParser_' . (++$classCounter) . '_' . substr(md5($expression), 0, 8);
        $code = $compiler->compile($grammar, $className);
        eval('?>' . $code);
        return new $className();
    } else {
        return new Parsing\CPEGParser($grammar, null, new Parsing\Options(['trace' => true]));
    }
}
