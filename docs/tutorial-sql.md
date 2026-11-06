Tutorial: parsing SQL
=====================

This tutorial builds a parser for a modest subset of SQL, one step at a time, for someone who has
never written a grammar. Every grammar and every input below is run by the test suite
(`charm-tests/0050-sql-tutorial.php` reads this file), and every output block is what the parser
really returned, so if you copy a step you get what is printed.

The finished grammar is [`example-grammars/sql.cpeg`](../example-grammars/sql.cpeg). It parses a
single `SELECT` statement into a tree of PHP arrays.

**The subset.**

* `SELECT [DISTINCT]` with expressions, aliases, `*` and `t.*`
* `FROM` a table or a subquery, with aliases and `[INNER|LEFT|RIGHT] JOIN ... ON`
* `WHERE`, `GROUP BY`, `HAVING`, `ORDER BY [ASC|DESC]`, `LIMIT ... [OFFSET ...]`
* expressions: `+ - * / %`, unary minus, `= <> != < <= > >=`, `AND OR NOT`, `IS [NOT] NULL`,
  `[NOT] IN (list or subquery)`, `[NOT] BETWEEN`, `[NOT] LIKE`, function calls including
  `COUNT(*)`, scalar subqueries, string, number, `NULL`, `TRUE`, `FALSE`
* `?` and `:name` parameters, `--` and `/* */` comments, keywords in any case

Not covered: `UNION`, `WITH`, `CASE`, `EXISTS`, `CAST`, schema-qualified names, comma joins,
`INSERT`/`UPDATE`/`DELETE`, `\` escapes in strings, backtick identifiers. The last step shows
where such things would go.

**How to read the steps.** Each step states a problem, shows the rules it adds or replaces, runs
a few inputs and says what was learned. A rule that appears again later replaces the earlier
version. A comment line `# entry` above a rule means that rule sits at the top of the file, and
the first rule of a grammar is where parsing starts. The sample inputs are SQL, the results are
JSON (`json_encode()` of the PHP value, re-flowed for the page).

To try anything yourself:

```php
require 'vendor/autoload.php';

$grammar = file_get_contents('example-grammars/sql.cpeg');
echo json_encode(Charm\cpeg_parse($grammar, 'SELECT 1', ['params' => 0]), JSON_PRESERVE_ZERO_FRACTION);
```
```output
{"type":"select","distinct":false,"columns":[{"expr":{"type":"literal","value":1},"alias":null}],"from":null,"where":null,"groupBy":[],"having":null,"orderBy":[],"limit":null}
```

(The third argument seeds `$state`; step 11 explains why this grammar wants it.)

Step 1: literals and identifiers
--------------------------------

*Problem.* Recognise a number, a string or a name.

A grammar is a list of rules, `Name = pattern`. A pattern can be a quoted string (`"SELECT"`), a
regular expression between slashes, or the name of another rule. `A | B` means "try `A`; if it
fails, try `B`". Parsing starts at the first rule.

```cpeg
# entry
Expr = Number | String | Identifier

Number = /[0-9]+(\.[0-9]+)?/
String = /'(?:[^']|'')*'/
Identifier = /[A-Za-z_][A-Za-z0-9_]*/
```

```sql
42
```
```json
"42"
```

```sql
3.14
```
```json
"3.14"
```

```sql
'it''s'
```
```json
"'it''s'"
```

```sql
users
```
```json
"users"
```

Terminals return the text they matched, quotes and all. Now the input `42abc`:

```sql
42abc
```
```json
"42"
```

It succeeded with `"42"`. A parse is allowed to stop early: the grammar says what a *prefix* of the
input looks like, and nothing says the rest must be consumed. `$` matches only at the end of the
input; put it at the end of the entry rule to demand the whole string.

```cpeg
# entry
Expr = (Number | String | Identifier) $
```

```sql
42
```
```json
["42"]
```

```sql
42abc
```
```error
Parse error at line 1, column 3: expected end of input, found 'a'
```

Two things changed. `42abc` is now an error, and the result of `42` is an array. A sequence (two
or more things in a row) returns an array holding the value of each part that has one; `$` has no
value, so the array holds one entry. A rule that is a single thing (`Number | String | Identifier`)
returns that thing's value as it is.

Raw text is not what a program wants. A *snippet*, PHP between `<?` and `?>` at the end of a
sequence, computes the value instead. `$_0` is the value of the first part. A `name:` in front of a
part gives it a name, and a snippet reads it as a variable of that name.

```cpeg
# entry
Expr = value:(Number | String | Identifier) $  <? return $value; ?>

Number = /[0-9]+(\.[0-9]+)?/
  <? return ['type' => 'literal', 'value' => str_contains($_0, '.') ? (float) $_0 : (int) $_0]; ?>

String = /'(?:[^']|'')*'/
  <? return ['type' => 'literal', 'value' => str_replace("''", "'", substr($_0, 1, -1))]; ?>
```

```sql
42
```
```json
{"type":"literal","value":42}
```

```sql
3.14
```
```json
{"type":"literal","value":3.14}
```

```sql
'it''s'
```
```json
{"type":"literal","value":"it's"}
```

*Learned.* Rules, `|`, regexes, `$`. Terminals return text, a sequence returns an array, a snippet
returns whatever you build. From here on the grammar builds the tree directly; the node shape
`['type' => ..., ...]` is our own choice, not something the library imposes.

Step 2: whitespace and the select list
--------------------------------------

*Problem.* `SELECT id, name AS n, *` has spaces, a comma-separated list, an optional alias and a
star.

Whitespace is just more text, so the grammar has to say where it may appear. The convention used
here: a rule named `_` matches any amount of whitespace (including none), and **every token eats the
whitespace after it**; only the entry rule also starts with `_`. Marking `_` with `%silent` means a
reference to it produces no value, so it never shifts `$_0`, `$_1`, ... or shows up in arrays.

A list is `Item (Comma Item)*`. Each repetition of `(Comma Item)` is one array, so the result is
`[first, [..], [..]]`, which is not what we want. The spread operator `...` flattens an array
into the sequence that contains it. With the comma also silent, each repetition is `[item]`, and
`Item ...(COMMA Item)*` becomes `[item, item, item]` with the nodes intact.

```cpeg
# entry
Statement = _ query:Query (";" _)? $  <? return $query; ?>

Query = SELECT columns:SelectList  <? return ['type' => 'select', 'columns' => $columns]; ?>

SelectList = SelectItem ...(COMMA SelectItem)*

SelectItem = Star
           | expr:Expr alias:Alias?  <? return ['expr' => $expr, 'alias' => $alias]; ?>

Star = "*" _  <? return ['type' => 'star', 'table' => null]; ?>

Alias = AS name:Identifier  <? return $name; ?>

Expr = Number | String | Column

Column = name:Identifier  <? return ['type' => 'column', 'table' => null, 'name' => $name]; ?>

Number = /[0-9]+(\.[0-9]+)?/ _
  <? return ['type' => 'literal', 'value' => str_contains($_0, '.') ? (float) $_0 : (int) $_0]; ?>

String = /'(?:[^']|'')*'/ _
  <? return ['type' => 'literal', 'value' => str_replace("''", "'", substr($_0, 1, -1))]; ?>

Identifier = /[A-Za-z_][A-Za-z0-9_]*/ _  <? return $_0; ?>

SELECT = "SELECT" _ %silent
AS = "AS" _ %silent
COMMA = "," _ %silent
_ = /\s*/ %silent
```

```sql
SELECT 1
```
```json
{"type":"select","columns":[{"expr":{"type":"literal","value":1},"alias":null}]}
```

```sql
SELECT id, name AS n, 42 AS answer
```
```json
{
  "type": "select",
  "columns": [
    {"expr":{"type":"column","table":null,"name":"id"},"alias":null},
    {"expr":{"type":"column","table":null,"name":"name"},"alias":"n"},
    {"expr":{"type":"literal","value":42},"alias":"answer"}
  ]
}
```

```sql
SELECT *
```
```json
{"type":"select","columns":[{"type":"star","table":null}]}
```

```sql
SELECT 1;
```
```json
{"type":"select","columns":[{"expr":{"type":"literal","value":1},"alias":null}]}
```

Each token ends with `_`, so `Number`, `String` and `Identifier` need it too; the snippet
`return $_0` is only there to unwrap the one-element array that `/.../ _` produces (a sequence of
two parts returns an array, even though `_` contributes no value). Keywords are plain literals
for now, so lower case fails:

```sql
select 1
```
```error
Parse error at line 1, column 1: expected Statement, found 's'
```

*Learned.* The `_` convention, `%silent`, optional `?`, named captures, spread for lists, the
shape rule for sequences. Step 4 deals with keywords properly.

Step 3: FROM, tables and JOIN
-----------------------------

*Problem.* `FROM users AS u JOIN orders AS o ON o.user_id = u.id`, and `u.*`.

A qualified name `u.id` is an identifier, a dot, an identifier. The join condition is a real
expression, which comes in step 5; for now an `Expr` may be `Column = Column`, enough to
exercise the structure. `INNER? JOIN` accepts both `JOIN` and `INNER JOIN`, and a snippet
decides which `kind` the whole sequence stands for.

```cpeg
Query = SELECT columns:SelectList from:FromClause?
  <? return ['type' => 'select', 'columns' => $columns, 'from' => $from]; ?>

SelectItem = QualifiedStar
           | Star
           | expr:Expr alias:Alias?  <? return ['expr' => $expr, 'alias' => $alias]; ?>

QualifiedStar = table:Identifier "." _ "*" _  <? return ['type' => 'star', 'table' => $table]; ?>

FromClause = FROM source:TableRef joins:JoinClause*  <? return ['source' => $source, 'joins' => $joins]; ?>

TableRef = name:Identifier alias:Alias?  <? return ['type' => 'table', 'name' => $name, 'alias' => $alias]; ?>

JoinClause = kind:JoinKind source:TableRef ON on:Expr
  <? return ['type' => 'join', 'kind' => $kind, 'source' => $source, 'on' => $on]; ?>

JoinKind = INNER? JOIN       <? return 'INNER'; ?>
         | LEFT OUTER? JOIN  <? return 'LEFT'; ?>
         | RIGHT OUTER? JOIN <? return 'RIGHT'; ?>

Expr = left:Column "=" _ right:Column  <? return ['type' => 'binary', 'op' => '=', 'left' => $left, 'right' => $right]; ?>
     | Number
     | String
     | Column

Column = first:Identifier rest:Dotted?
  <? return $rest === null ? ['type' => 'column', 'table' => null, 'name' => $first]
                           : ['type' => 'column', 'table' => $first, 'name' => $rest]; ?>

Dotted = "." _ name:Identifier  <? return $name; ?>

FROM = "FROM" _ %silent
JOIN = "JOIN" _ %silent
INNER = "INNER" _ %silent
LEFT = "LEFT" _ %silent
RIGHT = "RIGHT" _ %silent
OUTER = "OUTER" _ %silent
ON = "ON" _ %silent
```

```sql
SELECT u.name FROM users AS u
```
```json
{
  "type": "select",
  "columns": [{"expr":{"type":"column","table":"u","name":"name"},"alias":null}],
  "from": {"source":{"type":"table","name":"users","alias":"u"},"joins":[]}
}
```

```sql
SELECT u.name, o.total FROM users AS u JOIN orders AS o ON o.user_id = u.id
```
```json
{
  "type": "select",
  "columns": [
    {"expr":{"type":"column","table":"u","name":"name"},"alias":null},
    {"expr":{"type":"column","table":"o","name":"total"},"alias":null}
  ],
  "from": {
    "source": {"type":"table","name":"users","alias":"u"},
    "joins": [
      {
        "type": "join",
        "kind": "INNER",
        "source": {"type":"table","name":"orders","alias":"o"},
        "on": {
          "type": "binary",
          "op": "=",
          "left": {"type":"column","table":"o","name":"user_id"},
          "right": {"type":"column","table":"u","name":"id"}
        }
      }
    ]
  }
}
```

```sql
SELECT t.* FROM t LEFT OUTER JOIN s ON t.a = s.a
```
```json
{
  "type": "select",
  "columns": [{"type":"star","table":"t"}],
  "from": {
    "source": {"type":"table","name":"t","alias":null},
    "joins": [
      {
        "type": "join",
        "kind": "LEFT",
        "source": {"type":"table","name":"s","alias":null},
        "on": {
          "type": "binary",
          "op": "=",
          "left": {"type":"column","table":"t","name":"a"},
          "right": {"type":"column","table":"s","name":"a"}
        }
      }
    ]
  }
}
```

Ordered choice matters in `SelectItem`: `QualifiedStar` is tried before the general expression, because
`t.*` starts out looking like the column `t`. And `Expr` tries `Column "=" Column` before plain
`Column`. In a PEG, an earlier alternative that matches a *prefix* of what a later one would match
wins, so the more specific alternative goes first.

*Learned.* Optional parts yield `null` when absent, repetition yields `[]`, a sequence whose parts
are all silent can still carry a snippet that decides the value.

Step 4: keywords
----------------

*Problem.* SQL keywords are case-insensitive and reserved, and so far neither is true. The worse
problem shows up when the alias drops its `AS`, which SQL allows. Change `Alias` to `AS?
name:Identifier` and try the simplest query with a `FROM`:

```cpeg
Alias = AS? name:Identifier  <? return $name; ?>
```

```sql
SELECT a FROM t
```
```error
Parse error at line 1, column 15: expected COMMA, found 't'
```

`FROM` looks exactly like an identifier, so the parser took it as the alias of `a`; the select
list then wanted a `COMMA` and found `t`. A PEG never reconsiders: once `Alias?` has succeeded, the
choice is made. The fix is to say that an identifier is *not a keyword*. The predicate `!X`
succeeds when `X` does not match at this point, and consumes nothing.

The grammar has no case-insensitivity flag, so `/select/i` does not work (it is the regex
`/select/` followed by a reference to a rule called `i`). Use an inline group instead:
`/(?i:select)\b/`. The `\b` stops `select` from matching the start of `selection`.

```cpeg
Identifier = !Keyword /[A-Za-z_][A-Za-z0-9_]*/ _  <? return $_0; ?>

Keyword = /(?i:select|distinct|from|where|group|by|having|order|limit|offset|join|inner|left|right|outer|on|as|and|or|not|in|is|null|true|false|between|like|asc|desc)\b/

SELECT = /(?i:select)\b/ _ %silent
AS = /(?i:as)\b/ _ %silent
FROM = /(?i:from)\b/ _ %silent
JOIN = /(?i:join)\b/ _ %silent
INNER = /(?i:inner)\b/ _ %silent
LEFT = /(?i:left)\b/ _ %silent
RIGHT = /(?i:right)\b/ _ %silent
OUTER = /(?i:outer)\b/ _ %silent
ON = /(?i:on)\b/ _ %silent
```

The `Keyword` list names every keyword the finished grammar uses; the rules for the others arrive
with the steps that need them. Each new keyword goes in two places: the list and its own rule.

```sql
SELECT a FROM t JOIN u ON t.id = u.id
```
```json
{
  "type": "select",
  "columns": [{"expr":{"type":"column","table":null,"name":"a"},"alias":null}],
  "from": {
    "source": {"type":"table","name":"t","alias":null},
    "joins": [
      {
        "type": "join",
        "kind": "INNER",
        "source": {"type":"table","name":"u","alias":null},
        "on": {
          "type": "binary",
          "op": "=",
          "left": {"type":"column","table":"t","name":"id"},
          "right": {"type":"column","table":"u","name":"id"}
        }
      }
    ]
  }
}
```

```sql
select selection from t
```
```json
{
  "type": "select",
  "columns": [{"expr":{"type":"column","table":null,"name":"selection"},"alias":null}],
  "from": {"source":{"type":"table","name":"t","alias":null},"joins":[]}
}
```

`selection` is a column although it starts with `select`: the keyword regex needs a word boundary
after `select`, and there is none.

*Learned.* The keyword idiom (`/(?i:...)\b/` plus `!Keyword`), and that a PEG commits to the first
alternative that succeeds. Order alternatives, and add predicates, so the right one is chosen.

Step 5: expressions and precedence
----------------------------------

*Problem.* `1 + 2 * 3` must group as `1 + (2 * 3)`, `10 - 4 - 3` as `(10 - 4) - 3`, and
`a = 1 OR b = 2 AND c = 3` as `a = 1 OR (b = 2 AND c = 3)`.

A rule that starts with a reference to itself (`Sum = Sum "+" Sum | ...`) is *left-recursive*. A
plain PEG cannot handle that, Charm does: it grows the result with the operator alternatives,
and decides how deep to nest from each alternative's *precedence* and *associativity*. You
state them in an `@prec` table at the top of the grammar. Each line is one precedence level,
later lines bind tighter, and `left` / `right` / `none` is the associativity. The operator of
an alternative is the first string literal in it (`"+"`), which is looked up in the table. The
alternatives themselves can be listed in any order.

Comparison is a separate rule on top of the arithmetic one (`Predicate`, which uses `Sum`):
SQL comparisons do not chain, `1 < 2 < 3` is an error, not `(1 < 2) < 3`, and operators that
follow the subject like `IS NULL` and `BETWEEN` (step 7) need to hang off one operand. The
rule `PredicateTail` returns the *rest* of the comparison, and `Predicate` joins it to the
subject. (Writing one alternative per form, each starting with `Sum`, would parse the subject
over and again, and nested parentheses multiply that cost; the reference's performance section has
numbers.) Unary minus has the same literal `"-"` as subtraction, which the table would rank at
the `+`/`-` level; `%prec=NEG` picks the `NEG` line instead, so `-a * b` is `(-a) * b`.

This step also adds `WHERE`, whose condition is an `Expr`.

```cpeg
@prec {
  left "+" "-"
  left "*" "/" "%"
  left NEG
}

Query = SELECT columns:SelectList from:FromClause? where:WhereClause?
  <? return ['type' => 'select', 'columns' => $columns, 'from' => $from, 'where' => $where]; ?>

WhereClause = WHERE cond:Expr  <? return $cond; ?>

Expr = Predicate

Predicate = subject:Sum tail:PredicateTail?
  <?
    if ($tail === null) return $subject;
    return ['type' => 'binary', 'op' => $tail['op'], 'left' => $subject, 'right' => $tail['right']];
  ?>

PredicateTail = op:CompareOp right:Sum  <? return ['op' => $op, 'right' => $right]; ?>

CompareOp = /<=|>=|<>|!=|=|<|>/ _  <? return $_0; ?>

Sum = Sum "+" _ Sum  <? return ['type' => 'binary', 'op' => '+', 'left' => $_0, 'right' => $_2]; ?>
    | Sum "-" _ Sum  <? return ['type' => 'binary', 'op' => '-', 'left' => $_0, 'right' => $_2]; ?>
    | Sum "*" _ Sum  <? return ['type' => 'binary', 'op' => '*', 'left' => $_0, 'right' => $_2]; ?>
    | Sum "/" _ Sum  <? return ['type' => 'binary', 'op' => '/', 'left' => $_0, 'right' => $_2]; ?>
    | Sum "%" _ Sum  <? return ['type' => 'binary', 'op' => '%', 'left' => $_0, 'right' => $_2]; ?>
    | "-" _ Sum      %prec=NEG  <? return ['type' => 'unary', 'op' => '-', 'expr' => $_1]; ?>
    | Primary

Primary = Number
        | String
        | Column
        | "(" _ Expr ")" _  <? return $_1; ?>

WHERE = /(?i:where)\b/ _ %silent
```

In the `Sum` snippets `$_0` is the left operand, `$_1` the operator text and `$_2` the right operand: the
silent `_` takes no number. In the unary alternative `"-" _ Sum`, `$_0` is the `-` and `$_1` the
operand.

```sql
SELECT 1 + 2 * 3
```
```json
{
  "type": "select",
  "columns": [
    {
      "expr": {
        "type": "binary",
        "op": "+",
        "left": {"type":"literal","value":1},
        "right": {"type":"binary","op":"*","left":{"type":"literal","value":2},"right":{"type":"literal","value":3}}
      },
      "alias": null
    }
  ],
  "from": null,
  "where": null
}
```

```sql
SELECT 10 - 4 - 3
```
```json
{
  "type": "select",
  "columns": [
    {
      "expr": {
        "type": "binary",
        "op": "-",
        "left": {"type":"binary","op":"-","left":{"type":"literal","value":10},"right":{"type":"literal","value":4}},
        "right": {"type":"literal","value":3}
      },
      "alias": null
    }
  ],
  "from": null,
  "where": null
}
```

```sql
SELECT -a * b, (1 + 2) * 3
```
```json
{
  "type": "select",
  "columns": [
    {
      "expr": {
        "type": "binary",
        "op": "*",
        "left": {"type":"unary","op":"-","expr":{"type":"column","table":null,"name":"a"}},
        "right": {"type":"column","table":null,"name":"b"}
      },
      "alias": null
    },
    {
      "expr": {
        "type": "binary",
        "op": "*",
        "left": {"type":"binary","op":"+","left":{"type":"literal","value":1},"right":{"type":"literal","value":2}},
        "right": {"type":"literal","value":3}
      },
      "alias": null
    }
  ],
  "from": null,
  "where": null
}
```

```sql
SELECT a FROM t WHERE a >= 18
```
```json
{
  "type": "select",
  "columns": [{"expr":{"type":"column","table":null,"name":"a"},"alias":null}],
  "from": {"source":{"type":"table","name":"t","alias":null},"joins":[]},
  "where": {"type":"binary","op":">=","left":{"type":"column","table":null,"name":"a"},"right":{"type":"literal","value":18}}
}
```

```sql
SELECT 1 < 2 < 3
```
```error
Parse error at line 1, column 14: expected "+", found '<'
```

*Learned.* `@prec`, `left`, why each rule level exists, `%prec=NAME` for an alternative whose
operator is not a plain literal. 

Step 6: AND, OR, NOT
--------------------

*Problem.* The logical operators are keywords, not literals, so the table cannot find them by
looking at the alternative. Name them: the table accepts identifiers as operators, and
`%prec=NAME` on an alternative looks the name up. `NOT` is a prefix operator that binds tighter
than `AND` and looser than comparison, so `NOT a = 1 AND b = 2` is `(NOT (a = 1)) AND (b = 2)`.
The table is replaced in full; adding lines at the top renumbers the levels but not their order.

```cpeg
@prec {
  left OR
  left AND
  left NOT
  left "+" "-"
  left "*" "/" "%"
  left NEG
}

Expr = Expr OR Expr   %prec=OR   <? return ['type' => 'binary', 'op' => 'OR', 'left' => $_0, 'right' => $_1]; ?>
     | Expr AND Expr  %prec=AND  <? return ['type' => 'binary', 'op' => 'AND', 'left' => $_0, 'right' => $_1]; ?>
     | NOT Expr       %prec=NOT  <? return ['type' => 'unary', 'op' => 'NOT', 'expr' => $_0]; ?>
     | Predicate

OR = /(?i:or)\b/ _ %silent
AND = /(?i:and)\b/ _ %silent
NOT = /(?i:not)\b/ _ %silent
```

These keywords are `%silent`, so `Expr AND Expr` has just two values, `$_0` and `$_1`.

```sql
SELECT a FROM t WHERE a = 1 OR b = 2 AND c = 3
```
```json
{
  "type": "select",
  "columns": [{"expr":{"type":"column","table":null,"name":"a"},"alias":null}],
  "from": {"source":{"type":"table","name":"t","alias":null},"joins":[]},
  "where": {
    "type": "binary",
    "op": "OR",
    "left": {"type":"binary","op":"=","left":{"type":"column","table":null,"name":"a"},"right":{"type":"literal","value":1}},
    "right": {
      "type": "binary",
      "op": "AND",
      "left": {"type":"binary","op":"=","left":{"type":"column","table":null,"name":"b"},"right":{"type":"literal","value":2}},
      "right": {"type":"binary","op":"=","left":{"type":"column","table":null,"name":"c"},"right":{"type":"literal","value":3}}
    }
  }
}
```

```sql
SELECT a FROM t WHERE NOT a = 1 AND b = 2
```
```json
{
  "type": "select",
  "columns": [{"expr":{"type":"column","table":null,"name":"a"},"alias":null}],
  "from": {"source":{"type":"table","name":"t","alias":null},"joins":[]},
  "where": {
    "type": "binary",
    "op": "AND",
    "left": {
      "type": "unary",
      "op": "NOT",
      "expr": {"type":"binary","op":"=","left":{"type":"column","table":null,"name":"a"},"right":{"type":"literal","value":1}}
    },
    "right": {"type":"binary","op":"=","left":{"type":"column","table":null,"name":"b"},"right":{"type":"literal","value":2}}
  }
}
```

```sql
SELECT a FROM t WHERE (a = 1 OR b = 2) AND c = 3
```
```json
{
  "type": "select",
  "columns": [{"expr":{"type":"column","table":null,"name":"a"},"alias":null}],
  "from": {"source":{"type":"table","name":"t","alias":null},"joins":[]},
  "where": {
    "type": "binary",
    "op": "AND",
    "left": {
      "type": "binary",
      "op": "OR",
      "left": {"type":"binary","op":"=","left":{"type":"column","table":null,"name":"a"},"right":{"type":"literal","value":1}},
      "right": {"type":"binary","op":"=","left":{"type":"column","table":null,"name":"b"},"right":{"type":"literal","value":2}}
    },
    "right": {"type":"binary","op":"=","left":{"type":"column","table":null,"name":"c"},"right":{"type":"literal","value":3}}
  }
}
```

Step 7: IS NULL, IN, BETWEEN, LIKE
----------------------------------

*Problem.* `a IS NOT NULL`, `a NOT IN (1, 2)`, `a BETWEEN 1 AND 10`, `a LIKE 'x%'`.

All four come after a `Sum`, so they are more alternatives of `PredicateTail`, each returning
a node without its subject, and `Predicate` adds `'expr'`. Two details:

* The bounds of `BETWEEN` are `Sum`s, not `Expr`s. `BETWEEN` has its own `AND`; an `Expr` as the
  low bound would read `1 AND 10` as a logical expression and leave `BETWEEN` without its `AND`.
  Parse the operands with the rule that cannot contain `AND`.
* `not:NOT?` is a named capture on an optional part: `null` when absent, so `$not !== null` is the
  flag.

```cpeg
Predicate = subject:Sum tail:PredicateTail?
  <?
    if ($tail === null) return $subject;
    if ($tail['type'] === 'binary') {
        return ['type' => 'binary', 'op' => $tail['op'], 'left' => $subject, 'right' => $tail['right']];
    }
    return ['type' => $tail['type'], 'expr' => $subject] + $tail;
  ?>

PredicateTail = IS not:NOT? NULL                          <? return ['type' => 'is_null', 'negated' => $not !== null]; ?>
              | not:NOT? IN "(" _ values:ExprList ")" _   <? return ['type' => 'in_list', 'negated' => $not !== null, 'values' => $values]; ?>
              | not:NOT? BETWEEN low:Sum AND high:Sum     <? return ['type' => 'between', 'negated' => $not !== null, 'low' => $low, 'high' => $high]; ?>
              | not:NOT? LIKE pattern:Sum                 <? return ['type' => 'like', 'negated' => $not !== null, 'pattern' => $pattern]; ?>
              | op:CompareOp right:Sum                    <? return ['type' => 'binary', 'op' => $op, 'right' => $right]; ?>

ExprList = Expr ...(COMMA Expr)*

Primary = Number
        | String
        | NullLiteral
        | TrueLiteral
        | FalseLiteral
        | Column
        | "(" _ Expr ")" _  <? return $_1; ?>

NullLiteral = NULL   <? return ['type' => 'literal', 'value' => null]; ?>
TrueLiteral = TRUE   <? return ['type' => 'literal', 'value' => true]; ?>
FalseLiteral = FALSE <? return ['type' => 'literal', 'value' => false]; ?>

IS = /(?i:is)\b/ _ %silent
IN = /(?i:in)\b/ _ %silent
NULL = /(?i:null)\b/ _ %silent
TRUE = /(?i:true)\b/ _ %silent
FALSE = /(?i:false)\b/ _ %silent
BETWEEN = /(?i:between)\b/ _ %silent
LIKE = /(?i:like)\b/ _ %silent
```

The spread-based `ExprList` is the select-list idiom again.

```sql
SELECT a FROM t WHERE a IS NOT NULL AND b IN (1, 2, 3)
```
```json
{
  "type": "select",
  "columns": [{"expr":{"type":"column","table":null,"name":"a"},"alias":null}],
  "from": {"source":{"type":"table","name":"t","alias":null},"joins":[]},
  "where": {
    "type": "binary",
    "op": "AND",
    "left": {"type":"is_null","expr":{"type":"column","table":null,"name":"a"},"negated":true},
    "right": {
      "type": "in_list",
      "expr": {"type":"column","table":null,"name":"b"},
      "negated": false,
      "values": [{"type":"literal","value":1},{"type":"literal","value":2},{"type":"literal","value":3}]
    }
  }
}
```

```sql
SELECT a FROM t WHERE c BETWEEN 1 AND 10 AND d NOT LIKE 'x%'
```
```json
{
  "type": "select",
  "columns": [{"expr":{"type":"column","table":null,"name":"a"},"alias":null}],
  "from": {"source":{"type":"table","name":"t","alias":null},"joins":[]},
  "where": {
    "type": "binary",
    "op": "AND",
    "left": {
      "type": "between",
      "expr": {"type":"column","table":null,"name":"c"},
      "negated": false,
      "low": {"type":"literal","value":1},
      "high": {"type":"literal","value":10}
    },
    "right": {
      "type": "like",
      "expr": {"type":"column","table":null,"name":"d"},
      "negated": true,
      "pattern": {"type":"literal","value":"x%"}
    }
  }
}
```

```sql
SELECT NULL, TRUE
```
```json
{
  "type": "select",
  "columns": [{"expr":{"type":"literal","value":null},"alias":null},{"expr":{"type":"literal","value":true},"alias":null}],
  "from": null,
  "where": null
}
```

*Learned.* How to model "operand, then one of several things that may follow" without parsing the
operand twice: factor the operand out and return the tail as a partial node.

Step 8: function calls
----------------------

*Problem.* `COUNT(*)`, `UPPER(name)`, `COUNT(DISTINCT x)`, `NOW()`.

A call is an identifier followed by `(`. It must be tried before `Column` in `Primary`, because
`COUNT` alone is a perfectly good column name. The argument list may be empty (`args:CallArgs?`),
a `*`, or expressions; the `*` node is the one the select list already uses.

```cpeg
Primary = Number
        | String
        | NullLiteral
        | TrueLiteral
        | FalseLiteral
        | Call
        | Column
        | "(" _ Expr ")" _  <? return $_1; ?>

Call = name:Identifier "(" _ distinct:DISTINCT? args:CallArgs? ")" _
  <? return ['type' => 'call', 'name' => strtoupper($name), 'distinct' => $distinct !== null, 'args' => $args ?? []]; ?>

CallArgs = Star  <? return [$_0]; ?>
         | ExprList

DISTINCT = /(?i:distinct)\b/ _ %silent
```

```sql
SELECT COUNT(*), upper(name), COUNT(DISTINCT dept), now() FROM t
```
```json
{
  "type": "select",
  "columns": [
    {"expr":{"type":"call","name":"COUNT","distinct":false,"args":[{"type":"star","table":null}]},"alias":null},
    {
      "expr": {"type":"call","name":"UPPER","distinct":false,"args":[{"type":"column","table":null,"name":"name"}]},
      "alias": null
    },
    {
      "expr": {"type":"call","name":"COUNT","distinct":true,"args":[{"type":"column","table":null,"name":"dept"}]},
      "alias": null
    },
    {"expr":{"type":"call","name":"NOW","distinct":false,"args":[]},"alias":null}
  ],
  "from": {"source":{"type":"table","name":"t","alias":null},"joins":[]},
  "where": null
}
```

*Learned.* A named capture on a `%silent` reference still gets its value (`$distinct`), because
the name wins; the silent rule is only dropped when it is unnamed.

Step 9: DISTINCT, GROUP BY, HAVING, ORDER BY, LIMIT
---------------------------------------------------

*Problem.* The remaining clauses, each optional and in a fixed order.

Nothing new in the technique: a rule per clause, a named capture per optional clause in `Query`,
and a `?` on each. Absent clauses are `null`, and the snippet turns `null` into an empty list
where an empty list is the natural value.

```cpeg
Query = SELECT distinct:DISTINCT? columns:SelectList from:FromClause? where:WhereClause?
        groupBy:GroupByClause? having:HavingClause? orderBy:OrderByClause? limit:LimitClause?
  <? return [
       'type' => 'select', 'distinct' => $distinct !== null, 'columns' => $columns, 'from' => $from,
       'where' => $where, 'groupBy' => $groupBy ?? [], 'having' => $having,
       'orderBy' => $orderBy ?? [], 'limit' => $limit,
     ]; ?>

GroupByClause = GROUP BY list:ExprList  <? return $list; ?>

HavingClause = HAVING cond:Expr  <? return $cond; ?>

OrderByClause = ORDER BY items:OrderItems  <? return $items; ?>

OrderItems = OrderItem ...(COMMA OrderItem)*

OrderItem = expr:Expr dir:Direction?  <? return ['expr' => $expr, 'dir' => $dir ?? 'ASC']; ?>

Direction = ASC   <? return 'ASC'; ?>
          | DESC  <? return 'DESC'; ?>

LimitClause = LIMIT count:Integer offset:OffsetClause?  <? return ['count' => $count, 'offset' => $offset]; ?>

OffsetClause = OFFSET n:Integer  <? return $n; ?>

Integer = /[0-9]+/ _  <? return (int) $_0; ?>

GROUP = /(?i:group)\b/ _ %silent
BY = /(?i:by)\b/ _ %silent
HAVING = /(?i:having)\b/ _ %silent
ORDER = /(?i:order)\b/ _ %silent
LIMIT = /(?i:limit)\b/ _ %silent
OFFSET = /(?i:offset)\b/ _ %silent
ASC = /(?i:asc)\b/ _ %silent
DESC = /(?i:desc)\b/ _ %silent
```

```sql
SELECT DISTINCT a FROM t ORDER BY a DESC, b LIMIT 10 OFFSET 5
```
```json
{
  "type": "select",
  "distinct": true,
  "columns": [{"expr":{"type":"column","table":null,"name":"a"},"alias":null}],
  "from": {"source":{"type":"table","name":"t","alias":null},"joins":[]},
  "where": null,
  "groupBy": [],
  "having": null,
  "orderBy": [
    {"expr":{"type":"column","table":null,"name":"a"},"dir":"DESC"},
    {"expr":{"type":"column","table":null,"name":"b"},"dir":"ASC"}
  ],
  "limit": {"count":10,"offset":5}
}
```

```sql
SELECT dept, COUNT(*) AS n FROM emp GROUP BY dept HAVING COUNT(*) > 5
```
```json
{
  "type": "select",
  "distinct": false,
  "columns": [
    {"expr":{"type":"column","table":null,"name":"dept"},"alias":null},
    {"expr":{"type":"call","name":"COUNT","distinct":false,"args":[{"type":"star","table":null}]},"alias":"n"}
  ],
  "from": {"source":{"type":"table","name":"emp","alias":null},"joins":[]},
  "where": null,
  "groupBy": [{"type":"column","table":null,"name":"dept"}],
  "having": {
    "type": "binary",
    "op": ">",
    "left": {"type":"call","name":"COUNT","distinct":false,"args":[{"type":"star","table":null}]},
    "right": {"type":"literal","value":5}
  },
  "orderBy": [],
  "limit": null
}
```

*Learned.* A rule can be a single keyword plus one thing, with the snippet only there to unwrap
(`$list`, `$cond`); the final AST is assembled in one place, `Query`.

Step 10: subqueries
-------------------

*Problem.* `FROM (SELECT ...) AS s`, `a IN (SELECT ...)`, and `(SELECT MAX(x) FROM u)` as a value.

`Query` is a rule like any other, so a subquery is just `"(" _ Query ")"` in the right place. Three
rules change: a derived table in `FROM` (an alias is required), a second `IN` alternative that
must be tried before the list form, and a parenthesised `Query` in `Primary` that must be tried
before the parenthesised `Expr`. In both cases the parser tries the subquery form first because it
begins with a keyword that the other form cannot start with; when it fails at `SELECT`, the next
alternative gets its chance.

```cpeg
TableRef = "(" _ query:Query ")" _ alias:Alias  <? return ['type' => 'subquery', 'query' => $query, 'alias' => $alias]; ?>
         | name:Identifier alias:Alias?         <? return ['type' => 'table', 'name' => $name, 'alias' => $alias]; ?>

PredicateTail = IS not:NOT? NULL                          <? return ['type' => 'is_null', 'negated' => $not !== null]; ?>
              | not:NOT? IN "(" _ query:Query ")" _       <? return ['type' => 'in_subquery', 'negated' => $not !== null, 'query' => $query]; ?>
              | not:NOT? IN "(" _ values:ExprList ")" _   <? return ['type' => 'in_list', 'negated' => $not !== null, 'values' => $values]; ?>
              | not:NOT? BETWEEN low:Sum AND high:Sum     <? return ['type' => 'between', 'negated' => $not !== null, 'low' => $low, 'high' => $high]; ?>
              | not:NOT? LIKE pattern:Sum                 <? return ['type' => 'like', 'negated' => $not !== null, 'pattern' => $pattern]; ?>
              | op:CompareOp right:Sum                    <? return ['type' => 'binary', 'op' => $op, 'right' => $right]; ?>

Primary = Number
        | String
        | NullLiteral
        | TrueLiteral
        | FalseLiteral
        | Call
        | Column
        | "(" _ query:Query ")" _  <? return ['type' => 'subquery', 'query' => $query]; ?>
        | "(" _ Expr ")" _         <? return $_1; ?>
```

```sql
SELECT a FROM (SELECT b AS a FROM t) AS s
```
```json
{
  "type": "select",
  "distinct": false,
  "columns": [{"expr":{"type":"column","table":null,"name":"a"},"alias":null}],
  "from": {
    "source": {
      "type": "subquery",
      "query": {
        "type": "select",
        "distinct": false,
        "columns": [{"expr":{"type":"column","table":null,"name":"b"},"alias":"a"}],
        "from": {"source":{"type":"table","name":"t","alias":null},"joins":[]},
        "where": null,
        "groupBy": [],
        "having": null,
        "orderBy": [],
        "limit": null
      },
      "alias": "s"
    },
    "joins": []
  },
  "where": null,
  "groupBy": [],
  "having": null,
  "orderBy": [],
  "limit": null
}
```

```sql
SELECT a FROM t WHERE a IN (SELECT b FROM u WHERE c > 0)
```
```json
{
  "type": "select",
  "distinct": false,
  "columns": [{"expr":{"type":"column","table":null,"name":"a"},"alias":null}],
  "from": {"source":{"type":"table","name":"t","alias":null},"joins":[]},
  "where": {
    "type": "in_subquery",
    "expr": {"type":"column","table":null,"name":"a"},
    "negated": false,
    "query": {
      "type": "select",
      "distinct": false,
      "columns": [{"expr":{"type":"column","table":null,"name":"b"},"alias":null}],
      "from": {"source":{"type":"table","name":"u","alias":null},"joins":[]},
      "where": {"type":"binary","op":">","left":{"type":"column","table":null,"name":"c"},"right":{"type":"literal","value":0}},
      "groupBy": [],
      "having": null,
      "orderBy": [],
      "limit": null
    }
  },
  "groupBy": [],
  "having": null,
  "orderBy": [],
  "limit": null
}
```

```sql
SELECT (SELECT MAX(x) FROM u) AS m
```
```json
{
  "type": "select",
  "distinct": false,
  "columns": [
    {
      "expr": {
        "type": "subquery",
        "query": {
          "type": "select",
          "distinct": false,
          "columns": [
            {
              "expr": {"type":"call","name":"MAX","distinct":false,"args":[{"type":"column","table":null,"name":"x"}]},
              "alias": null
            }
          ],
          "from": {"source":{"type":"table","name":"u","alias":null},"joins":[]},
          "where": null,
          "groupBy": [],
          "having": null,
          "orderBy": [],
          "limit": null
        }
      },
      "alias": "m"
    }
  ],
  "from": null,
  "where": null,
  "groupBy": [],
  "having": null,
  "orderBy": [],
  "limit": null
}
```

*Learned.* Recursion between rules needs nothing special; only a rule that *starts* with itself is
left recursion, and that is the case `@prec` handles.

Step 11: parameters and `$state`
--------------------------------

*Problem.* Prepared-statement placeholders: `?` (numbered by position) and `:name`.

A positional parameter needs to know how many came before it. The snippets of one parse share
an array, `$state`, and its initial value is the extra argument to `cpeg_parse()` (the argument of
the generated class's `parse()` for a compiled parser). `Param` counts with it:

```cpeg
Primary = Number
        | String
        | NullLiteral
        | TrueLiteral
        | FalseLiteral
        | Param
        | Call
        | Column
        | "(" _ query:Query ")" _  <? return ['type' => 'subquery', 'query' => $query]; ?>
        | "(" _ Expr ")" _         <? return $_1; ?>

Param = "?" _                          <? return ['type' => 'param', 'name' => null, 'index' => $state['params']++]; ?>
      | /:[A-Za-z_][A-Za-z0-9_]*/ _    <? return ['type' => 'param', 'name' => substr($_0, 1), 'index' => null]; ?>
```

```sql
SELECT a FROM t WHERE x = ? AND y IN (?, ?) AND z = :name
```
```json
{
  "type": "select",
  "distinct": false,
  "columns": [{"expr":{"type":"column","table":null,"name":"a"},"alias":null}],
  "from": {"source":{"type":"table","name":"t","alias":null},"joins":[]},
  "where": {
    "type": "binary",
    "op": "AND",
    "left": {
      "type": "binary",
      "op": "AND",
      "left": {
        "type": "binary",
        "op": "=",
        "left": {"type":"column","table":null,"name":"x"},
        "right": {"type":"param","name":null,"index":0}
      },
      "right": {
        "type": "in_list",
        "expr": {"type":"column","table":null,"name":"y"},
        "negated": false,
        "values": [{"type":"param","name":null,"index":1},{"type":"param","name":null,"index":2}]
      }
    },
    "right": {
      "type": "binary",
      "op": "=",
      "left": {"type":"column","table":null,"name":"z"},
      "right": {"type":"param","name":"name","index":null}
    }
  },
  "groupBy": [],
  "having": null,
  "orderBy": [],
  "limit": null
}
```

Two cautions about `$state`:

* The counter must exist. This grammar reads `$state['params']`, so the caller seeds it:
  `['params' => 0]`, as every example here does. (Without it, the interpreter reports
  `Undefined variable $params`.) Every `parse()` starts from the state you pass, in both engines, so reusing one
  compiled object is safe.
* A snippet runs as soon as *its* sequence matches, even if the alternative that contains it fails
  later and the parser backtracks. `$state` is not rolled back. Here that cannot happen,
  because a `Param` is never re-parsed, but a counter in a place that can be retried counts the
  attempt twice:

```php
require 'vendor/autoload.php';

$grammar = 'S = A "x" | A "y"
A = "a" <? return $state["n"]++; ?>';

echo json_encode(Charm\cpeg_parse($grammar, 'ay', ['n' => 0]));
```
```output
[1,"y"]
```

`A` matched once for each alternative of `S`; the result is `1`, the second count. Use `$state`
for things that tolerate that (a set of names seen, a maximum, a flag), or compute the number
from the finished tree.

*Learned.* `$state` and its initial value, and that snippets have side effects the parser does not undo.

Step 12: comments
-----------------

*Problem.* `-- to end of line` and `/* block */` comments between tokens.

Because every token already ends with `_`, only `_` changes. It stays something that may match
nothing (`(...)*`), so it never fails.

```cpeg
_ = /(?:\s+|--[^\n]*|\/\*(?s:.*?)\*\/)*/ %silent
```

```sql
SELECT a -- the column
FROM t /* the table */ WHERE a = 1
```
```json
{
  "type": "select",
  "distinct": false,
  "columns": [{"expr":{"type":"column","table":null,"name":"a"},"alias":null}],
  "from": {"source":{"type":"table","name":"t","alias":null},"joins":[]},
  "where": {"type":"binary","op":"=","left":{"type":"column","table":null,"name":"a"},"right":{"type":"literal","value":1}},
  "groupBy": [],
  "having": null,
  "orderBy": [],
  "limit": null
}
```

The pattern is a PCRE regex. `(?s:.*?)` switches on dot-matches-newline for the block comment only,
and the lazy `*?` stops at the first `*/`. (The grammar text has no `.` of its own; a dot
only exists inside regexes.)

*Learned.* All whitespace policy is in one place, thanks to the "every token eats what follows"
convention.

Step 13: error messages and `@`
-------------------------------

*Problem.* A parser for people has to say what is wrong and where.

Start with what the parser says now. Both engines report the position of the *furthest terminal
that failed* (a literal or regex that was tried and did not match; predicates do not count). The
generated parser reports line and column counted from 1, in bytes; the interpreter counts the
column from 0. What it *expected* is the first thing that was tried at that position.

```sql
SELECT a FROM
```
```error
Parse error at line 1, column 14: expected TableRef, found end of input
```

```sql
SELECT a FROM t LIMIT x
```
```error
Parse error at line 1, column 23: expected Integer, found 'x'
```

`TableRef` and `Integer` are our rule names. A rule can carry a *description* between its name and
the `=`, which is used in place of the name. Add descriptions where the name means nothing to a user:

```cpeg
TableRef "table name" = "(" _ query:Query ")" _ alias:Alias  <? return ['type' => 'subquery', 'query' => $query, 'alias' => $alias]; ?>
         | name:Identifier alias:Alias?         <? return ['type' => 'table', 'name' => $name, 'alias' => $alias]; ?>

Identifier "identifier" = !Keyword /[A-Za-z_][A-Za-z0-9_]*/ _  <? return $_0; ?>
                        | /"(?:[^"]|"")*"/ _                    <? return str_replace('""', '"', substr($_0, 1, -1)); ?>

Integer "integer" = /[0-9]+/ _  <? return (int) $_0; ?>
```
(The second alternative of `Identifier` is a quoted identifier, `"my column"`, which the earlier steps left out.)

```sql
SELECT a FROM
```
```error
Parse error at line 1, column 14: expected table name, found end of input
```

```sql
SELECT a FROM t LIMIT x
```
```error
Parse error at line 1, column 23: expected integer, found 'x'
```

The commit operator `@` goes after a keyword that settles what the rest must be. `WHERE @ cond:Expr`
means: once `WHERE` has matched, the clause *must* continue; if the condition is missing that is
an error right there, not "no WHERE clause, try something else". Without `@`, `WhereClause?` would
turn the failure into `null`, the parser would carry on, and the error would show up later, at
whatever tried to read what was left of the input. The failure is also marked `committed`, which tells a
caller (and the generated `{Class}ParseError`) that no alternative could have rescued the input.

```cpeg
FromClause "FROM clause" = FROM @ source:TableRef joins:JoinClause*  <? return ['source' => $source, 'joins' => $joins]; ?>

JoinClause = kind:JoinKind @ source:TableRef ON @ on:Expr
  <? return ['type' => 'join', 'kind' => $kind, 'source' => $source, 'on' => $on]; ?>

WhereClause = WHERE @ cond:Expr  <? return $cond; ?>

GroupByClause = GROUP BY @ list:ExprList  <? return $list; ?>

HavingClause = HAVING @ cond:Expr  <? return $cond; ?>

OrderByClause = ORDER BY @ items:OrderItems  <? return $items; ?>

LimitClause = LIMIT @ count:Integer offset:OffsetClause?  <? return ['count' => $count, 'offset' => $offset]; ?>
```

A commit belongs to the sequence it is in and ends with it: it does not stop an enclosing choice or
`?` from trying something else *after that sequence succeeded*.

```sql
SELECT a FROM t
WHERE a = 1 AND
```
```error
Parse error at line 2, column 16: expected /(?i:not)\b/, found end of input
```

```php
require 'vendor/autoload.php';

$grammar = file_get_contents('example-grammars/sql.cpeg');
foreach (['SELECT a FROM', 'SELECT a FROM t WHERE', 'SELECT a,, b', 'SELECT a FROM t WHERE a = 1 b'] as $sql) {
    try {
        Charm\cpeg_parse($grammar, $sql, ['params' => 0]);
    } catch (Charm\Parsing\Error\AbstractParseError $e) {
        echo get_class($e), "\n    ", $e->getMessage(), "\n";
    }
}
```
```output
Charm\Parsing\Error\State\CommittedError
    Expected TableRef in FromClause on parsed source:1:13, found end of file
Charm\Parsing\Error\State\CommittedError
    Expected Expr in WhereClause on parsed source:1:21, found end of file
Charm\Parsing\Error\ParseError
    Failed parsing "SelectList" on parsed source:1:9, unexpected ","
Charm\Parsing\Error\ParseError
    Failed parsing "Sum" on parsed source:1:28, unexpected "b"
```

The interpreter names the rule that gave up and, after a commit, the rule it was waiting for. Both its
messages and the generated parser's carry the position.

Compiled messages quote the first terminal tried at the failing position, so a keyword rule with no
description appears as its regex source (`/(?i:not)\b/` above). That regex is also why: the compiler
reads a regex to learn which characters it can start with and how short it can be, and it
does not understand an inline `(?i:...)` group, so it knows nothing about these rules and cannot
use them to skip alternatives early. Step 15 puts a number on it.

*Learned.* Rule descriptions, `@`, `committed`, and what "furthest failure" means.

Step 14: what a snippet can return
----------------------------------

*Problem.* Reject input that is syntactically fine but not acceptable, and know what happens when
a snippet goes wrong.

A snippet returns the value of its sequence. Besides an ordinary value, it may return the
special value `$MISS`, which makes the sequence fail exactly as if a terminal had not matched, so
the parser tries the next alternative or reports an error at the furthest position. An integer
that does not fit a PHP `int` is an example (`(int)` would clamp it silently):

```cpeg
Integer "integer" = /[0-9]+/ _  <? return strlen($_0) > 18 ? $MISS : (int) $_0; ?>
```

```sql
SELECT a FROM t LIMIT 99999999999999999999
```
```error
Parse error at line 1, column 23: expected integer, found '9'
```

```sql
SELECT a FROM t LIMIT 100
```
```json
{
  "type": "select",
  "distinct": false,
  "columns": [{"expr":{"type":"column","table":null,"name":"a"},"alias":null}],
  "from": {"source":{"type":"table","name":"t","alias":null},"joins":[]},
  "where": null,
  "groupBy": [],
  "having": null,
  "orderBy": [],
  "limit": {"count":100,"offset":null}
}
```

Other things a snippet can do or see:

| In a snippet | Meaning |
|---|---|
| `$_0`, `$_1`, ... | the values of the parts that have one, in order; silent rules, predicates, `$`, `^` and named parts do not take a number |
| `$name` | a named part (`name:Thing`); `null` for an optional part that did not match |
| `$context` | the whole collected array, names and numbers (interpreter only) |
| `$state` | the array shared by all snippets of one parse |
| `$MISS`, `$HIT` | return `$MISS` to fail; `$HIT` is the "no value" marker |
| `new \Charm\Parsing\SequenceIgnore` | succeed with no value |
| `new \Charm\Parsing\SequenceResume` | treat the snippet as not there and carry on |

Anything else a snippet throws is wrapped:

```php
require 'vendor/autoload.php';

try {
    Charm\cpeg_parse('S = /[0-9]+/ <? throw new \DomainException("not now"); ?>', '1');
} catch (Charm\Parsing\Error\SnippetError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
    echo get_class($e->getPrevious()), "\n";
}
```
```output
Charm\Parsing\Error\SnippetError: Error in snippet: not now
DomainException
```

The exception is not a parse failure: it ends the parse, and no other alternative is tried. Use
`$MISS` for "this input is not valid here" and exceptions for "the grammar or the host is wrong".

*Learned.* `$MISS`, the other return values, and the line between a parse failure and an error in
your own code. Write a `\` in front of global classes in a snippet (`\DomainException`, `\ArrayObject`): the
interpreter evaluates snippets inside a namespace of its own, where a bare class name is looked up
first. Bare function names fall back to the global ones, as the grammar above uses `strlen`.

Step 15: compiling the grammar
------------------------------

`cpeg_parse()` interprets the grammar: it walks the rule objects for each input. For a long-running
service, or a library you ship, compile it once into a PHP class instead:

```bash
vendor/bin/charm-compile example-grammars/sql.cpeg -c SqlParser -n 'App\Sql' -o src/Sql/SqlParser.php
```

That writes a single file of about 4,700 lines (178 KB) with no dependency on this library. The
class has one method, `parse(string $source, array $initialState = [])`, and uses nothing but PHP:

```php
require __DIR__ . '/SqlParser.php';   // no autoloader, no vendor/

$parser = new App\Sql\SqlParser();
$ast = $parser->parse('SELECT a FROM t WHERE a = ?', ['params' => 0]);
echo json_encode($ast['where']), "\n";

try {
    $parser->parse('SELECT a FROM t WHERE', ['params' => 0]);
} catch (App\Sql\SqlParserParseError $e) {
    printf("%d:%d expected %s, found %s (committed: %s)\n",
        $e->sourceLine, $e->sourceColumn, $e->expected, $e->found, var_export($e->committed, true));
}
```
```text
{"type":"binary","op":"=","left":{"type":"column","table":null,"name":"a"},"right":{"type":"param","name":null,"index":0}}
1:22 expected /(?i:not)\b/, found end of input (committed: true)
```

The error class is generated into the same file, next to the parser. It extends `\RuntimeException`
and carries `sourceLine`, `sourceColumn` (both counted from 1, the column in bytes), `offset`,
`expected`, `found` and `committed`. Because the generated class and the interpreter agree on every
result and on where an error is, you can develop against `cpeg_parse()` and ship the class.

What differs between the two:

* `$context` exists only in the interpreter. Do not use it in a grammar you will compile.
* `$state` starts from the initial state on every call in both engines, so one compiled object can parse
  any number of queries. Pass the state you need on every call, as above.
* Interpreter errors are `Charm\Parsing\Error\ParseError` (and `State\CommittedError`) with the column
  counted from 0.

*Speed.* One 221-byte query with joins, a `WHERE`, `GROUP BY`, `HAVING`, `ORDER BY` and `LIMIT`,
parsed repeatedly on PHP 8.5.12 (CLI, no opcache), best of five rounds:

| | per parse |
|---|---|
| `cpeg_parse()`, interpreted | 1.65 ms |
| compiled `SqlParser` | 0.29 ms |
| compiled, keywords spelled `[Ss][Ee][Ll][Ee][Cc][Tt]\b` instead of `(?i:select)\b` | 0.22 ms |

Compiling is a factor of about six here. The last row is the cost of `(?i:...)` that step 13
mentioned: written as character classes the same keywords are understood by the compiler's
analysis, which lets it skip alternatives by their first character, and the parser takes 24 percent
less time. `@` made no measurable difference in this grammar (a grammar containing `@` loses some of
the compiler's optimizations, so measure yours). The interpreter pays the same either way.
Compile when parse time matters; otherwise the interpreter is easier to debug.

*Learned.* `charm-compile`, the generated class and its error type, and the few differences to
watch for.

Step 16: doing something with the tree
--------------------------------------

The result is plain arrays, so any function can walk it. Every node has a `'type'`, so a generic
walk visits arrays and ignores everything else. This one lists the tables a query reads and the
columns it uses, resolving `u.name` through the alias `u`:

```php
require 'vendor/autoload.php';

function referenced_tables_and_columns(array $ast): array
{
    $tables = [];
    $columns = [];
    $walk = function (mixed $node) use (&$walk, &$tables, &$columns): void {
        if (!is_array($node)) {
            return;
        }
        if (($node['type'] ?? null) === 'table') {
            $tables[$node['alias'] ?? $node['name']] = $node['name'];
        }
        if (($node['type'] ?? null) === 'column') {
            $columns[] = [$node['table'], $node['name']];
        }
        foreach ($node as $child) {
            $walk($child);
        }
    };
    $walk($ast);

    $resolved = [];
    foreach ($columns as [$table, $name]) {
        $table = $table === null ? null : ($tables[$table] ?? $table);
        $resolved[] = $table === null ? $name : "$table.$name";
    }
    return ['tables' => array_values(array_unique($tables)), 'columns' => array_values(array_unique($resolved))];
}

$grammar = file_get_contents('example-grammars/sql.cpeg');
$ast = Charm\cpeg_parse($grammar, "
    SELECT u.name, COUNT(o.id) AS orders
    FROM users u LEFT JOIN orders o ON o.user_id = u.id
    WHERE u.age >= 18 AND o.total IN (SELECT total FROM top_orders)
    GROUP BY u.name
", ['params' => 0]);

echo json_encode(referenced_tables_and_columns($ast)), "\n";
```
```output
{"tables":["users","orders","top_orders"],"columns":["users.name","orders.id","orders.user_id","users.id","users.age","orders.total","total"]}
```

A tree can also be rewritten. Given a schema, this expands `SELECT *` into the real column list:

```php
require 'vendor/autoload.php';

function expand_stars(array $query, array $schema): array
{
    $columns = [];
    foreach ($query['columns'] as $item) {
        if (($item['type'] ?? null) !== 'star') {
            $columns[] = $item;
            continue;
        }
        foreach ($schema[$query['from']['source']['name']] as $name) {
            $columns[] = [
                'expr' => ['type' => 'column', 'table' => $item['table'], 'name' => $name],
                'alias' => null,
            ];
        }
    }
    return ['columns' => $columns] + $query;
}

$grammar = file_get_contents('example-grammars/sql.cpeg');
$ast = Charm\cpeg_parse($grammar, 'SELECT *, 1 AS one FROM users', ['params' => 0]);
$ast = expand_stars($ast, ['users' => ['id', 'name']]);

foreach ($ast['columns'] as $item) {
    echo ($item['expr']['name'] ?? $item['expr']['value']), ' ';
}
echo "\n";
```
```output
id name 1 
```

The tree is the contract: writing it back out as SQL, checking names against a schema, or turning
it into a different query language are all functions over these arrays.

Where to go from here
---------------------

* To add syntax, add a rule and one alternative where it belongs: `CASE` is a new `Primary`
  alternative, `EXISTS (SELECT ...)` another, `UNION` a rule above `Query`, an `INSERT` statement an
  alternative of `Statement`. New keywords go into `Keyword` and get a rule.
* For the precise meaning of every operator, pragma and flag used here, see the
  [reference](../REFERENCE.md); [Precedence and pragmas](precedence-and-pragmas.md) and
  [Snippets and state](snippets-and-state.md) go deeper than this tutorial did, and
  [Errors and debugging](errors-and-debugging.md) covers everything a parse can throw.
