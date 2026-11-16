<?php
namespace Charm\Parsing\Compiler\Portable;

/**
 * Thrown when a grammar uses something the portable targets (C, JavaScript, Python) cannot express:
 * snippets, token mode, or a regex construct outside the supported subset.
 */
final class UnsupportedGrammar extends \RuntimeException
{
}
