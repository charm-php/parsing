<?php
namespace Charm\Parsing\Error;

/**
 * Exception thrown when a snippet (inline PHP code in grammar) fails.
 */
class SnippetError extends \RuntimeException {
    private string $snippetSource;

    public function __construct(string $message, string $snippetSource, ?\Throwable $previous = null) {
        $this->snippetSource = $snippetSource;
        parent::__construct($message, 0, $previous);
    }

    public function getSnippetSource(): string {
        return $this->snippetSource;
    }
}
