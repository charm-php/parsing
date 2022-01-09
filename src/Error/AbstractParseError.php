<?php
namespace Charm\Parsing\Error;

use Charm\Parsing\Util\StringTools;
use Charm\Parsing\Util\ExceptionTools;

/**
 * Generic parsing error
 */
abstract class AbstractParseError extends \RuntimeException {

    private
        $_messageTemplate,          // a message template which can use variables :location, :file, :line, :column, :source_description
        $_file,                     // the filename where the source we parsed came from, or a descriptive string
        $_lineOffset=0,             // an adjustment to the line number (if the file was embedded in another file)
        $_columnOffset=0;           // an adjustment to the column number (if the file was embedded in another file with indentation that was removed before parsing)

    public function __construct(string $messageTemplate, $code = 0, \Throwable $previous=null)  {
        $this->_messageTemplate = $messageTemplate;
        parent::__construct($this->generateMessageString(), $code, $previous);
    }

    /**
     * Get the full source that was parsed if available
     */
    abstract public function getSource(): ?string;

    /**
     * Get the 0 based line number where the error occurred
     */
    abstract protected function getInternalLineNumber(): int;

    /**
     * Get the 0 based column number where the error occurred
     */
    abstract protected function getInternalColumnNumber(): int;

    /**
     * Describe the error at the source offset. For example 'whitespace' or 'T_NUMBER'
     * or 'quoted string'.
     */
    abstract public function getSourceDescription(): string;

    /**
     * Add additional information about the error to better pinpoint the error location.
     */
    public function setFileInfo(string $filename, int $lineOffset=0, int $columnOffset=0) {
        $this->_file = $filename;
        $this->_lineOffset += $lineOffset;
        $this->_columnOffset += $columnOffset;
        $this->update();
    }

    public function adjustErrorLine(int $adjustment) {
        $this->_lineOffset += $adjustment;
        $this->update();
    }

    public function adjustErrorColumn(int $adjustment) {
        $this->_columnOffset += $adjustment;
        $this->update();
    }

    public function getLocationString(): string {
        return $this->interpolateString(':file::line::column');
    }

    public function getErrorFile(): string {
        return $this->_file ?? "parsed source";
    }

    public function setErrorFile(string $filename) {
        $this->_file = $filename;
        $this->update();
    }

    public function getErrorLine(): int {
        return $this->getInternalLineNumber() + $this->_lineOffset + 1;
    }

    public function getErrorColumn(): int {
        return $this->getInternalColumnNumber() + $this->_columnOffset;
    }

    protected function update() {
        $et = new ExceptionTools($this);
        $et->setMessage($this->interpolateString($this->_messageTemplate));
    }

    protected function generateMessageString(): string {
        return $this->interpolateString($this->_messageTemplate);
    }

    protected function interpolateString(string $message): string {
        $vars = [
            ':location' => function() { return $this->getLocationString(); },
            ':file' => function() { return $this->getErrorFile(); },
            ':line' => function() { return $this->getErrorLine(); },
            ':column' => function() { return $this->getErrorColumn(); },
            ':source_description' => function() { return $this->getSourceDescription(); },
        ];
        foreach ($vars as $k => &$v) {
            if (strpos($message, $k) === false) {
                unset($vars[$k]);
            } else {
                $v = $v();
            }
        }

        return strtr($message, $vars);
    }

}
