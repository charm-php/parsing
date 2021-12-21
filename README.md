Charm/Parsing
=============

A powerful parsing library for parsing PEG style grammars. It has functionality similar to `peg.js`, but is written from scratch
in PHP.

 * Simple to write complex parsers
 * Supports direct left recursion
 * PEG style recursive descent parsing
 * Scannarless

Example JSON parser
-------------------

```
JSON =
        Number
    |   String
    |   "null"
    |   "true"
    |   "false"
    |   Object
    |   Array

Object  = '{' _ Members? '}' _
Array   = '[' _ List? ']' _
Members = Member (',' Member)*
Member  = Key ':' _ JSON
Key     = String | Number
List    = JSON (',' JSON)*
Number  = /(0|[1-9][0-9]*)(\.[0-9]+)?/ _        {{ return floatval($_0); }}
String  = '"' ( '\\' | '\"' | !'"' . ) '"' _    {{ return implode("", $context); }}

_       = /\s*/                                 {{ return ignore(); }}
```



Example Grammar Syntax
----------------------

This basic syntax allows you to parse mathematical expressions. 

```
Expr
  = Addition

Addition
  = Multiplication ( ( "+" / "-" ) Multiplication )

Multiplication
  = Primary (("*"/"/") Primary) 

Primary
  = Number

Number
  = Float
  | Integer

Float
  = /0|[1-9][0-9]*(\.[0-9]+)/           { return floatval( $_0 ); }

Integer
  = /0|[1-9][0-9]*/                     { return intval( $_0 ); }
```

"Snippets" - inline PHP Code
----------------------------

To build a better parse tree, or to implement functionality you can
use embedded PHP code. The PHP code available is severely restricted,
but it should be enough to let you do the most important things.

Code is embedded via curly braces:

```
SumExpression = MulExpression "+" SumExpression { /* inline PHP code */ }
```

The PHP code can analyze previous matches and return values which will
become part of the final result of the sequence.

The most common use of this functionality is to finalize the sequence
and provide a custom node for the parse tree or to provide customized
parse errors.

Essential return values:

 * `return false` will be interpreted by the sequence as a normal failed
   clause.
 * `return true` will be interpreted by the sequence as a successful clause
   which does not provide a result.
 * `return fail()` will stop the sequence with a negative result.
 * `return nothing()` will stop the sequence with a non-capturing positive
   result.
 * `return done("Some value")` will stop the sequence and add "Some value"
   to the parse tree.
 * Any other return value will be added to the sequence result as if it was
   parsed by a normal terminal clause.

Trigger a parse error:

 * `error("A parse error was raised");`

