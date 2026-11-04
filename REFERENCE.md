# Charm/Parsing Grammar Reference

Complete reference for the PEG grammar syntax.

---

## Rules

```
RuleName = clause
```

Rules define named patterns. The **first rule** is the entry point for parsing.

**Assignment operators:** `=`, `::=`, `:=`, `->`

```
Greeting = "hello" Name
Name ::= /[a-z]+/i
```

**Rule descriptions** (for error messages):

```
Number "integer" = /[0-9]+/
```

When parsing fails at `Number`, the error will say "expected integer" instead of "expected Number".

---

## Comments

```
# Line comment (shell style)
// Line comment (C++ style)
/* Block comment */
```

---

## Terminals

### String Literals

| Syntax | Description |
|--------|-------------|
| `"text"` | Literal string match |
| `'text'` | Literal string match (single quotes) |

Escape sequences: `\\`, `\"`, `\'`, `\n`, `\r`, `\t`

### Patterns

| Syntax | Description |
|--------|-------------|
| `/regex/` | Regular expression (PCRE) |
| `/regex/i` | Case-insensitive regex |
| `[a-z]` | Character class (shorthand for `/[a-z]/`) |

Regex patterns are anchored at the current position automatically.

```
Identifier = /[a-zA-Z_][a-zA-Z0-9_]*/
Whitespace = /\s+/
HexNumber = /0x[0-9a-fA-F]+/i
Vowel = [aeiouAEIOU]
```

### Special Symbols

| Symbol | Description |
|--------|-------------|
| `.` | Any single UTF-8 character |
| `^` | Start of input (zero-width assertion) |
| `$` | End of input (zero-width assertion) |
| `\nothing` | Always succeeds without consuming input (epsilon) |

**Examples:**

```
# Match any character except newline
AnyButNewline = !"\n" .

# Ensure we're at the start
StartAnchored = ^ "BEGIN"

# Ensure complete consumption
Complete = Expression $

# Optional element using \nothing
OptionalSign = "+" | "-" | \nothing
```

---

## Operators

### Sequence

```
A B C
```

Match A, then B, then C in order. Results collected into an array.

```
Assignment = Identifier "=" Expression
# Input: "x=42" → ["x", "=", "42"]
```

### Ordered Choice

```
A | B | C
A / B / C
```

Try A first; if it fails, try B; if that fails, try C. Returns first successful match.

```
Value = Number | String | Boolean | Null
Boolean = "true" | "false"
```

**Note:** Choices are tried in order. Put longer/more specific matches first:

```
# Wrong - "else" matches before "elseif"
Keyword = "else" | "elseif" | "if"

# Correct
Keyword = "elseif" | "else" | "if"
```

### Quantifiers

| Syntax | Name | Description |
|--------|------|-------------|
| `A?` | Optional | Match zero or one time, returns match or `null` |
| `A*` | Zero or more | Match zero or more times, returns array |
| `A+` | One or more | Match one or more times, returns array (fails if zero) |

```
# Optional whitespace
OptWS = /\s*/

# One or more digits
Digits = [0-9]+

# Zero or more arguments
Args = (Expr ("," Expr)*)?
```

### Grouping

```
(A B C)
```

Group clauses together to apply operators or for clarity.

```
# Repeat a sequence
KeyValues = (Key ":" Value)*

# Choice within sequence
Statement = ("let" | "const") Identifier "=" Expr
```

### Predicates

| Syntax | Name | Description |
|--------|------|-------------|
| `&A` | And-predicate | Succeed if A matches, but don't consume input |
| `!A` | Not-predicate | Succeed if A fails, don't consume input |

Predicates are zero-width assertions - they look ahead without consuming.

```
# Match identifier that's not a keyword
Identifier = !Keyword /[a-z]+/i
Keyword = "if" | "else" | "while" | "return"

# Peek at next character
FollowedByDigit = &[0-9]

# Match until end marker (without consuming it)
Content = (!"}}" .)*
```

**Practical example - C-style comments:**

```
BlockComment = "/*" (!"*/" .)* "*/"
```

### Spread

```
...A
```

Flatten array results into the parent sequence. Skips `null` values.

```
# Without spread
Items = ("a" "b")* "end"
# Input: "ababend" → [["a","b"], ["a","b"], "end"]

# With spread
Items = ...("a" "b")* "end"
# Input: "ababend" → ["a", "b", "a", "b", "end"]
```

**Spread with optional (skips null):**

```
List = ...Item? ...Item? ...Item?
Item = /[a-z]/
# Input: "ab" → ["a", "b"]  (no null for missing third)
```

**Spread in templates:**

```
Bracketed<T> = "[" ...T "]"
List<T, Sep> = T (Sep T)*
Expr = Bracketed<List<Number, ",">>
Number = /[0-9]+/
# Input: "[1,2,3]" → ["[", "1", ",", "2", ",", "3", "]"]
```

**Spread with named capture:**

```
...name:Clause
```

Combines spread with a named capture. The name captures the original array, while spread flattens the elements into positional variables.

```
Program = ...body:Statement*  <? return ['body' => $body]; ?>
Statement = /[a-z]/
# Input: "abc"
# $body = ["a", "b", "c"]  (original array)
# $_0 = "a", $_1 = "b", $_2 = "c"  (spread elements)
```

**Note:** The syntax `name:...Clause` is invalid. Always put spread before the name: `...name:Clause`.

### Commit

```
@
```

Prevent backtracking past this point. Improves error messages by committing to a parse path.

```
Statement = "if" @ "(" Expr ")" Block
          | "while" @ "(" Expr ")" Block
          | Expr ";"
```

After matching `"if"`, the parser commits. If `"("` fails, it won't backtrack to try `"while"` - instead it reports an error like "expected '(' after 'if'".

---

## Named Captures

```
name:Clause
```

Assign a name to a matched value. Accessible in snippets as `$name`.

```
Pair = key:String ":" value:Number
# Input: "foo":42 → ["foo", ":", "42", "key" => "foo", "value" => "42"]
```

Named captures add associative keys to the result array alongside positional entries.

---

## Snippets (Inline PHP)

```
<? php_code ?>
```

Execute PHP code after a successful match. Access matched values and transform results.

**Available variables:**

| Variable | Description |
|----------|-------------|
| `$_0`, `$_1`, ... | Matched values by position |
| `$name` | Named captures |
| `$state` | Persistent state across all snippets |

**Return values:**

| Return | Effect |
|--------|--------|
| Any value | Becomes the rule's result |
| `true` | Succeed but contribute nothing to parent sequence |
| `false` | Fail this alternative, try next |

**Examples:**

```
# Transform to integer
Number = /[0-9]+/  <? return intval($_0); ?>

# Build an object
Pair = key:String ":" value:Expr  <? return [$key => $value]; ?>

# Filter/validate
PositiveInt = /[0-9]+/  <?
    $n = intval($_0);
    return $n > 0 ? $n : false;
?>

# Use state for context
Expr = Number (Op Number)*  <?
    $state['lastExpr'] = $_0;
    return $_0;
?>
```

**Snippet in choice (conditional matching):**

```
# Only match if value is in valid range
Byte = /[0-9]+/  <?
    $v = intval($_0);
    return ($v >= 0 && $v <= 255) ? $v : false;  # false tries next alternative
?>
```

---

## Pragmas

```
%name=value
```

Modify parsing behavior for a specific alternative.

### Associativity

```
%assoc=left    # Left associative (default)
%assoc=right   # Right associative
%assoc=none    # Non-associative (error on chaining)
```

**Operator precedence** is determined by the order of alternatives (earlier = higher precedence):

```
Expr = Expr "**" Expr  %assoc=right    # Highest precedence, right-assoc
     | Expr "*"  Expr                  # Higher precedence, left-assoc
     | Expr "+"  Expr                  # Lowest precedence, left-assoc
     | Number

# "2+3*4" parses as "2+(3*4)" due to precedence
# "2**3**4" parses as "2**(3**4)" due to right-assoc
```

**Non-associative (comparison operators):**

```
Expr = Expr "<" Expr  %assoc=none
     | Number

# "1<2" works
# "1<2<3" fails (non-associative)
```

Also: `%prec=N|T_NAME` sets explicit precedence and `%silent` on a rule keeps its result out of
sequence arrays.

---

## Token Mode

A grammar starting with `@lexer Some\Lexer\Class` is parsed over tokens, not characters. The class
implements `LexerInterface` (`lex(string): TokensInterface`, `getSymbolMap(): array`).

| Syntax | Clause | Matches |
|--------|--------|---------|
| `T_NAME` | `Token` | a token whose type is `T_NAME` in the symbol map; returns its text |
| `"+"` | `Literal` | a single-character token (stored as a string in the token array); returns it |

Directive `@prec { left "+" "-"  left "*" "/"  right T_POW  none "<" }` defines a precedence table
(later lines bind tighter; `left`, `right` or `none`).

Compile with `charm-compile grammar.cpeg -c Parser -o Parser.php`; the token-mode template is selected
automatically. `-t, --template <file>` supplies your own template (placeholders such as
`{{GRAMMAR_NAME}}`, `{{NAMESPACE}}`, `{{CLASS_DECLARATION}}`, `{{PARSE_METHODS}}`, `{{SIGNAL_ENUM}}`).
Token-mode parsers depend on `Charm\Parsing\TokensInterface` and the lexer class at runtime.

---

## Templates (Generics)

```
Name<Param1, Param2> = clause using Param1 and Param2
```

Define reusable parameterized rules. Templates are expanded at preprocessing time.

**Definition:**

```
Paren<T> = "(" T ")"
List<T, Sep> = T (Sep T)*
Between<L, R, T> = L T R
```

**Usage:**

```
ParenExpr = Paren<Expr>
CommaSeparated = List<Number, ",">
Bracketed = Between<"[", "]", Expr>
```

**Nested templates:**

```
Paren<T> = "(" T ")"
List<T, Sep> = T (Sep T)*
Expr = Paren<List<Number, ",">>
# Expands to match "(1,2,3)"
```

**With spread:**

```
Paren<T> = "(" ...T ")"
List<T, Sep> = T (Sep T)*
Expr = Paren<List<Number, ",">>
# Input: "(1,2,3)" → ["(", "1", ",", "2", ",", "3", ")"]
```

**String literal arguments:**

```
Wrap<L, R, T> = L T R
Parens = Wrap<"(", ")", Expr>
Brackets = Wrap<"[", "]", Expr>
Angles = Wrap<"<", ">", Expr>
```

---

## Complete Examples

### Calculator with Precedence

```
Expr = Expr _ "**" _ Expr  %assoc=right  <? return pow($_0, $_4); ?>
     | Expr _ "*" _ Expr   <? return $_0 * $_4; ?>
     | Expr _ "/" _ Expr   <? return $_0 / $_4; ?>
     | Expr _ "+" _ Expr   <? return $_0 + $_4; ?>
     | Expr _ "-" _ Expr   <? return $_0 - $_4; ?>
     | "(" _ Expr _ ")"    <? return $_2; ?>
     | Number

Number = /[0-9]+(\.[0-9]+)?/  <? return floatval($_0); ?>
_      = /\s*/
```

### JSON Parser

```
JSON = _ Value _  <? return $_1; ?>

Value = Object | Array | String | Number | True | False | Null

Object = "{" _ "}"                    <? return (object)[]; ?>
       | "{" _ Pairs _ "}"            <? return (object)$_2; ?>

Pairs = Pair ("," _ Pair)*            <?
          $result = $_0;
          foreach ($_1 as $p) $result = array_merge($result, $p[2]);
          return $result;
       ?>

Pair = String _ ":" _ Value           <? return [$_0 => $_4]; ?>

Array = "[" _ "]"                     <? return []; ?>
      | "[" _ Elements _ "]"          <? return $_2; ?>

Elements = Value ("," _ Value)*       <?
             $result = [$_0];
             foreach ($_1 as $e) $result[] = $e[2];
             return $result;
          ?>

String = /"([^"\\]|\\.)*"/            <? return json_decode($_0); ?>
Number = /-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?/
                                      <? return floatval($_0); ?>
True = "true"                         <? return true; ?>
False = "false"                       <? return false; ?>
Null = "null"                         <? return null; ?>

_ = /\s*/                             <? return true; ?>
```

### S-Expression Parser

```
SExpr = Atom | List
List = "(" ...SExpr* ")"
Atom = Symbol | Number | String

Symbol = /[a-zA-Z_][a-zA-Z0-9_-]*/
Number = /-?[0-9]+(\.[0-9]+)?/  <? return floatval($_0); ?>
String = /"([^"\\]|\\.)*"/      <? return stripcslashes(substr($_0, 1, -1)); ?>
```

### CSV with Templates

```
CSV<Sep> = Row<Sep> ("\n" Row<Sep>)*
Row<Sep> = Cell (Sep Cell)*
Cell = QuotedCell | /[^,\n"]*/
QuotedCell = /"([^"]|"")*"/  <? return str_replace('""', '"', substr($_0, 1, -1)); ?>

# Usage
CommaCSV = CSV<",">
TabCSV = CSV<"\t">
```
