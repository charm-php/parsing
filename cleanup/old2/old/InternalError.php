<?php
namespace Charm\Parsing\Error;

/**
 * Thrown when a bug in the Charm\Parsing library is detected. Due to the
 * complexity of parsing, the grammar makes assertions to detect bugs and
 * unexpected side effects so that the bug can be fixed..
 */
class InternalError extends \LogicException {
}
