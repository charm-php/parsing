# Errors and debugging

What each engine throws, how to read the messages, and how to get more
information out of a failing parse.

## What to catch

| Class | Extends | Raised by |
|---|---|---|
| `Charm\Parsing\Error\ParseError` | `AbstractParseError` → `\RuntimeException` | The interpreted parser (`CPEGParser::parse()`) when the goal rule misses; `Lexer::tokenize()` on an unmatched character or a broken combined pattern |
| `Charm\Parsing\Error\State\ParseError` | `AbstractParseError` | `GrammarParser` - errors in grammar text (`@prec` blocks, missing `;`, bad directives) |
| `Charm\Parsing\Error\SnippetError` | `\RuntimeException` | `Clause\Snippet::interpret()` - any `\Throwable` that escapes inline grammar code |
| `Charm\Parsing\Error\CustomError` | `\Exception` | Grammar construction and snippet validation: duplicate rule name, unknown rule reference, invalid regex, snippet not last in a sequence |
| `\RuntimeException` | - | Compiled parsers, from the generated `fail()` method |
| `\LogicException` | - | `Regex` clause used in token mode; writing an undeclared property on `State` |

Both `AbstractParseError` subclasses and `SnippetError` extend
`\RuntimeException`, so a single `catch (\RuntimeException $e)` in front of
`cpeg_parse()` covers the interpreted engine and a compiled parser.
`CustomError` does not - it extends `\Exception` directly, and it is the class
that surfaces grammar bugs such as:

```
Rule 'Expr' is already declared in this grammar. Use the Choice operator `/` to add multiple rules to a grammar
Rule `Foo` does not exist in the grammar
Unknown identifier 'bar' in grammar
Error in regex `*`: <preg message> code <n>
```

A grammar-text parse runs through `GrammarParser::start()`, which re-raises
`CustomError` as a located error:

```php
} catch (CustomError $e) {
    throw new ParseError($this, $e->getMessage().' on :location', $e->getCode(), $e);
}
```

so grammar mistakes arrive as `Error\State\ParseError` with ` on <file>:<line>:<column>`
appended.

## <a name="parser-options"></a>Parser options

```php
use Charm\Parsing\Options;

new Options(['trace' => true, 'auto_tokenize' => false, 'base_namespace' => 'App\\Grammar']);
```

| Option | Default | Documented purpose |
|---|---|---|
| `trace` | `false` | Record a trace of every clause entry |
| `auto_tokenize` | `true` | Tokenize based on the terminals in the grammar |
| `base_namespace` | `''` | Namespace prefix used when compiling snippets |

Options come from `Charm\AbstractOptions` (`charm/options`): unknown keys throw
`Charm\Options\UnknownOptionException`, wrongly typed values throw
`IllegalValueException`, and instances are immutable after construction
(`IllegalOperationException`).

Read these as a declaration of intent rather than as working switches:
`CPEGParser::parse()` never reads `$this->options`, so `trace` and
`auto_tokenize` have no effect there - `cpeg_create()` passes
`new Options(['trace' => true])` and the parser traces anyway (see below). The
one options object that is honoured is `GrammarParserOptions`, whose
`base_namespace` is passed to every `Snippet` clause.

## Interpreted parse failures

`CPEGParser::parse(string $source, string $filename, int $lineOffset = 0, int $columnOffset = 0, array $initialState = [])`
returns the goal rule's value, or:

```php
if (!$state->trace) {
    $state->trace = true;
    goto start_over_with_trace;
}
```

A miss is therefore parsed twice: once without tracing, then again with
`State::$trace` set so the trace tree exists for error reporting. Turning
tracing on by default would double the cost of every failed attempt, which is
why it is done this way.

The message is then built from the trace, not from the last clause that ran:

1. Walk the tree with `$trace->visitPreOrder()` and collect every node that
   returned `Signal::MISS` but was partially successful
   (`Trace::isPartialSuccess()` - it, or one of its descendants, matched
   something).
2. Keep only those at the largest `$node->offset`.
3. If several tie, keep the one with the largest `getMaxLength() + offset` -
   the deepest reach before backtracking.
4. If nothing qualifies, blame the root node.

```php
$failingRule = $failingNode->clause->getRule();
$startDesc = $failingRule->getDescription() ?? json_encode($failingRule->getRuleName());
throw new ParseError($state->source, $failingNode->offset,
    "Failed parsing $startDesc on :location, unexpected $endDesc");
```

`$endDesc` is `end-of-input` at the end of the source, the pattern name in
quotes in token mode (`'T_NUMBER'`), otherwise `json_encode()` of the
character. A typical failure:

```
Failed parsing "a number" on config.json:3:11, unexpected "x"
```

Because the reported node is the outermost one that got furthest, the message
names the *rule* that gave up rather than the terminal that failed. Prefix your
rules with descriptions (`Number "a number" = /[0-9]+/ ;`) to make these
readable - see [Grammar syntax](grammar-syntax.md).

## The message placeholders

`AbstractParseError` stores the template and interpolates it:

| Placeholder | Value |
|---|---|
| `:location` | `:file::line::column` (`getLocationString()`) |
| `:file` | The filename given with `setFileInfo()` / `setErrorFile()`, else the literal `parsed source` |
| `:line` | 1-based line |
| `:column` | 0-based column |
| `:source_description` | `State::describeOffsetAt()` (token mode) or `StringTools::describeOffset()` |

Only placeholders that occur in the template are evaluated. The message is
rebuilt by `update()` through reflection (`Util\ExceptionTools::setMessage()`),
so calling `setFileInfo()`, `setErrorFile()`, `adjustErrorLine()` or
`adjustErrorColumn()` on a caught error changes `getMessage()`:

```php
try {
    $value = cpeg_parse($grammar, $text);
} catch (\Charm\Parsing\Error\AbstractParseError $e) {
    $e->setFileInfo('config.json', 12, 0);   // grammar embedded at line 13
    throw $e;                                  // message now points at the PHP file
}
```

`setFileInfo()` and the two adjust methods *add* to the existing offsets, so
apply them once.

Two limits worth knowing:

* Lines are 1-based but columns are 0-based in the interpreted engine
  (`getErrorColumn()` does not add one). The compiled template adds one, so
  compiled messages are 1-based.
* `:source_description` only works on `Error\State\ParseError` in token mode.
  `Error\ParseError::getSourceDescription()` calls
  `$st->describeOffseT($this->getOffset())`, and `ParseError` has no
  `getOffset()`; `State::describeOffsetAt()` calls
  `$st->describeOffsetAt()`, which `StringTools` does not define. Either call
  produces a fatal "Call to undefined method" instead of a description.

Grammar-text errors are the common case: parsing runs on tokens, so
`Error\State\ParseError::getInternalLineNumber()` returns `0` (it bails out when
`$state->source === null`) and the line number in the message comes from the
offsets `GrammarParser::start()` passes to `setFileInfo()`.

Real grammar-text messages, all from `GrammarParser`:

```
Expected ';' or clauses on :location, got :source_description
Expected clause on :location, got :source_description
Expected identifier, string, number or regex on :location, got :source_description
Expected ')' on :location, got :source_description
Expected '|' on :location, got :source_description
Expected end-of-file on :location, found :source_description
Unknown directive @{$name} on :location
Expected '{' after @prec on :location
Invalid associativity '$assoc' on :location. Expected 'left', 'right', or 'none'
Expected at least one token after '$assoc' on :location
Regex patterns are not supported in token mode on :location
Invalid syntax 'name:...' on :location. Use '...name:clause' instead
Brace mismatch, expected '{$close}' on :location, found :source_description
```

`GrammarParser` also emits `Excpected clause on :location, got :source_description`
- misspelling in the source, quoted here as it appears in the wild.

`RecursiveDescentParser::parse()` memoises results in a static cache keyed on
`md5(source + filename + lineOffset + columnOffset)`, so the same grammar text
returns the same `Grammar` object. `GrammarParser::clearCache()` empties it;
that matters when you edit a grammar string and re-parse it under a different
filename, or when you want a fresh clause tree to mutate.

## Snippet failures

`Clause\Snippet::interpret()` installs an error handler for `E_ALL` and wraps
everything:

```php
} catch (\Throwable $e) {
    throw new SnippetError(
        "Error in snippet: " . $e->getMessage(),
        $this->invokerSource,
        $e
    );
}
```

So PHP notices become exceptions first - the handler rewrites
`Undefined array key "foo"` to `Undefined variable $foo` and
`Undefined array key 0` to `Undefined variable $_0` - and everything arrives at
your `catch` as `Charm\Parsing\Error\SnippetError`:

```
Error in snippet: Undefined variable $foo
```

with `getPrevious()` holding the original `CustomError` and
`getSnippetSource()` holding the *generated* closure source, not the snippet
text from the grammar:

```php
} catch (\Charm\Parsing\Error\SnippetError $e) {
    fwrite(STDERR, $e->getMessage()."\n".$e->getSnippetSource()."\n");
}
```

Reading the generated source is the fastest way to debug a snippet: variables
appear as `$_context['name']` or `$_context[0]`. `$this` is rewritten the same
way (`$this` becomes `$_context['this']`), so a snippet cannot use object
context - it fails with `Undefined variable $this`. The private
`Snippet::fn_error(string $reason = null)` helper that raises
`CustomError($reason ?? "Unknown parse error")` is therefore not reachable from
grammar text; `return $MISS` is the supported way to reject a match.

An exception from a snippet does not backtrack - it aborts the whole parse,
because the `catch (ExceptionInterface $e)` blocks that would absorb it in
`Clause::parse()` and `Sequence::interpret()` name an interface that is not
autoloaded (see below). To reject a match, return `$MISS` instead of throwing;
see [Snippets and state](snippets-and-state.md).

## Compiled parser errors

Generated parsers do not use the `Error\*` classes. Each method that can fail
records the furthest point reached and throws a `\RuntimeException`:

```php
private function fail(string $expected): never
{
    $offset = \max($this->offset, $this->maxOffset);
    ...
    throw new \RuntimeException("Parse error at line {$line}, column {$col}: expected {$expected}, found {$found}");
}
```

* `found` is `end of input`, or the character in single quotes, or
  `\x<hex>` for non-printables.
* `expected` is whatever `expect($rule)` recorded last - and `expect()` only
  stores when `$this->offset > $this->maxOffset`, i.e. the expectation that
  belongs to the deepest attempt wins.
* `expect()` calls are emitted for rules that carry a description and can
  consume input (`getMinByteLength() > 0`), plus `$this->expect('$')` for the
  end-of-input check. Descriptions live in the generated `DESCRIPTIONS`
  constant, which always contains `'$' => 'end of input'`; an unknown rule name
  falls back to the rule name itself.
* `$this->commitOffset` is saved and restored around alternatives, so a commit
  (`@`) inside a failed alternative does not leak into the reported position.

Line and column are 1-based here, unlike the interpreted engine. The token-mode
template produces the same message shape with `found` taken from
`$this->tokenData->describe($offset)`.

```php
try {
    $parser = new App\Parser\JsonParser();
    $data = $parser->parse(file_get_contents($file));
} catch (\RuntimeException $e) {
    // Parse error at line 12, column 5: expected ',' or '}', found ':'
}
```

## Reading the trace

`State::traceStart(Clause $clause)` and `State::traceEnd(Clause $clause,
$result)` build a tree of `Charm\Parsing\Trace` nodes while
`State::$trace` is true. `Clause::parse()` calls them around every clause. The
tree is reachable with `$state->getTrace()` and has public properties `ts`,
`offset`, `endOffset`, `clause`, `parent`, `children`, `hasResult`, `result`.

```php
$trace = $state->getTrace();
echo $trace;                              // indented grammar-string tree
foreach ($trace->visitPreOrder() as $node) {
    if ($node->result === Signal::MISS && $node->isPartialSuccess()) {
        printf("%s @%d..%d\n", $node->clause->getClauseName(), $node->offset, $node->endOffset);
    }
}
```

Useful members: `getMaxLength()` (how far the subtree got before backtracking),
`isPartialSuccess()`, `visitPreOrder()`, `visitRootFirst()`, `visitRootLast()`,
and `__debugInfo()` for `var_dump()`.

The rough edges:

* `visitPostOrder()` calls `$child->walk()`, which `Trace` does not define - it
  fatals as soon as a node has children. Use `visitPreOrder()`.
* `getLength()` requires `hasResult` and throws `Error\InternalError` otherwise;
  that class is not autoloaded, so you get a "Class not found" error instead.
* `CPEGParser::dumpTrace()` is private and its only call site is commented out,
  so the colourised dump it produces (via `Util\CSI`) is not available without
  patching the class.

`Charm\Parsing\Debug` has two helpers: `validateClauseTree(Clause $clause,
Clause $parent = null)` walks a clause tree and throws `\Exception` when a
child's `getParent()` disagrees with its position (useful after building
grammars programmatically), and `call_trace()` returns a fixed-width
`file|line|call` backtrace as a string.

## Classes the code references but does not ship

These names appear in `src/` but exist only under `cleanup/`, which is not in
the composer autoload. Any code path that reaches a `throw` of one of them dies
with `Error: Class "..." not found`:

| Missing class | Referenced from | Symptom |
|---|---|---|
| `Charm\Parsing\InternalError` | `Clause.php`, `Node.php`, `Clause\Commit` (imported), `Clause\NonTerminal` (imported) | "Class not found" instead of the internal error message |
| `Error\InternalError` (relative name: `Charm\Parsing\Error\InternalError` in `Trace.php`, `Charm\Parsing\Clause\Error\InternalError` in `Clause\Identifier.php`) | `Trace::assertHasResult()`, `Clause\Identifier::getClauseName()` on a clause with neither parent nor rule | as above, when reading an unfinished trace or an orphan clause |
| `Charm\Parsing\Clause\CommittedError` (unqualified `new` in namespace `Charm\Parsing\Clause`) | `Sequence::interpret()` after a `@` commit | using `@` in the interpreted engine ends in "Class not found" |
| `Charm\Parsing\ExpectedError` | `Clause\Sequence` import list | unused, but the import is unresolved |
| `ExceptionInterface` (`Charm\Parsing\ExceptionInterface` in `Clause`, `RecursiveDescentParser` and `Charm\Parser`; `Charm\Parsing\Clause\ExceptionInterface` in `Sequence`) | `catch` blocks | never matches, so nothing is caught - snippet exceptions escape instead of being turned into a miss, and the file/line fix-ups never run |
| `Charm\Parsing\Exception` | `Charm\Parser::__construct()` when `GRAMMAR` is unset | "Class not found" instead of the "GRAMMAR is not declared" message |

Practical consequences: commit symbols (`@`) are usable only in compiled
parsers, `SequenceFail` is honoured by neither engine
(`Sequence::interpret()` checks only for `SequenceIgnore` and
`SequenceResume`, and the compiler emits no check at all), and grammar classes
extending `Charm\Parser` report an unhelpful error when `GRAMMAR` is missing.

## See also

* [Getting started](getting-started.md) - `cpeg_parse()` and `Charm\Parser`.
* [Grammar syntax](grammar-syntax.md) - rule descriptions, the source of most
  interpreted error text.
* [Snippets and state](snippets-and-state.md) - `$MISS` versus throwing.
* [Compiled parsers and the CLI](compiled-parsers-and-cli.md) - generated
  `fail()`/`expect()` code in context.
* [Token mode](token-mode.md) and [The lexer API](LEXER.md) - token
  descriptions and lexing errors.
