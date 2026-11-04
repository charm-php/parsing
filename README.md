# Charm/Parsing

A PEG parser for PHP. Parse expressions, JSON, SQL, or any context-free grammar.

```php
$result = Charm\cpeg_parse('
    Expr = Expr "**" Expr   %assoc=right
         | Expr "*" Expr
         | Expr "/" Expr
         | Expr "+" Expr
         | Expr "-" Expr
         | "(" Expr ")"
         | /[0-9]+/
', '2+3*4**2');
// Returns: ["2", "+", ["3", "*", ["4", "**", "2"]]]
// Precedence: ** > * / > + - (like JavaScript)
```

## Installation

```bash
composer require charm/parsing
```

## Quick Start

### One-liner parsing

```php
use function Charm\cpeg_parse;

// Parse and get AST
$ast = cpeg_parse('Number = /[0-9]+/', '42');

// Parse and evaluate
$result = cpeg_parse('
    Expr = Expr "*" Expr  <? return $_0 * $_2; ?>
         | Expr "+" Expr  <? return $_0 + $_2; ?>
         | /[0-9]+/       <? return intval($_0); ?>
', '2+3*4');
// Returns: 14
```

### Parser class

```php
class Calculator extends Charm\Parser {
    const GRAMMAR = <<<'GRAMMAR'
        Expr = Expr "*" Expr  <? return $_0 * $_2; ?>
             | Expr "+" Expr  <? return $_0 + $_2; ?>
             | /[0-9]+/       <? return intval($_0); ?>
    GRAMMAR;
}

$calc = new Calculator();
echo $calc->parse('2+3*4'); // 14
```

### Compiled parser (10x faster)

```bash
# Generate standalone PHP class
vendor/bin/charm-compile grammar.peg -o Parser.php -c MyParser
```

```php
use Charm\Parsing\Compiler\GrammarCompiler;

$compiler = new GrammarCompiler();
$code = $compiler->compile($grammar, 'MyParser', [
    'namespace' => 'App\\Parser',
]);
file_put_contents('MyParser.php', $code);
```

---

## Grammar Reference

### Rules

```
RuleName = clause
```

Rules define named patterns. The first rule is the entry point.

```
Greeting = "hello" Name
Name = /[a-z]+/i
```

Rules can have descriptions (for error messages):

```
Number "integer" = /[0-9]+/
```

Alternative assignment operators: `=`, `::=`, `:=`, `->`

### Comments

```
# Line comment (shell style)
// Line comment (C++ style)
/* Block comment */
```

### Terminals

**Literals and patterns:**

| Syntax | Description |
|--------|-------------|
| `"text"` | Literal string match |
| `'text'` | Literal string match (single quotes) |
| `/regex/` | Regular expression |
| `[a-z]` | Character class (shorthand for `/[a-z]/`) |

**Special symbols:**

| Symbol | Description |
|--------|-------------|
| `.` | Any single UTF-8 character |
| `^` | Start of input (zero-width assertion) |
| `$` | End of input (zero-width assertion) |
| `\nothing` | Always succeeds without consuming input (epsilon) |

### Operators

| Syntax | Name | Description |
|--------|------|-------------|
| `A B` | Sequence | Match A then B |
| `A \| B` | Choice | Match A or B (try in order) |
| `A / B` | Choice | Same as `\|` (alternative syntax) |
| `A?` | Optional | Match A zero or one time |
| `A*` | Zero or more | Match A zero or more times |
| `A+` | One or more | Match A one or more times |
| `(A B)` | Group | Group clauses together |
| `&A` | And-predicate | Succeed if A matches, don't consume |
| `!A` | Not-predicate | Succeed if A fails, don't consume |
| `...A` | Spread | Flatten array results into parent |
| `@` | Commit | Prevent backtracking past this point |

The spread operator `...` flattens array results into the parent sequence:

```
# Without spread: [["a","b"], ["a","b"], "end"]
Items = ("a" "b")* "end"

# With spread: ["a", "b", "a", "b", "end"]
Items = ...("a" "b")* "end"

# Spread with named capture: ...name:Clause
Program = ...body:Statement*  # $body = array, $_0/$_1/... = elements
```

The commit operator `@` improves error messages by preventing the parser from
trying other alternatives after a definitive match:

```
Statement = "if" @ "(" Expr ")" Block   # Once "if" matches, commit
          | "while" @ "(" Expr ")" Block
          | Expr ";"
```

Use `\nothing` for explicit empty alternatives:

```
OptionalName = Name | \nothing   # Match name or nothing
```

### Named captures

```
Pair = key:String ":" value:Number
```

Access in snippets as `$key` and `$value`.

### Snippets (inline PHP)

```
Number = /[0-9]+/  <? return intval($_0); ?>
```

Variables available:
- `$_0`, `$_1`, ... — matched values by position
- `$name` — named captures
- `$state` — persistent state across snippets

Return values:
- Any value — becomes the rule's result
- `true` — succeed but add nothing to result
- `false` — fail this alternative, try next

### Pragmas

```
Expr = Expr "**" Expr  %assoc=right   # Right associative
     | Expr "+" Expr                   # Left associative (default)
     | Expr "<" Expr   %assoc=none    # Non-associative (a < b < c fails)
```

Values: `%assoc=left` (default), `%assoc=right`, `%assoc=none`

---

## Example Grammars

### Calculator with precedence

```php
$calc = Charm\cpeg_parse('
    Expr = Expr _ "**" _ Expr  %assoc=right  <? return pow($_0, $_4); ?>
         | Expr _ "*" _ Expr                 <? return $_0 * $_4; ?>
         | Expr _ "/" _ Expr                 <? return $_0 / $_4; ?>
         | Expr _ "+" _ Expr                 <? return $_0 + $_4; ?>
         | Expr _ "-" _ Expr                 <? return $_0 - $_4; ?>
         | Primary

    Primary = "(" _ Expr _ ")"               <? return $_2; ?>
            | Number

    Number = /[0-9]+(\.[0-9]+)?/             <? return floatval($_0); ?>
    _      = /\s*/
', '2**3**2 + 1');
// Returns: 513.0 (2^(3^2) + 1 = 2^9 + 1)
```

### JSON parser

```php
$json = Charm\cpeg_parse('
    JSON   = _ val:Value _                         <? return $val; ?>

    Value  = Object
           | Array
           | String
           | Number
           | "true"   <? return true; ?>
           | "false"  <? return false; ?>
           | "null"   <? return null; ?>

    Object = "{" _ "}"                             <? return (object)[]; ?>
           | "{" _ p:Pairs _ "}"                   <? return (object)$p; ?>

    Pairs  = p:Pair ps:("," _ Pair)*               <?
               $result = [$p[0] => $p[1]];
               foreach ($ps as $x) $result[$x[1][0]] = $x[1][1];
               return $result;
           ?>

    Pair   = k:String _ ":" _ v:Value              <? return [$k, $v]; ?>

    Array  = "[" _ "]"                             <? return []; ?>
           | "[" _ i:Items _ "]"                   <? return $i; ?>

    Items  = v:Value vs:("," _ Value)*             <?
               $result = [$v];
               foreach ($vs as $x) $result[] = $x[1];
               return $result;
           ?>

    String = /"([^"\\\\]|\\\\.)*"/                 <? return json_decode($_0); ?>

    Number = /-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?/
                                                   <? return strpos($_0, ".") !== false
                                                        ? floatval($_0) : intval($_0); ?>

    _      = /\\s*/                                <? return true; ?>
', '{"name": "Alice", "scores": [95, 87, 92]}');
// Returns: stdClass with name="Alice", scores=[95, 87, 92]
```

### SQL SELECT parser

```php
$sql = Charm\cpeg_parse('
    Select   = "SELECT" _ cols:Columns _ "FROM" _ table:Ident where:Where? _
               <? return ["select" => $cols, "from" => $table, "where" => $where]; ?>

    Columns  = "*"                                 <? return "*"; ?>
             | Column ("," _ Column)*              <?
                   $cols = [$_0];
                   foreach ($_1 as $c) $cols[] = $c[1];
                   return $cols;
               ?>

    Column   = Ident

    Where    = _ "WHERE" _ Condition               <? return $_1; ?>

    Condition = left:Ident _ op:Op _ right:Value
                <? return ["left" => $left, "op" => $op, "right" => $right]; ?>

    Op       = "=" | "!=" | "<>" | "<=" | ">=" | "<" | ">"

    Value    = String | Number | Ident

    String   = /"[^"]*"/                           <? return trim($_0, "\""); ?>
    Number   = /[0-9]+/                            <? return intval($_0); ?>
    Ident    = /[a-zA-Z_][a-zA-Z0-9_]*/

    _        = /\s*/                               <? return true; ?>
', 'SELECT id, name FROM users WHERE status = "active"');
// Returns: ["select" => ["id", "name"], "from" => "users",
//           "where" => ["left" => "status", "op" => "=", "right" => "active"]]
```

---

## CLI Tool

```bash
# Compile grammar to PHP class
vendor/bin/charm-compile grammar.peg -c MyParser -o src/MyParser.php

# With namespace
vendor/bin/charm-compile grammar.peg -c Parser -n "App\\Parser" -o src/Parser.php

# From stdin
echo 'Num = /[0-9]+/' | vendor/bin/charm-compile - -c NumParser

# Options
#   -o, --output <file>     Output file (default: stdout)
#   -c, --class <name>      Class name
#   -n, --namespace <ns>    PHP namespace
#   -e, --extends <class>   Base class
#   -i, --implements <if>   Interfaces (comma-separated)
#   -q, --quiet             No progress messages
```

---

## Performance

Compiled parsers are ~10x faster than interpreted:

```
Expression          Interpreted    Compiled     Speedup
1+2                    0.014 ms    0.001 ms       10x
1+2*3                  0.020 ms    0.002 ms       10x
1+2+3+4+5              0.037 ms    0.004 ms       10x
```

Run benchmarks: `php benchmarks/run.php`

---

## License

GPL-3.0-only, with an additional permission (Bison-style output exception): parsers generated by
`charm-compile` / `Charm\Parsing\Compiler`, including the template text copied into them, are not
covered by the GPL and may be used under any terms. See [LICENCE.md](LICENCE.md).
