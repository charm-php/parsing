<?php
require 'vendor/autoload.php';

use Charm\Parsing\Grammar;
use Charm\Parsing\Rule;
use Charm\Parsing\Clause\{Nothing, Start, Sequence};

$t = microtime(true);
$g = Grammar::fromString(<<<PEG
Stmt
  = DefStmt $
  / ExprStmt $

DefStmt
  = "let" __ AssignmentExpr _ ";"

ExprStmt
  = Expr _ ";"

Expr
  = AssignmentExpr

AssignmentExpr
  = Primary ("=" Expr)

Expression
  = OrExpression

OrExpression
  = AndExpression (__ OR __ AndExpression)*

AndExpression
  = AdditiveExpression (__ AND __ AdditiveExpression)*

AdditiveExpression
  = MultiplicativeExpression (("+" / "-") AdditiveExpression)?

MultiplicativeExpression
  = UnaryExpression (("*" / "/" / "%") MultiplicativeExpression)?

UnaryExpression
  = ("!" / "+" / "-" / "~")? Primary

Primary
  = Variable
  / Literal
  / _ "(" _ Expression _ ")"

FunctionCall
  = Variable _ Arguments

Arguments
  = "(" (_ Expression (_ "," Expression)*)? _ ")"

/* a value can't be assigned with values */
Value
  = Expression
  / Variable
  / Literal
  
/* a variable can be assigned a value */
Variable
  = Identifier (_ ("." _ Variable / "[" _ Value _ "]"))*
  

Literal
  = StringLiteral
  / NumberLiteral
  / BooleanLiteral
  / Null
  
StringLiteral
  = '"' ("\\" / '\"' / [^"] .)* '"'
  / "'" ("\\" / "\'" / [^'] .)* "'"

NumberLiteral
  = FloatLiteral
  / IntegerLiteral
  
FloatLiteral
  = IntegerLiteral "." [0-9]+

IntegerLiteral
  = [1-9] [0-9]*
  / [0-9]
  
BooleanLiteral
  = True
  / False

True
  = "true"

False
  = "false"
  
Null
  = "null"
  
This
  = "this"
  
Parent
  = "parent"
  
Self
  = "self"

Identifier
= [a-zA-Z] [a-zA-Z0-9]*

COMMA
  = _ "," _
AND
  = /(?ii:and)/i
OR
  = /(?ii:or)/


__
  = [ \t\n\r]+
_
  = [ \t\n\r]*
PEG);

echo "Time parsing grammar: ".(microtime(true) - $t)."\n";

echo "-----------\n";
echo $g;
echo "-----------\n";
