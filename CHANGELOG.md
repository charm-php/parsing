# Changelog

## Unreleased

- Compiled parsers: `$context` in a snippet is the sequence's collected array (names and numbers), as when
  interpreted; it used to be undefined. Rows in `charm-tests/0046`.
- Spread (`...A`, `...name:A`) in the operator alternatives of a left-recursive rule (`Expr = Expr "+" ...Args`)
  now flattens in both engines; both used to ignore it there (only the non-left-recursive alternatives
  honoured it) and keep the list as one element. Rows in `charm-tests/0047`.

## 0.1.4

Fixes for four differences between the engines (found while writing the SQL tutorial), plus documentation
and tests.

Fixed
- Compiled parsers: rules whose names differ only in case (`Select` / `SELECT`) no longer fail with
  "Cannot redeclare parseSELECT"; the later rule's method gets a `__2` suffix (`parseSELECT__2`). Grammars
  without such a pair generate exactly the same method names as before. Rows in `charm-tests/0042`.
- Compiled parsers: `...name:Clause` binds `$name` to the whole value, and in a snippet after any spread
  `$_0`, `$_1` ... are the spread elements, as when interpreted (a compiled snippet used to get `$name`
  undefined and the whole list as `$_0`). Rows in `charm-tests/0043`.
- Compiled parsers: reading an undefined variable in a snippet (`$_5`, a typo, `$_0` after only named
  captures) raises `Error\SnippetError` with the interpreter's message instead of reading `null`. Only
  snippets that read such a variable get an error handler; the interpreter's message now says `$_5`, not
  `$5`. Rows in `charm-tests/0044`.
- Compiled parsers: `parse($input, $initialState)` starts `$state` from the `globals` compile option merged
  with `$initialState`; it used to merge into what the previous `parse()` on the same object left. The
  generated `parse()` therefore differs in one line from 0.1.3's. Rows in `charm-tests/0045`.
- Confirmed in both engines (row in `0045`): `$state` is not rolled back when the parser backtracks.

- New: `docs/tutorial-sql.md`, a step-by-step SQL `SELECT` parser (literals to joins, `@prec` expressions,
  keywords, parameters and `$state`, comments, errors and `@`, `$MISS`, compiling, walking the tree), and
  its finished grammar `example-grammars/sql.cpeg`.
- New tests: `charm-tests/0050-sql-tutorial.php` reads the tutorial itself (grammar steps, inputs with their
  AST in both engines, error messages, PHP examples; `sql.cpeg` must hold exactly the tutorial's rules; the
  compiled class must run with nothing loaded) via `charm-tests/support/tutorial.php`;
  `0051-reference-examples.php` runs the examples of `REFERENCE.md` and the README through both engines.
  `conformance_run()` takes an optional initial `$state`.
- `README.md` is now the front door (two sentences, install, a 60-second interpreted and compiled example,
  which engine, features, links, licence in plain words); the grammar reference it duplicated lives only in
  `REFERENCE.md`, rewritten as one exhaustive table with a verified example and value for every operator,
  pragma, snippet variable, API call and CLI flag. `docs/index.md` is the map.
- Corrections: compiled parsers throw `{Class}ParseError` (index, getting started), the compiled `Signal::HIT`
  return (compiled chapter), an empty rule body is never allowed, indirect left recursion is not supported
  (it was documented as supported), `@prec` names for keyword operators and the fall-through of an unknown
  `%prec=NAME`, a snippet's `$state` writes survive backtracking, undefined snippet variables differ between
  the engines, `CommittedError` in the error chapter, the checked-in `src/Library` parsers' provenance,
  token-mode chapter's "only compiled parsers use `@prec`", `.` wildcard and `/regex/i` samples in the old
  `REFERENCE.md`/`README.md`, and a snippet's `false` return, which is a value and not a failure.
- Found while writing (documented, not changed): indirect left recursion crashes the interpreter
  or hangs the compiled parser; a regex with an inline `(?i:...)` group is opaque to the compiler's analysis.

## 0.1.3

- Repetition: `*` and `+` no longer collect value-less iterations. `ε*`, `ε+` and a predicate-only
  group under `*` give `[]` (the interpreter pushed its internal `Signal::HIT`; compiled parsers pushed
  their private enum). Conformance rows in `charm-tests/0040`.
- Compiled parsers throw `{ClassName}ParseError extends \RuntimeException` (emitted into every generated
  file, like the `Signal` enum) with readonly `committed`, `sourceLine`, `sourceColumn`, `offset`,
  `expected`, `found`, instead of a `\RuntimeException` with code 0/1. `catch (\RuntimeException)` keeps working. Rows in `charm-tests/0041`.
- A sequence/group that collects nothing (`(&"a")`, `(!"a" $)`) is value-less (`Signal::HIT`) in both
  engines instead of `null`, so its parent does not collect it: `(&"a")+` is `[]` (was `[null]`),
  `(&"a") "a"` is `['a']` (was `[null, 'a']`). Top-level result is still `null`. Rows in `0039`.

## 0.1.2

Conformance suite `charm-tests/0030`-`0039`: the interpreter and the compiled parser now agree on
every row. Fixes, by engine:

Both engines
- Commit operator `@`: the interpreter threw `Class CommittedError not found` (f1eddd6; the class is
  `Error\State\CommittedError` now). In both engines a commit is scoped to its sequence, a failure after
  it is a parse error that `*`, `+`, `?` and choices do not swallow, a commit inside an alternative
  that succeeded is forgotten, and inside a predicate a committed failure just means "no match".
- `*` / `+` over a group that can match nothing (`("a"*)*`, `("a"?)*`, `ε*`) hung. An iteration that
  consumes nothing now ends the repetition and is not collected (the first one still counts for `+`).
- `%prec=N`, `%prec=NAME` and `@prec` tables are honoured by the interpreter too; both engines resolve
  precedence through `Grammar::resolveAlternativePrecedence()`. `%assoc=none` groups like `left`
  in both (the compiled parser used to group it to the right).
- Left recursion: a reference to the rule inside a bracketed alternative (`"(" Expr ")"`) is a complete
  expression in both engines; only a trailing reference (prefix operator) is bound by the alternative's
  precedence. Fixes `(1+2)+3` (interpreter) and `(1+2)` (both) for grammars with the brackets first.
- A quantifier on a predicate (`&A*`) is ignored by the compiled parser too, as in the interpreter.
- A bare `%assoc` or `%prec` reports the missing argument instead of "Undefined array key 0".
- `!!A`, `&!A` (stacked predicates) parse; `(&A)` and `($)` no longer crash with "Undefined variable $0".

Compiled parser
- Ordered choice: first success wins, including an empty success. The first-byte `switch` and the
  single-variance-point optimizer no longer skip optional, nullable or predicate alternatives or
  quantified groups, and no longer lose a later terminal when the suffix of an earlier one fails.
  Both are off for grammars containing `@`.
- Snippets: predicates and named captures no longer occupy a `$_N` slot (a lone `&` used to pass a
  Signal object); `$MISS` / `Signal::MISS` fails the sequence, `SequenceIgnore` and `SequenceResume` are
  honoured; anything thrown is wrapped in `Error\SnippetError`; `$MISS` and `$HIT` are defined.
- Error positions: terminals record the furthest failure offset, so line:col equals the interpreter's
  (`fail()` used to report where the rule started). The rule-call guard only tests the first byte.
- `Identifier::getMinByteLength()` / `getFirstBytes()` caches were keyed by rule name across grammars;
  `Sequence::getFirstBytes()` ignored the first bytes of optional elements.
- String literals containing quotes (`"a\"b"`, `"'"`) matched wrongly (3b5a04b).
- Templates: `$commitOffset` is gone; `fail(string $expected, bool $committed = false)`.

Docs: `REFERENCE.md` no longer lists the `.` wildcard or `/regex/i` (the grammar text has neither; see
`docs/grammar-syntax.md`); the precedence, commit, snippet and error chapters describe the agreed
behaviour. Case-insensitive terminals (`Clause::CASE_INSENSITIVE`) remain unreachable from grammar text.

## 0.1.1

- Compiled parsers return `null` again when the goal rule matches with no value, as `cpeg_parse()`
  and 0.0.7 did; 0.1.0 returned `true` from compiled parsers only. Regression test 0021.

## 0.1.0

- Grammar compiler (`charm-compile`, `Charm\Parsing\Compiler\GrammarCompiler`) producing standalone
  PHP parsers, with commit semantics, a rule failure cache and optimizers (single-variance point,
  consecutive terminals), plus a snippet registry in `Grammar`.
- Token mode: `@lexer` directive, `Token` (`T_NAME`) and `Literal` (`"+"`) clauses, `@prec` precedence
  tables, `%prec` / `%silent` pragmas, `parser-token.template.php`, `-t/--template` option,
  `TokensInterface`, `LexerTokens`, `PhpLexer`/`PhpTokens` (nikic/php-parser ^5, suggested).
- `State` now holds `tokens` (`array<int|string>`) and `tokenData` (`TokensInterface`);
  `setStateString()` / `setStateTokens()`.
- Compiled parsers return `true` (was `null`) when the goal rule matches with no value; snippet
  methods receive the rule start offset as a leading `$_offset` parameter.
- Licence: Bison-style output exception (see LICENCE.md); generated parsers carry a header line.
- Tests: `composer test` / `php run-tests.php` prints a summary and exits non-zero on failure;
  removed the unused phpunit.xml.
- Examples: unfinished PHP grammar and its yacc conversion tools in `example-grammars/php/`
  (not library API; the generated parser currently recurses without bound in `parseName()`).
