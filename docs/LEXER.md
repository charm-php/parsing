Lexing
======

`Charm\Parsing\Lexer` turns a source string into a list of `LexerToken`
objects using one regular expression per token type. It is used by
`GrammarParser` to read grammar files, and it can be used as the lexer for a
[token-mode](token-mode.md) grammar.

Lexing happens in a single `preg_match_all()` call, so the cost is dominated by
that regex: pattern order and backtracking matter more than the number of
patterns.

## Defining a lexer

```php
use Charm\Parsing\{Lexer, LexerPattern, LexerToken};

$lexer = new Lexer([
    // identifiers
    new LexerPattern(1, 'T_IDENT', '[a-zA-Z_][a-zA-Z0-9_]*+'),

    // numbers, with a processor that runs when the value is asked for
    new LexerPattern(2, 'T_NUMBER', '(0|[1-9][0-9]*)(\.[0-9]+)?',
        function (LexerToken $token) {
            return floatval($token->text);
        }),

    // single characters are their own token type
    new LexerPattern(3, 'T_OP', '[-+*(),;]'),

    // negative type numbers are dropped from the output
    new LexerPattern(-4, 'T_WHITESPACE', '\s+'),
]);
```

`LexerPattern::__construct(int $type, string $name, string $regex, callable $processor = null)`

* `$type` identifies the token in the parse. **Negative values are discarded
  after matching** - use them for whitespace and comments.
* `$name` is a human readable label. It is what `getSymbolMap()` keys on and
  what `LexerToken::getDebugString()` prints.
* `$regex` is a PCRE body without delimiters.
* `$processor` is optional. It is bound with `Closure::bindTo(null, null)`, so
  it cannot use `$this` from the surrounding scope.

Two constraints follow from how the regex is built:

```php
$reParts[] = '(?<type'.str_replace('-', '_', (string) $pattern->type).'>(?>'.$pattern->regex.'))';
// ...
$re = '/'.implode('|', $reParts).'/ux';
```

* Patterns are alternated **in the order given** and the first one that matches
  wins, so put more specific patterns first (`T_NUMBER` before a catch-all).
  Each is wrapped in an atomic group, so a pattern that matches will not give
  characters back to a later alternative.
* The combined pattern is compiled with the `x` (extended) modifier, so line
  breaks and indentation inside a pattern are free but literal spaces must be
  escaped (`\x20`), and `#` starts a comment inside a pattern.
* The patterns are inserted into a `/.../ ` delimited regex, so a literal slash
  outside a character class needs escaping: `'\/'`.

## Tokenizing

```php
$tokens = $lexer->tokenize('<?php 1+2;', __FILE__);
```

`tokenize(string $source, string $filename, int $lineOffset = 0, int $columnOffset = 0): array`

Returns a `LexerToken[]` array of the kept (non-negative) tokens, in source
order. The `$filename`, `$lineOffset` and `$columnOffset` arguments are part of
the signature but are not used; the position information on the tokens is
absolute within `$source`.

Anything no pattern matches falls into a final `(?<__UNKNOWN__>[\s\S])` group
and aborts the whole lex:

```
Lexing error: Unrecognized `$` at parsed source:<line>:<column>
```

That is a `Charm\Parsing\Error\ParseError` built from the source and the byte
offset of the offending character; because no filename is attached, the
`:location` placeholder falls back to `parsed source`. See
[Errors and debugging](errors-and-debugging.md). If the combined pattern itself
fails to compile, `tokenize()` throws `ParseError` with the message
`LexerPattern error: <preg error message>`.

## Tokens

```php
$tokens[0]->type;          // 1        the LexerPattern's type
$tokens[0]->text;          // string   the matched text
$tokens[0]->offset;        // int      byte offset of the match
$tokens[0]->length;        // int      see below
$tokens[0]->getValue();    // the processor's return value, or the token itself
$tokens[0]->getDebugString();   // "`1` (T_NUMBER)"
```

`length` is **not** `strlen($token->text)`. Dropped tokens have their length
added to the token in front of them, so `$tokens[$i]->offset + $tokens[$i]->length`
still lands on the start of the next kept token. Use `strlen($token->text)` for
the text length.

`getValue()` calls `LexerPattern::process()` every time; it is not cached. With
no processor it returns the token object itself, so give a processor whenever
the value is used. `LexerToken` also implements `JSONSerializable` by returning
`getValue()`, and stringifies to `$text`.

## The token-stream interface

`Lexer` implements `LexerInterface`, so its output can drive a token-mode
grammar. `lex()` wraps the result instead of returning the raw array:

```php
$tokenData = $lexer->lex('<?php 1+2;');   // Charm\Parsing\Lexers\LexerTokens

$tokenData->getTokensArray();  // [1, 3, 1, ...] - the types, for matching
$tokenData->getSource();       // the original string
$tokenData->count();           // number of kept tokens
$tokenData->getValue(0);       // text of token 0
$tokenData->getOffset(0);      // byte offset of token 0
$tokenData->getLine(0);        // 1-based line, counted from the source
$tokenData->describe(0);       // LexerToken::getDebugString()
$tokenData->getLexerToken(0);  // the LexerToken object itself
```

`Lexers\LexerTokens` is the `TokensInterface` implementation for this lexer;
`Lexers\PhpTokens` does the same job for PHP source. Both are described in
[token mode](token-mode.md).

`getSymbolMap()` maps pattern name to type, skipping discarded patterns:

```php
$lexer->getSymbolMap();   // ['T_IDENT' => 1, 'T_NUMBER' => 2, 'T_OP' => 3]
```

Only patterns with a non-negative type appear, and names must therefore be
unique. The map is built on first call and cached on the lexer instance.
Nothing in this library calls it: the interpreted `Clause\Token` looks for a
`symbolMap` entry in the clause context (which no caller sets), and the compiler
emits the token type as a PHP constant instead. Treat `getSymbolMap()` as
something for your own code - it is required by `LexerInterface`.

## Writing a parser against it

`Charm\Parsing\RecursiveDescentParser` is the hand-written-parser base class:
it extends `State`, lexes the input in `parse()`, and exposes the token stream.

```php
class MyParser extends \Charm\Parsing\RecursiveDescentParser {
    protected function getLexer(): \Charm\Parsing\LexerInterface {
        return new \Charm\Parsing\Lexer([ /* ... patterns ... */ ]);
    }

    protected function start() {
        $result = [];
        while (!$this->eof()) {
            $result[] = $this->accept();
        }
        return $result;
    }
}
```

Available inside `start()` and the methods it calls:

| Method | Meaning |
|-------|---------|
| `accept(): LexerToken` | the current token, then move one token forward |
| `peek(int $offset): ?LexerToken` | a token relative to the current one |
| `type(int $offset = 0): int` | token type ahead of the cursor, `-1` past the end |
| `text(int $offset = 0): string` | token text ahead of the cursor, `''` past the end |
| `typeIs(int $type, int $offset = 0): bool` | type check with lookahead |
| `textIs(string $text, int $offset = 0): bool` | text check with lookahead |
| `eof(): bool` | `$offset >= $length` |
| `byteOffset(): int` | byte offset of the current token in the source |

`accept()` and `peek()` need the raw `LexerToken` objects, so they assert that
the token stream is a `Lexers\LexerTokens` - they are unavailable when a
different lexer is installed. Everything else goes through `TokensInterface`.

`parse(string $source, string $filename, int $lineOffset = 0, int $columnOffset = 0)`
memoises results in a static cache keyed on those four values;
`RecursiveDescentParser::clearCache()` empties it. `parseFile(string $filename)`
reads a file and forwards to `parse()`.

`GrammarParser` is a worked example of a lexer plus recursive-descent parser in
this style: 21 token types, comments and whitespace on negative types, and a
processor on every pattern whose text needs unescaping or converting
(`GrammarParser::getLexer()`).

## See also

* [Token mode](token-mode.md) - grammars that match tokens instead of characters
* [Grammar syntax](grammar-syntax.md)
* [Errors and debugging](errors-and-debugging.md)
* [Documentation index](index.md)
