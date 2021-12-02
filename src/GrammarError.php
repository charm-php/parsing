<?php
namespace Charm\Parsing;

use LogicException;

/**
 * Thrown when the grammar appears to contain a syntax or logical error, such
 * as direct or indirect left recursion or simply a parsing error.
 */
class GrammarError extends LogicException {

}
