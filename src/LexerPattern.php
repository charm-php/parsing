<?php
namespace Charm\Parsing;

class LexerPattern {

    public int $type;
    public string $name;
    public string $regex;
    private $processor;

    /**
     * @param $type         An integer that identifies this token pattern. Negative values are removed from the token array.
     * @param $name         A human readable pattern name
     * @param $regex        A regular expression which this pattern matches
     * @param $processor    A function which transforms LexerToken objects into a value
     */
    public function __construct(int $type, string $name, string $regex, callable $processor=null) {
        $this->type = $type;
        $this->name = $name;
        $this->regex = $regex;
        if ($processor) {
            $this->processor = \Closure::fromCallable($processor)->bindTo(null, null);
        } else {
            $this->processor = null;
        }
    }

    public function process(LexerToken $token) {
        if ($this->processor) {
            return ($this->processor)($token);
        } else {
            return $token;
        }
    }

}
