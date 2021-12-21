<?php
namespace Charm\Parsing\Compiler;

class ConstantBuilder extends AbstractBuilder {
    use Traits\Value;
    use Traits\Name;

    public function __clone(){
    }
}
