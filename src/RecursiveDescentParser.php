<?php
namespace Charm\Parsing;

use Charm\Vector;
use Charm\Parsing\Util\{
    StringTools,
    ExceptionTools,
};

/*

TIPS FOR WRITING PARSE FUNCTIONS

 1. Return `null` when lookahead indicates that a parse will succeed.

 2. Return an array, an AST node object or a LexerToken when a parse succeds.

 3. If a rule fails parsing, throw an exception with a helpful error message.


Utility functions

 *  `$this->accept(): mixed` will return the value at the current parse offset.
    It will throw an exception if we're at the end of input.


Terminal parsing

    Generally, terminals are parsed using the `is()` and the `accept()` functions
    above. We don't recommend writing functions for parsing single terminals, unless
    it returns an optional value.

    If the terminal function may return a positive result `null` then you should
    wrap the value in an object (for example `return new Charm\Parsing\BoxedResult(null)`).


Repeating terminal clauses

    ```
    // OneOrMoreDigits ::= T_DIGIT+
    function _OneOrMoreDigits() {
        if (!$this->is('T_DIGIT')) {
            return null;
        }
        $result = [];
        do {
            $result[] = $this->accept();
        } while ($this->is('T_DIGIT'));
        return $result;
    }

    // ZeroOrMoreDigits ::= T_DIGIT*
    function _ZeroOrMoreDigits() {
        $result = [];
        while ($this->is('T_DIGIT')) {
            $result[] = $this->accept();
        }
        return $result;
    }
    ```


These are some example implementations
    for various production rules:

    ```
    // Rule := "while" Expr Statement;
    function _Rule() {
        if (!$this->is(null, 'while')) {
            return null;
        }

        $while = $this->accept();

        if (null === ($expression = $this->_Expr())) {
            throw new \Exception("Expected expression");
        }

        if (null === ($statement = $this->_Statement())) {
            throw new \Exception("Expected a statement");
        }

        return new WhileExpression($expression, $statement, $while->offset);
    }

    // ValueList := Value ("," Value)*
    function _ValueList() {
        $value = $this->_Value();
        if ($value === null) {
            return null;
        }

        $values = [ $value ];

        while ($this->is(null, ',')) {
            $comma = $this->accept();
            $values
    }
    ```



The simplest kind of terminal function simply calls `$this->accept('TOKEN_KIND')`.


Rule ::= Expr ";"

    /**
     * The function should decide by looking at the first token if it
     * is able to parse. If it isn't, it returns NULL.
     *
     * Once the function has decided
    function _Rule() {


 */
abstract class RecursiveDescentParser implements ParserInterface {

    /**
     * How to extend/build a recursive descent parser on top of this abstract
     * class.
     *
     * 1. Define a constructor which configures the built-in lexer by calling
     *    the `addTerminal()` method for every terminal symbol you wish to parse.
     *
     *    for example
     *
     *    ```
     *    $this->addTerminal(
     *        'T_IDENTIFIER',                       // token type
     *        '[a-zA-Z_][a-zA-Z0-9_]*',             // regular expression
     *        function($t) { return $t->text; }     // optional LexerToken transformer function
     *    );
     *    $this->addTerminal(
     *        'T_BOOL',
     *        'true|false',
     *        function($t) { return ['true' => true, 'false' => false][$t->text]; }
     *    );
     *    ```
     *
     * 2. Declare the `start()` method, which will begin parsing the source string
     *    and return the parse result. Generally your sub-class will consist of
     *    one function for every production rule in your grammar.
     *
     * The parser functions can use several utility functions to perform parsing
     * of the source file:
     *
     * `accept()` will return the `LexerToken` at the current offset and move forward
     */

    /**
     * *** OVERRIDE ***
     */

    /**
     * The initial parsing function. It normally just calls the first production rule function.
     */
    abstract protected function start();

    /**
     * *** INTERNAL UTILITY FUNCTIONS ***
     */

    /**
     * The token we're currently parsing. This token acts as the lookahead token
     */
    protected final function addIgnore(int $type): void {
        $this->ignores[$type] = $type;
    }

    protected final function addTerminal(int $type, string $regex, callable $processor=null): void {
        if (isset($this->terminals[$type])) {
            throw new \LogicException("Terminal type '$type' is already added");
        }
        if ($processor !== null) {
            $processor = \Closure::fromCallable($processor)->bindTo(null, null);
        }
        $this->terminals[$type] = [ $regex, $processor, "type$type" ];
    }

    protected final function byteOffset(): int {
        if ($this->offset >= $this->length) {
            return strlen($this->source);
        }
        return $this->tokens[$this->offset]->offset;
    }

    protected final function getOffset(): int {
        return $this->offset;
    }

    protected final function setOffset(int $offset) {
        $this->offset = $offset;
    }

    protected final function eof(): bool {
        return $this->offset >= $this->length;
    }

    public final function parseFile(string $filename) {
        return $this->parse(file_get_contents($filename), $filename);
    }

    /**
     * Begins parsing by tokenizing the source string and calling `$this->start()`.
     */
    public function parse(string $source, string $filename=null) {
        $this->state = [];
        $this->offset = 0;
        $this->tokenizeString($source);
        $this->source = $source;
        $this->filename = $filename;
        try {
            return $this->start();
        } catch (ExceptionInterface $e) {
            /**
             * Exception was thrown while parsing the grammar, so we rewrite the exception
             * to provide the correct location data
             */
            $offendingToken = $this->tokens[$this->offset - 1];
            $st = new StringTools($source);

            $et = new ExceptionTools($e);
            $et->setFileName($filename);
            $et->setLine($st->getLineNumberAt($offendingToken->offset));

            throw $e;
        }
    }

    public final function &getState(): array {
        return $this->state;
    }

    private $terminals = [];    // terminal patterns
    private $ignores = [];      // ignore patterns

    protected $source;            // the original source
    protected $filename;          // the filename we're parsing - if provided

    private $tokens;            // regex matches
    private $offset;            // the token we're currently parsing
    private $length;            // the number of tokens

    /**
     * Return the string at the current offset and step forward
     */
    protected final function accept(): LexerToken {
        return $this->tokens[$this->offset++];
    }

    protected final function type(int $offset=0): int {
        return $this->tokens[$offset + $this->offset]->type ?? -1;
    }

    protected final function text(int $offset=0): string {
        return $this->tokens[$offset + $this->offset]->text ?? '';
    }

    protected final function typeIs(int $type, int $offset=0): bool {
        if ($this->offset + $offset < $this->length) {
            return $this->tokens[$this->offset + $offset]->type === $type;
        }
        return false;
    }

    protected final function textIs(string $text, int $offset=0): bool {
        if ($this->offset + $offset < $this->length) {
            return $this->tokens[$this->offset + $offset]->text === $text;
        }
        return false;
    }

    /**
     * Lookahead deeper
     */
    protected final function peek(int $offset): ?LexerToken {
        return $this->tokens[$this->offset + $offset] ?? null;
    }

    private static function esc(string $text): string {
        return strtr($text, [
            "\n" => "\\n",
            "\r" => "\\r",
            "\t" => "\\t",
            "\f" => "\\f",
            "\v" => "\\v",
        ]);
    }

    private static function unesc(string $text): string {
        return strtr($text, [
            "\\n" => "\n",
            "\\r" => "\r",
            "\\t" => "\t",
            "\\f" => "\f",
            "\\v" => "\v",
        ]);
    }

    private static function trimDots(string $text, int $length): string {
        if (mb_strlen($text) > $length) {
            return mb_substr($text, 0, $length -3).'...';
        }
        return $text;
    }

    private static function renderToken(LexerToken $token): string {
        return "`".self::esc(self::trimDots($token->text, 15))."` (".((string) $token->type).")";
    }

    /**
     * Highly optimized tokenizer. Bad performance is because of poorly optimized regexes.
     */
    private function tokenizeString(string $source): void {
        $reParts = [];
        foreach ($this->terminals as $name => $def) {
            $reParts[] = '(?<'.preg_quote($def[2], '/').'>(?>'.$def[0].'))';
        }
        $reParts[] = '(?<__UNKNOWN__>[\s\S])';
        $re = '/'.implode('|', $reParts).'/ux';
        $count = preg_match_all($re, $source, $matches, PREG_PATTERN_ORDER|PREG_UNMATCHED_AS_NULL);

        if (!is_int($count)) {
            if (function_exists('preg_last_error_msg')) {
                $message = preg_last_error_msg();
            } else {
                $message = preg_last_error();
            }
            throw new RegexError($message, preg_last_error(), $re);
        }

        // calculate offsets
        $offsets = [];
        $length = 0;
        foreach ($matches[0] as $s) {
            $offsets[] = $length;
            $length += strlen($s);
        }

        // check for unknown matches
        foreach (array_filter($matches['__UNKNOWN__']) as $offset => $text) {
            throw new LexingError("Unrecognized `".self::trimDots($text, 5)."` while tokenizing grammar. It was not recognized by any terminal regexes.", 240, $source, $offsets[$offset]);
        }

        // create token objects efficiently
        foreach ($this->terminals as $type => $def) {
            if (isset($this->ignores[$type])) {
                foreach (array_filter($matches[$def[2]]) as $offset => $null) {
                    $matches[0][$offset] = null;
                }
            } else {
                foreach (array_filter($matches[$def[2]]) as $offset => $text) {
                    $matches[0][$offset] = new LexerToken($text, $type, $offsets[$offset], $def[1]);
                }
            }
        }

        $this->tokens = array_values(array_filter($matches[0]));
        $this->length = count($this->tokens);
        $this->offset = 0;
        return;
    }
}


