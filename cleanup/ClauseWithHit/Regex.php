<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\Clause;
use Charm\Parsing\Hit;
use Charm\Parsing\GrammarError;

class Regex extends Terminal {

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

    public function parse(ParserInterface $parser): ?Hit {
        $offset = $parser->offset;
        $parser->trace($this);
        $count = preg_match($this->re, $parser->source, $matches, 0, $parser->offset);
        if (!is_int($count)) {
            throw new GrammarError("Error in grammar regex `".$this->pattern."`: ".preg_last_error_msg()." code ".preg_last_error());
        }
        if ($count === 0) {
            $parser->traceMiss($this, $offset);
            return null;
        }
        $length = strlen($matches[0]);
        $parser->offset += $length;
        $parser->traceHit($this, $offset, $matches[0]);
        return new Hit($this, $matches[0], $offset, $length);
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
