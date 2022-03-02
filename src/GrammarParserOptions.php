<?php
namespace Charm\Parsing;

use Charm\AbstractOptions;

class GrammarParserOptions extends \Charm\AbstractOptions {

    /**
     * Base namespace for snippets
     *
     * @var string
     */
    public string $base_namespace = '';
}