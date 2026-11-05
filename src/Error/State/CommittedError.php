<?php
namespace Charm\Parsing\Error\State;

use Charm\Parsing\ClauseInterface;
use Charm\Parsing\State;

/**
 * A sequence failed after its commit operator (`@`): the parser does not backtrack to try
 * other alternatives but reports what the committed sequence expected.
 */
class CommittedError extends ParseError {

    public function __construct(State $state, ClauseInterface $sequence, ClauseInterface $failed) {
        $rule = $sequence->getRule();
        $where = $rule !== null ? ' in '.$rule->getRuleName() : '';
        parent::__construct($state, 'Expected '.$failed->toGrammarString().$where.' on :location, found :source_description');
    }
}
