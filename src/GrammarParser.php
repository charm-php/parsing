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
use Charm\Parsing\Util\StringTools;
use Charm\Parsing\Error\State\ParseError;
use Charm\Parsing\Error\AbstractParseError;
use Charm\Parsing\Error\CustomError;

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
    const T_IDENT       = 1;
    const T_STRING      = 2;
    const T_REGEX       = 3;
    const T_NUMBER      = 4;
    const T_SNIPPET     = 5;
    const T_COMMENT     = -6;
    const T_BEGIN       = 7;
    const T_END         = 8;
    const T_PREDICATE   = 9;
    const T_OPERATOR    = 10;
    const T_COMMIT      = 11;
    const T_COLON       = 12;
    const T_BRACE_OPEN  = 13;
    const T_BRACE_CLOSE = 14;
    const T_OR          = 15;
    const T_TOKEN       = 16;
    const T_WHITESPACE  = -17;
    const T_PRAGMA      = 18;

    protected $options;
    
    public function __construct(GrammarParserOptions $options=null) {
        if ($options === null) {
            $options = new GrammarParserOptions();
        }
        $this->options = $options;
    }

    public function getLexer(): LexerInterface {
        return new Lexer([
            new LexerPattern(
                self::T_COMMENT, 'T_COMMENT',
                '(?<COMMENT>(\#|\/\/)[^\n]*+|\/\*((?!\*\/)[\s\S])*\*\/)'
                ),

            new LexerPattern(
                self::T_IDENT, 'T_IDENT',
                '[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+|\^|\$'
                ),

            new LexerPattern(
                self::T_STRING, 'T_STRING',
                <<<'RE'
                (?<STRING>"(\\[\\"]|[^"\\]+|[^"])*+"|'(\\[\\']|[^'\\]+|[^'])*+')
                RE,
                function($match): string {
                    $q = $match->text[0];
                    $match = substr($match->text, 1, -1);
                    return strtr($match, [ "\\\\" => "\\", "\\$q" => $q ]);
                }
                ),

            new LexerPattern(
                self::T_REGEX, 'T_REGEX',
                <<<'RE'
                \/(\\[\s\S]|[^\/\n])*+\/|\[(\\[\S\s]|[^]])*+\]
                RE,
                function($match) {
                    if ($match->text[0] === '[') {
                        return '/['.preg_quote(substr($match->text, 1, -1), '/').']/';
                    }
                    return $match->text;
                }
                ),

            new LexerPattern(
                self::T_PRAGMA, 'T_PRAGMA',
                <<<'RE'
                %[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\-]*
                RE,
                function($match) {
                    return substr($match->text, 1);
                }
                ),

            new LexerPattern(
                self::T_NUMBER, 'T_NUMBER',
                '(0|[1-9][0-9]*+)(\.[0-9]++)?',
                function($match) {
                    if (is_int(strpos($match->text, '.'))) {
                        return (float) $match->text;
                    }
                    return (int) $match->text;
                }),

            new LexerPattern(
                self::T_SNIPPET, 'T_SNIPPET',
                <<<'RE'
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
                }),

            new LexerPattern(self::T_BEGIN, 'T_BEGIN', '::=|:=|:=|->|='),
            new LexerPattern(self::T_END, 'T_END', ';'),
            new LexerPattern(self::T_PREDICATE, 'T_PREDICATE', '&|!'),
            new LexerPattern(self::T_OPERATOR, 'T_OPERATOR', '\*|\+|\?'),
            new LexerPattern(self::T_COLON, 'T_COLON', ':'),
            new LexerPattern(self::T_COMMIT, 'T_COMMIT', '@'),
            new LexerPattern(self::T_BRACE_OPEN, 'T_BRACE_OPEN', <<<'RE'
                [[({<]
                RE),
            new LexerPattern(self::T_BRACE_CLOSE, 'T_BRACE_CLOSE', <<<'RE'
                [\])}>]
                RE),

            new LexerPattern(self::T_OR, 'T_OR', '\||\/'),

            new LexerPattern(self::T_TOKEN, 'T_TOKEN', '\.|,'),

            new LexerPattern(self::T_WHITESPACE, 'T_WHITESPACE', '\\s+'),
        ]);
    }

    protected function start() {
        try {
            $grammar = $this->_CPEG();
        } catch (AbstractParseError $e) {
            $grammarOffset = $this->getByteOffsetAt($this->offset - 1);

            $st = new StringTools($this->source);
            $e->setFileInfo($this->filename, $st->getLineNumberAt($grammarOffset), $st->getColumnNumberAt($grammarOffset));
            throw $e;
        } catch (CustomError $e) {
            throw new ParseError($this, $e->getMessage().' on :location', $e->getCode(), $e);
        }

        if ($grammar === null) {
            return null;
        }

        if (!$this->eof()) {
            throw new ParseError($this, 'Expected end-of-file on :location, found :source_description');
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
                throw new ParseError($this, "Rules must contain clauses on :location");
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
            throw new ParseError($this, "Expected ';' or clauses on :location, got :source_description");
        }
        $this->accept();

        done:
        $rule = new Rule($ruleName->getValue(), $clauses);

        if ($description !== null) {
            $rule->setDescription($description->getValue());
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
            $subs = [];
            $pragmas = [];
            foreach ($clauses as $clause) {
                if ($clause instanceof Pragma) {
                    $pragmas[] = $clause;
                } else {
                    $subs[] = $clause;
                }
            }

            if (count($subs) === 1) {
                foreach ($pragmas as $pragma) {
                    $subs[0]->addPragma($pragma);
                }
                return $subs[0];
            }

            $s = new Sequence(Clause::NONE, null);
            foreach ($pragmas as $pragma) {
                $s->addPragma($pragma);
            }
            foreach ($subs as $subClause) {
                $s[] = $subClause;
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
                    throw new ParseError($this, "Expected '|' on :location, got :source_description");
                }
                $this->accept();
                $groups[] = $buildSequence($result);
                $result = [];
            } elseif ($this->typeIs(self::T_COMMIT)) {
                $this->accept();
                $result[] = new Commit(Clause::NONE, null);
            } elseif (null !== ($clause = $this->_FullClause())) {
                $result[] = $clause;
            } elseif (null !== ($pragma = $this->_Pragma())) {
                $result[] = $pragma;
            } else {
                break;
            }
        }
        if ($result === null) {
            return null;
        }
        if ($result === []) {
            throw new ParseError($this, "Expected clause on :location, got :source_description");
        }
        if ($groups === null) {
            return $buildSequence($result);
        }
        $groups[] = $buildSequence($result);
        return $buildOrderedChoice($groups);
    }

    private function _Pragma() {
        if (!$this->typeIs(self::T_PRAGMA)) {
            return null;
        }

        $pragmaToken = $this->accept();
        $params = [];
        if ($this->textIs('(')) {
            $this->accept();
            if (!$this->textIs(')')) {
                do {
                    if (!in_array($this->type(), [self::T_IDENT, self::T_STRING, self::T_NUMBER, self::T_REGEX])) {
                        throw new ParseError($this, "Expected identifier, string, number or regex on :location, got :source_description");
                    }
                    $params[] = $this->accept();
                } while ($this->textIs(',') && $this->accept());
            }
            if (!$this->textIs(')')) {
                throw new ParseError($this, "Expected ')' on :location, got :source_description");
            }
            $this->accept();
        } elseif ($this->textIs('=')) {
            $this->accept();
            if (!in_array($this->type(), [self::T_IDENT, self::T_STRING, self::T_NUMBER, self::T_REGEX])) {
                throw new ParseError($this, "Expected identifier, string, number or regex on :location, got :source_description");
            }
            $params[] = $this->accept();
        }

        return Pragma::create($pragmaToken->getValue(), $params);
    }

    private function _FullClause() {
        if (!in_array($this->type(), [self::T_IDENT, self::T_STRING, self::T_REGEX, self::T_SNIPPET, self::T_PREDICATE, self::T_BRACE_OPEN])) {
            return null;
        }
        $label = $this->_Label();
        $predicate = $this->_Predicate();
        $clause = $this->_Clause();

        if ($clause === null && ($label !== null || $predicate !== null)) {
            throw new ParseError($this, "Excpected clause on :location, got :source_description");
        } elseif ($clause === null) {
            return null;
        }

        $operator = $this->_Operator();

        $modifier = 0;

        if ($predicate !== null) {
            switch ($predicate->text) {
                case '&': $modifier |= Clause::AND; break;
                case '!': $modifier |= Clause::NOT; break;
                default :
                    throw new ParseError($this, "Illegal predicate '{$predicate->text}' on :location");
            }
        }

        if ($operator !== null) {
            switch ($operator->text) {
                case '*': $modifier |= Clause::OPTIONAL | Clause::REPEATING; break;
                case '+': $modifier |= Clause::REPEATING; break;
                case '?': $modifier |= Clause::OPTIONAL; break;
                default :
                    throw new ParseError($this, "Illegal operator '{$operator->text}' on :location");
            }
        }
        $clause->setModifier($modifier);
        if ($label !== null) {
            switch ($label->type) {
                case self::T_IDENT: $label = $label->text; break;
                default:
                    throw new ParseError($this, "Expected identifier on :location, got :source_description");
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
                    return new Text($clause->getValue(), Clause::NONE, null);

                case self::T_SNIPPET:
                    return new Snippet($clause->getValue(), Clause::NONE, null, $this->options->base_namespace);

                case self::T_REGEX:
                    return new Regex($clause->getValue(), Clause::NONE, null);

                default :
                    throw new ParseError($this, "Unexpected :source_description on :location");
            }
        }
        return $this->_BracedClause();
    }

    private function _BracedClause() {
        if (!$this->typeIs(self::T_BRACE_OPEN)) {
            return null;
        }

        $openOffset = $this->offset;
        $open = $this->accept();

        $clauses = $this->_Clauses();

        if ($clauses === null) {
            throw new ParseError($this, "Expected clause on :location, got :source_description");
        }

        if (!($clauses instanceof Sequence) && !($clauses instanceof OrderedChoice)) {
            /**
             * This was intentionally put inside braces, so we'll ensure it is a sequence
             */
            $newClauses = new Sequence(Clause::NONE, null);
            $newClauses[] = $clauses;
            $newClauses[] = new Snippet('return $_0;', Clause::NONE, null);
            $clauses = $newClauses;
        }

        $close = ['<' => '>', '(' => ')', '[' => ']', '{' => '}'][$open->text];
        if (!$this->typeIs(self::T_BRACE_CLOSE)) {
            $this->offset = $openOffset;
            throw new ParseError($this, "Expected $close on :location, found :source_description");
        }

        if (!$this->textIs($close)) {
            throw new ParseError($this, "Brace mismatch, expected '{$close}' on :location, found :source_description");
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
