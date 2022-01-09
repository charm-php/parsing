Charm\Parsing\Lexer
===================

The lexer is a very fast string tokenizer which is used by the
RecursiveDescentParser implementation to tokenize the source string.

Regarding performance; in general the vast majority of lexing time
will be spent inside `preg_match_all`.


Usage
-----

```
use Charm\Parsing\{Lexer, LexerPattern};

const T_IDENT = 1;
const T_NUM = 2;
const T_WHITESPACE = 3;

$lexer = new Lexer([
    /**
     * A simple regex pattern which matches variable names
     */
    new LexerPattern(T_IDENT, '[a-zA-Z][a-zA-Z0-9]*'),

    /**
     * A regex which matches integers and floats with a
     * function which will be invoked when calling
     * `$token->value()`.
     */
    new LexerPattern(T_NUM, '(0|[1-9][0-9]*)(\.[0-9]+)?', function(LexerToken $token) {
        return floatval($token->text);
    }),

    /**
     * With a negative identifier, tokens will not be included in the result.
     */
    new LexerPattern(-T_WHITESPACE, '\s+'),
]);

/**
 * Create an array of LexerToken objects. If unrecognized tokens are found,
 * throw `LexingError`.
 */
$tokens = $lexer->tokenize(file_get_contents(__FILE__), __FILE__);
```
