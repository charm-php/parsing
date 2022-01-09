<?php
namespace Charm\Parsing;

class Config extends Charm\Config {

    /**
     * If true, terminal matches will be owned by the non-terminal parse tree node.
     */
    public bool $remove_unlabeled_terminals = true;

}
