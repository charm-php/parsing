<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\State;
use Charm\Parsing\Clause;
use Charm\Parsing\Signal;
use Charm\Parsing\Error\CustomError;
use Charm\Parsing\Grammar;
use Charm\Parsing\Util\EcmaRegexParser;
use Charm\Parsing\Util\RegexAnalyzer;

class Regex extends Terminal {

    public function asString(): string {
        return $this->pattern;
    }

    // the regex as provided by the grammar
    private $pattern;
    // the final regex, as used for parsing
    private $re;
    // cached AST from ECMA regex parser (null if not parsed, false if parse failed)
    private $ast = null;
    // cached analysis results
    private ?int $cachedMinByteLength = null;
    private ?string $cachedFirstBytes = null;
    private bool $analyzed = false;

    public function __construct(string $pattern, int $modifier, ?string $name) {
        parent::__construct($modifier, $name);
        $this->pattern = $pattern;
        $this->re = $pattern.'uA';
        if ($this->isCaseInsensitive()) {
            $this->re .= 'i';
        }
        // Validate the regex by attempting a match
        set_error_handler(function($errno, $errstr, $errfile=null, $errline=null, $errcontext=null) {
            restore_error_handler();
            throw new CustomError(strtr($errstr, [
                'preg_match():' => $this->re.':',
            ]));
        }, E_ALL);
        try {
            preg_match($this->re, "sample string", $matches, 0, 0);
        } finally {
            restore_error_handler();
        }
    }

    public function interpret(State $state, array $context) {
        $offset = $state->offset;

        $count = preg_match($this->re, $state->source, $matches, 0, $state->offset);
        if (!is_int($count)) {
            throw new CustomError("Error in regex `{$this->pattern}`: ".preg_last_error_msg()." code ".preg_last_error());
        }
        if ($count === 0) {
            return Signal::MISS;
        }
        $length = strlen($matches[0]);
        $state->offset += $length;
        return $matches[0];
    }

    /**
     * Parse the regex pattern and analyze it (lazy, cached)
     */
    private function ensureAnalyzed(): void {
        if ($this->analyzed) {
            return;
        }
        $this->analyzed = true;

        // Extract inner pattern (between delimiters)
        $inner = substr($this->pattern, 1, -1);
        if ($inner === '') {
            return;
        }

        // Try to parse with ECMA regex parser
        try {
            static $parser = null;
            if ($parser === null) {
                $parser = new EcmaRegexParser();
            }
            $this->ast = $parser->parse($inner);
            [$this->cachedMinByteLength, $this->cachedFirstBytes] = RegexAnalyzer::analyze($this->ast);

            // Handle case insensitivity
            if ($this->isCaseInsensitive() && $this->cachedFirstBytes !== null) {
                $this->cachedFirstBytes = strtolower($this->cachedFirstBytes)
                    . strtoupper($this->cachedFirstBytes);
                $this->cachedFirstBytes = count_chars($this->cachedFirstBytes, 3);
            }
        } catch (\Throwable $e) {
            // Parse failed - pattern might use PCRE-specific features
            $this->ast = false;
        }
    }

    public function getMinByteLength(): int {
        $this->ensureAnalyzed();
        return $this->cachedMinByteLength ?? 0;
    }

    public function getFirstBytes(): ?string {
        $this->ensureAnalyzed();
        return $this->cachedFirstBytes;
    }
}
