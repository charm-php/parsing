<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\Clause;
use Charm\Parsing\GrammarError;

class Regex extends Terminal implements RegexInterface {

    // the regex as provided by the grammar
    private $pattern;
    // the final regex, as used for parsing
    private $re;

    public function __construct(string $pattern, int $modifier, ?string $name) {
        parent::__construct($modifier, $name);
        $this->pattern = $pattern;
        $this->re = $pattern.'uA';
        if ($this->isCaseInsensitive()) {
            $this->re .= 'i';
        }
    }

    public function getRegex(): string {
        return substr($this->pattern, 1, -1);
    }

    public function getRegexPriority(): int {
        return -mb_strlen($this->pattern);
    }

    public function parse(ParserInterface $parser, &$parentResult) {
        $parser->traceStart($this, $parser->offset);
        $offset = $parser->offset;
        $count = preg_match($this->re, $parser->source, $matches, 0, $parser->offset);
        if (!is_int($count)) {
            throw new GrammarError("Error in grammar regex `".$this->pattern."`: ".preg_last_error_msg()." code ".preg_last_error());
        }
        if ($count === 0) {
            $parser->traceEnd($this, false);
            return false;
        }
        $length = strlen($matches[0]);
        $parser->offset += $length;
        $parser->traceEnd($this, $matches[0]);
        return $matches[0];
    }


    public function asString(): string {
        return $this->pattern;
    }

    public function debugInfo() {
        if ($this->getModifier() !== 0) {
            return [ 'modifier' => $this->getModifier(), 'regex' => $this->pattern ];
        }
        return ['regex' => $this->pattern];
    }
}
