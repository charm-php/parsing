<?php
namespace Charm\Parsing;

use Charm\AbstractOptions;

class ErrorInfo extends AbstractOptions {

    /**
     * Source filename
     */
    public ?string $filename = null;

    /**
     * Source code for improved error messages
     */
    public ?string $source = null;

    /**
     * Error byte offset in source code
     */
    public ?int $offset = null;

    /**
     * Error line offset in source code (only if offset is not provided)
     */
    public ?int $line = null;

    /**
     * Function name responsible for the error
     */
    public ?string $functionName = null;

    /**
     * Arguments passed to the function that caused the error
     */
    public ?array $args = null;

    /**
     * Get the line number when offset and source code or filename is
     * available.
     */
    public function getLine(): ?int {
        if ($this->offset === null) {
            return $this->line;
        }
        if ($this->source !== null) {
            $st = new Util\StringTools($this->source);
            return $st->getLineNumberAt($this->offset);
        } elseif ($this->filename !== null && file_exists($this->filename)) {
            $st = new Util\Stringtools(file_get_contents($this->filename));
            return $st->getLineNumberAt($this->offset);
        }
        return null;
    }

}
