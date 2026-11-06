# Charm/Parsing reference

Every operator, pragma, directive, snippet variable, API entry point and CLI flag appears here once, with
a one-line example and the value it yields. The chapters under [docs/](docs/index.md) explain; this page
states. Where a chapter and this page disagree, the conformance tests in `charm-tests/0030`-`0041` and
`charm-tests/0051-reference-examples.php` are right: they run the examples below through both engines (the
interpreter and compiled parsers), which agree on all of them except where a note says otherwise.

How to read the examples: the grammar, the input, and `=>` the value that `cpeg_parse()` returns, shown as
`json_encode()` shows it.

```
S = "a" "b"      "ab"  =>  ["a","b"]
```

`ERROR@1:2` means the parse fails and the generated parser reports line 1, column 2 (columns count bytes from
1; the interpreter's own message counts the column from 0).

Contents: [Grammar file](#grammar-file) | [Terminals](#terminals) | [Operators](#operators) |
[Values](#values) | [Choice, repetition, predicates, commit](#choice-repetition-predicates-and-commit) |
[Left recursion and precedence](#left-recursion-and-precedence) | [Snippets](#snippets) |
[Pragmas and directives](#pragmas-and-directives) | [Token mode](#token-mode) |
[Template rules](#template-rules) | [Errors](#errors) | [PHP API](#php-api) | [CLI](#cli) |
[Performance](#performance) | [Licence](#licence)

---

## Grammar file

A grammar is a list of rules. The **first rule is the entry point** (or the rule named by the `GOAL`
constant of a `Charm\Parser` subclass).

| Syntax | Meaning |
|---|---|
| `Name = clause` | define a rule; also `::=`, `:=` and `->` |
| `Name "description" = clause` | the description replaces the name in error messages |
| `Name = a ; Other = b` | the `;` is optional; a rule ends where the next `Name =` begins |
| `# ...`, `// ...`, `/* ... */` | comments |
| `@lexer Class`, `@prec { ... }` | directives, before the first rule ([below](#pragmas-and-directives)) |

Rule names are PHP-style identifiers, case-sensitive in the grammar, but the compiler turns each into a
method (`parseName`) and PHP method names are not case-sensitive: **two rules whose names differ only in case
(`Select` and `SELECT`) collide when compiled** (a fatal "cannot redeclare"). A rule declared twice, an empty
rule body, a reference to a rule that does not exist and an unknown pragma are errors when the grammar is read.

---

## Terminals

A terminal matches text at the current position and yields the text it matched, unless marked "no value".

| Syntax | Matches | Example | Value |
|---|---|---|---|
| `"text"`, `'text'` | exactly that text | `S = "ab"` on `ab` | `"ab"` |
| `/regex/` | PCRE at the current position (anchored, `u` mode) | `S = /[a-z]+/` on `abc1` | `"abc"` |
| `[a-c]` | character class, shorthand for `/[a-c]/` | `S = [a-c]+` on `abcd` | `["a","b","c"]` |
| `^` | start of input; zero width, no value | `S = ^ "a"` on `a` | `["a"]` |
| `$` | end of input; zero width, no value | `S = "a" $` on `a` | `["a"]` |
| `\nothing`, `ε` | always matches, zero width, no value | `S = "a" \nothing "b"` on `ab` | `["a","b"]` |
| `Name` | the rule `Name` | `S = D D` and `D = /[0-9]/` on `12` | `["1","2"]` |

* **String escapes:** `\\`, `\"`, `\'` (the surrounding quote), `\n`, `\r`, `\t`, `\0`.
* **Regexes have no flags.** `/abc/i` is the regex `/abc/` followed by a reference to a rule called `i`
  (usually an "Unknown identifier" error). Use an inline group: `/(?i:abc)/`. The text matched is returned as
  typed: `S = /(?i:select)\b/` on `SeLeCt x` yields `"SeLeCt"`. A literal `/` inside a regex is written `\/`.
  Regexes are anchored by the engine; do not write `^`.
* **There is no `.` wildcard in the grammar text**, only inside a regex (`/./`, `/[\s\S]/`).
* A failing terminal fails at the offset where it started: `S = "ab"` on `ax` fails at 1:1, not 1:2.

---

## Operators

Listed from loosest to tightest binding.

| Syntax | Name | Example | Value |
|---|---|---|---|
| `A \| B`, `A / B` | ordered choice | `S = "a" \| "b"` on `b` | `"b"` |
| `A B` | sequence | `S = "a" "b" "c"` on `abc` | `["a","b","c"]` |
| `name:A` | named capture | `S = x:"a" y:"b"` on `ab` | `{"x":"a","y":"b"}` |
| `...A` | spread | `S = ...A "z"`, `A = "a" "b"` on `abz` | `["a","b","z"]` |
| `&A` | and-predicate: A must match; consumes nothing, no value | `S = &"a" "a"` on `a` | `["a"]` |
| `!A` | not-predicate: A must fail; consumes nothing, no value | `S = !"b" "a"` on `a` | `["a"]` |
| `A?` | optional | `S = "a"?` on `a` / on the empty string | `"a"` / `null` |
| `A*` | zero or more | `S = "a"*` on `aaa` / on the empty string | `["a","a","a"]` / `[]` |
| `A+` | one or more | `S = "a"+` on `aaa` / on the empty string | `["a","a","a"]` / `ERROR@1:1` |
| `(A B)`, `<A B>`, `{A B}` | group | `S = ("a" \| "b") "c"` on `bc` | `["b","c"]` |
| `@` | commit | `S = "a" @ "b" \| "a" "c"` on `ac` | `ERROR@1:2` |
| `<? php ?>` | snippet | `S = /[0-9]+/ <? return (int) $_0; ?>` on `42` | `42` |

* A group may be written with `( )`, `< >` or `{ }`; `[ ]` is always a character class.
* A quantifier on a predicate is ignored: `S = &"a"* "a"` on `a` yields `["a"]`. Predicates stack: `!!A` is
  `&A`, and `&!A` is `!A`.
* A snippet must be the last clause of its sequence ([Snippets](#snippets)).
* `name:` and `...` combine as `...name:A` (spread first); `name:...A` is a grammar error.

---

## Values

What a clause yields, in full. "Value-less" clauses produce no value at all (internally `Signal::HIT`): `^`,
`$`, `\nothing`, predicates, `@`, and references to a `%silent` rule.

| Clause | Value |
|---|---|
| terminal | the matched text (value-less for `^`, `$`, `\nothing`) |
| rule reference | whatever the rule yields |
| choice `A \| B` | the value of the first alternative that succeeds, even an empty success |
| `A?` | A's value, or `null` when A did not match |
| `A*`, `A+` | list of the values of the iterations that produced one |
| sequence of **one clause** (`("a")`) | that clause's value, unchanged |
| sequence of **two or more clauses** | a list of the values of the clauses that have one (so `!"b" "a"` is `["a"]`, not `"a"`) |
| sequence whose clauses all lack a value | value-less |
| sequence ending in a snippet | the snippet's return value |

```
S = ("a")                 "a"     =>  "a"
S = !"b" "a"              "a"     =>  ["a"]
S = "a"? "b"              "b"     =>  [null,"b"]
S = "a" _ "b"             "a b"   =>  ["a","b"]        with  _ = /\s*/ %silent
S = &"a"                  "a"     =>  null             value-less at the top level
S = "x" (&"a") "a"        "xa"    =>  ["x","a"]        the group collected nothing: not collected
S = (&"a")+               "a"     =>  []
S = ("a" | ε)*            "aab"   =>  ["a","a"]
```

**Positions in a sequence.** Only clauses that have a value take a slot (`$_0`, `$_1`, ...). Value-less
clauses, silent references, predicates and named captures do not:

```
S = x:"a" "b" "c"                       "abc"  =>  {"x":"a","0":"b","1":"c"}
S = ws:_ "a"                            " a"   =>  {"ws":" ","0":"a"}     with _ = /\s*/ %silent: a name wins
S = "a" _ "b" <? return $_1; ?>         "a b"  =>  "b"                    _ takes no slot
```

A one-clause sequence with a name (`S = x:"a"`) yields `"a"`, not `{"x":"a"}`. With several clauses the result is
an array whose string keys are the names and whose integer keys count the unnamed values.

**Spread** (`...A`) replaces A's value by its items inside the parent sequence. A list value contributes each
of its items, and an item that is itself a list is replaced by its own items (two levels, no deeper); `null`
adds nothing; a non-list value is added as it is. Spread matters only in a sequence of two or more clauses:
`S = ...A*` alone returns what `A*` returns.

```
S = ...("a" "b")* "z"           "ababz"  =>  ["a","b","a","b","z"]
S = ("a" "b")* "z"              "ababz"  =>  [[["a","b"],["a","b"]],"z"]      without spread
S = "z" ...A    A = "a" ("b" "c")   "zabc"  =>  ["z","a","b","c"]             two levels
S = ...A? "z"   A = "a" "b"     "z"      =>  ["z"]
S = A ...("," A)*   A = /[a-z]/   "a,b,c"  =>  ["a",",","b",",","c"]
```

Because spread flattens the items of the list, `...Item*` over items that are lists or associative arrays
spills their elements into the parent. The list idiom that keeps the items intact is a repeated *group*, whose
iteration is a one-element list: `Item ...(COMMA Item)*` with `COMMA = "," %silent` yields
`[item, item, ...]`. `...name:A*` binds `$name` to the original value and also flattens it. **Known gap:** the
compiled parser does not bind the name in that combination (`S = ...p:A* <? return $p; ?>` with `A = "a"` on
`aa` yields `["a","a"]` interpreted and `null` compiled).

**Top level.** `cpeg_parse()` and a compiled `parse()` return `null` when the entry rule yields no value
(`S = ^ $` on the empty string). A parse does not have to consume all input; end the entry rule with `$` to
require it.

---

## Choice, repetition, predicates and commit

**Ordered choice.** The first alternative that succeeds wins, even if a later one would match more input, and
even if it succeeds without consuming anything.

```
S = "" | "a"                       "a"  =>  ""          the empty alternative wins
S = "a"? | "b"                     "b"  =>  null
S = A | "x"    A = "x" | \nothing  "x"  =>  "x"
```

Put longer or more specific alternatives first (`"elseif" | "else"`).

**Repetition.** `*` and `+` stop at the first iteration that fails, and also at one that succeeds without
consuming input (otherwise they would never end). An empty iteration is not collected, except that the first
iteration of `+` still counts as the one match `+` needs. Iterations without a value (`ε`, `$`, a
predicate-only group) add nothing.

```
S = ("a"*)*              "aaa"   =>  [["a","a","a"]]
S = ("a"?)*              "aaa"   =>  ["a","a","a"]
S = ε*                   ""      =>  []
S = ε+                   ""      =>  []
S = ("a" | "b")* "c"     "abac"  =>  [["a","b","a"],"c"]
```

**Predicates.** `&A` and `!A` try A at the current position and restore the position. A predicate yields no
value and takes no `$_N` slot.

```
S = &"a" "a" !"b" /[a-z]/ <? return [$_0, $_1]; ?>      "ac"  =>  ["a","c"]
```

A predicate on a terminal never becomes an error position ([Errors](#errors)). A committed failure inside a
predicate only means "A did not match": `S = !("a" @ "b") "a"` on `a` yields `["a"]`.

**Commit `@`.** "This alternative is the right one; a failure after this point is an error, not a reason to try
something else."

* It belongs to the sequence that contains it, and takes effect when passed. A later failure in that sequence
  (including a snippet returning `$MISS`) is a parse error that no enclosing `*`, `+`, `?` or choice swallows.
* A commit that is the first thing in an alternative commits on entry: `S = @ "a" | "b"` fails on `b`.
* A commit inside an alternative that succeeded is forgotten, so later failures can still backtrack:
  `S = A "z" | "xy" "q"`, `A = "x" @ "y"` on `xyq` yields `["xy","q"]`.
* It is value-less and takes no slot; a trailing `@` is harmless.

```
S = "a" @ "b" | "a" "c"        "ac"   =>  ERROR@1:2     without @ the second alternative matches
S = ("a" @ "b")* "c"           "abac" =>  ERROR@1:4     not swallowed by *
S = ("a" @ "b")? "a"           "a"    =>  ERROR@1:2     not swallowed by ?
```

A grammar that contains `@` loses two compiler optimizations ([Performance](#performance)).

---

## Left recursion and precedence

A rule is left-recursive when one of its alternatives **starts with a reference to the rule itself**. Both
engines grow such a rule by repeatedly wrapping a *start value* (parsed from the non-recursive alternatives)
in the recursive alternatives; the grouping comes from each alternative's precedence level and associativity.
Only **direct** left recursion is supported. An indirect cycle (`A = B "x" | "y"`, `B = A "z"`) is not grown:
the interpreter returns the non-recursive prefix or fails with an internal error, and a compiled parser can
hang. Rewrite it as direct recursion.

**Precedence of an alternative**, resolved in this order (`Grammar::resolveAlternativePrecedence()`, shared by
both engines):

1. `%prec=N` (an integer) is used as it is. `%prec=NAME` looks `NAME` up in the `@prec` table and takes its
   level and its associativity. A `NAME` that is not in the table has no effect; resolution goes on with 2.
2. With an `@prec` table: the alternative's *operator*, the first string literal (or token, in token mode) that
   is not the rule itself or a snippet, is looked up in the table. A rule reference such as `OR` is never an
   operator here; write `%prec=OR`.
3. Otherwise the position: with N alternatives the first has level N and the last 1, so the first alternative
   binds tightest.

Associativity is `left` unless the table says otherwise or `%assoc=...` is given; `%assoc` always wins. `none`
is treated like `left` in both engines: `E = E "<" E %assoc=none | /[0-9]/` on `1<2<3` yields
`[["1","<","2"],"<","3"]`. To forbid chaining, keep the operator out of a left-recursive rule, as the
[SQL tutorial](docs/tutorial-sql.md) does.

```
E = E "*" E | E "+" E | /[0-9]/                    "1+2*3"  =>  ["1","+",["2","*","3"]]       position
E = E "+" E | E "*" E | /[0-9]/                    "1+2*3"  =>  [["1","+","2"],"*","3"]
E = E "-" E | /[0-9]/                              "1-2-3"  =>  [["1","-","2"],"-","3"]       left
E = E "^" E %assoc=right | /[0-9]/                 "1^2^3"  =>  ["1","^",["2","^","3"]]
E = E "+" E %prec=2 | E "*" E %prec=1 | /[0-9]/    "1+2*3"  =>  [["1","+","2"],"*","3"]       %prec=N
```

With an `@prec` table (levels number from 1, later lines bind tighter; operators are strings, or identifiers
that `%prec=NAME` can refer to):

```
@prec { left "+" "-"  left "*" }
E = E "*" E | E "+" E | /[0-9]/                    "1+2*3"  =>  ["1","+",["2","*","3"]]

@prec { right "^" }
E = E "^" E | /[0-9]/                              "1^2^3"  =>  ["1","^",["2","^","3"]]

@prec { left "^" }
E = E "^" E %assoc=right | /[0-9]/                 "1^2^3"  =>  ["1","^",["2","^","3"]]       %assoc overrides

@prec { left PLUS  left TIMES }
E = E /\*/ E %prec=TIMES | E /\+/ E %prec=PLUS | /[0-9]/    "1+2*3"  =>  ["1","+",["2","*","3"]]
```

**Where the recursive reference sits.** A reference at the **end** of an alternative that does not start with
the rule (`"-" E`) is a prefix operator's operand: it is parsed with the alternative's precedence as the floor, so
only tighter operators fold into it. A reference followed by something else (`"(" E ")"`, the middle of
`E "?" E ":" E`) is delimited and is a complete expression whatever the precedence. A postfix alternative
(`E "!"`) wraps the left value like an infix one.

```
E = "-" E | E "+" E | /[0-9]/              "-1+2"    =>  [["-","1"],"+","2"]      prefix listed first: tightest
E = E "+" E | "-" E | /[0-9]/              "-1+2"    =>  ["-",["1","+","2"]]
E = "(" E ")" | E "+" E | /[0-9]/          "(1+2)+3" =>  [["(",["1","+","2"],")"],"+","3"]
E = E "!" | E "+" E | /[0-9]/              "1+2!"    =>  ["1","+",["2","!"]]
```

---

## Snippets

`<? php ?>` is PHP run when the sequence it ends has matched. It is the **last clause of its sequence** (the
grammar reader rejects anything else). The code is compiled when the grammar is read, so PHP syntax errors
appear then. Write `\Name` for classes (the interpreter evaluates the code in the `Charm\Parsing\Clause`
namespace, where a bare class name may resolve to a library class); bare function names fall back to PHP's
global ones.

| Variable | Contents | Interpreter | Compiled |
|---|---|---|---|
| `$_0`, `$_1`, ... | values of the sequence by position (value-less clauses, silent references, predicates and named captures take no slot) | yes | yes |
| `$name` | named capture; `null` when an optional part did not match | yes | yes |
| `$context` | the whole collected array, names and numbers | yes | no |
| `$state` | array shared by all snippets of one parse | yes | yes |
| `$HIT`, `$MISS` | `Signal::HIT`, `Signal::MISS` | yes | yes |
| `$_offset` | byte offset where the rule started | no | yes |

Reading a variable that does not exist: the interpreter throws `SnippetError` ("Undefined variable $n"); a
compiled parser reads `null`.

**What a snippet returns.**

| Return | Effect |
|---|---|
| any value, including `null`, `false`, `true`, `0` | the value of the sequence (`false` is a value, not a failure) |
| `$MISS` (`Signal::MISS`) | the sequence fails: the next alternative is tried, or the parse fails (a parse error after a commit) |
| `new \Charm\Parsing\SequenceIgnore` | the sequence succeeds with no value |
| `new \Charm\Parsing\SequenceResume` | the snippet counts as not there; the sequence's value is what was collected before it |
| an exception thrown | `Charm\Parsing\Error\SnippetError` (message `Error in snippet: ...`, original in `getPrevious()`); the parse ends and no alternative is tried. A compiled parser rethrows it as it is when `SnippetError` is not loaded |

```
S = /[0-9]+/ <? return (int) $_0 > 9 ? $MISS : $_0; ?> | /[0-9]+/     "12"  =>  "12"     the second alternative
S = "a" <? return false; ?>                                           "a"   =>  false
S = "x" A "y"    A = "a" <? return new \Charm\Parsing\SequenceIgnore(); ?>    "xay"  =>  ["x","y"]
S = "a" <? return new \Charm\Parsing\SequenceResume(); ?>             "a"   =>  ["a"]
```

**`$state`** is seeded by the caller (`cpeg_parse($grammar, $input, ['n' => 0])`, a compiled
`parse($input, ['n' => 0])`, the last argument of `CPEGParser::parse()`) and its writes last for the rest of
the parse. The interpreter replaces it on every parse; a compiled parser merges the seed into what the
previous `parse()` on the same object left behind, so seed whatever you read. **A snippet runs when its own
sequence matches, even if an enclosing alternative later fails and backtracks, and `$state` is not rolled
back.**

```
S = A A    A = "a" <? $n = $state['n'] ?? 0; $state['n'] = $n + 1; return $n; ?>     "aa"  =>  [0,1]
```

---

## Pragmas and directives

A pragma `%name` or `%name=value` attaches to the alternative (sequence) it ends, or to a rule's clause when
written after the whole rule body. Anything else is a `\LogicException` ("Unknown pragma directive %foo.
Supported: ...").

| Pragma | Where | Effect |
|---|---|---|
| `%assoc=left\|right\|none` | an alternative of a left-recursive rule | associativity; `none` behaves as `left` |
| `%prec=N`, `%prec=NAME` | an alternative of a left-recursive rule | precedence level, or a name from the `@prec` table |
| `%silent` | a rule | references to the rule yield no value and take no slot, unless named |

A pragma on the last alternative of a multi-alternative rule applies to that alternative only; wrap the body in
parentheses to give every alternative the pragma.

| Directive | Effect |
|---|---|
| `@prec { left "+" "-"  right T_POW  none "<" }` | precedence table: one level per line (`left`, `right` or `none`, then operators as strings or identifiers); levels count from 1 and later lines bind tighter |
| `@lexer Class\Name` | switch the grammar to [token mode](#token-mode) |

---

## Token mode

`@lexer Some\Lexer` makes the grammar match a token stream instead of characters.

| Syntax in token mode | Matches | Yields |
|---|---|---|
| `T_NAME` (identifier starting with `T_`) | a token of that type | the token's text |
| `"+"` | a single-character token | the literal |
| `/regex/` | not allowed ("Regex patterns are not supported in token mode") | |

Boundaries: only the **compiled** engine supports token mode; `cpeg_parse()` throws `\LogicException` ("Token
clause can only be used in token mode"). The generated parser is not standalone: it needs
`Charm\Parsing\TokensInterface` and the lexer class at run time, and token names must exist as PHP constants.
A multi-character operator that the lexer emits as a typed token (`**` as `T_POW`) cannot be matched as a
literal. `@prec` works as in string mode. See [token mode](docs/token-mode.md) and [lexing](docs/LEXER.md).

---

## Template rules

```
S = Paren<Num>        Paren<T> = "(" T ")"          "(1)"  =>  ["(","1",")"]
S = List<Num, ",">    List<T, Sep> = T (Sep T)*     "1,2"  =>  ["1",[[",","2"]]]
```

Parameters may be rules, string literals or nested instantiations. `GrammarPreprocessor` expands templates,
and only `cpeg_parse()` and `cpeg_create()` run it: a `Charm\Parser` subclass and `charm-compile` read the
grammar as it is and reject `Name<Arg>`.

---

## Errors

**Where.** An error is reported at the **furthest offset at which a terminal failed**, which is where that
terminal started (not where the alternative began): `S = "ab"` on `ax` is 1:1. `$` counts. **Predicates do
not**: a predicate on a terminal (`&"b"`, `!"c"`) and the attempt that ends a repetition of a terminal (`"a"*`)
are not positions. Columns are bytes; the compiled parser counts lines and columns from 1, the interpreter's
messages count the column from 0.

**What is thrown.**

| Class | When |
|---|---|
| `Charm\Parsing\Error\ParseError` | interpreter: the entry rule did not match ("Failed parsing X on file:line:col, unexpected Y") |
| `Charm\Parsing\Error\State\CommittedError` | interpreter: a failure after `@` ("Expected X in Rule on file:line:col, found Y") |
| `{Class}ParseError`, a `\RuntimeException` | compiled: generated into the parser's file; readonly `committed`, `sourceLine`, `sourceColumn`, `offset`, `expected`, `found`; message "Parse error at line L, column C: expected E, found F" |
| `Charm\Parsing\Error\SnippetError` | a snippet threw |
| `Charm\Parsing\Error\State\ParseError` | the grammar text is wrong (message ends `on file:line:col`) |
| `\LogicException` | unknown pragma; a token clause under the interpreter |

`catch (\RuntimeException)` covers the first four. A compiled `expected` is the first terminal tried at the
failing offset, or the description of a described rule that was entered there: describe the rules users will
see, and know that a regex without a description is quoted as its source. See
[errors and debugging](docs/errors-and-debugging.md).

---

## PHP API

| Call | Meaning |
|---|---|
| `Charm\cpeg_parse(string $grammar, string $subject, array $initialState = []): mixed` | interpret `$grammar` (cached, 20 grammars) on `$subject`; templates expanded; with the environment variable `CHARM_COMPILED` set it compiles and `eval()`s instead |
| `Charm\cpeg_create(string $grammar)` | the parser object behind it |
| `class P extends Charm\Parser { const GRAMMAR = '...'; const GOAL = 'Rule'; const OPTIONS = [...]; }` | a reusable interpreted parser; `->parse($source, $filename, $lineOffset = 0, $columnOffset = 0, $initialState = [])`; no template expansion |
| `(new Charm\Parsing\GrammarParser())->parse($text, $filename): Grammar` | read a grammar yourself (no templates) |
| `(new Charm\Parsing\Compiler\GrammarCompiler())->compile($grammar, $className, $options): string` | generate a class; options `namespace`, `implements` (array), `baseClass`, `template` (path), `globals` (seed array) |
| `GrammarCompiler::compileToFile($grammar, $className, $path, $options)` | the same, written to a file |
| generated `->parse(string $source, array $initialState = []): mixed` | the compiled parser; it does not check for trailing input |

---

## CLI

`vendor/bin/charm-compile <grammar-file|-> [options]` (`-` reads standard input). The class name defaults to
the file name without `.peg` or `.grammar`, capitalised, plus `Parser`: `expr.peg` is `ExprParser`, `x.cpeg` is
`XcpegParser` (pass `-c`). Status messages go to standard error.

| Flag | Meaning |
|---|---|
| `-o`, `--output <file>` | write here instead of standard output; missing directories are created |
| `-c`, `--class <name>` | class name (letters, digits, underscore) |
| `-n`, `--namespace <ns>` | namespace of the generated class |
| `-e`, `--extends <class>` | base class |
| `-i`, `--implements <a,b>` | interfaces, comma separated |
| `-t`, `--template <file>` | custom code-generation template, replacing the built-in one |
| `-q`, `--quiet` | no progress messages |
| `-h`, `--help` | usage, exit 0 (no arguments prints it and exits 1) |

It does not expand template rules and has no flag for the `globals` option.

---

## Performance

* Compiled parsers are about six times faster than the interpreter on a realistic grammar (the
  [SQL tutorial](docs/tutorial-sql.md#step-15-compiling-the-grammar) measures 1.65 ms against 0.29 ms for a
  221-byte query) and between 6 and 10 times on the JSON benchmarks in `benchmarks/`. Compile when parse time
  matters; use the interpreter while developing.
* For each ordered choice the compiler uses one combined regex (alternatives that differ in one terminal), a
  `switch` on the first byte, or sequential tries, and it guards rule calls by first byte. That depends on the
  compiler **understanding your regexes**: it reads ECMAScript-style syntax, and an inline `(?i:...)` group
  makes it give up on that regex (minimum length 0, first bytes unknown). Keywords spelled `[Ss][Ee][Ll]...`
  are analysable; in the tutorial grammar that is 24 percent faster compiled.
* The first-byte `switch` and the single-regex optimization are **off for a grammar that contains `@`**,
  because an alternative they skip might have been a committed error. Measure before dropping commits for
  speed; in the tutorial grammar the difference is below the noise.
* `Charm\Parsing\Optimizer\ConsecutiveTerminalsOptimizer` (opt-in, through `OptimizerPipeline`) merges adjacent
  literal alternatives into one regex.
* Do not parse the same operand twice. `Pred = Sum "<" Sum | Sum "=" Sum | Sum` re-parses `Sum` for every
  alternative; with `Prim = /[0-9]+/ | "(" Pred ")"` the cost multiplies at every level of nesting. Eight nested
  parentheses took 225 ms compiled and 49 s interpreted, against 0.9 ms and 6.5 ms for the factored form
  `Pred = Sum (("<" | "=") Sum)?`. Factor the shared prefix out and return the tail.

---

## Licence

`charm/parsing` is **GPL-3.0-only** with an additional permission, a Bison-style output exception, in
[LICENCE.md](LICENCE.md). In plain words: the library and the compiler stay GPL. **A parser that
`charm-compile` generates from your grammar is yours.** It, including the template text and runtime support
copied into it, is not covered by the GPL, and you may distribute it under any terms. The exception also covers
the interfaces and lexer classes a generated token-mode parser needs at run time. It does not extend to a
modified compiler or library. Generated files say so in their header. Running the interpreter
(`cpeg_parse()`) uses the library itself, so it is the compiled output that carries the exception.
