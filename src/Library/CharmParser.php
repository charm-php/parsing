<?php
namespace Charm\Parsing\Library;

class CharmParser extends \Charm\Parser {
    const OPTIONS = [
        'trace' => false
    ];
    const GRAMMAR = <<<'CPEG'

Charm "program body"
=   ^ TopLevelStatements $ <? return node('charm', ...$_0); ?>

TopLevelStatements
=   TopLevelStatement+ <? return $_0; ?>

TopLevelStatement
=   PackageDecl
|   ImportDecl
|   ClassDecl
|   __

StatementList
=   Statement+

Statement
=   BlockStatement

BlockStatement
=   "{" @ _ StatementList? "}" _
    <? return node('block', $_1 ?? []); ?>
|   "if" @ _ "(" _ Expression ")" _ Statement ("elseif" _ Statement)* ("else" _ Statement)?
    <? return node('if', node('test', $_2), node('statement', $_4)); ?>
|   "while" @ _ "(" _ Expression ")" _ Statement
    <? return node('while', node('test', $_2), $_4); ?>
|   "do" @ _ Statement "while" _ "(" _ Expression ")" _ ";" _
    <? return node('do', node('test', $_4), $_1); ?>
|   "for" @ _ "(" _ Expressions? ";" _ Expressions? ";" _ Expressions? _ ")" _ Statement
    <?
        return node('for', node('init', $_2), node('test', $_4), node('step', $_6), $_8);
    ?>
|   ";" _
    <? return node('empty-statement'); ?>
|   Expression ";" _
    <? return $_0; ?>

/**
 * Expressions and calls
 */
Expressions
=   Expression ("," _ Expression)* ","?

Expression
=   "(" _ ParameterListDecl? ")" _ ReturnTypeDecl "=>" _ Statement          <? return node('closure', $_1, $_3, $_5); ?>
|   "(" _ Expression ")" _                                                  <? return $_1; ?>
|   Expression "(" _ Expressions? ")" _                                     <? return node('call', $_0, $_2); ?>
|   "function" __ "(" _ ParameterListDecl? ")" _ ReturnTypeDecl Statement   <? return node('closure', $_2, $_4, $_5); ?>
|   "new" _ Expression                                                      <? return node($_0, $_1); ?>
|   ("+" | "-" | "~") _ Expression                                          <? return node('unary-prefix', $_0, $_1); ?>
|   "!" _ Expression                                                        <? return node('unary-prefix', $_0, $_1); ?>
|   ("++" | "--") Expression                                                <? return node('unary-prefix', $_0, $_1); ?>
|   Expression ("++" | "--")                                                <? return node('unary-postfix', $_1, $_0); ?>
|   Expression ("*" | "/" | "%") _ Expression                               <? return node($_1, $_0, $_2); ?>
|   Expression ("<<" | ">>") _ Expression                                   <? return node($_1, $_0, $_2); ?>
|   Expression ("<=" | "<" | ">=" | ">") _ Expression                       <? return node($_1, $_0, $_2); ?>
|   Expression ("==" | "!=") _ Expression                                   <? return node($_1, $_0, $_2); ?>
|   Expression "&" _ Expression                                             <? return node($_1, $_0, $_2); ?>
|   Expression "^" _ Expression                                             <? return node($_1, $_0, $_2); ?>
|   Expression "|" _ Expression                                             <? return node($_1, $_0, $_2); ?>
|   Expression "&&" _ Expression                                            <? return node($_1, $_0, $_2); ?>
|   Expression "||" _ Expression                                            <? return node($_1, $_0, $_2); ?>
|   Expression ("??" _ Expression)                                          <? return node($_1, $_0, $_2); ?>
|   Expression "?" _ Expression ":" _ Expression                            <? return node($_1, $_0, $_2); ?>
|   Expression "=" _ Expression                                             <? return node($_1, $_0, $_2); ?>
|   Atom

/**
 * Special declarations
 */
PackageDecl "package declaration"
=   "package" @ __ STRING _ ";" _
    <?
        return node('package-declaration', $_1);
    ?>

ImportDecl "import declaration"
=   "import" @ __ Identifier __ "from" __ STRING _ ";" _

/**
 * Class definition
 */
ClassDecl "class declaration"
=   Identifier "{" @ _ MemberDecl* "}" _ <? return node('class-declaration', $_0, $_2); ?>

MemberDecl "class member"
=   Identifier @ (MethodMemberDecl | PropertyMemberDecl)
    <?
        if ($_1[0] === 'property') {
            $result = $_1;
            $identifier = $_0[1];
            $result[1] = $identifier;

            if ($result[2] === null) {
                if ($result[3] !== null) {
                    // Primitive inference
                    switch (gettype($result[3])) {
                        case "string":
                            $result[2] = node('identifier', 'String');
                            break;
                        case "integer":
                            $result[2] = node('identifier', 'Int');
                            break;
                        case "double":
                            $result[2] = node('identifier', 'Float');
                            break;
                        case "bool":
                            $result[2] = node('identifier', 'Bool');
                            break;
                        case "object":
                            var_dump($result);die('Library/CharmParser');
                            return error(json_encode($result[3]));
                        default:
                            return error("Unrecognized type ".gettype($result[3]));
                    }
                }
            }
        } elseif ($_1[0] === 'method') {
            $result = $_1;
            $result[1] = $_0;
            return $result;
        }

        return $result;
    ?>

MethodMemberDecl "class method" =
    "(" @ _ ParameterListDecl? ")" _ ReturnTypeDecl Statement
    <?
        return node('method', null, $_1, $_3, $_4);
    ?>

PropertyMemberDecl=
    TypeDecl? ("=" _ Literal)? ";" _
    <?
        if ($_0 === null && $_1 === null) {
            return fail();
        }
        // returns a partial property member which will be modified by MemberDecl
        return node('property', null, $_0, $_1 ? $_1[1] : null);
    ?>

/**
 * Common declarations
 */
ParameterListDecl=
    ParameterDecl ("," @ _ ParameterDecl)* <? var_dump($context); die("make params node".'Library/CharmParser'); ?>
|   <? return node('params'); ?>

ParameterDecl=
    Identifier TypeDecl ("=" @ _ Literal)?

TypeDecl=
    ":" @ _ FullIdentifier <? return $_1; ?>

ReturnTypeDecl=
    TypeDecl? <? return node('return-type', $_0); ?>


/**
 * Primitives
 */
Atom=
    Literal         <? return $_0; ?>
|   FullIdentifier _ <? return $_0; ?>

Literal=
    STRING _            <? return $_0; ?>
|   FLOAT _             <? return $_0; ?>
|   INT _               <? return $_0; ?>
|   BOOL _              <? return $_0; ?>

FullIdentifier=
    Identifier _ IdentifierTail*
    <?
        $result = $_0;
        foreach ($_1 as $member) {
            $result = node('member-lookup', $result, $member);
        }
        return $result;
    ?>


IdentifierTail=
    "[" @ _ Expression "]" _ <? return $_1; ?>
|   "." @ _ Identifier <? return $_1[1]; ?>

Identifier=
    "@"? IDENT _ <? return node('identifier', ($_0 ?? "").$_1); ?>


IDENT
=   /[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*/

STRING
=   /"(\\[\\"]|[^"])*"|'(\\[\\']|[^'])*'/       <? $q = $_0[0]; return strtr(substr($_0, 1, -1), [ "\\$q" => $q ]); ?>

FLOAT
=   /(0|[1-9][0-9]*)(\.[0-9]+)/                 <? return (float) $_0; ?>

INT
=   /(0|[1-9][0-9]*)(?!\.)/                     <? return (int) $_0; ?>

BOOL
=   /true|false/                                <? return $_0 === 'true'; ?>

_
=   /\s*/                                       <? return ignore(); ?>;

__ "whitespace"
=   /\s+/                                       <? return ignore(); ?>;


CPEG;
}
