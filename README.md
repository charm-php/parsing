# Charm/Parsing

A PEG grammar library for PHP: describe a language as rules with optional inline PHP, and parse it with an
interpreter or compile the same grammar into a standalone PHP class with no runtime dependency. Both engines
produce the same values and the same error positions; a conformance suite runs every behaviour through both.

## Install

```bash
composer require charm/parsing
```

PHP 8.1 or newer.

## Sixty seconds

`calc.cpeg`:

```
Calc = _ expr:Expr $  <? return $expr; ?>

Expr = Expr "*" _ Expr   <? return $_0 * $_2; ?>
     | Expr "+" _ Expr   <? return $_0 + $_2; ?>
     | "(" _ Expr ")" _  <? return $_1; ?>
     | Number

Number = /[0-9]+/ _      <? return (int) $_0; ?>

_ = /\s*/ %silent
```

The first rule is where parsing starts. `Expr` starts with itself, which a PEG parser normally cannot do;
here the alternatives listed first bind tighter (`*` before `+`). `_` is whitespace and `%silent` keeps it out
of `$_0`, `$_1`, ... in snippets. Interpreted:

```php
require 'vendor/autoload.php';

$grammar = file_get_contents('calc.cpeg');

echo Charm\cpeg_parse($grammar, '2 + 3 * (4 + 1)'), "\n";
try {
    Charm\cpeg_parse($grammar, '2 + * 3');
} catch (RuntimeException $e) {
    echo $e->getMessage(), "\n";
}
```
```text
17
Failed parsing "Expr" on parsed source:1:4, unexpected "*"
```

The same grammar compiled into a class with no dependency on this library:

```bash
vendor/bin/charm-compile calc.cpeg -c CalcParser -o CalcParser.php
```

```php
require 'CalcParser.php';   // nothing else to load

$parser = new CalcParser();
echo $parser->parse('2 + 3 * (4 + 1)'), "\n";
try {
    $parser->parse('2 + * 3');
} catch (RuntimeException $e) {
    echo $e->getMessage(), "\n";
}
```
```text
17
Parse error at line 1, column 5: expected "(", found '*'
```

Without snippets the parse returns the parse tree: `Expr = Expr "+" Expr | /[0-9]+/` on `1+2+3` gives
`[["1","+","2"],"+","3"]`.

## Which engine

| | Interpreter (`cpeg_parse()`, `Charm\Parser`) | Compiled (`charm-compile`) |
|---|---|---|
| Setup | none | one command, a generated file to keep in step with the grammar |
| Speed | the baseline | about 6 times faster on a realistic grammar |
| Needs at run time | this library | nothing (string grammars) |
| Errors | `Error\ParseError`, `Error\State\CommittedError` | a generated `{Class}ParseError` with `sourceLine`, `sourceColumn`, `expected`, `found`, `committed` |
| Token mode (`@lexer`) | no | yes |
| `$context` in snippets | yes | yes |

Develop with the interpreter, compile when parse time matters or when you ship a parser without the library.
[REFERENCE.md](REFERENCE.md#performance) has the numbers and what slows a compiled parser down.

## What it does

* Rules, `|` ordered choice, `?` `*` `+`, groups, `&` / `!` lookahead, regexes, case-insensitive keywords
  through `"select"i` (or `(?i:...)` in a regex)
* **Left recursion** with precedence and associativity: alternative order, `%prec`, `%assoc`, an `@prec` table
* **Values you control**: sequences return lists, `name:` captures, `...` spread, `%silent` rules, inline PHP
  snippets with `$_0`, `$name`, `$state`, `$MISS`
* **Commit `@`** for errors that say what was expected where
* Template rules (`List<T, Sep>`), a token mode that parses a lexer's token stream
* Generated parsers throw their own `{Class}ParseError`; positions are the furthest terminal that failed, the
  same in both engines

## Documentation

* [Tutorial: parsing SQL](docs/tutorial-sql.md): build a `SELECT` parser step by step; the finished grammar is
  [`example-grammars/sql.cpeg`](example-grammars/sql.cpeg)
* [REFERENCE.md](REFERENCE.md): every operator, pragma, snippet variable, API call and CLI flag, with the value
  it yields
* [docs/index.md](docs/index.md): the map of the chapters (getting started, grammar syntax, snippets,
  precedence, token mode, lexing, compiled parsers and the CLI, errors)
* [CHANGELOG.md](CHANGELOG.md)
* `example-grammars/`: JSON, JavaScript expressions, ES3, ECMAScript regular expressions, yacc

Run the tests with `php run-tests.php` (or `composer test`).

## Licence

GPL-3.0-only, with an additional permission (a Bison-style output exception). In plain words: the library and
the compiler stay GPL, **but a parser that `charm-compile` generates from your grammar is yours**: it is not
covered by the GPL and you may distribute it under any terms. See [LICENCE.md](LICENCE.md) and the
[reference](REFERENCE.md#licence).
