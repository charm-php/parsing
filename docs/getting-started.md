Getting started
===============

Charm/Parsing is a PEG parser for PHP. You describe the input language with a
grammar string; the library then either interprets that grammar at runtime or
compiles it to a standalone PHP class.

## Install

```bash
composer require charm/parsing
```

`composer.json` declares no `php` constraint, but the source uses `enum` and
`readonly` properties, so it needs PHP 8.1 or newer. Required dependencies are
`charm/vector`, `charm/options`, `charm/map`, `charm/util-phpencode` and
`psr/log`. `nikic/php-parser` is only *suggested* - you need it yourself if you
want [`Charm\Parsing\Lexers\PhpLexer`](token-mode.md).

Autoloading is set up as follows:

| Entry | What it provides |
|-------|------------------|
| `files: functions.php` | `Charm\cpeg_parse()` and `Charm\cpeg_create()` |
| `classmap: Parser.php` | `Charm\Parser` (note: not in the `Charm\Parsing` namespace) |
| `psr-4: src/` | Everything else, under `Charm\Parsing\` |

## A first parse

```php
require 'vendor/autoload.php';

$result = Charm\cpeg_parse('Number = /[0-9]+/', '42');
// string(2) "42"
```

A terminal returns the text it matched, so `$_0` and the result above are
strings. Convert them in a [snippet](snippets-and-state.md):

```php
$result = Charm\cpeg_parse('Number = /[0-9]+/ <? return intval($_0); ?>', '42');
// int(42)
```

`cpeg_parse()` is defined as:

```php
function cpeg_parse(string $expression, string $subject, array $initialState = []): mixed
```

Grammars are parsed and cached in a static LRU of 20 entries, so calling
`cpeg_parse()` repeatedly with the same grammar string only builds the grammar
once. Setting the environment variable `CHARM_COMPILED` makes
`cpeg_parse()` compile the grammar to a PHP class and `eval()` it instead of
interpreting it; this is intended for testing compiled output.

The first rule in the grammar is the entry point. A parse that does not consume
the whole input still succeeds unless the grammar asks for `$`:

```php
Charm\cpeg_parse('Number = /[0-9]+/', '42xyz');   // "42"
Charm\cpeg_parse('Number = /[0-9]+/ $', '42xyz'); // throws
```

## A reusable parser class

Extend `Charm\Parser` and put the grammar in a `GRAMMAR` constant:

```php
class Calculator extends \Charm\Parser
{
    const GRAMMAR = <<<'G'
        Expr
            = Expr ("*" | "/") Expr     <? return $_1 == "*" ? $_0 * $_2 : $_0 / $_2; ?>
            | Expr ("+" | "-") Expr     <? return $_1 == "+" ? $_0 + $_2 : $_0 - $_2; ?>
            | Number

        Number
            = /[1-9][0-9]*|0/           <? return intval($_0); ?>
        G;
}

$calc = new Calculator();
echo $calc->parse('1+2', __FILE__);   // 3
```

Three constants control the subclass:

* `GRAMMAR` - the grammar text. Required; the constructor throws if it is
  missing.
* `OPTIONS` - an array passed to
  [`Charm\Parsing\Options`](errors-and-debugging.md#parser-options), or `null`.
* `GOAL` - the rule to start at. When it is `null` the first rule is used.

Because the grammar lives inside a PHP file, grammar errors are reported against
the grammar's own line numbers: the constructor finds the `GRAMMAR` constant
with reflection and adds its line number to the exception.

`parse()` is inherited from `Charm\Parsing\CPEGParser`:

```php
public function parse(
    string $source,
    string $filename,
    int $lineOffset = 0,
    int $columnOffset = 0,
    array $initialState = []
): mixed
```

`$filename` has no default value, so pass something even when you have no file
- it is only used for error messages. `$lineOffset` and `$columnOffset` are
added to every location reported while parsing. `$initialState` seeds the
`$state` variable available to snippets.

## A compiled parser

The same grammar can be compiled to a PHP file that has no dependency on this
library:

```bash
cat > expr.peg <<'EOF'
Expr = Expr "*" Expr
     | Expr "+" Expr
     | Number
Number = /[0-9]+/
EOF

vendor/bin/charm-compile expr.peg -o ExprParser.php -c ExprParser
```

```php
require 'ExprParser.php';

$result = (new ExprParser())->parse('1+2*3');
// ['1', '+', ['2', '*', '3']]
```

Compiled parsers take a different signature and a different failure mode:

```php
public function parse(string $source, array $initialState = []): mixed
```

They throw `\RuntimeException("Parse error at line N, column C: expected ...,
found ...")`. See [compiled parsers and the CLI](compiled-parsers-and-cli.md).

## Next steps

* [Grammar syntax](grammar-syntax.md) - rules, terminals, operators, captures.
* [Snippets and state](snippets-and-state.md) - running PHP from a grammar.
* [Precedence and pragmas](precedence-and-pragmas.md) - operator grammars.
* [Errors and debugging](errors-and-debugging.md) - what to catch and why.

## The test suite

The repository ships a regression runner rather than PHPUnit test classes:

```bash
php run-tests.php          # all tests
php run-tests.php 0004     # only files matching *0004*
```

It also runs through Composer: `composer test`. Benchmarks live in
`benchmarks/` and are run with `php benchmarks/run.php [grammar|json|expression|expr-eval]`.
