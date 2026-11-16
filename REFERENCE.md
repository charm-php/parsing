# Charm/Parsing reference

Every operator, pragma, directive, snippet variable, API entry point and CLI flag appears here once, with
a one-line example and the value it yields. The chapters under [docs/](docs/index.md) explain; this page
states. Where a chapter and this page disagree, the conformance tests in `charm-tests/0030`-`0041` and
`charm-tests/0051-reference-examples.php` are right: they run the examples below through both engines (the
interpreter and compiled parsers), which agree on all of them except where a note says otherwise.

How to read the examples: the grammar, the input, and `=>` the value that `cpeg_parse()` returns, shown as
`json_encode()` shows it.

```text
S = "a" "b"      "ab"  =>  ["a","b"]
```

`ERROR@1:2` means the parse fails and the generated parser reports line 1, column 2 (columns count bytes from
1; the interpreter now agrees).

Contents: [Grammar file](#grammar-file) | [Terminals](#terminals) | [Operators](#operators) |
[Values](#values) | [Choice, repetition, predicates, commit](#choice-repetition-predicates-and-commit) |
[Left recursion and precedence](#left-recursion-and-precedence) | [Snippets](#snippets) |
[Pragmas and directives](#pragmas-and-directives) | [Token mode](#token-mode) |
[Template rules](#template-rules) | [Errors](#errors) | [PHP API](#php-api) | [CLI](#cli) |
[Portable targets: C, JavaScript, Python](#portable-targets-c-javascript-python) | [Portable snippets](#portable-snippets) |
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
method (`parseName`); PHP method names are not case-sensitive, so rules whose names differ only in case
(`Select` and `SELECT`) are given distinct methods (`parseSelect`, `parseSELECT__2`). A rule declared twice, an empty
rule body, a reference to a rule that does not exist and an unknown pragma are errors when the grammar is read.

---

## Terminals

A terminal matches text at the current position and yields the text it matched, unless marked "no value".

| Syntax | Matches | Example | Value |
|---|---|---|---|
| `"text"`, `'text'` | exactly that text | `S = "ab"` on `ab` | `"ab"` |
| `"text"i`, `'text'i` | that text in any letter case (the `i` follows the quote directly) | `S = "ab"i` on `aB` | `"aB"` |
| `/regex/` | PCRE at the current position (anchored, `u` mode) | `S = /[a-z]+/` on `abc1` | `"abc"` |
| `[a-c]` | character class, shorthand for `/[a-c]/` | `S = [a-c]+` on `abcd` | `["a","b","c"]` |
| `^` | start of input; zero width, no value | `S = ^ "a"` on `a` | `["a"]` |
| `$` | end of input; zero width, no value | `S = "a" $` on `a` | `["a"]` |
| `\nothing`, `ε` | always matches, zero width, no value | `S = "a" \nothing "b"` on `ab` | `["a","b"]` |
| `Name` | the rule `Name` | `S = D D` and `D = /[0-9]/` on `12` | `["1","2"]` |

* **String escapes:** `\\`, `\"`, `\'` (the surrounding quote), `\n`, `\r`, `\t`, `\0`.
* **Regexes have no flags.** `/abc/i` is the regex `/abc/` followed by a reference to a rule called `i`
  (usually an "Unknown identifier" error). For case-insensitive text use the literal `"abc"i`, or an inline
  group in a regex: `/(?i:abc)/`. The text matched is returned as typed: `S = "select"i /\b/` and
  `S = /(?i:select)\b/` both give `"SeLeCt"` on `SeLeCt x`. `"abc"i` folds ASCII letters only for an ASCII
  literal and uses Unicode case folding (`"é"i` matches `É`) otherwise; it works in string mode only, not
  in token mode. The flag composes like any literal (`k:"and"i+`, `!"x"i`); it is printed back as `"abc"i`
  in messages and by `toGrammarString()`. `"a"id` is still `"a"` followed by the rule `id`. A literal `/` inside a regex is written `\/`.
  Regexes are anchored by the engine; do not write `^`.
* **There is no `.` wildcard in the grammar text**, only inside a regex (`/./`, `/[\s\S]/`).
* A failing terminal fails at the offset where it started: `S = "ab"` on `ax` fails at 1:1, not 1:2.

---

## Operators

Listed from loosest to tightest binding.

| Syntax | Name | Example | Value |
|---|---|---|---|
| `A \| B`, `A / B` | ordered choice | `S = "a" \| "b"` on `b` | `"b"` |
| `A B` | sequence | `S = "a" "b" "c"` on `abc` | `["a","b","c"]` (one value-producing element: that value, see [Value of a sequence](#value-of-a-sequence)) |
| `name:A` | named capture | `S = x:"a" y:"b"` on `ab` | `{"x":"a","y":"b"}` |
| `...A` | spread | `S = ...A "z"`, `A = "a" "b"` on `abz` | `["a","b","z"]` |
| `&A` | and-predicate: A must match; consumes nothing, no value | `S = &"a" "a"` on `a` | `"a"` |
| `!A` | not-predicate: A must fail; consumes nothing, no value | `S = !"b" "a"` on `a` | `"a"` |
| `A?` | optional | `S = "a"?` on `a` / on the empty string | `"a"` / `null` |
| `A*` | zero or more | `S = "a"*` on `aaa` / on the empty string | `["a","a","a"]` / `[]` |
| `A+` | one or more | `S = "a"+` on `aaa` / on the empty string | `["a","a","a"]` / `ERROR@1:1` |
| `(A B)`, `<A B>`, `{A B}` | group | `S = ("a" \| "b") "c"` on `bc` | `["b","c"]` |
| `@` | commit | `S = "a" @ "b" \| "a" "c"` on `ac` | `ERROR@1:2` |
| `<? php ?>` | snippet | `S = /[0-9]+/ <? return (int) $_0; ?>` on `42` | `42` |

* A group may be written with `( )`, `< >` or `{ }`; `[ ]` is always a character class.
* A quantifier on a predicate is ignored: `S = &"a"* "a"` on `a` yields `"a"`. Predicates stack: `!!A` is
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
| sequence in which **exactly one element can produce a value** | that value itself (`!"b" "a"` is `"a"`, `"a" $` is `"a"`) |
| sequence with **two or more elements that can produce a value** | a list of their values |
| sequence with no element that can produce a value | value-less |
| sequence ending in a snippet | the snippet's return value |

```text
S = ("a")                 "a"     =>  "a"
S = !"b" "a"              "a"     =>  "a"             one element can produce a value: it collapses
S = "a"? "b"              "b"     =>  [null,"b"]
S = "a" _ "b"             "a b"   =>  ["a","b"]        with  _ = /\s*/ %silent
S = &"a"                  "a"     =>  null             value-less at the top level
S = "x" (&"a") "a"        "xa"    =>  ["x","a"]        the group collected nothing: not collected
S = (&"a")+               "a"     =>  []
S = ("a" | ε)*            "aab"   =>  ["a","a"]
```

### Value of a sequence

A sequence (a rule body or a parenthesised group) whose elements include **exactly one element that can
produce a value** returns that element's value itself, not a one-element list. This is decided from the
grammar, never from the input, so the shape of a rule's result is the same for every input.

An element **cannot** produce a value if it is one of:

* a predicate (`&A`, `!A`)
* `^` or `$`
* `\nothing` (`ε`)
* `@`
* a snippet
* a reference to a `%silent` rule

Every other element can: a terminal, a rule reference, a group, `A?` (a miss is `null`, which is a value),
`A*`, `A+`. Consequently:

* zero elements that can produce a value: the sequence is value-less (`Signal::HIT`, `null` at the top level);
* two or more: a list of their values, as before (`"a" "b"?` is `["a", null]`);
* a spread element (`...A`) can produce several values, and a named capture (`n:A`) makes the result an
  array (`{"n": ...}`), so a sequence containing either never collapses;
* a reference to a rule that only *sometimes* returns a value (its snippet returns `SequenceIgnore`) counts
  as an element that can produce one; when it did not, the list simply lacks its entry;
* a sequence ending in a snippet is not collapsed: the snippet's return value is the value, and its
  `$_0`, `$_1`, ... are numbered as before (value-less elements take no slot, collapse or not);
* the alternatives of a left-recursive (Pratt) rule that start with the rule itself (the operator
  alternatives, `E = E "+" E`) are not collapsed: they return the list `[left, "+", right]`. Their other
  alternatives are ordinary sequences.

```text
S = "a" $                    "a"     =>  "a"               $ has no value
S = Letter+ _                "ab "   =>  ["a","b"]         with _ = /\s*/ %silent; one element can produce a value
S = ("a" _) "b"              "a b"   =>  ["a","b"]         the group collapses to "a"; the outer sequence has two
S = _ A _                    " a "   =>  the value of A    with _ = /\s*/ %silent
S = "a" "b"?                 "a"     =>  ["a",null]
S = n:A _                    "a"     =>  {"n":"a"}
S = &"a"                     "a"     =>  null              no element can produce a value
```

To get a one-element list back, add a second element that can produce a value (a group never helps: it
collapses too) or end the sequence in a snippet: `S = "a" $ <? return [$_0]; ?>`.

A spread of a repetition of something that collapses flattens one level, because the iterations are not
wrapped in lists any more (see Spread below).

**Positions in a sequence.** Only clauses that have a value take a slot (`$_0`, `$_1`, ...). Value-less
clauses, silent references, predicates and named captures do not:

```text
S = x:"a" "b" "c"                       "abc"  =>  {"x":"a","0":"b","1":"c"}
S = ws:_ "a"                            " a"   =>  {"ws":" ","0":"a"}     with _ = /\s*/ %silent: a name wins
S = "a" _ "b" <? return $_1; ?>         "a b"  =>  "b"                    _ takes no slot
```

A one-clause sequence with a name (`S = x:"a"`) yields `"a"`, not `{"x":"a"}`. With several clauses the result is
an array whose string keys are the names and whose integer keys count the unnamed values.

**Spread** (`...A`) replaces A's value by its items inside the parent sequence. A list value contributes each
of its items, and an item that is itself a list is replaced by its own items (two levels, no deeper; one
level when what is spread is a group or rule that collapses, see [Value of a sequence](#value-of-a-sequence)); `null`
adds nothing; a non-list value is added as it is. Spread matters only in a sequence of two or more clauses:
`S = ...A*` alone returns what `A*` returns.

```text
S = ...("a" "b")* "z"           "ababz"  =>  ["a","b","a","b","z"]
S = ("a" "b")* "z"              "ababz"  =>  [[["a","b"],["a","b"]],"z"]      without spread
S = "z" ...A    A = "a" ("b" "c")   "zabc"  =>  ["z","a","b","c"]             two levels
S = ...A? "z"   A = "a" "b"     "z"      =>  ["z"]
S = A ...("," A)*   A = /[a-z]/   "a,b,c"  =>  ["a",",","b",",","c"]
```

Because spread flattens the items of the list, `...Item*` over items that are lists or associative arrays
spills their elements into the parent. The list idiom that keeps the items intact is a repeated *group*, whose
iteration is the item itself because the group collapses: `Item ...(COMMA Item)*` with `COMMA = "," %silent`
yields `[item, item, ...]`. `...name:A*` binds `$name` to the original value and also flattens it, so the elements are
`$_0`, `$_1`, ... (`S = ...p:A* <? return [$p, $_0, $_1]; ?>` with `A = "a"` on `aa` gives `[["a","a"],"a","a"]`
in both engines).

**Top level.** `cpeg_parse()` and a compiled `parse()` return `null` when the entry rule yields no value
(`S = ^ $` on the empty string). A parse does not have to consume all input; end the entry rule with `$` to
require it.

---

## Choice, repetition, predicates and commit

**Ordered choice.** The first alternative that succeeds wins, even if a later one would match more input, and
even if it succeeds without consuming anything.

```text
S = "" | "a"                       "a"  =>  ""          the empty alternative wins
S = "a"? | "b"                     "b"  =>  null
S = A | "x"    A = "x" | \nothing  "x"  =>  "x"
```

Put longer or more specific alternatives first (`"elseif" | "else"`).

**Repetition.** `*` and `+` stop at the first iteration that fails, and also at one that succeeds without
consuming input (otherwise they would never end). An empty iteration is not collected, except that the first
iteration of `+` still counts as the one match `+` needs. Iterations without a value (`ε`, `$`, a
predicate-only group) add nothing.

```text
S = ("a"*)*              "aaa"   =>  [["a","a","a"]]
S = ("a"?)*              "aaa"   =>  ["a","a","a"]
S = ε*                   ""      =>  []
S = ε+                   ""      =>  []
S = ("a" | "b")* "c"     "abac"  =>  [["a","b","a"],"c"]
```

**Predicates.** `&A` and `!A` try A at the current position and restore the position. A predicate yields no
value and takes no `$_N` slot.

```text
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

```text
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
Precedence and associativity apply to **direct** recursion only. An **indirect** cycle, a rule that reaches
itself in left position through other rules (`A = B "x" | "y"`, `B = A "z"`), is *seed-grown* instead: while
`A` is being parsed at an offset, a nested `A` at that offset returns the best result so far (at first a miss,
so `B "x"` fails and `"y"` provides the seed), and `A` is re-parsed until it stops getting longer. `yzxzx`
gives `[[[["y","z"],"x"],"z"],"x"]`. Alternatives are tried in order on every pass and a right operand is
parsed greedily, so `T = E "*" E | E "+" E | Num` with `E = T` groups `1+2*3` as `["1","+",["2","*","3"]]`
whatever `%prec` says; write operator rules with direct recursion. A snippet in a grown rule runs once per
pass. A reference only reached after consuming input (`A = "(" B ")"`) is ordinary recursion, not a cycle.

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

```text
E = E "*" E | E "+" E | /[0-9]/                    "1+2*3"  =>  ["1","+",["2","*","3"]]       position
E = E "+" E | E "*" E | /[0-9]/                    "1+2*3"  =>  [["1","+","2"],"*","3"]
E = E "-" E | /[0-9]/                              "1-2-3"  =>  [["1","-","2"],"-","3"]       left
E = E "^" E %assoc=right | /[0-9]/                 "1^2^3"  =>  ["1","^",["2","^","3"]]
E = E "+" E %prec=2 | E "*" E %prec=1 | /[0-9]/    "1+2*3"  =>  [["1","+","2"],"*","3"]       %prec=N
```

With an `@prec` table (levels number from 1, later lines bind tighter; operators are strings, or identifiers
that `%prec=NAME` can refer to):

```text
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

```text
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
| `$context` | the whole collected array, names and numbers | yes | yes |
| `$state` | array shared by all snippets of one parse | yes | yes |
| `$HIT`, `$MISS` | `Signal::HIT`, `Signal::MISS` | yes | yes |
| `$_offset` | byte offset where the rule that contains the snippet started (for a left-recursive rule: where the whole expression started) | yes | yes |
| `$_line`, `$_column` | 1-based line and column (bytes) of `$_offset` | yes | yes |

Reading a variable that does not exist throws `SnippetError` ("Undefined variable $n. Available variables are
...") in both engines; use `$_5 ?? null` or `isset($_5)` to probe. Named captures take no `$_N` slot.

**What a snippet returns.**

| Return | Effect |
|---|---|
| any value, including `null`, `false`, `true`, `0` | the value of the sequence (`false` is a value, not a failure) |
| `$MISS` (`Signal::MISS`) | the sequence fails: the next alternative is tried, or the parse fails (a parse error after a commit) |
| `new \Charm\Parsing\SequenceIgnore` | the sequence succeeds with no value |
| `new \Charm\Parsing\SequenceResume` | the snippet counts as not there; the sequence's value is what was collected before it |
| an exception thrown | `Charm\Parsing\Error\SnippetError` (message `Error in snippet: ...`, original in `getPrevious()`); the parse ends and no alternative is tried. A compiled parser rethrows it as it is when `SnippetError` is not loaded |

```text
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

```text
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

```text
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
messages count it from 1 as well.

**What is thrown.**

| Class | When |
|---|---|
| `Charm\Parsing\Error\ParseError` | interpreter: the entry rule did not match ("Failed parsing X on file:line:col, unexpected Y") |
| `Charm\Parsing\Error\State\CommittedError` | interpreter: a failure after `@` ("Expected X in Rule on file:line:col, found Y") |
| `{Class}ParseError`, a `\RuntimeException` | compiled: generated into the parser's file; readonly `committed`, `sourceLine`, `sourceColumn`, `offset`, `expected`, `found`; message "Parse error at line L, column C: expected E, found F" |
| `Charm\Parsing\Error\SnippetError` | a snippet threw |
| `Charm\Parsing\Error\State\ParseError` | the grammar text is wrong (message ends `on file:line:col`) |
| `\LogicException` | unknown pragma; a token clause under the interpreter |

`catch (\RuntimeException)` covers the first four. A compiled `expected` lists **every** terminal that failed at the
failing offset, joined with `" or "` (`expected "b" or "c"`), and the description of every described rule that
was entered there; a described rule's description stands for the terminals inside it, so describing the rules
users will see keeps the list short. A regex without a description is quoted as its source. The interpreter
appends the same list to its message (`..., unexpected "x", expected "b" or "c"`); `CommittedError` names rules by
their description and counts lines from 1. See
[errors and debugging](docs/errors-and-debugging.md).

---

## PHP API

| Call | Meaning |
|---|---|
| `Charm\cpeg_parse(string $grammar, string $subject, array $initialState = []): mixed` | interpret `$grammar` (cached, 20 grammars) on `$subject`; templates expanded; with the environment variable `CHARM_COMPILED` set it compiles and `eval()`s instead |
| `Charm\cpeg_create(string $grammar)` | the parser object behind it |
| `class P extends Charm\Parser { const GRAMMAR = '...'; const GOAL = 'Rule'; const OPTIONS = [...]; }` | a reusable interpreted parser; `->parse($source, $filename, $lineOffset = 0, $columnOffset = 0, $initialState = [])`; no template expansion |
| `new Charm\Parsing\CPEGParser(Grammar $grammar, ?string $goal = null, ?Options $options = null)` | an interpreter over a `Grammar` you built; `->parse($source, $filename, $lineOffset = 0, $columnOffset = 0, $initialState = [])`. `$filename` and the offsets are not used by the interpreter's messages |
| `Charm\Parsing\Options` | `trace` (bool, false), `auto_tokenize` (bool, true), `base_namespace` (string, ''); see [errors and debugging](docs/errors-and-debugging.md#parser-options) |
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
| `-T`, `--target <t>` | `php` (default), `c`, `js` or `python`: see [Portable targets](#portable-targets-c-javascript-python) |
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
  makes it give up on that regex (minimum length 0, first bytes unknown). Keywords spelled `"select"i` or
  `[Ss][Ee][Ll]...` are analysable (a case-insensitive literal counts as both cases of its first byte, or as
  unknown if it is not ASCII); in the tutorial grammar that is around 20 percent faster compiled.
* The first-byte `switch` and the single-regex optimization are **off for a grammar that contains `@`**,
  because an alternative they skip might have been a committed error. Measure before dropping commits for
  speed; in the tutorial grammar the difference is below the noise.
* Compiled parsers only guard a rule call on its first byte when the rule has a description (the description is
  what is expected there); the old first-byte `switch` over alternatives is gone, so every alternative reports what it expected.
* `Charm\Parsing\Optimizer\ConsecutiveTerminalsOptimizer` (opt-in, through `OptimizerPipeline`) merges adjacent
  literal alternatives into one regex.
* Do not parse the same operand twice. `Pred = Sum "<" Sum | Sum "=" Sum | Sum` re-parses `Sum` for every
  alternative; with `Prim = /[0-9]+/ | "(" Pred ")"` the cost multiplies at every level of nesting. Eight nested
  parentheses took 225 ms compiled and 49 s interpreted, against 0.9 ms and 6.5 ms for the factored form
  `Pred = Sum (("<" | "=") Sum)?`. Factor the shared prefix out and return the tail.

---

## Portable targets: C, JavaScript, Python

`charm-compile grammar.cpeg --target=c|js|python -o parser.<ext>` generates a standalone parser in that language
that returns **exactly the tree the PHP parsers return** for the same grammar (the conformance suite runs every
row through both PHP engines and all three targets, and compares trees, error positions, `committed`, `found` and
`expected`). A generated file is the same small engine in each language plus the grammar as integer tables; its header says it is covered
by the output exception ([Licence](#licence)).

What a target accepts: any string-mode grammar whose snippets are [portable](#portable-snippets) (a grammar without snippets
always qualifies). A grammar with another snippet, or in token mode (`@lexer`), is rejected at generation time with
the rule and the snippet in the message; so is a regex outside the subset below. Supported and identical to the PHP engines: ordered choice, predicates,
repetition (empty iterations stop), labels, spread (two levels, null skipping), the single-value collapse rule,
`%silent`, `%prec`/`%assoc`/`@prec`, Pratt parsing of direct left recursion, seed growing for indirect left
recursion, `@`, `"x"i`, and error reporting (furthest failure, expected set, 1-based line and byte column).

| | C | JavaScript | Python |
|---|---|---|---|
| Output | `parser.c` + `parser.h` (`-o x.c` writes `x.h`; `-c` is the identifier prefix) | one CommonJS module (`-c` is only a label) | one module |
| Call | `r = P_parse(src, len, &err)`; `P_result_value(r)`; `P_result_free(r)` | `parse(stringOrBuffer)` | `parse(str_or_bytes)` |
| Tree | tagged `P_value`: NULL, STRING (slice of the source, not NUL-terminated), LIST, MAP (ordered, keys are labels or integers), INT, BOOL | `null`, string, array, plain object, number, boolean | `None`, `str`, `list`, `dict` (insertion order), `int`, `bool` |
| Error | `P_error` (`kind`, `committed`, `offset`, `line`, `column`, `expected`, `found`, `message`) | `ParseError` (`committed`, `sourceLine`, `sourceColumn`, `offset`, `expected`, `found`) | `ParseError` (`committed`, `source_line`, `source_column`, ...) |
| Extras | `P_get(map, "label")`, `P_to_json(value, buf, cap)`; no global state, reentrant; C99, warning-free with `-Wall -Wextra` under gcc and as C++ | | |

Details that differ from PHP, all deliberate:

* **Offsets and columns are bytes of the UTF-8 input in every target** (JavaScript and Python convert strings to UTF-8 first).
* **JavaScript object key order**: integer-like keys (`"0"`, `"1"`, the unlabelled values of a map) are listed before labels,
  because that is how JavaScript orders object keys. Everything else, including labelled values, is in PHP's order.
* **Nesting depth**: rule calls nest at most 2500 deep in C (`-DP_MAX_DEPTH=n`), 1200 in JavaScript and Python; deeper input is an error
  (`P_ERR_DEPTH`, `Error: nesting too deep`, `RecursionError`) instead of a crash.
* **Invalid UTF-8**: a regex terminal does not match across an invalid sequence (PHP's `preg_match` with `u` fails the whole match).
* The C tree owns an arena: free it once with `P_result_free`; the source buffer must outlive the tree.

**Regular expressions: decision.** C has no PCRE, and generated parsers must be standalone, so the generator compiles each regex
to bytecode for a small backtracking matcher (about 150 lines per language, the same program in all three) instead of depending on PCRE2
or on the host language's regex engine (which differ from PCRE for `\w`, `\s`, `\b` and case folding). Matching is anchored and
UTF-8 aware like PHP's `u` modifier, which also turns on Unicode properties: `\w \d \s \b` and `[[:alpha:]]` use PHP's own PCRE
tables, computed at generation time, and `"é"i` / `(?i:...)` use Unicode simple case folding (`k` also matches U+212A).
Supported: literals and escapes (`\n \r \t \f \e \a \0 \xHH \x{H..}`, escaped punctuation), `.`, classes with ranges, negation,
`\d \w \s \h \v` (and negations), `\p{..}`/`\P{..}`, POSIX `[:alpha:]`; alternation; groups (capturing groups act as
non-capturing: only the whole match is returned); `* + ? {n} {n,} {n,m}` greedy, lazy and possessive; atomic groups `(?>..)`; lookahead
`(?=..) (?!..)`; lookbehind `(?<=..) (?<!..)` of one fixed length; inline flags `i s m`; `(?#..)`; `^ $ \A \z \Z \b \B`.
Rejected at generation time with the regex in the message: back references, recursion and subroutine calls, conditionals, `\R \X \K \G \Q..\E`,
the `x`, `U`, `J` flags, branch reset, verbs, variable-length lookbehind, repeat counts above 1000.

**Performance** (gcc -O2, this machine; the engine does no memoisation, like the PHP parsers): the SkeinLang grammar parses its example files
(78 to 811 bytes) in 0.08 to 0.32 ms each and a 5 KB file in 1.8 ms in C, 5 ms in Node and 98 ms in Python; a 5 KB JSON document with a
snippet-free grammar takes 0.43 ms in C, against 1.2 ms for the PHP compiled parser and 8.7 ms for the interpreter.

---

## Portable snippets

The C, JavaScript and Python targets cannot run PHP, but they run the declarative subset below, which is enough to build tagged nodes with
source positions. A portable snippet is **one `return` of one expression**:

```text
expr    ::= value | "[" element ("," element)* ","? "]" | $label "??" default
element ::= expr | 'key' "=>" expr                 string keys only; a keyless element gets the next integer key
value   ::= 'text' | "text" | 123 | -123 | true | false | null
          | $label                                 a labelled value of the same sequence (must exist)
          | $_offset                               byte offset where the enclosing rule started
          | $_line | $_column                      1-based line and column (bytes) of $_offset
default ::= value | "[" "]"                        not a variable
```

* `"text"` may use only the escapes `\n \r \t \\ \" \$` and no interpolation; integers are decimal and at most 2147483647.
* `$label ?? default` yields the default when the label's value is null (an optional part that did not match) or the label is absent.
* `$_offset` is the byte offset where the **rule** containing the snippet started, in both PHP engines and all targets; for a left-recursive
  rule every operator node of a chain shares the offset of the chain's first operand. `$_line`/`$_column` are derived from it.
* Everything else is rejected for the targets with the rule and the snippet in the message (`$_0`, `$context`, `$state`, `$HIT`/`$MISS`, calls,
  operators, assignments, several statements, floats, interpolation) and keeps working in PHP.
* The tree gets `int` and `bool` values from these snippets; a snippet always succeeds with its value (it cannot return `$MISS`).

```text
E = l:E _ op:"+" _ r:T <? return ['binary', $op, $l, $r, '_offset' => $_offset]; ?> | T
T = n:/[0-9]+/ <? return ['number', $n, '_offset' => $_offset]; ?>        "1 + 2"
=> ["binary","+",["number","1","_offset":0],["number","2","_offset":4],"_offset":0]
```

---

## Licence

`charm/parsing` is **GPL-3.0-only** with an additional permission, a Bison-style output exception, in
[LICENCE.md](LICENCE.md). In plain words: the library and the compiler stay GPL. **A parser that
`charm-compile` generates from your grammar is yours.** It, including the template text and runtime support
copied into it, is not covered by the GPL, and you may distribute it under any terms. The exception also covers
the interfaces and lexer classes a generated token-mode parser needs at run time. It does not extend to a
modified compiler or library. Generated files (PHP, C, JavaScript, Python) say so in their header. Running the interpreter
(`cpeg_parse()`) uses the library itself, so it is the compiled output that carries the exception.
