<?php
namespace Charm\Parsing;

use Charm\Parsing\Compiler\{
    ClassBuilder
};

class Compiler extends Parser {

    public function compile(string $className) {
        $parser = new ClassBuilder($className);
    }

}
