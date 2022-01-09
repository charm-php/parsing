<?php
namespace Charm\Parsing\Error;

use Charm\Parsing\StateInterface;

class StringParseError extends BaseError {
    private $_sourceString;
    private $_byteOffset;

    public function __construct(string $source, int $offset, string $messageTemplate, $code = 0, \Throwable $previous = null) {
        $this->_sourceString = $source;
        $this->_byteOffset = $offset;
    }

    public function getSourceDescription() {
        if ($this->_byteOffset < 0) {
            return '<invalid negative offset>';
        }
        if ($this->_byteOffset >= strlen($this->_sourceString)) {
            return 'end of file';
        }
        $st = new StringTools($this->_sourceString);
        
    }

}

