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
     * Base namespace for snippets
     *
     * @var string
     */
    public string $base_namespace = '';

    /**
     * Discard zero length matches
     */
//    public bool $discard_empty = true;
}
