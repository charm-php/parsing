<?php
namespace Charm\Parsing;

use Charm\Parsing\Clause\{
    Identifier,
    Sequence,
    OrderedChoice,
    Text,
    Regex,
    Any,
    EOF,
    SOF,
    Snippet
};
/**
 * Parser for PEG-style grammar string into a GrammarInterface instance.
 *
 * Format
 * ======
 *
 * The parser tries to accept different notation styles by allowing a variety of different symbols for the same cause.
 *
 * Quick Start
 * -----------
 * We reccommend writing PEG grammars in PHP using "nowdoc" format to avoid problems with double escaped slashes and different
 * quoting styles. {@see https://www.php.net/manual/en/language.types.string.php#language.types.string.syntax.nowdoc}
 *
 * This example illustrates many of the capabilities of the grammar parser:
 * ```
 * <?php
 * require 'vendor/autoload.php';
 * $grammarParser = new Charm\Parsing\GrammarParser(Grammar::class);
 *
 * $expressionGrammar = $parser->parse(<<<'PEG'
 *
 *     Expression <-    ^ SumExpression $                                   #  This is a comment and the hat '^' and '$' means start/end of file
 *     SumExpression:   MulExpression ( ("+" / "-") MulExpression )*        // Here we use ':' instead of '<-' and show nested sub-expressions.
 *     MulExpression =  ExpExpression ( ("*" / "/") ExpExpression )*        #  Here we use '='. The '*' symbol means "zero or more".
 *     ExpExpression:                                                       #  Rules can span multiple lines.
 *       Value ('^' Value)*                                                 #  Single quotes are allowed
 *     Value:
 *       Float /                                                            #  Forward slash / means "or"
 *       Integer |                                                          #  Alternatively you can use the pipe symbol |
 *       Identifier
 *     Float:           Integer "." Integer
 *     Integer:         /[1-9][0-9]*|0/                                     #  Regular expressions between slash symbols.
 *     DoubleQuote:     "\""                                                #  Backslash escapes the quoting symbol (also for single quoted strings)
 *     QuoteSymbol:     ["']                                                #  Character ranges are supported
 *     EscapedQuote:    "\\" QuoteSymbol                                    #  Inside strings the backslash must be escaped
 *     PEG);
 *
 * $expressionParser = new Charm\Parser($expressionGrammar);
 * // Note that you can cache the parser by serializing or using var_export.
 *
 * $tree = $expressionParser->parse('10*2-20');
 * ```
 *
 * Production Rules
 * ----------------
 * The following are equivalent. Whitespace is ignored unless it belongs to a token.

 * ```
 * Number <- $clauses[]                         # This is the original style defined by Bryan Ford who formalized PEG grammars.
 * Number := $clauses[]
 * Number = $clauses[]
 * Number : $clauses[]
 * Number <-                                    # Multiple lines may be used to improve readability
 *   $clauses[]
 * ```
 *
 * Clauses
 * -------
 * Clauses are the "expressions" that must match at any given offset in the file you're parsing. A clause may refer to another
 * production rule, in which case they are known as 'non terminal' clauses. Clauses that perform an actual match of the source
 * code are known as 'terminal' clauses.
 *
 * Terminal Clauses
 * ----------------
 * The following list shows all variations of terminal clauses currently supported (except for a few built-int clauses we'll
 * explain next.
 *
 * ```
 * Number       <- /[1-9][0-9]*|0](\.[0-9]*)/   # Regular expressions begin and end with /.
 * True         <- "true"                       # A literal match
 * False        <- 'false'                      # Single and double quotes are allowed
 * DoubleQuote  <- "\""                         # Quotes are escaped using backslashes
 * Digit        <- [0-9]                        # Character range matches
 * AOrB         <- [abAB]                       # Single character matches, escape the ] using a backslash when needed: [[\]]
 * Hex          <- "0x" /[0-9a-fA-F]+/          # Multiple terminal clauses can follow each other
 * ```
 *
 * Special Terminal Clauses
 * ------------------------
 * Some terminal symbols have special meaning in the grammar. They can mark the start '^' or the end '$' of the file, or "epsilon"
 * which means that it matches nothing at all and always succeeds.
 *
 * Example grammar for a file containing a single integer number without any whitespace.
 * ```
 * Number <- ^ /([1-9][0-9]*)/ $
 * ```
 *
 * Epsilon is the greek letters 'Ε' and 'ε', and literally means 'the smallest positive number'. It is used in grammars to create
 * a clause which is the smallest possible successful match. You can achieve a similar result by using an empty string terminal ''.
 *
 * In PEGs the epsilon symbol is rarely needed, but they are supported to simplify porting a grammar which requires epsilon.
 *
 * ```
 * AnOptionalMatch <- "Hello" / ε               # Either "Hello" or nothing is a successful match.
 * ```
 *
 * Non-Terminal Clauses
 * --------------------
 * Non terminal clauses are simply identifiers that call upon other production rules. For example:
 * ```
 * Value = Number / True / False / Hex          # Returns a Value containing either Number / True / False or Hex
 * ```
 *
 * Ordered Choice Clauses
 * ----------------------
 * Often you'll want to allow multiple different rules. This is annotated by the forward slash or pipe symbol:
 *
 * On a single line:
 * ```
 * Boolean      <- True / False
 * ```
 *
 * Using whitespace for readability:
 * ```
 * Boolean:     True
 *            / False
 * Boolean:
 *   True /
 *   False
 * ```
 *
 * ```
 * Boolean      <- True / False                 # First tries to match with rule "True". If that fails, it tries the next rule "False"
 * True         <- "true"
 * False        <- "false"
 * ```
 *
 * Repeating or optional clauses
 * ------------------------------
 * Clauses can have modifiers which change their behavior to repeat matches or make them optional.
 *
 * An optional match marked by a '?' suffix:
 * ```
 * DollarsOptional: "$"? Number                 # The "$" terminal is not required for the rule to match
 * ```
 * Match one or more times marked by a '+' suffix:
 * ```
 * Number:          Digit+
 * ```
 * Match zero or more times marked by a '*' suffix:
 * ```
 * Hex:             "0x" [0-9a-f] [0-9a-f]*
 * ```
 * Note that the '*' suffix is essentially the same as a combination of '+' and '?'
 * ```
 * IgnoreSpace:     " "+?
 * ```
 *
 * Nested clauses
 * --------------
 * Nested clauses are an important tool for parsing things like an mathematical expression, an argument
 * list or an array of values. Use parentheses to create nested expressions.
 *
 * ```
 * CallStatement:   FunctionName "(" Arguments ")"
 * Arguments:
 *   Argument ( "," Argument )*                 # Both "," and Argument must be repeated - or neither
 * Argument:
 *   CallStatement /
 *   Identifier /
 *   Number
 * FunctionName:    Identifier
 * Identifier:      /([a-zA-Z][a-zA-Z0-9]*)/
 * Number:          /([1-9][0-9]*|0)(\.[0-9]+)?/
 * ```
 */
class GrammarParser implements ParserInterface {

    const TOKENIZER_REGEX = <<<'TOKENIZER'
        /
        (?<Rule>[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(?!:[\S!&])(?=\s*(:=|=(?!>)|<-|:)))
        |(?<Suffix>(?<!\s)[+*?i])
        |(?<Identifier>([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+)(?!\:)|\$|\^|\.|Ε|ε)
        |(?<String>"(\\\\|\\"|[^"])*"|'(\\\\|\\'|[^'])*')
        |(?<Comment>(\/\/|\#)[^\n]*|\/\*((?&Comment)|.)*?\*\/)
        |(?<Regex>\/(\\\\|\\\/|[^\/ ])*\/|\[(\\\\|\\]|[^\]])+])
        |(?<Prefix>[!&]|[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*\:)
        |(?<Or>\/|\|)
        |(?<Begin>:=|=(?!>)|<-|:)
        |(?<Sequence>\((?R)+\))
        |(?<Snippet>{{[\s\S]*?}})
        |(?<Whitespace>\s+)
        /xmu
        TOKENIZER;

    /**
     * @param class-name $grammarClass The grammar class we'll be creating.
     */
    public function __construct(string $grammarClass) {
        $this->grammarClass = $grammarClass;
    }

    public static function fromString(string $grammar): static {
        throw new \LogicException("Use `Charm\Parser::fromString()` instead.", 255);
    }

    public function getGrammar(): GrammarInterface {
        throw new InternalError("The GrammarParser is not currently a complete self-hosted parser and can't be used this way.");
    }

    /**
     * Parse a PEG-style grammar into a GrammarInterface representation.
     */
    public function parse(string $grammarString): GrammarInterface {
        $tokens = static::tokenize($grammarString);

        $rules = [];
        foreach (static::ruleMaker($tokens) as $ruleInfo) {
            $name = $ruleInfo[0]->name;
            $ruleName = $ruleInfo[0]->text;
            $clause = static::ruleClauseMaker($ruleInfo[1], Clause::NONE, $name);
            $rule = new Rule($ruleName, $clause);
            $clause->setRule($rule);

            if (isset($rules[$ruleName])) {
                $rules[$ruleName]->addAlternativeClause($rule);
            } else {
                $rules[$ruleName] = $rule;
            }
        }

        $className = $this->grammarClass;
        return new $className($rules);
    }

    /**
     * Splits the token stream into an array of arrays like [ $ruleNameToken, $arrayOfClauseTokens ]
     *
     * @param $tokens An array of Token objects as parsed from the grammar source code.
     */
    protected static function ruleMaker(array $tokens) {
        $ruleToken = null;
        $buffer = [];
        $result = [];

        foreach ($tokens as $token) {
            if ($token->kind === 'Rule') {
                if ($ruleToken !== null) {
                    $result[] = [$ruleToken, $buffer];
                    $buffer = [];
                }
                $ruleToken = $token;
            } elseif ($token->kind === 'Begin' || $token->kind === 'Comment' || $token->kind === 'Whitespace') {
            } elseif ($token->kind === 'Identifier' || $token->kind === 'Or' || $token->kind === 'Regex' || $token->kind === 'String' || $token->kind === 'Sequence' || $token->kind === 'Snippet') {
                $buffer[] = $token;
            } else { // FRODE
var_dump($token);
die("UNKNOWN TOKEN");
                $buffer[] = $token;
            }
        }
        if ($ruleToken !== null) {
            $result[] = [ $ruleToken, $buffer ];
        }
        return $result;
    }

    /**
     * Tokenizes the grammar source code for parsing. This functionality would ideally
     * use Charm\Parser, but in this case the chicken came before the egg.
     *
     * @param $grammarString A string of the grammar we're going to parse
     * @param $offset The start offset of the string (in the case of recursively parsing a sub-string)
     * @return array<Token>
     */
    protected static function tokenize(string $grammarString, int $offset=0): array {
        $res = preg_match_all(static::TOKENIZER_REGEX, $grammarString, $matches, PREG_SET_ORDER, 0);
        if (!is_int($res)) {
            throw new InternalError("The tokenizer regex contains an error: ".preg_last_error_msg(), preg_last_error());
        }
        $tokens = [];
        $token = null;
        $isAnd = false;
        $isNot = false;
        $name = null;
        foreach ($matches as $match) {
            if (!empty($match['Whitespace']) || !empty($match['Comment'])) {
                $offset += strlen($match[0]);
                continue;
            }
            if (!empty($match['Rule'])) {
                if ($token !== null) {
                    $tokens[] = $token;
                    $token = null;
                }
                $isAnd = false;
                $isNot = false;
                $name = null;
                $tokens[] = new Token($match[0], 'Rule', $offset, [
                    'name' => $name,
                    'isAnd' => false,
                    'isNot' => false,
                    'isOptional' => false,
                    'isOneOrMore' => false,
                    'isZeroOrMore' => false,
                    'isCaseInsensitive' => false,
                ]);
            } elseif (
                !empty($match[$kind = 'Identifier']) || 
                !empty($match[$kind = 'String']) ||
                !empty($match[$kind = 'Regex']) ||
                !empty($match[$kind = 'Sequence']) ||
                !empty($match[$kind = 'Snippet'])
            ) {
                if ($token !== null) {
                    $tokens[] = $token;
                }
                $token = new Token($match[0], $kind, $offset, [
                    'name' => $name,
                    'isAnd' => $isAnd,
                    'isNot' => $isNot,
                    'isOptional' => false,
                    'isOneOrMore' => false,
                    'isZeroOrMore' => false,
                    'isCaseInsensitive' => false,
                ]);
                $name = null;
                $isAnd = false;
                $isNot = false;
            } elseif (!empty($match['Prefix'])) {
                if ($token !== null) {
                    $tokens[] = $token;
                    $token = null;
                    $name = null;
                    $isAnd = false;
                    $isNot = false;
                }
                if ($match[0] === '&') {
                    $isAnd = true;
                } elseif ($match[0] === '!') {
                    $isNot = true;
                } elseif (substr($match[0], -1) === ':') {
                    $name = substr($match[0], 0, -1);
                } else {
                    throw new \Exception("Unknown prefix '".$match[0]."'");
                }
            } elseif (!empty($match['Or'])) {
                if ($token !== null) {
                    $tokens[] = $token;
                    $token = null;
                    $name = null;
                    $isAnd = false;
                    $isNot = false;
                }
                $tokens[] = new Token('/', 'Or', $offset, [
                    'name' => null,
                    'isAnd' => false,
                    'isNot' => false,
                    'isOptional' => false,
                    'isOneOrMore' => false,
                    'isZeroOrMore' => false,
                    'isCaseInsensitive' => false,
                ]);
            } elseif (!empty($match['Suffix']) && $token !== null) {
                if ($match[0] === '+') {
                    $token->isOneOrMore = true;
                } elseif ($match[0] === '?') {
                    $token->isOptional = true;
                } elseif ($match[0] === '*') {
                    $token->isZeroOrMore = true;
                } elseif ($match[0] === 'i') {
                    $token->isCaseInsensitive = true;
                } else {
                    throw new \Exception("Unknown suffix token ".$match[0]);
                }
            } elseif (!empty($match['Begin'])) {
                // ignored
            } else {
var_dump($match);
                throw new GrammarError("Unexpected ".substr($grammarString, $offset, 10)."[...] at offset $offset");
            }
            $offset += strlen($match[0]);
        }
        if ($token !== null) {
            $tokens[] = $token;
        }
        return $tokens;
    }

    /**
     * Creates Clause objects from an array of Tokens and attached prefix and suffix modifiers to the returned token.
     *
     * Multiple clauses will be combined into a Sequence or an OrderedClause object.
     *
     * @param $tokens An array of Token object
     * @param $modifier An integer according to Clause::* constants which will be attached to the returned clause.
     */
    protected static function ruleClauseMaker(array $tokens, int $modifier, ?string $name): Clause {
        $orderedChoice = null;
        $current = new Sequence($modifier, $name);
        foreach ($tokens as $token) {
            if ($token->kind === 'Begin' || $token->kind === 'Comment' || $token->kind === 'Whitespace') {
                continue;
            }

            if ($token->kind === 'Identifier') {
                /**
                 * Some special tokens are parsed as identifiers and handled here. This could be
                 * refactored into a separate token kind named Keyword at a later time.
                 */
                if ($token->text === '^') {
                    $current->addClause(new SOF($token->getModifier(), $token->name));
                } elseif ($token->text === '$') {
                    $current->addClause(new EOF($token->getModifier(), $token->name));
                } elseif ($token->text === '.') {
                    $current->addClause(new Any($token->getModifier(), $token->name));
                } elseif ($token->text === 'Ε' || $token->text === 'ε' || $token->text === 'EPSILON') {
                    $current->addClause(new Nothing($token->getModifier(), $token->name));
                } else {
                    $current->addClause(new Identifier($token->text, $token->getModifier(), $token->name));
                }
            } elseif ($token->kind === 'Sequence') {
                $subString = substr($token->text, 1, -1);
                $subTokens = self::tokenize($subString, $token->offset);
                $clause = self::ruleClauseMaker($subTokens, $token->getModifier(), $token->name);
                $current->addClause($clause);
            } elseif ($token->kind === 'Or') {
                if ($orderedChoice === null) {
                    $orderedChoice = new OrderedChoice($current->getModifier(), $current->getName());
                    $current->setModifier(Clause::NONE);
                    $current->setName(null);
                }
                $orderedChoice->addClause(static::shorten($current));
                $current = new Sequence(Clause::NONE, null);
            } elseif ($token->kind === 'Regex') {
                if ($token->text[0] === '[') {
                    $current->addClause(new Regex('/'.$token->text.'/', $token->getModifier(), $token->name));
                } else {
                    $current->addClause(new Regex($token->text, $token->getModifier(), $token->name));
                }
            } elseif ($token->kind === 'String') {
                $escapeChar = substr($token->text, 0, 1);
                $innerString = strtr(substr($token->text, 1, -1), [
                    '\\'.$escapeChar => $escapeChar,
                ]);
                $current->addClause(new Text($innerString, $token->getModifier(), $token->name));
            } elseif ($token->kind === 'Snippet') {
                $current->addClause(new Snippet(substr($token->text, 2, -2), $token->getModifier(), $token->name));
            } else {
                die("Unknown token kind ".$token->kind."\n");
            }
        }
        if ($orderedChoice !== null) {
            $orderedChoice->addClause(static::shorten($current));
            return $orderedChoice;
        } else {
            return static::shorten($current);
            if (count($current) === 1) {
                return $current[0];
            }
            return $current;
        }
    }

    protected static function shorten(Clause $clause) {
        while ($clause instanceof Sequence && count($clause) === 1) {
/*
            if (
                ($clause->getModifier() !== 0 && $clause[0]->getModifier() !== 0)
            ) {
                break;
            }
*/
            if ($clause->getName() !== null) {
                $clause[0]->setName($clause->getName());
            }
            $clause[0]->setModifier($clause[0]->getModifier() | $clause->getModifier());
            $clause = $clause[0];
        }
        return $clause;
    }

}
