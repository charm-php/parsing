Precedence and pragmas
======================

Charm parses left-recursive rules without rewriting them into the usual
`Term = Primary (op Term)*` shape. Two mechanisms control how the alternatives
of such a rule are grouped: the order of the alternatives, and pragmas written
`%name` or `%name=value`.

Pragmas are implemented by `Charm\Parsing\Pragma`. `Pragma::create()` accepts
exactly three names; anything else throws a `\LogicException`:

```
Unknown pragma directive %foo. Supported: %assoc=left|right|none, %prec=N, %silent
```

| Pragma | Arguments | Read by |
|--------|-----------|---------|
| `%assoc` | `left`, `right` or `none` (required) | both engines |
| `%prec` | an integer, or a token name in the `@prec` table | compiled engine only |
| `%silent` | none | both engines |

Bad arguments are rejected while the grammar is being parsed:

```
Pragma directive %assoc requires an argument 'left', 'right' or 'none', e.g. '%assoc=right'
Pragma directive %prec requires a value, e.g. '%prec=5' or '%prec=T_POW'
Pragma directive %prec requires a number or token name, e.g. '%prec=5' or '%prec=T_POW'
```

## Where a pragma attaches

A pragma is parsed as part of the clause list, and `GrammarParser` moves it onto
the sequence it terminates (`_Clauses()` -> `$buildSequence`). A single-clause
group gets the pragma on that clause; a longer group gets it on the sequence
that wraps it.

```
Expr = Expr "or" Expr %assoc=right      # pragma on the whole alternative
     | Primary

Sub  = "(" Expr ")" %silent             # pragma on the paren group
```

Because the pragma belongs to one alternative, `%silent %assoc=left` at the end
of a multi-alternative rule applies to the last alternative only, not to the
rule as a whole. Wrap the body in parentheses to attach a pragma to every
alternative at once.

Reading pragmas from PHP: `Clause::pragma('assoc')` returns the `Pragma` object
or `null`, and `Clause::getPragmas()` returns them all. Parameters are read by
offset - `$pragma[0]` - and the object stringifies to `%name(arg)`.

## Left recursion, the short version

A rule is left-recursive when an alternative starts with a reference to the
rule itself, directly or through other rules. Both engines detect this
(`OrderedChoice::prepare()` in the interpreted engine,
`GrammarCompiler::isLeftRecursive()` when compiling) and parse those rules with
a precedence loop instead of recursing into the first alternative forever.

Each alternative gets a precedence number. In both engines the default is
positional, and **the first alternative gets the highest number**, so it binds
tightest:

```
Expr = Expr "**" Expr        # precedence 4 - binds tightest
     | Expr [*/] Expr        # precedence 3
     | Expr [+-] Expr        # precedence 2
     | Number                # precedence 1
```

Numbered alternatives are the only precedence source the interpreted engine
uses; `@prec` and `%prec` are ignored there.

### Interpreted engine

`OrderedChoice::lrParse()` first parses a non-left-recursive "start" value from
the alternatives that are not left-recursive, then repeatedly wraps that value
with left-recursive alternatives whose precedence is above a floor. The
recursive reference on the right of an operator is parsed with the floor set to
that operator's precedence, which is what makes an operator non-associative on
the right; the outer loop then re-wraps the result, producing left-associative
trees:

```
1 + 2 + 3      ->  [[1, "+", 2], "+", 3]
```

Only the value `right` of `%assoc` is consulted at runtime:

```php
$match = $this->lrParse($state, $prep->precedence,
    $prep->clause->pragma('assoc') !== null && $prep->clause->pragma('assoc')[0] === 'right');
```

With `right`, an alternative at exactly the floor precedence is allowed on the
right-hand side, so `1 ** 2 ** 3` groups as `[1, "**", [2, "**", 3]]`. `left`
and `none` are equivalent in the interpreted engine.

Prefix operators (alternatives that reference the rule but do not start with
it) are handled by the same loop; a `State->extra` marker records that a rule is
still looking for a start value, which is what lets `-10` parse as `["-", 10]`
and `!!!!10` nest without swallowing the following operators.

### Compiled engine

`GrammarCompiler::generatePrattMethod()` emits a pair of methods per
left-recursive rule:

```php
private function parseExpr(int $_minPrec = 0): mixed
private function parseExprPrimary(): mixed
```

`parseExprPrimary()` tries the alternatives that can start an expression,
including prefix operators. `parseExpr()` loops over the operator alternatives
and only applies one when it outranks the caller's floor:

```php
// Precedence 3 (right-associative)
if ($_minPrec < 3) { ... }

// Precedence 2 (non-associative)
if ($_minPrec <= 2) { ... }
```

The right operand of an operator is parsed with `$_minPrec` set to the
operator's precedence, or one lower for `%assoc=right`
(`$nextPrec = $rightAssoc ? ($precedence - 1) : $precedence`).

Precedence for an alternative is resolved in this order:

1. `%prec=N`, used as-is. `%prec=NAME` looks `NAME` up in the `@prec` table and
   takes both its level and its associativity.
2. If the grammar has a `@prec` table, the operator of the alternative is
   extracted - the first literal, string or token that is not a recursive
   reference or a snippet - and looked up in the table.
3. Otherwise the position fallback `$numAlts - $index` applies, matching the
   interpreted engine.

`%assoc` always overrides the associativity that came from the table.

## %prec

`%prec` gives an alternative an explicit precedence. Use the numeric form when
you want to stay engine-independent in the grammar text:

```
Expr =  Expr "+"  Expr %prec=2
      | Expr "*"  Expr %prec=3
      | "(" Expr ")"
      | Number
```

`%prec=T_NAME` resolves the name through the `@prec` table and therefore does
nothing unless the grammar also declares `@prec`. The interpreted engine ignores
`%prec` entirely - it is accepted, and the alternative falls back to its
position.

## @prec

`@prec` declares an operator table for the grammar. It is a directive, so it
goes before the first rule, and it takes one associativity keyword per line
followed by the operators on that level:

```
@prec {
  left  "+" "-"
  left  "*" "/" "%"
  right T_POW
  none  "<" ">"
}
Expr = Expr "+" Expr | Expr "*" Expr | ...
```

* Levels are numbered from 1 in the order the lines appear, and each line binds
  tighter than the one before it. The example above gives `+` level 1 and `T_POW`
  level 3.
* Operators are written as strings (`"+", which matches a `"+"` clause) or as
  identifiers (`T_POW`, which matches a `T_POW` token clause in
  [token mode](token-mode.md)).
* The associativity keyword must be `left`, `right` or `none`; lines are
  separated by the keyword, so a level's operators stop at the next keyword or
  at the closing brace.

The table is stored on the grammar and can be inspected:

```php
$grammar->hasPrecedenceTable();   // bool
$grammar->getPrecedenceTable();   // ['+' => ['level' => 1, 'assoc' => 'left'], ...]
$grammar->getPrecedence('*');     // ['level' => 2, 'assoc' => 'left'] or null
```

Parse errors from this directive:

```
Expected '{' after @prec on :location
Expected 'left', 'right', or 'none' on :location
Invalid associativity 'nope' on :location. Expected 'left', 'right', or 'none'
Expected at least one token after 'left' on :location
Expected '}' to close @prec block on :location
```

As with `%prec`, the table is only consulted when compiling. An interpreted
parse of the same grammar still uses alternative order.

## %silent

`%silent` on a rule marks references to that rule as producing no value. In
`Name _ Name`, a silent `_` contributes nothing to the collected array, so the
sequence yields two values instead of three:

```
Start = A _ A       <? return $_; ?>
A     = /[a-z]+/
_     = /\s*/ %silent
```

Both engines skip the value twice over: it is not appended to the sequence's
result array, and it is not added to the snippet context, so the `$_0`, `$_1`
numbering in a later snippet does not count it. A named reference
(`ws:_`) is captured regardless - the name wins.

`Clause\Identifier::isSilent()` checks the pragma on the referenced rule's
clause, so the pragma has to end up on the rule's top-level clause (see
[Where a pragma attaches](#where-a-pragma-attaches)).

## Commit: @

`@` in a sequence says "this far and no further - do not backtrack past
here to try another alternative". It consumes nothing and produces
`Signal::HIT`.

```
Statement = "if" @ Expr Block
          | "while" @ Expr Block
          | Expression ";"
```

Compiled parsers implement this with a `commitOffset` field. Matching `@` sets
it to the current offset. A sequence that fails after a commit rewinds the
offset only if the commit lies behind where the sequence started, and an
ordered choice that fails on an alternative checks
`$this->commitOffset !== $savedCommit` and gives up instead of trying the
remaining alternatives. `parse()` resets the field to `null` on every call, and
a commit inside a successful alternative is discarded, so it does not block
later backtracking.

In the interpreted engine a `Commit` clause is recorded by `Sequence::interpret`
but the failure path throws `CommittedError`, and a `Commit` that reaches
`Commit::interpret()` throws `InternalError`. Neither class is part of the
autoloaded `Charm\Parsing` namespace in this version, so a commit that fires
under `cpeg_parse()` surfaces as a PHP "Class not found" error rather than a
parse error. Treat `@` as a compiled-parser feature; see
[compiled parsers](compiled-parsers-and-cli.md).

## A worked example

`charm-tests/0004-operator-precedence.php` builds an expression grammar in the
interpreted engine, mixing operator shapes with all three associativity values:

```
Expr "expression"
=   Expr "**" _ Expr                                     %assoc=right
|   ("++" | "--" | [-+~@]) _ Expr
|   Expr ("++" | "--") _
|   Expr "instanceof" _ Expr
|   "!" Expr
|   Expr [*/%] _ Expr
|   Expr [-+] _ Expr
|   Expr /<<|>>/ _ Expr
|   Expr "." _ Expr
|   Expr /<=|>=|<|>/ _ Expr                              %assoc=none
|   Expr /===|==|!==|!=|<>|<=>/ _ Expr                    %assoc=none
|   Expr "&" _ Expr
|   Expr "^" _ Expr
|   Expr "|" _ Expr
|   Expr "&&" _ Expr
|   Expr "||" _ Expr
|   Expr "??" _ Expr                                     %assoc=right
|   Expr "?" _ Expr ":" _ Expr                           %assoc=none
|   Expr /=|\+=|-=|\*\*=|\*=|\/=|\.=|%=|&=|\|=|\^=|<<=|>>=|\?\?=/ _ Expr   %assoc=right
|   "yield" _ "from" _ Expr
|   "yield" _ Expr
|   "print" _ Expr
|   Expr "and" _ Expr
|   Expr "xor" _ Expr
|   Expr "or" _ Expr
|   Primary

Primary = (FLOAT / INTEGER) _        <? return $_0; ?>
FLOAT   = /(0|[1-9][0-9]*)(\.[0-9]+)/ <? return floatval($_0); ?>
INTEGER = /(0|[1-9][0-9]*)/          <? return intval($_0); ?>
_       = /\s*/                                          %silent
```

`_` is silent, so each alternative's result array holds only the operands and
the operator text. The test's expectations follow directly from the rules above:

| Input | Result | Why |
|-------|--------|-----|
| `1` | `1` | falls through to `Primary` |
| `1+2+3` | `[[1, "+", 2], "+", 3]` | default left associativity |
| `1**2**3` | `[1, "**", [2, "**", 3]]` | `%assoc=right` |
| `1^2^3^4^5` | `[[[[1,"^",2],"^",3],"^",4],"^",5]` | `^` is listed once, left associative |
| `-10` | `["-", 10]` | prefix alternative |
| `!!!!10` | `["!",["!",["!",["!",10]]]]` | prefix alternative, nested |
| `10++` | `[10, "++"]` | postfix alternative |
| `++10++` | `[["++",10],"++"]` | prefix binds tighter - it is listed first |

Run it with `php run-tests.php 0004`.

## See also

* [Grammar syntax](grammar-syntax.md)
* [Token mode](token-mode.md)
* [Compiled parsers and the CLI](compiled-parsers-and-cli.md)
