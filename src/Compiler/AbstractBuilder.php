<?php
namespace Charm\Parsing\Compiler;

/**
 * Generic builder class for PHP code, such as functions, methods,
 * interfaces, traits and classes.
 */
abstract class AbstractBuilder {

    /**
     * Must clone all properties we are referencing
     */
    abstract public function __clone();

    /**
     * Must validate everything for code generation
     */
    abstract public function validate();

}
