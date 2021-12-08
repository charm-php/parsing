<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\Clause;
use Charm\Parsing\ParserInterface;
use Charm\Parsing\{
    GrammarError,
    InternalError
};

abstract class NonTerminal extends Clause {

    public function __construct(int $modifier, ?string $name) {
        $this->modifier = $modifier;
        $this->name = $name;
    }

/*
    private $_hasLeftMostResult = false;
    private $_leftMostResult = null;
    private $_ruleName = null;
*/
/*    protected function processLeftRecursiveSubClause(ParserInterface $parser, Clause $subClause, &$parentResult, $leftMostResult, $ruleName) {
        $hasLeftMostResult = $this->_hasLeftMostResult;
        $leftMostResult = $this->_leftMostResult;
        $ruleName = $this->_ruleName;
        $this->_hasLeftMostResult = true;
        $this->_leftMostResult = $leftMostResult;
        $this->_ruleName = $ruleName;
        $result = $this->_processSubClause($parser, $subClause, $parentResult);
        $this->_leftMostResult = $leftMostResult;
        $this->_hasLeftMostResult = $hasLeftMostResult;
        $this->_ruleName = $ruleName;
        return $result;
    }*/

    /**
     * Handles processing of modifiers and labels from sub clauses.
     */
/*    protected function processSubClause(ParserInterface $parser, Clause $subClause, &$parentResult) {
        $hasLeftMostResult = $this->_hasLeftMostResult;
        $leftMostResult = $this->_leftMostResult;
        $ruleName = $this->_leftMostResult;
        $this->_hasLeftMostResult = false;
        $this->_leftMostResult = null;
        $this->_ruleName = null;
        $result = $this->_processSubClause($parser, $subClause, $parentResult);
        $this->_leftMostResult = $leftMostResult;
        $this->_hasLeftMostResult = $hasLeftMostResult;
        $this->_ruleName = $ruleName;
        return $result;
    }*/

    protected function processSubClause(ParserInterface $parser, Clause $subClause, array &$context, array $args=null) {

        if ($subClause instanceof Snippet) {
            if ($subClause->getModifier() !== static::NONE) {
                throw new GrammarError("Snippets can't be annotated with ".$subClause->getModifierString()." currently.");
            }
            return $subClause->parse($parser, $context, $args);
        }

        $offset = $parser->offset;

        $result = null;

        if ($subClause->isRepeating()) {
            /**
             * The reptition expression e* succeeds for zero or more matches, and the
             * repetition expression e+ succeeds for one or more matches.
             */
            while (true) {
                $subResult = $subClause->parse($parser, $context);

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
        } else {
            $result = $subClause->parse($parser, $parentResult);
            if ($result === false && $subClause->isOptional()) {
                $result = true;
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

        return $result;
    }

    public final function isTerminal(): bool {
        return false;
    }

}
