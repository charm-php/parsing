<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\InternalError;

class Commit extends Terminal {

    public function asString(): string {
        return '@';
    }

    public function parse(ParserInterface $parser, array &$context, array $args=null) {

var_dump(debug_backtrace(false));die("COMMIT FAIL");

        throw new InternalError("`".$this->toLocationString()."` commit symbol not handled");
    }

}
