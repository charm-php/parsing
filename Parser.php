<?php
namespace Charm;

use Charm\Parsing\Options;
use Charm\Parsing\GrammarError;
use Charm\Parsing\ExceptionInterface;
use Charm\Parsing\Util\{
    ExceptionTools,
    StringTools
};
use Charm\Parsing\GrammarParserOptions;

abstract class Parser extends Parsing\CPEGParser {
    const GRAMMAR = null;
    const OPTIONS = null;
    const GOAL = null;

    public function __construct() {
        if (static::GRAMMAR === null) {
            throw new Parsing\Exception("`".static::class."`::GRAMMAR is not declared");
        }

        $rc = new \ReflectionClass($this);
        $grammarParser = new Parsing\GrammarParser(new GrammarParserOptions([
            "base_namespace" => $rc->getNamespaceName()
        ]));

        try {
            $rc = new \ReflectionClass($this);
            $grammar = $grammarParser->parse(static::GRAMMAR, $rc->getFileName());
        } catch (Parsing\ExceptionInterface $e) {
            /**
             * Since we're embedding the grammar inside the PHP class file,
             * the line number in exceptions will be slightly wrong, so we
             * rewrite the error line number here.
             */
            $rcc = new \ReflectionClassConstant(static::class, 'GRAMMAR');
            $rc = $rcc->getDeclaringClass();
            $fileString = file_get_contents($rc->getFileName());
            preg_match(<<<'RE'
                /
                const\s+GRAMMAR\s*=\s*
                (\'|"|<<<'[^']+'[^\n]*\n|<<<\w+[^\n]*\n)
                /x
                RE, $fileString, $matches, PREG_OFFSET_CAPTURE);

            $constOffset = $matches[0][1];
            $st = new StringTools($fileString);
            $constLineNumber = $st->getLineNumberAt($constOffset);
            $constLineNumber += substr_count($matches[0][0], "\n");
            $et = new ExceptionTools($e);
            $et->setLine($constLineNumber + $e->getLine());
            throw $e;
        } catch (\Throwable $e) {
            throw $e;
        }
        if (static::OPTIONS !== null) {
            $options = new Options(static::OPTIONS);
        } else {
            $options = null;
        }
        parent::__construct($grammar, static::GOAL, $options);
    }

/*
    protected function tagException(\Throwable $e) {
        if ($e instanceof GrammarError) {
            return $e;
        }
        $source = new Parsing\Util\StringTools($this->source);
        $lineNumber = $source->getLineNumberAt($this->offset);
        $et = new Parsing\Util\ExceptionTools($e);
        $et->setFileName($this->filename ?? "string");
        $et->setLine($lineNumber);
        return $e;
    }
*/
}
