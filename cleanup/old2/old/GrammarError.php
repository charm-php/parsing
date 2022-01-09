<?php
namespace Charm\Parsing\Error;

/**
 * Thrown when the grammar appears to contain an error which was not discovered
 * during parsing of the grammar. For example if a code snippet contains invalid
 * code or tries to access undefined variables, or a predicate is combined with
 * a clause or operator that does not support the predicate.
 *
 * May also be thrown when the grammar contains obvious problems which can be
 * corrected by rewriting the grammar.
 */
class GrammarError extends ParsingError {
}
