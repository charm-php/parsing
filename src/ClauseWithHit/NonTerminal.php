<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\Clause;
use Charm\Parsing\Hit;
use Charm\Parsing\ParserInterface;

abstract class NonTerminal extends Clause {

    /**
     * Handles processing of modifiers and labels from sub clauses.
     */
    protected function processSubClause(ParserInterface $parser, Clause $subClause): ?Hit {
        $parser->trace($this, "PROCESS SUB CLAUSE $subClause");
        $offset = $parser->offset;

        $result = null;
        if ($subClause->isRepeating()) {
            /**
             * The reptition expression e* succeeds for zero or more matches, and the
             * repetition expression e+ succeeds for one or more matches.
             */
            $results = [];
            while (null !== ($hit = $subClause->parse($parser))) {
                $results[] = $hit;
            }
            if ($subClause->isOptional() && $results === []) {
                $parser->trace($this, "Repeating sub clause $subClause missed but is optional");
                $result = new Hit($subClause, null, $offset, $parser->offset - $offset);
            } elseif ($results === []) {
                $parser->trace($this, "Repeating sub clause $subClause missed");
            } else {
                $parser->trace($this, "Repeating sub clause $subClause matched");
                /**
                 * The repetition expression e* is handled as optional
                 */
                $result = new Hit($subClause, $results, $offset, $parser->offset - $offset);
            }
        } else {
            $result = $subClause->parse($parser);

            if ($result === null) {
                if ($subClause->isOptional()) {
                    $parser->trace($this, "Sub clause $subClause missed but is optional");
                    $result = new Hit($subClause, null, $offset, 0);
                } else {
                    $parser->trace($this, "Sub clause $subClause missed");
                }
            } else {
                $parser->trace($this, "Sub clause $subClause matched");
            }

        }

        if ($subClause->hasNotPredicate()) {
            /**
             * The not-predicate expression !e succeeds if e fails and fails if e succeeds,
             * consuming no input in either case.
             */
            $parser->offset = $offset;
            if ($result === null) {
                $result = new Hit($subClause, null, $offset, 0);
            } else {
                $result = null;
            }
        }

        if ($subClause->hasAndPredicate()) {
            /**
             * The and-predicate expression &e invokes the sub-expression e, and then succeeds
             * if e succeeds and fails if e fails, but in either case never consumes any input.
             */
            $parser->offset = $offset;
            if ($result !== null) {
                // we'll reuse the "Hit" instance
                $result->length = 0;
                $result->value = null;
            }
        }

        if ($parser->options->discard_empty) {
            if (is_array($result->value)) {
                foreach ($result->value as $k => $v) {
                    if ($v->length === 0) {
                        unset($result->value[$k]);
                    }
                }
                $result->value = array_values($result->value);
            }
        }

        return $result;
    }

    public final function isTerminal(): bool {
        return false;
    }

}
