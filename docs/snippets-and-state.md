Snippets and state
==================

A snippet is a block of PHP written inside the grammar:

```
Number = /[0-9]+/  <? return intval($_0); ?>
```

It runs after the clauses before it have matched, and its return value becomes
the value of the sequence it belongs to.

## Placement

A snippet must be the last clause of its sequence. `GrammarParser` rejects
anything else with:

```
Snippet must be the last clause in a sequence. Found snippet at position 1 of 2 clauses.
```

Each alternative of a choice may end in its own snippet:

```
Value = Number            <? return ['n', $_0]; ?>
      | String            <? return ['s', $_0]; ?>
```

Snippets consume no input: `Snippet::getMinByteLength()` returns `0` and
`getFirstBytes()` returns `null`, so they are invisible to the engine's
first-byte and minimum-length checks.

## How the code is built

`Snippet::__construct()` asserts that the code is not empty and immediately
compiles it:

1. `compile()` runs `token_get_all()` over the code and rewrites variables.
   `$foo` becomes `$_context['foo']`, and `$_3` becomes `$_context[3]`.
2. `build()` wraps the result in a closure and `eval`s it:
   `$this->invoker = function(Charm\Parsing\Clause\Snippet\Context $_context) { ... };`

Two consequences:

* **PHP syntax errors surface at grammar build time**, as
  `Charm\Parsing\Error\CustomError`, not when the rule is reached.
* **The closure is evaluated inside the `Charm\Parsing\Clause` namespace**, so
  an unqualified name resolves there first. `Charm\Parsing\Clause\Regex`,
  `Text`, `Token`, `Sequence`, `Literal` and others exist, so `new Regex()` or
  `new Token()` in a snippet can pick one of them up. Prefix global classes and
  functions with `\` - `new \ArrayObject()`, `\is_array($x)`. Compiled parsers
  put the same code in a different namespace, which is another reason to always
  use the leading backslash. `example-grammars/json.cpeg` follows this style.

### Local variables

The rewriting keeps a name local when it is bound by the snippet itself:
`function`/`fn` parameters, `foreach ... as` targets, and variables in a
`for(;;)` header. Everything else - including plain `$x = ...` assignments - is
stored in the per-invocation `$_context` array:

```
Items = Value ("," Value)*  <?
    $list = [$_0];              # stored in the snippet's context array
    foreach ($_1 as $r) {       # $r stays a real PHP local
        $list[] = $r;
    }
    return $list;
?>
```

The context array is created fresh for every snippet invocation, so writes to
ordinary variables never leak into the grammar's result or into the next
invocation. `$state` is the exception - see below.

## Variables available

The context passed to the closure is the sequence's accumulated result plus
these entries (`Snippet::interpret()`):

| Variable | Contents |
|----------|----------|
| `$_0`, `$_1`, ... | Positional results of the sequence, in order |
| `$name` | Named captures (`name:Clause`) |
| `$context` | The raw accumulated array, keys and all |
| `$state` | Reference to `State::$globals` |
| `$HIT` | `Charm\Parsing\Signal::HIT` |
| `$MISS` | `Charm\Parsing\Signal::MISS` |

The extra entries are merged with `$context + [...]`, so a named capture called
`state`, `context`, `HIT` or `MISS` shadows the built-in entry. Avoid those
names for captures.

Only clauses that contribute a value take a positional slot. Named captures,
`Signal::HIT` results (`^`, `$`, `\nothing`, predicates) and references to
[`%silent`](precedence-and-pragmas.md) rules do not, so indices depend on what
precedes them:

```
Expr = /[0-9]+/ "+" /[0-9]+/   <? return intval($_0) + intval($_2); ?>
       # $_0 = left operand, $_1 = "+", $_2 = right operand
```

Reading a variable that is not in the context throws:

```
Undefined variable $foo. Available variables are $_0, $_1, $context, $state, $HIT, $MISS.
```

which reaches your code as a `Charm\Parsing\Error\SnippetError` (see
[Errors and debugging](errors-and-debugging.md)).

## Return values

`Sequence::interpret()` inspects what the snippet returns:

| Snippet returns | Effect |
|-----------------|--------|
| Any value, including `null`, `false`, `true`, `0` | Becomes the sequence's value |
| `$MISS` (`Signal::MISS`) | This sequence fails; the engine backtracks and tries the next alternative |
| `new \Charm\Parsing\SequenceIgnore` | Sequence succeeds and contributes nothing (`Signal::HIT`) |
| `new \Charm\Parsing\SequenceResume` | Sequence treats the snippet as a valueless clause and carries on - the result is whatever was accumulated before it |

`Signal` is an enum precisely so that booleans are ordinary values. These two
tests from `charm-tests/0011-snippet-errors.php` show the behaviour:

```php
'Test = /[a-z]+/ <? return false; ?>'   // parsing "hello" gives false
'Test = /[a-z]+/ <? return true; ?>'    // parsing "hello" gives true
```

To fail an alternative on a condition, return `$MISS`:

```
Byte = /[0-9]+/  <?
    $v = intval($_0);
    return ($v >= 0 && $v <= 255) ? $v : $MISS;
?>
```

Because `SequenceIgnore` and `SequenceResume` are plain classes, use the fully
qualified name.

### Throwing from a snippet

Any `\Throwable` escaping the snippet is caught and rethrown as
`Charm\Parsing\Error\SnippetError` with the message prefixed `Error in snippet:
`. The original exception is available via `getPrevious()` and the generated
code via `getSnippetSource()`:

```php
try {
    Charm\cpeg_parse($grammar, $input);
} catch (\Charm\Parsing\Error\SnippetError $e) {
    echo $e->getMessage();          // "Error in snippet: ..."
    echo $e->getSnippetSource();    // the compiled closure
}
```

`SnippetError` is not one of the parser's internal `ExceptionInterface` types,
so it is not caught by backtracking - it aborts the parse. Use a `$MISS` return
for expected failures and exceptions for genuine problems.

Snippets run under `error_reporting(E_ALL)` with an error handler that converts
warnings and notices into `Charm\Parsing\Error\CustomError` (which then becomes
a `SnippetError`). PHP's `Undefined array key` notice is rewritten to
`Undefined variable $name` so the message names the grammar variable.

## State

`$state` is a reference to `State::$globals`. Writes survive across snippets and
across rules for the rest of the parse:

```php
$state = ['counter' => 0];

Charm\cpeg_parse(<<<'GRAMMAR'
    DotCounter = ^ Dots $                   <? return $_0; ?>
    Dots       = Dot+
    Dot        = "."                        <? return $state['counter']++; ?>
    GRAMMAR,
    "...",
    $state
);
// [0, 1, 2]
```

`$initialState` is the third argument of `cpeg_parse()` and the fifth argument
of `CPEGParser::parse()`:

```php
function cpeg_parse(string $expression, string $subject, array $initialState = []): mixed
```

```php
public function parse(
    string $source,
    string $filename,
    int $lineOffset = 0,
    int $columnOffset = 0,
    array $initialState = []
): mixed
```

The interpreted engine assigns `$state->globals = $initialState` at the start of
every `parse()` call, so globals never leak between two calls.

`State::$extra` is a second scratch array on the same object, but it is not
exposed to snippets - `Clause\OrderedChoice` uses it internally for left
recursive parsing.

## Compiled parsers differ

`GrammarCompiler` emits snippet code as private methods instead of closures
(`snippet_Pair_1()` in `src/Library/JsonParser.php` is one of them). Each method
starts with `$state = &$this->globals;` and takes the rule's start offset as its
first parameter, `$_offset`, followed by one parameter per accumulated value:

```php
private function snippet_Pair_1($_p0, $_p1, $_p2, $_p3, $_p4, $_p5)
{
    $state = &$this->globals;
    return [$_p0 => $_p5];
}
```

The differences that matter when a grammar has to run both ways:

| | Interpreted | Compiled |
|---|---|---|
| `$MISS` return fails the sequence | Yes | No - the generated code assigns the return value and continues |
| `SequenceIgnore` / `SequenceResume` | Honoured | Not checked |
| `$context`, `$HIT`, `$MISS` | Available | Not defined; use `\Charm\Parsing\...` constants with care, they are not imported |
| `$_offset` | Not defined | Rule start offset |
| `$initialState` | Replaces globals on each parse | `array_merge` into the parser's `$globals` property, so values persist across `parse()` calls on one instance |

Compiled parsers are also free of the closure's namespace: the code lands in the
generated class's namespace, and `--namespace` changes where unqualified names
resolve.

Snippets that return a value are registered in `Grammar::getSnippets()` under
`RuleName_N` (`Grammar::collectSnippets()`), and `Snippet::getCompiledSource()`
returns the rewritten code the compiler embeds.

## See also

* [Grammar syntax](grammar-syntax.md) - where snippets sit in a rule
* [Precedence and pragmas](precedence-and-pragmas.md) - snippets inside
  left-recursive rules
* [Compiled parsers and the CLI](compiled-parsers-and-cli.md) - the generated
  snippet methods
* [Errors and debugging](errors-and-debugging.md) - `SnippetError` and friends
