<?php
namespace Charm\Parsing;

class ExpectedError extends SyntaxError {

    private $parser, $partialClause, $failingClause;

    public function __construct(ParserInterface $parser, Clause $partialClause, Clause $failingClause) {
        $this->parser = $parser;
        $this->partialClause = $partialClause;
        $this->failingClause = $failingClause;
        parent::__construct(
            "Expected ".($this->failingClause)." ".
            "in ".($this->partialClause->getRule()->getDescription() ?? "`".$this->partialClause->getRule()->getRuleName()."`"), 4);
    }

}
