Token mode
==========

Token mode is the second of the two ways Charm consumes input. Instead of
matching a grammar against raw characters, the input is first turned into a
token stream by a lexer, and the grammar matches token types and literal token
text.

Token mode is selected with the `@lexer` directive. It is honoured by the
compiled engine only - see [Running a token-mode grammar](#running-a-token-mode-grammar).

## The `@lexer` directive

```
@lexer Charm\Parsing\Lexers\PhpLexer

Sum = T_LNUMBER "+" T_LNUMBER
```

* The directive must appear before the first rule; `GrammarParser::_CPEG()`
  reads directives in a loop and then reads rules.
* The value is a class name, optionally namespaced - the parser accepts
  `Foo\Bar\Baz` by consuming backslash-separated identifiers.
* Errors: `Expected class name after @lexer on :location` and
  `Expected identifier after \ in class name on :location`.
* The name is stored on the grammar (`Grammar::setLexerClass()`), and
  `Grammar::isTokenMode()` returns `true` as soon as it is set. The class is
  not loaded, and not checked, while the grammar is parsed.

## How the grammar text changes

`GrammarParser::_Clause()` branches on `$this->lexerClass !== null`:

| Grammar text | String mode | Token mode |
|--------------|-------------|------------|
| `T_LNUMBER` | `Clause\Identifier` (rule reference) | `Clause\Token` |
| `"+"` | `Clause\Text` | `Clause\Literal` |
| `/[0-9]+/` | `Clause\Regex` | parse error |
| `Expr` | `Clause\Identifier` | `Clause\Identifier` |
| `^`, `$`, `\nothing` | unchanged | unchanged |

Two consequences worth remembering:

* Only identifiers that start with `T_` are treated as token names. A rule
  named `T_SOMETHING` cannot be referenced in a token-mode grammar.
* Regexes are rejected outright:
  `Regex patterns are not supported in token mode on :location`. Patterns
  belong in the lexer instead, see [Lexing](LEXER.md).

`Clause\Token` and `Clause\Literal` are ordinary clauses, so `?`, `*`, `+`,
predicates, labels and spreads all work on them. `Token::getTokenName()` and
`Literal::getLiteral()` return the names they match, and `Literal::asString()` renders
the literal with quotes, so `"+"` prints as `"+"` and `Token::asString()`
prints the bare name.

## Matching rules

Both clause kinds look at one position of the token array and advance one
token.

`Clause\Token` (interpreted, `interpret()`):

```php
if ($state->tokens === null) {
    throw new \LogicException("Token clause can only be used in token mode");
}
...
$symbolMap = $context['symbolMap'] ?? [];
if (!isset($symbolMap[$this->tokenName])) {
    throw new \LogicException("Unknown token type: {$this->tokenName}");
}
```

On a match it returns the token's text via `State::$tokenData->getValue()`.
`Clause\Literal` compares the token array element to the literal string and
returns that string.

In compiled parsers the same tests are emitted inline, and the token type is
written as a PHP constant:

```php
if ($this->offset < $this->length && $this->tokens[$this->offset] === \T_LNUMBER) {
    $_result = $this->tokenData->getValue($this->offset);
    $this->offset++;
}
```

That means the token names in a compiled token-mode grammar must exist as PHP
constants at run time. `T_*` names from PHP's own tokenizer always do; a custom
lexer either has to define constants for its token types, or the grammar has to
match those tokens as literals. Names are *not* validated against the lexer's
symbol map when the parser is compiled.

`Literal` clauses are compared against the token array as strings. With
`Lexers\PhpTokens` only single-character tokens are stored as text, so `"+"`
matches and a multi-character operator such as `"**"` never does - that one
arrives as `T_POW`.

## Offsets and positions

In token mode `State::$offset` and `State::$length` count tokens, not bytes:

```php
$state->setStateTokens($tokens);      // TokensInterface
$state->isTokenMode();                // true
$state->length;                       // token count
$state->offset;                        // current token index
$state->getByteOffset();               // byte offset of the current token
$state->getSourceString();              // the original source
$state->tokenData->getValue(0);         // text of token 0
$state->setStateString('abc');          // back to string mode
```

`setStateTokens()` fills `State::$tokens` (the array of `int|string` used for
matching) and keeps the `TokensInterface` object in `State::$tokenData` for
values, offsets and line numbers. `setStateString()` clears both.

## Running a token-mode grammar

The compiled engine owns the lexer. The generated class is built from
`src/Compiler/parser-token.template.php`, whose `parse()` method lexes the
source itself:

```php
public function parse(string $source, array $initialState = []): mixed
{
    $lexer = new \Charm\Parsing\Lexers\PhpLexer();
    $this->tokenData = $lexer->lex($source);
    $this->tokens = $this->tokenData->getTokensArray();
    ...
}
```

```php
use Charm\Parsing\GrammarParser;
use Charm\Parsing\Compiler\GrammarCompiler;

$grammar = (new GrammarParser())->parse(
    "@lexer Charm\\Parsing\\Lexers\\PhpLexer\nSum = T_LNUMBER \"+\" T_LNUMBER",
    'calc.peg'
);

$compiler = new GrammarCompiler();
$code = $compiler->compile($grammar, 'SumParser');
file_put_contents('SumParser.php', $code);
require 'SumParser.php';

(new \SumParser())->parse('<?php 1+2;');    // ['1', '+', '2']
```

The interpreted engine never tokenizes - `CPEGParser::parse()` always calls
`State::setStateString($source)` - so a `Clause\Token` reached under
`cpeg_parse()` throws `\LogicException("Token clause can only be used in token
mode")`. Run `@lexer` grammars through `GrammarCompiler`,
`bin/charm-compile`, or with `CHARM_COMPILED=1` set for
[`cpeg_create()` / `cpeg_parse()`](getting-started.md).

Errors from a compiled token-mode parser convert the failing token index back
to a source position before reporting:

```
Parse error at line <line>, column <column>: expected <expected>, found <describe()>
```

The `expected` part is the description of the deepest rule reached, the `found`
part comes from `TokensInterface::describe()` (for `PhpTokens` this is
`token_name($id)` followed by the truncated token text), and the line and
column come from `TokensInterface::getOffset()` of the failing token.

## Writing a lexer

A lexer implements two interfaces:

```php
interface LexerInterface {
    public function lex(string $source): TokensInterface;
    public function getSymbolMap(): array;      // ['T_LNUMBER' => 315, ...]
}

interface TokensInterface {
    public function getTokensArray(): array;    // array<int|string>
    public function getSource(): string;
    public function getToken(int $index): int|string|null;
    public function getValue(int $index): ?string;
    public function getOffset(int $index): ?int;
    public function getLine(int $index): ?int;
    public function count(): int;
    public function describe(int $index): string;
}
```

The token array is the only thing the parser reads while matching: an element
is an `int` token type id, or the literal string for a single-character token.
Everything else - text, byte offset, line - is fetched on demand from the
`TokensInterface` object.

The fastest way to build one is the pattern-based `Charm\Parsing\Lexer`, which
already implements `LexerInterface` and returns a `Lexers\LexerTokens` object;
see [Lexing](LEXER.md).

### PHP tokens

```php
$tokens = (new Charm\Parsing\Lexers\PhpLexer())->lex('<?php $a = 1;');
$tokens->count();            // 4
$tokens->getValue(0);        // '$a'
$tokens->describe(0);        // "T_VARIABLE `$a`"
```

`PhpLexer` wraps `PhpParser\Lexer` from `nikic/php-parser` (`^5`, a dev and
suggested dependency - install it separately for production use). Its symbol
map is built from every defined `T_*` constant of PHP's tokenizer extension.

`PhpTokens` (the `TokensInterface` it returns) drops the tokens nikic's parser
ignores, so grammars do not need whitespace-skipping rules:

```php
private const DROP_TOKENS = [
    0 => true,            // EOF marker
    T_WHITESPACE => true,
    T_OPEN_TAG => true,
    T_COMMENT => true,
    T_DOC_COMMENT => true,
    T_BAD_CHARACTER => true,
];
```

It stores tokens with an id below 256 as their text and everything else as the
integer id, which is what makes `";"` and `"("` match as literals.

## `@prec` in token mode

The precedence table uses the same names the grammar uses, unquoted:

```
@lexer Charm\Parsing\Lexers\PhpLexer

@prec {
    left  "+" "-"
    left  "*" "/" "%"
    right T_POW
    none  "<" ">"
}
```

`example-grammars/php/php.cpeg` is a large worked example - a full PHP grammar
generated from `php.y`, with a few hundred `@prec` entries. Reading the table
back:

```php
$grammar->hasPrecedenceTable();   // true
$grammar->getPrecedence('T_POW'); // ['level' => 3, 'assoc' => 'right']
```

See [Precedence and pragmas](precedence-and-pragmas.md) for how those levels
are used; the interpreter and the compiler resolve them the same way (the interpreter
cannot run a token-mode grammar, but the same table drives string-mode grammars).

## See also

* [Lexing](LEXER.md) - building token streams with `Lexer` and `LexerPattern`
* [Precedence and pragmas](precedence-and-pragmas.md)
* [Compiled parsers and the CLI](compiled-parsers-and-cli.md)
* [Documentation index](index.md)
