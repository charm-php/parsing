Grammar syntax
==============

A grammar is a list of rules. The first rule is the entry point unless a goal
rule is given to the parser constructor.

```
Expr  = Term (("+" / "-") Term)*
Term  = Primary (("*" | "/") Primary)*
Primary = "(" Expr ")" | Number
Number = /[0-9]+/
```

Comments use `#`, `//` or `/* ... */` and are discarded.

## Rules

```
Name "description" = clause clause ;
Other ::= clause | clause
```

* Assignment can be written `=`, `::=`, `:=` or `->`.
* A string between the rule name and the assignment operator is the rule's
  description. It is used in error messages instead of the rule name.
* The trailing `;` is optional - a rule ends when the next rule name starts.
* Declaring the same rule name twice is an error:
  `Rule 'X' is already declared in this grammar. Use the Choice operator `/` to
  add multiple rules to a grammar`.

Rule names are PHP-style identifiers, and a rule body may not be empty: `X = ;` raises
`Rules must contain clauses on :location` (write `X = \nothing` for a rule that matches nothing). Compiled
parsers turn each rule into a PHP method, and PHP method names are not case-sensitive, so two rules whose
names differ only in case (`Select` and `SELECT`) get distinct methods anyway (the later one is suffixed `__2`,
`__3`, ...), so they compile together; a grammar without such a pair keeps the plain `parseName` methods.

## Terminals

| Syntax | Class | Matches | Returns |
|--------|-------|---------|---------|
| `"text"`, `'text'` | `Clause\Text` | that exact substring | the matched text |
| `"text"i`, `'text'i` | `Clause\Text` | that substring in any letter case | the matched text, as typed in the input |
| `/regex/` | `Clause\Regex` | PCRE at the current offset | the matched text |
| `[0-9a-f]` | `Clause\Regex` | shorthand for `/[0-9a-f]/` | the matched text |
| `^` | `Clause\SOF` | start of input, zero width | `Signal::HIT` |
| `$` | `Clause\EOF` | end of input, zero width | `Signal::HIT` |
| `\nothing` or `ε` | `Clause\Nothing` | always, zero width | `Signal::HIT` |
| `Other` | `Clause\Identifier` | the referenced rule | whatever that rule returns |

Regexes are matched with the `u` and `A` modifiers added by the engine, so they
are anchored at the current offset and are UTF-8 aware. Do not append flags:
`/foo/i` is parsed as the regex `/foo/` followed by the rule name `i`. Use an
inline group instead - `/(?i:foo)/` - or, for plain text, the case-insensitive literal `"foo"i` (string mode
only). The `i` must follow the closing quote directly and not continue into a longer name: `"foo"i+` and
`k:"foo"i` are fine, `"foo"id` is `"foo"` followed by the rule `id`.

String literals understand `\n`, `\r`, `\t`, `\0`, `\\` and an escaped copy of
the surrounding quote. The `[...]` form is always read as a character class,
never as a group - group with `( )`.

There is no wildcard terminal in the grammar text: `.` is lexed as a separator
and does not become a clause. Match any character with a regex such as `/[\s\S]/`.

## Operators

| Syntax | Meaning | Result |
|--------|---------|--------|
| `A B` | sequence: match A, then B | array of the values produced |
| `A / B`, `A \| B` | ordered choice, tried left to right | first successful value |
| `A?` | zero or one | A's value, or `null` |
| `A*` | zero or more | array, empty when nothing matched |
| `A+` | one or more | array, fails when nothing matched |
| `(A B)` | group | the group's value |
| `&A` | and-predicate: A must match, nothing is consumed | `Signal::HIT` |
| `!A` | not-predicate: A must fail, nothing is consumed | `Signal::HIT` |
| `...A` | spread | see below |
| `@` | commit | see [precedence and pragmas](precedence-and-pragmas.md) |

Choice is ordered, not "longest match": the first alternative that succeeds
wins, even if a later one would have matched more input - including an
alternative that succeeds without consuming anything (`"" | "a"` never reaches
`"a"`, and neither does anything after `"a"? |`).

`*` and `+` stop at an iteration that consumes nothing, since repeating it would
never end: `("a"*)*` on `aaa` is `[["a", "a", "a"]]`, `("a"?)*` is `["a", "a",
"a"]`, and `\nothing*` is `[]`. The empty iteration is not collected, except that
the first iteration of `+` still counts as the one match `+` requires.

An iteration that matches without producing a value (`ε`, `$`, a predicate) advances
the loop but contributes nothing, as in a sequence: `ε*` and `ε+` are `[]`, and
`("a" | ε)*` on `aab` is `["a", "a"]`.

Predicates may be stacked: `!!A` is `&A`, and `&!A` is `!A`, written out as nested
groups. A quantifier on a predicate (`&A*`) is ignored; the predicate looks for one
match of `A`.

Groups may also be written with `< >` (and `{ }`), but `[ ]` is unavailable
because square brackets are read as a character-class regex first.

## Capturing results

A sequence collects the values of its clauses into an array. Three modifiers
change what ends up in that array:

```
Pair = key:Name "=" value:Name     # ['key' => 'a', 'value' => 'b']
List = ...Item* "," End            # items flattened into the parent array
Skip = Name _ Name                 # _ refers to a %silent rule, so 2 values
```

* `name:Clause` adds the value under the string key `name` only. It does not
  also occupy a positional slot.
* `...Clause` flattens an array result into the parent sequence and skips
  `null` values. The spread goes first: `...name:Clause` is valid, and
  `name:...Clause` is rejected with `Invalid syntax 'name:...' on :location.
  Use '...name:clause' instead`.
* A reference to a rule marked [`%silent`](precedence-and-pragmas.md) produces
  no value at all.
* Clauses that return `Signal::HIT` (`^`, `$`, `\nothing`, predicates) are not
  added to the array.
* A sequence that collected nothing is itself value-less (like a lone predicate): its
  parent does not collect it, and at the top level the parser returns `null`.
* A sequence of **one** clause is that clause's value (`("a")` is `"a"`); a sequence of **two or more** is
  always a list, even when only one of them has a value (`!"b" "a"` is `["a"]`). So `/[a-z]+/ _` with a
  silent `_` is a one-element list, and a snippet (`return $_0;`) unwraps it.
* Spread flattens two levels: the list `...A` yields, and any list among its items. It only has an effect
  inside a sequence of two or more clauses. Over items that are themselves lists or associative arrays it
  spills their elements into the parent; a repeated group (`Item ...(COMMA Item)*` with a silent `COMMA`)
  keeps the items intact. The [reference](../REFERENCE.md#values) has the complete table.
* Spread works the same in the alternatives of a left-recursive rule, operator alternatives included
  (`Expr = Expr "," ...Args | Atom`); `...Expr` on the left operand spreads the value parsed so far.

Labels must be plain identifiers. `"key":Clause` fails with
`Expected identifier on :location`.

## Snippets

A grammar can run PHP at the end of a sequence:

```
Number = /[0-9]+/ <? return intval($_0); ?>
```

A snippet must be the last clause of its sequence, otherwise the grammar parser
reports `Snippet must be the last clause in a sequence. Found snippet at
position N of M clauses.` See [snippets and state](snippets-and-state.md).

## Templates

`cpeg_parse()` and `cpeg_create()` run the grammar through
`Charm\Parsing\GrammarPreprocessor` first, which expands parameterised rules:

```
Paren<T>  = "(" T ")"
List<T,S> = T (S T)*
Expr      = Paren<Number> | List<Number, ",">
Number    = /[0-9]+/
```

An instantiation is rewritten to a mangled rule name - `Paren<Number>` becomes
`Paren__Number` - and the generated rule bodies are appended to the grammar
under a `# Generated template instantiations` heading. Arguments may be rule
names, string literals (encoded as name parts, e.g. `lparen` for `(`) or nested
template calls. A wrong argument count throws
`Template 'X' expects N arguments, got M`.

Templates are expanded by the helper functions only. `Charm\Parser` subclasses
and `bin/charm-compile` pass the grammar straight to `GrammarParser`, so
`Name<Arg>` is not available there.

## Directives

Directives start with `@` and must appear before the first rule.

```
@lexer App\MyLexer          # switch the grammar to token mode
@prec {
  left "+" "-"
  left "*" "/"
  right T_POW
}
```

`@prec` records an operator precedence table; each line binds tighter than the
one before it, and levels are numbered from 1. See
[token mode](token-mode.md) and [precedence and pragmas](precedence-and-pragmas.md).
An unknown directive raises `Unknown directive @foo on :location`.

## Parsing the grammar yourself

```php
use Charm\Parsing\GrammarParser;

$grammar = (new GrammarParser(new GrammarParserOptions([
    'base_namespace' => 'App\\Grammar',
])))->parse($grammarText, 'grammar.peg');
```

`parse()` requires a filename - it is used in grammar error messages. Parsed
grammars are memoised in a static cache keyed on
`source + filename + lineOffset + columnOffset`; call
`GrammarParser::clearCache()` to drop it.

`base_namespace` is passed to every snippet clause and controls the namespace
used for the code the snippet is compiled into.

## See also

* [Snippets and state](snippets-and-state.md)
* [Precedence and pragmas](precedence-and-pragmas.md)
* [Errors and debugging](errors-and-debugging.md)
* [Documentation index](index.md)
