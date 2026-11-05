Charm/Parsing
=============

Charm/Parsing (`charm/parsing`) is a PEG parser library for PHP. You write a
grammar - rules of terminals and other rules, plus optional inline PHP - and the
library matches input against it and returns the values your grammar produced.

```php
require 'vendor/autoload.php';

$result = Charm\cpeg_parse('Number = /[0-9]+/ <? return intval($_0); ?>', '42');
// int(42)
```

Left-recursive rules (`Expr = Expr "+" Expr | Number`) are supported by both
engines; operator grouping comes from the order of the alternatives and from
pragmas (`%assoc`, `%prec`) and an `@prec` table.

## The two engines

The same grammar text runs two ways.

| | Interpreted | Compiled |
|---|---|---|
| Started by | `Charm\cpeg_parse()` / `cpeg_create()`, or a `Charm\Parser` subclass | `Charm\Parsing\Compiler\GrammarCompiler`, or `vendor/bin/charm-compile` |
| At runtime | `Charm\Parsing\CPEGParser` walks the clause objects built by `GrammarParser` | A generated PHP method per rule (two for a left-recursive rule), plus a per-class `Signal` enum |
| Dependency at runtime | This library | Nothing, for string-mode grammars |
| Failure | `Charm\Parsing\Error\ParseError` (`RuntimeException`) | `\RuntimeException("Parse error at line N, column C: expected ..., found ...")` |
| Columns in messages | 0-based | 1-based |
| Left recursion | `OrderedChoice::lrParse()` - alternative order, `%assoc=right` | Generated Pratt pair `parseRule(int $_minPrec)` / `parseRulePrimary()`, with `@prec` and `%prec` |
| Token mode (`@lexer`) | No - a `Clause\Token` throws `\LogicException` | Yes |
| Commit (`@`) | No - the class it throws is not autoloaded | Yes, via a `commitOffset` field |
| Global state | Replaced by `$initialState` on every `parse()` | `array_merge`d into the parser's `$globals`, so values persist per instance |

Grammar template rules (`List<T,S>`) are a third axis: they are expanded by
`GrammarPreprocessor`, which only `cpeg_parse()` and `cpeg_create()` run. A
`Charm\Parser` subclass and `bin/charm-compile` pass grammar text straight to
`GrammarParser`, so `Name<Arg>` is a parse error there unless you preprocess
first.

## Pages

* [Getting started](getting-started.md) - install, `cpeg_parse()`, a
  `Charm\Parser` subclass, a compiled parser, the regression runner.
* [Grammar syntax](grammar-syntax.md) - rules, terminals, operators, captures,
  template rules, directives.
* [Snippets and state](snippets-and-state.md) - inline PHP, `$_0`/`$state`,
  return values, `$MISS`, what differs in generated code.
* [Precedence and pragmas](precedence-and-pragmas.md) - `%assoc`, `%prec`,
  `%silent`, `@prec`, commit (`@`), how each engine groups a left-recursive rule.
* [Token mode](token-mode.md) - grammars over PHP tokens instead of characters:
  `@lexer`, `T_*` and literal matching, `LexerInterface` / `TokensInterface`.
* [Lexing](LEXER.md) - `Lexer`, `LexerPattern` and `LexerToken`, and the
  hand-written `RecursiveDescentParser` style used by `GrammarParser`.
* [Compiled Parsers and the CLI](compiled-parsers-and-cli.md) -
  `GrammarCompiler` options, the templates and their placeholders, how rules
  become methods, `charm-compile` flags, the optimizer pipeline, the recorded
  benchmarks.
* [Errors and debugging](errors-and-debugging.md) - what to catch, the message
  placeholders, snippet errors, compiled `fail()`/`expect()`, the trace, options.

A sensible first pass is getting started, then grammar syntax, then snippets.
Reach for the precedence page as soon as a rule is left-recursive, the token
mode and lexing pages when you want the input split into tokens, and the compiled
page when a grammar has to run fast enough to be worth generating code for.

## Repository layout

| Path | Contents |
|---|---|
| `functions.php` | `Charm\cpeg_parse()`, `Charm\cpeg_create()` (composer `files` autoload) |
| `Parser.php` | `Charm\Parser` - the grammar-in-a-class base (composer `classmap`) |
| `src/` | `Charm\Parsing\` (composer `psr-4`): clauses, `GrammarParser`, `Grammar`, `CPEGParser`, `State`, `Lexer*`, `Error/`, `Compiler/`, `Lexers/`, `Optimizer/`, `Util/` |
| `bin/charm-compile` | The compiler CLI (`vendor/bin/charm-compile`) |
| `charm-tests/` | Regression tests run by `run-tests.php` |
| `example-grammars/` | `.cpeg` grammars, including the yacc-derived PHP grammar |
| `benchmarks/` | Benchmark scripts and their `.log` result history |
| `cleanup/` | Code that is not autoloaded and not part of the API |

`src/Library/*.php` and the `PhpParser.php` / `PhpPrecParser.php` files in
`example-grammars/php/` are compiler output kept in the tree; the templates in
`src/Compiler/` are the reference, so regenerate before relying on them.

## Requirements and licence

Required PHP packages: `charm/vector`, `charm/options`, `charm/map`,
`charm/util-phpencode`, `psr/log`. `nikic/php-parser` is suggested and only
needed for `Charm\Parsing\Lexers\PhpLexer`. The source uses `enum` and
`readonly`, so it needs PHP 8.1 or newer; `composer.json` does not declare a
`php` constraint.

The library is GPL-3.0-only, with an output exception in `LICENCE.md` that lets
generated parsers be distributed under other terms. `CHANGELOG.md` records what
changed between releases; these pages describe the tree at 0.1.1.
