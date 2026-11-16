# Tutorial: a calculator in five steps

A first grammar, from "recognise `1+2`" to a compiled parser. About ten minutes. Every PHP block that is
followed by an `output` block is run by `charm-tests/0054-tutorial.php`, so what you see is what you get.
For a larger grammar see the [SQL tutorial](tutorial-sql.md); for every detail see the
[reference](../REFERENCE.md).

```bash
composer require charm/parsing
```

## 1. Rules and the parse tree

A grammar is a list of rules, `Name = pattern`. Parsing starts at the **first** rule. A pattern is a quoted
string, a `/regex/`, another rule's name, or a combination: `A B` is a sequence, `A | B` an ordered choice
(the first alternative that matches wins), and `A*`, `A+`, `A?` repeat.

```php
require 'vendor/autoload.php';

$grammar = <<<'G'
Sum    = Number "+" Number
Number = /[0-9]+/
G;
echo json_encode(Charm\cpeg_parse($grammar, '1+2')), "\n";
```
```output
["1","+","2"]
```

With no snippets, a parse returns the parse tree: a terminal yields the text it matched, a sequence yields the
list of its values. Parsing does not have to consume the whole input; end the first rule with `$` (end of
input) to require that.

## 2. Operators, precedence and whitespace

A rule may start with itself (left recursion), which a plain PEG parser cannot do. Here the alternatives
listed first bind tighter, so `*` beats `+`:

```php
require 'vendor/autoload.php';

$grammar = <<<'G'
Expr   = Expr "*" Expr
       | Expr "+" Expr
       | Number
Number = /[0-9]+/
G;
echo json_encode(Charm\cpeg_parse($grammar, '1+2*3')), "\n";
echo json_encode(Charm\cpeg_parse($grammar, '1+2+3')), "\n";
```
```output
["1","+",["2","*","3"]]
[["1","+","2"],"+","3"]
```

Operators are left-associative by default; `%assoc=right` after an alternative changes that, and `%prec=N`
or an `@prec` table overrides the order ([precedence](precedence-and-pragmas.md)).

For whitespace, write a rule for it and mark it `%silent`, so references to it produce no value and stay out
of your results. Put it after every token:

```
_ = /\s*/ %silent
```

## 3. Building an AST

A snippet, `<? php ?>`, ends a sequence and returns its value. `$_0`, `$_1`, ... are the values of the
sequence's elements by position; silent rules and predicates take no number. This is the finished grammar,
[`example-grammars/calc.cpeg`](../example-grammars/calc.cpeg):

```cpeg
Calc = _ Expr $

Expr = Expr "*" _ Expr    <? return ['op' => '*', 'left' => $_0, 'right' => $_2]; ?>
     | Expr "+" _ Expr    <? return ['op' => '+', 'left' => $_0, 'right' => $_2]; ?>
     | "(" @ _ Expr ")" _ <? return $_1; ?>
     | Number

Number = /[0-9]+/ _       <? return ['num' => (int) $_0]; ?>

_ = /\s*/ %silent
```

`Calc` has no snippet: `_` and `$` produce no value, so `Expr` is the only element with one and the
sequence returns it as it is. The parenthesis alternative returns `$_1` (the inner `Expr`; `"("` is `$_0`).

```php
require 'vendor/autoload.php';

$grammar = file_get_contents('example-grammars/calc.cpeg');
$ast = Charm\cpeg_parse($grammar, ' 2 + 3 * (4 + 1)');
echo json_encode($ast), "\n";

function evaluate(array $n): int {
    return match ($n['op'] ?? 'num') {
        'num' => $n['num'],
        '+' => evaluate($n['left']) + evaluate($n['right']),
        '*' => evaluate($n['left']) * evaluate($n['right']),
    };
}
echo evaluate($ast), "\n";
```
```output
{"op":"+","left":{"num":2},"right":{"op":"*","left":{"num":3},"right":{"op":"+","left":{"num":4},"right":{"num":1}}}}
17
```

Build whatever you like in snippets: arrays, your own node classes (`new \App\BinaryNode(...)`, with a leading
backslash), or just compute the answer. Other forms you will meet: `name:Rule` captures into `$name`, `...Rule`
spreads a list into its parent, `&A` / `!A` look ahead without consuming, and `$state` is an array shared by
all snippets of one parse ([snippets](snippets-and-state.md)).

## 4. Errors

A failed parse throws a `RuntimeException`. The message says where (line and column) and what was
unexpected:

```php
require 'vendor/autoload.php';

$grammar = file_get_contents('example-grammars/calc.cpeg');
foreach (['2 + * 3', '(1 + 2', '2 +'] as $input) {
    try {
        Charm\cpeg_parse($grammar, $input);
    } catch (RuntimeException $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
```
```output
Charm\Parsing\Error\ParseError: Failed parsing "Expr" on parsed source:1:5, unexpected "*", expected "(" or /[0-9]+/
Charm\Parsing\Error\State\CommittedError: Expected ")" in Expr on parsed source:1:7, found end of file
Charm\Parsing\Error\ParseError: Failed parsing "Expr" on parsed source:1:4, unexpected end-of-input, expected "(" or /[0-9]+/
```

The error is reported at the furthest position where a terminal failed to match. The interpreter counts
columns from 1, like a compiled parser.

The `@` in `"(" @ _ Expr ")"` is a **commit**: once `(` has matched, this alternative is the right one, so a
failure afterwards is reported as an error (`CommittedError`, and the message names what was expected)
instead of being swallowed while other alternatives are tried. Use it after a keyword or opening bracket
that can only start one construct. To get readable messages, give a rule a description,
`Number "a number" = /[0-9]+/`, which replaces its name in messages
([errors and debugging](errors-and-debugging.md)).

## 5. Compiling

The interpreter is for development. `charm-compile` turns the same grammar into a PHP class that needs
neither this library nor Composer, and is several times faster:

```bash
vendor/bin/charm-compile example-grammars/calc.cpeg -c CalcParser -o CalcParser.php
```

The same thing from PHP:

```php
require 'vendor/autoload.php';

use Charm\Parsing\Compiler\GrammarCompiler;
use Charm\Parsing\GrammarParser;

$grammar = (new GrammarParser())->parse(file_get_contents('example-grammars/calc.cpeg'), 'calc');
$file = sys_get_temp_dir() . '/CalcParser.php';
(new GrammarCompiler())->compileToFile($grammar, 'CalcParser', $file);

require $file;   // in your own code: require 'CalcParser.php'
$parser = new CalcParser();
echo json_encode($parser->parse('1 + 2')), "\n";
try {
    $parser->parse('2 + * 3');
} catch (CalcParserParseError $e) {
    echo $e->getMessage(), "\n";
    echo "line $e->sourceLine, column $e->sourceColumn\n";
}
```
```output
{"op":"+","left":{"num":1},"right":{"num":2}}
Parse error at line 1, column 5: expected "(" or /[0-9]+/, found '*'
line 1, column 5
```

The generated `{Class}ParseError` carries `sourceLine`, `sourceColumn`, `offset`, `expected`, `found` and
`committed`. Unlike `cpeg_parse()`, `parse()` takes only the source (and an optional initial `$state`).

## Where next

* [Grammar syntax](grammar-syntax.md) and [snippets and state](snippets-and-state.md): the rest of the notation.
* [Precedence and pragmas](precedence-and-pragmas.md): `%prec`, `%assoc`, `@prec`.
* [Tutorial: parsing SQL](tutorial-sql.md): a realistic grammar, step by step.
* [Compiled parsers and the CLI](compiled-parsers-and-cli.md), [token mode](token-mode.md).
