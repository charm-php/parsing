<?php
namespace Charm\Parsing;

/**
 * Something that should be impossible happened inside the library (a bug in the grammar object model or the
 * engines), as opposed to a problem with the grammar or the input.
 */
class InternalError extends \LogicException
{
}
