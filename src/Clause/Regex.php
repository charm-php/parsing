<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\State;
use Charm\Parsing\Clause;
use Charm\Parsing\GrammarError;
use Charm\Parsing\Error\CustomError;
use Charm\Parsing\Grammar;

class Regex extends Terminal {

    public function asString(): string {
        return $this->pattern;
    }

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
        // validate the exception
        set_error_handler(function($errno, $errstr, $errfile=null, $errline=null, $errcontext=null) {
            restore_error_handler();
            throw new CustomError(strtr($errstr, [
                'preg_match():' => $this->re.':',
            ]));
        }, E_ALL);
        if (!is_int(preg_match($this->re, "sample string", $matches, 0, 0))) {
            die("invalid regex");
        }
        restore_error_handler();
    }

    public function interpret(State $state, array $context) {
        $offset = $state->offset;
        
        $count = preg_match($this->re, $state->source, $matches, 0, $state->offset);
        if (!is_int($count)) {
            throw new CustomError("Error in regex `{$this->pattern}`: ".preg_last_error_msg()." code ".preg_last_error());
        }
        if ($count === 0) {
            return false;
        }
        $length = strlen($matches[0]);
        $state->offset += $length;
        return $matches[0];
    }

}
