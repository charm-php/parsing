<?php
namespace Charm\Parsing\Error;

use Charm\Parsing\Util\StringTools;

class ParseError extends AbstractParseError {

    private $_source, $_offset;

    public function __construct(string $source, int $offset, string $messageTemplate, $code = 0, \Throwable $previous = null) {
        $this->_source = $source;
        $this->_offset = $offset;
        parent::__construct($messageTemplate, $code, $previous);
    }

    public function getSource(): ?string {
        return $this->_source;
    }

    public function getInternalLineNumber(): int {
        $st = new StringTools($this->_source);
        return $st->getLineNumberAt($this->_offset);
    }

    public function getInternalColumnNumber(): int {
        $st = new StringTools($this->_source);
        return $st->getColumnNumberAt($this->_offset);
    }

    public function getSourceDescription(): string {
        $st = new StringTools($this->getSource());
        return $st->describeOffseT($this->getOffset());
    }

}
