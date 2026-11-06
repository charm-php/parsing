# Changelog

## 0.1.3

- Repetition: `*` and `+` no longer collect value-less iterations. `ε*`, `ε+` and a predicate-only
  group under `*` give `[]` (the interpreter pushed its internal `Signal::HIT`; compiled parsers pushed
  their private enum). Conformance rows in `charm-tests/0040`.
- Compiled parsers throw `{ClassName}ParseError extends \RuntimeException` (emitted into every generated
  file, like the `Signal` enum) with readonly `committed`, `sourceLine`, `sourceColumn`, `offset`,
  `expected`, `found`, instead of a `\RuntimeException` with code 0/1. `catch (\RuntimeException)` keeps working. Rows in `charm-tests/0041`.

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
