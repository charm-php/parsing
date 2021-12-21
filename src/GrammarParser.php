<?php
namespace Charm\Parsing;

use Charm\Vector;
use Charm\Parsing\Clause\{
    Commit,
    Snippet,
    SOF,
    EOF,
    Identifier,
    OrderedChoice,
    Sequence,
    Regex,
    Text
};

/**
 * This is a hand-written recursive descent parser for parsing grammars which can be
 * interpreted by `Charm\Parsing\CPEGParser`.
 *
 * In BNF form the grammmar for grammars is:
 *
 * ```
 * CPEG =               Rule* $
 * Rule =               RuleName RuleDescription? ("::=" | "::" | ":=" | "=" | "->") Clauses ";"?
 * RuleName =           IDENT
 * RuleDescription =    STRING
 * Clauses =            Clause+
 * Clause =             Label? Predicate? (IDENT | STRING | REGEX | SubExpression) Operator?
 * SubExpression =      "(" Clauses+ ")"
 * Label =              STRING ":"
 * Predicate =          "&" | "!"
 * Operator =           "*" | "+" | "?"
 * ```
 */
class GrammarParser extends RecursiveDescentParser {
    const T_IDENT = 1;
    const T_STRING = 2;
    const T_REGEX = 3;
    const T_NUMBER = 4;
    const T_SNIPPET = 5;
    const T_COMMENT = 6;
    const T_BEGIN = 7;
    const T_END = 8;
    const T_PREDICATE = 9;
    const T_OPERATOR = 10;
    const T_COMMIT = 11;
    const T_COLON = 12;
    const T_BRACE_OPEN = 13;
    const T_BRACE_CLOSE = 14;
    const T_OR = 15;
    const T_TOKEN = 16;
    const T_WHITESPACE = 17;

    public function __construct() {
        $this->addTerminal(self::T_COMMENT, '(?<COMMENT>(\#|\/\/)[^\n]*+|\/\*((?!\*\/)[\s\S])*\*\/)');

        $this->addTerminal(self::T_IDENT, '[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+|\^|\$');

        $this->addTerminal(self::T_STRING, <<<'RE'
            (?<STRING>"(\\[\\"]|[^"\\]+|[^"])*+"|'(\\[\\']|[^'\\]+|[^'])*+')
            RE,
            function($match): string {
                $q = $match->text[0];
                $match = substr($match->text, 1, -1);
                return strtr($match, [ "\\\\" => "\\", "\\$q" => $q ]);
            });

        $this->addTerminal(self::T_REGEX, <<<'RE'
            \/(\\[\s\S]|[^\/])*+\/|\[(\\[\S\s]|[^]])*+\]
            RE,
            function($match) {
                return $match->text;
                if ($match->text[0] === '/') {
                    return substr($match->text, 1, -1);
                } elseif ($match->text[0] === '[') {
                    return $match->text;
                } else {
                    throw new GrammarError("Unable to process regex `".$match->text."`");
                }
            });

        $this->addTerminal(self::T_NUMBER, '(0|[1-9][0-9]*+)(\.[0-9]++)?',
            function($match) {
                if (is_int(strpos($match->text, '.'))) {
                    return (float) $match->text;
                }
                return (int) $match->text;
            });

        $this->addTerminal(self::T_SNIPPET, <<<'RE'
            <\?(
                \?>(*ACCEPT)|
                (?=[\/"'#])(
                    (?&STRING)|
                    (?&COMMENT)
                )|
                [^'"#\/?]++|
                [\s\S]
            )*+
            RE,
            function($match) {
                return substr($match->text, 2, -2);
            });

        $this->addTerminal(self::T_BEGIN, '::=|:=|:=|->|=');
        $this->addTerminal(self::T_END, ';');
        $this->addTerminal(self::T_PREDICATE, '&|!');
        $this->addTerminal(self::T_OPERATOR, '\*|\+|\?');
        $this->addTerminal(self::T_COLON, ':');
        $this->addTerminal(self::T_COMMIT, '@');
        $this->addTerminal(self::T_BRACE_OPEN, <<<'RE'
            [[({<]
            RE);
        $this->addTerminal(self::T_BRACE_CLOSE, <<<'RE'
            [\])}>]
            RE);

        $this->addTerminal(self::T_OR, '\|');

        $this->addTerminal(self::T_TOKEN, '\.');

        $this->addTerminal(self::T_WHITESPACE, '\\s+');

        // Ignore whitespace
        $this->addIgnore(self::T_WHITESPACE);

        // Ignore comments starting with // or #
        $this->addIgnore(self::T_COMMENT);
    }

    protected function start() {
        $grammar = $this->_CPEG();

        if ($grammar === null) {
            return null;
        }

        if (!$this->eof()) {
            throw new GrammarError("Expected end of input");
        }

        return $grammar;
    }

    private function _CPEG() {
        $rules = null;

        while (null !== ($rule = $this->_Rule())) {
            $rules[] = $rule;
        }

        if ($rules !== null) {
            return new Grammar($rules, $this->filename);
        }

        return null;
    }

    private function _RuleName() {
        if (!$this->typeIs(self::T_IDENT)) {
            return null;
        }
        if (!($this->typeIs(self::T_BEGIN, 1) || ($this->typeIs(self::T_STRING, 1) && $this->typeIs(self::T_BEGIN, 2)))) {
            // rule names MUST be followed by BEGIN or by STRING+BEGIN
            return null;
        }
        return $this->accept();
    }

    private function _Rule() {
        if (!($this->typeIs(self::T_BEGIN, 1) || ($this->typeIs(self::T_STRING, 1) && $this->typeIs(self::T_BEGIN, 2)))) {
            // rule names MUST be followed by BEGIN or by STRING+BEGIN
            return null;
        }
        $ruleName = $this->accept();

        $description = $this->typeIs(self::T_STRING) ? $this->accept() : null;

        $begin = $this->typeIs(self::T_BEGIN) ? $this->accept() : null;

        $clauses = $this->_Clauses();

        if ($clauses === null) {
            if ($begin !== null) {
                throw new GrammarError("Empty rule `".$ruleName->value()."` not allowed.");
            }
        }

        if ($this->eof()) {
            goto done;
        }

        if ($this->typeIs(self::T_IDENT)) {
            // this test makes the ';' optional
            goto done;
        }

        if (!$this->textIs(';')) {
            throw new GrammarError("Expected rule name, ';' or more clauses");
        }
        $this->accept();

        done:
        $rule = new Rule($ruleName->value(), $clauses);

        if ($description !== null) {
            $rule->setDescription($description->value());
        }

        return $rule;
        return [
            'rule' => $ruleName,
            'description' => $description,
            'clauses' => $clauses,
        ];
    }

    private function _Clauses() {

        $buildSequence = function(array $clauses) {
            $s = new Sequence(Clause::NONE, null);
            foreach ($clauses as $clause) {
                $s[] = $clause;
            }
            return $s;
        };
        $buildOrderedChoice = function(array $sequences) {
            $oc = new OrderedChoice(Clause::NONE, null);
            foreach ($sequences as $sequence) {
                $oc[] = $sequence;
            }
            return $oc;
        };

        $result = null;
        $groups = null;
        while (!$this->eof()) {
            if ($this->typeIs(self::T_OR)) {
                if ($result === null) {
                    throw new GrammarError("Unexpected | without preceding clauses");
                }
                $this->accept();
                $groups[] = $buildSequence($result);
                $result = [];
            } elseif ($this->typeIs(self::T_COMMIT)) {
                $this->accept();
                $result[] = new Commit(Clause::NONE, null);
            } elseif (null !== ($clause = $this->_FullClause())) {
                $result[] = $clause;
            } else {
                break;
            }
        }
        if ($result === null) {
            return null;
        }
        if ($result === []) {
            throw new GrammarError("Expected clause after |");
        }
        if ($groups === null) {
            return $buildSequence($result);
        }
        $groups[] = $buildSequence($result);
        return $buildOrderedChoice($groups);
    }

    private function _FullClause() {
        if (!in_array($this->type(), [self::T_IDENT, self::T_STRING, self::T_REGEX, self::T_SNIPPET, self::T_PREDICATE, self::T_BRACE_OPEN])) {
            return null;
        }
        $label = $this->_Label();
        $predicate = $this->_Predicate();
        $clause = $this->_Clause();

        if ($clause === null && ($label !== null || $predicate !== null)) {
            throw new GrammarError("Expecting a clause after a predicate or a label");
        } elseif ($clause === null) {
            return null;
        }

        $operator = $this->_Operator();

        $modifier = 0;

        if ($predicate !== null) {
            switch ($predicate->text) {
                case '&': $modifier |= Clause::AND; break;
                case '!': $modifier |= Clause::NOT; break;
                default : throw new GrammarError("Unknown predicate '{$predicate->text}'");
            }
        }

        if ($operator !== null) {
            switch ($operator->text) {
                case '*': $modifier |= Clause::OPTIONAL | Clause::REPEATING; break;
                case '+': $modifier |= Clause::REPEATING; break;
                case '?': $modifier |= Clause::OPTIONAL; break;
                default : throw new GrammarError("Unknown operator '{$operator->text}'");
            }
        }
        $clause->setModifier($modifier);
        if ($label !== null) {
            switch ($label->type) {
                case self::T_IDENT: $label = $label->text; break;
                default: throw new GrammarError("Unknown label type '{$label->type}'");
            }
            $clause->setName($label);
        }
        return $clause;
    }

    private function _Label() {
        if (!$this->textIs(':', 1)) {
            return null;
        }
        if (in_array($this->type(), [self::T_IDENT, self::T_STRING])) {
            $label = $this->accept();
            $this->accept();
            return $label;
        }
        return null;
    }

    private function _Predicate() {
        if (!$this->typeIs(self::T_PREDICATE)) {
            return null;
        }
        $res = $this->accept();
        return $res;
    }

    private function _Clause() {
        if (in_array($this->type(), [self::T_STRING, self::T_IDENT, self::T_REGEX, self::T_SNIPPET])) {
            if ($this->typeIs(self::T_BEGIN, 1)) {
                // look forward and avoid parsing a rule name as a clause
                return null;
            }
            if ($this->typeIs(self::T_STRING, 1) && $this->typeIs(self::T_BEGIN, 2)) {
                // look forward and avoid parsing a rule name as a clause
                return null;
            }

            $clause = $this->accept();

            switch ($clause->type) {
                case self::T_IDENT:
                    switch ($clause->text) {
                        case '^' : return new SOF(Clause::NONE, null);
                        case '$' : return new EOF(Clause::NONE, null);
                        case '.' : return new Any(Clause::NONE, null);
                        default : return new Identifier($clause->text, Clause::NONE, null);
                    }

                case self::T_STRING:
                    return new Text($clause->value(), Clause::NONE, null);

                case self::T_SNIPPET:
                    return new Snippet($clause->value(), Clause::NONE, null);

                case self::T_REGEX:
                    return new Regex($clause->value(), Clause::NONE, null);

                default :
                    throw new GrammarError("Unknown clause type={$clause->type} $operator$clause");
            }
        }
        return $this->_BracedClause();
    }

    private function _BracedClause() {
        if (!$this->typeIs(self::T_BRACE_OPEN)) {
            return null;
        }

        $openOffset = $this->getOffset();
        $open = $this->accept();

        $clauses = $this->_Clauses();
        if ($clauses === null) {
            throw new GrammarError("Expected clauses inside sub-clause");
        }

        $close = ['<' => '>', '(' => ')', '[' => ']', '{' => '}'][$open->text];
        if (!$this->typeIs(self::T_BRACE_CLOSE)) {
            $this->setOffset($openOffset);
            throw new GrammarError("Unclosed `".$open->value()."`");
            throw new GrammarError("Expecting $close or another clause inside sub-clause");
        }

        if (!$this->textIs($close)) {
            throw new GrammarError("Mismatched closing brace {".$this->token()->text."}, expecting $close");
        }
        $this->accept();
        return $clauses;
    }

    private function _Operator() {
        if ($this->typeIs(self::T_OPERATOR)) {
            return $this->accept();
        }
        return null;
    }

}
