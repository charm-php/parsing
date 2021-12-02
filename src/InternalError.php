<?php
namespace Charm\Parsing;

use LogicException;

/**
 * Thrown when a bug in the Charm\Parsing library occurs. These exceptions
 * should result in a fix in the library. Pull requests welcomed.
 */
class InternalError extends LogicException {

}
