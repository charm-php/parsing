<?php
namespace Charm\Parsing\Pika\Util;

use Charm\Parsing\Pika\Clause;
use Charm\Parsing\Pika\Aux\ASTNodeLabel;
use Charm\Parsing\Pika\Aux\RuleRef;
use Charm\Parsing\Pika\NonTerminal\First;
use Charm\Parsing\Pika\NonTerminal\FollowedBy;
use Charm\Parsing\Pika\NonTerminal\NotFollowedBy;
use Charm\Parsing\Pika\NonTerminal\OneOrMore;
use Charm\Parsing\Pika\NonTerminal\Seq;
use Charm\Parsing\Pika\Terminal\Start;
use Charm\Parsing\Pika\Terminal\Nothing;
use Charm\Parsing\Pika\Terminal\Kind;
use Charm\Parsing\Pika\Rule;
use Charm\Parsing\Pika\Associativity;

class ClauseFactory {

    const RULE_DEFAULTS = [
        'presedence' => -1,
        'associativity' => null,
    ];

    public static function rule(string $ruleName, Clause $clause, array $options=self::RULE_DEFAULTS): Rule {
        return new Rule($ruleName, $options['presedence'], $options['associativity'], $clause);
    }

    public static function seq(Clause ...$subClauses): Clause {
        return new Seq($subClauses);
    }

    public static function oneOrMore(Clause $subClause): Clause {
        if (
            $subClause instanceof OneOrMore ||
            $subClause instanceof Nothing ||
            $subClause instanceof FollowedBy ||
            $subClause instanceof NotFollowedBy ||
            $subClause instanceof Start
        ) {
            return $subClause;
        }
        return new OneOrMore($subClause);
    }

    public static function optional(Clause $subClause): Clause {
        return self::first($subClause, self::nothing());
    }

    public static function zeroOrMore(Clause $subClause) {
        return self::optional(self::oneOrMore($subClause));
    }

    public static function first(Clause ...$subClauses) {
        return new First($subClauses);
    }

    public static function followedBy(Clause $subClause) {
        if ($subClause instanceof Nothing) {
            return $subClause;
        } elseif (
            $subClause instanceof FollowedBy ||
            $subClause instanceof NotFollowedBy ||
            $subClause instanceof Start
        ) {
            throw new InvalidArgumentException(FollowedBy::getSimpleName() . '(' +
                $subClause::getSimpleName() . ') is nonsensical');
        }
        return new FollowedBy($subClause);
    }

    public static function notFollowedBy(Clause $subClause) {
        if ($subClause instanceof Nothing) {
            throw new InvalidArgumentException(NotFollowedBy::getSimpleName() . '(' .
                Nothing::getSimpleName() . ') will never match anything');
        } elseif ($subClause instanceof NotFollowedBy) {
            return new FollowedBy($subClause->labeledSubClauses[0]->clause);
        } elseif (
            $subClause instanceof FollowedBy ||
            $subClause instanceof Start
        ) {
            throw new InvalidArgumentException(NotFollowedBy::getSimpleName() . '(' .
                $subClause::getSimpleName() . ') is nonsensical');
        }
        return new NotFollowedBy($subClause);
    }

    public static function start(): Clause {
        return new Start();
    }

    public static function nothing(): Clause {
        return new Nothing();
    }

    public static function ast(String $astNodeLabel, Clause $clause): Clause {
        return new ASTNodeLabel($astNodeLabel, $clause);
    }

    public static function ruleRef(string $ruleName) {
        return new RuleRef($ruleName);
    }
}
