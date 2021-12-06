<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\Clause;
use Charm\Parsing\ParserInterface;
use Charm\Parsing\{
    GrammarError,
    InternalError
};

abstract class NonTerminal extends Clause {

    /**
     * Handles processing of modifiers and labels from sub clauses.
     */
    protected function processSubClause(ParserInterface $parser, Clause $subClause, &$parentResult) {
        if ($subClause instanceof Snippet) {
            throw new InternalError("Snippet handling error");
            if ($subClause->isRepeating() || $subClause->isOptional()) {
                throw new GrammarError("Snippets can't be annotated with ?, * or +");
            }
            return $subClause->parse($parser, $parentResult);
        }

        $offset = $parser->offset;

        $result = null;
        if ($subClause->isRepeating()) {

            /**
             * The reptition expression e* succeeds for zero or more matches, and the
             * repetition expression e+ succeeds for one or more matches.
             */
            while (true) {
                $subResult = $subClause->parse($parser, $parentResult);

                if ($subResult === false) {
                    $subResult = false;
                    break;
                }
                if ($subResult === true) {
                    if ($result === null) {
                        // make result into an array because it no longer has no matches
                        $result = [];
                    }
                } else {
                    $result[] = $subResult;
                }
            }
            if ($result === null) {
                if ($subClause->isOptional()) {
                    $result = true;
                } else {
                    $result = false;
                }
            }
/*
            if ($result !== null && $parser->options->discard_empty) {
                $result = array_filter($result, function($el) {
                    return $el !== '';
                });
            }
*/
        } else {
            $result = $subClause->parse($parser, $parentResult);
            if ($result === false && $subClause->isOptional()) {
                $result = true;
            }
        }

        if ($subClause->hasNotPredicate()) {
            /**
             * The not-predicate expression !e succeeds if e fails and fails if e succeeds,
             * consuming no input in either case.
             */
            $parser->offset = $offset;
            if ($result === false) {
                // only failed results are true
                $result = true;
            } else {
                $result = false;
            }
        }

        if ($subClause->hasAndPredicate()) {
            /**
             * The and-predicate expression &e invokes the sub-expression e, and then succeeds
             * if e succeeds and fails if e fails, but in either case never consumes any input.
             */
            $parser->offset = $offset;
            if ($result !== false) {
                // all non-failed results are simply true
                $result = true;
            }
        }

/*
        if (!$subClause->isRepeating() && is_array($result) && count($result) === 1 && is_int(key($result))) {
            $result = current($result);
        }
*/

        return $result;
    }

    public final function isTerminal(): bool {
        return false;
    }

}
