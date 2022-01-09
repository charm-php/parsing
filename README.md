Charm/Parsing
=============

A parsing library which will let you easily parse almost anything from sentences
to programming languages to a custom configuration language.

With this library you can easily make a function like these:

 - `calc("1+2^3*4");`
 - `interpret("if visitorCount > 10 return true; else return false;");`

In fact, to make a parser for the expression above, you simply have to create
the following class:

```
<?php
class MyExpressionParser extends Charm\Parser {
    const GRAMMAR = <<<'GRAMMAR

    Expr
        = Expr "^" Expr                 %assoc=right
                                        <?
                                            return $_0 ^ $_2;
                                        ?>
        | Expr ("*" | "/") Expr         <?
                                            if ($_1 == "*")
                                                return $_0 * $_2;
                                            else
                                                return $_0 / $_2;
                                        ?>
        | Expr ("+" | "-") Expr         <?
                                            if ($_1 == "+")
                                                return $_0 + $_2;
                                            else
                                                return $_0 - $_2;
                                        ?>
        | Number

    Number
        = /[1-9][0-9]*|0/               <? return intval($_0); ?>

    GRAMMAR;  
}
```

To use this expression parser:

```
<?php
$parser = new MyExpressionParser();
echo $parser->parse("1+2"); // output: 3
```



Quick Start
-----------

The easiest way to begin parsing is using the `Charm\cpeg_parse()` method. This
function is our equivalent of `preg_match_all`.

Syntax:

```
cpeg_parse(string $expression, string $subject, array $initialState=[]): ?array;
```

Example for parsing a JSON:

```
use function Charm\cpeg_parse;

$expression = '
    Value   =   Number | String | "true" | "false" | "null" | Object | Array
    Array   =   "[" (Value ("," Value)*)? "]"
    Object  =   "{" (String ":" Value ("," String ":" Value)*)? "}"
    Number  =   /(0|[1-9][0-9]*)(\.[0-9]+)?/
    String  =   /"(\\[\\"]|[^"])*"/
';

$result = cpeg_parse($expression, '[1,2,"A string",{"key":[3,4]}');

```

Example for parsing an expression:

```
use function Charm\cpeg_parse;

$expression = '
    Expr    =   Expr ("*" | "/") Expr
            |   Expr ("+" | "-") Expr
            |   Number
    Number  =   /(0|[1-9][0-9]*)(\.[0-9]+)?/
';

$result = cpeg_parse($expression, '1*2+3*4');
```


Introduction to parsing
-----------------------

There are tons of examples online for writing grammars. It is very similar to parsing
regular expressions, but with a simpler and more verbose syntax.

The syntax is called the language grammar for whatever you're parsing.

### Terminology

A lot of terminology exists within the parsing academia. It is difficult to get a full
and complete understanding of all of this. Here is an example "grammar" which we will
use to explain the most important terms when talking about grammars and parsing:

```
Statement
    =   Expression

Expression
    =   Expression "+" Expression
    |   Expression "-" Expression
    |   Number

Number
    =   /[0-9]+/
```

The above *grammar* is written in *BNF* form.

Grammar
: A grammar contains a set ot *rules* for parsing a string. For a programmer, this is
    an analog to a full program. `Statement`, `Expression` and `Number` are the names
    of the rules in the above grammar.

BNF
: Short for "Backus-Naur form" and is a generic term for grammars in this style.

Rule
: Rules are the main constituents of a grammar. For a programmer, this is like
    functions. Rules consists of a `Clause` which is similar to an expression in
    a programming language. A clause can be very simple, like in `Statement` where
    the clause is simply `Expression`. Clauses can be more advanced, like in
    `Expression` where you see the or-*operator* `|` as well as `"+"` and `"-"`.

Goal
: The first rule in the grammar is the *goal* that we're parsing for. In the above
    grammar you can see that `Statement` is the goal.

Clause
: If a rule is a function, then a clause is a *function call*. There are two types
    of clauses; built-in clauses which actually match the source code, and clauses
    which invoke a rule. The built-in clauses are called *terminal clauses*, and
    clauses which invoke other rules are called *non-terminal clauses*.

Non-Terminal clause
: A clause which simply refers to another rule.

Terminal clause
: A clause which will actually match the source string and bring the parsing
    process forward or cause it to fail. See below for a summary of all the
    terminal clauses supported in this parser.

Operator
: If you want to accept either "+" or "-", you can use the `|` operator like
    this: `"+" | "-"`. The `|` operator is the only binary operator we need
    for parsing. Also there are unary suffix operators: `?`, `+` and `*` which 
    you may recognize from regular expressions, as well as `&` and `!` unary
    prefix operators.


### Built in terminal clauses

`"str"`
: Expect the characters `str` in the source. You can use both single and double
    quotes to write this clause. Use `\"` or `\'` to escape the quote symbol.

`/regex/`
: Expect the regular expression to match with the source at the current offset.

`[a-z]`
: Match characters in the range `a` to `z`.

`[abc]`
: Match one of the characters `a`, `b` or `c`.

`^`
: Matches only at the start of the source file.

`$`
: Matches only at the end of the source file.

`.`
: Matches any single UTF-8 character. This clause will only fail at the end
    of the file.

`(` and `)`
: Parentheses can be used to group several clauses together.



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

