<?php
namespace Charm\Parsing;

use Charm\AbstractOptions;

class Options extends AbstractOptions {

    /**
     * Trace log the parsing progress
     */
    public bool $trace = false;

    /**
     * Should the parser automatically tokenize based on terminals
     * in the grammar?
     */
    public bool $auto_tokenize = true;

    /**
     * Discard zero length matches
     */
//    public bool $discard_empty = true;
}
