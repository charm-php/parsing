<?php
namespace Charm\Parsing\Error;

/**
 * Parsing exceptions are thrown as a result of syntactic or logical errors in a string.
 * They expect to be caught and annotated with proper location information.
 */
class ParsingError extends \ParseError {

    private
        $byteOffset,
        $source = null,
        $columnNumber = null,
        $checkpoint = null;

    public function __construct(string $message, int $code, int $byteOffset) {
        parent::__construct($message, $code);
        $this->byteOffset = $byteOffset;
    }

    public function annotate(string $source, string $filename, int $lineOffset, int $columnOffset) {
        // trust the first annotation
        assert($this->checkpoint === null, new InternalError("Exception was already annotated", 0, $checkpoint));
        $this->checkpoint = new Checkpoint();

        $st = new Util\StringTools($source);
        $lineNumber = $st->getLineNumberAt($this->byteOffset) + $lineOffset + 1;
        $this->columnNumber = $st->getColumnNumberat($this->byteOffset) + $columnOffset + 1;

        $this->source = $source;

        $et = new Util\ExceptionTools($this);
        $et->setFile($filename);
        $et->setLine($lineNumber);
    }

    public function getOffset(): ?int {
        return $this->byteOffset;
    }

    public function getSource(): ?string {
        return $this->source;
    }

    public function getColumn(): ?int {
        return $this->columnNumber;
    }

    public function __toString() {
        if ($this->checkpoint === null) {
            return "NOTE! This parsingException was not annotated\n".parent::__toString();
        } else {
            return parent::__toString();
        }
    }

}
