<?php
namespace Charm\Parsing\Error;

/**
 * Error thrown when parsing a source code fails because the provided
 * source disagrees with the grammar.
 */
class CommittedError extends ParsingError {

    public function __construct(NonTerminal $managingClause, Clause $failingClause, int $byteOffset) {
        $rule = $managingClause->getRule();
        $parsingDescription = $rule->getDescription() ?? "`".$rule->getRuleName()."`"
        $expectingDescription = $failingClause->getDescription();

        parent::__construct("Error parsing $parsingDescription, expected $expectingDescription", 0, $byteOffset);
    }

}
