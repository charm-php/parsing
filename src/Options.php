<?php
namespace Charm\Parsing;

use Charm\AbstractOptions;

class Options extends AbstractOptions {

    /**
     * Trace log the parsing progress
     */
    public bool $trace = false;

    /**
     * Discard zero length matches
     */
    public bool $discard_empty = true;
}
