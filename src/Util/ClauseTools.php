<?php
namespace Charm\Parsing\Util;

use Charm\Parsing\Clause;

class ClauseTools {

    private $c;

    public function __construct(Clause $clause) {
        $this->c = $clause;
    }

    public function getPredicateString(): string {
        if ($this->c->hasAndPredicate()) {
            return '&';
        } elseif ($this->c->hasNotPredicate()) {
            return '!';
        } else {
            return '';
        }
    }

    public function getOperatorString(): string {
        if ($this->c->isOptional() && $this->c->isRepeating()) {
            return '*';
        } elseif ($this->c->isOptional()) {
            return '?';
        } elseif ($this->c->isRepeating()) {
            return '+';
        } else {
            return '';
        }
    }

    public function getClauseString(): string {
        return $this->c->asString();
    }

    public function getLocationString(): string {
        $c = $this->c->asString();
        $s = $c
    }
