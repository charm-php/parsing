<?php
namespace Charm\Parsing\Library;

class JSONParser extends \Charm\Parser {

    const GRAMMAR = <<<'CPEG'

    JSON =
            Value

    Value =
            Number
        |   String
        |   Boolean
        |   Null
        |   Object
        |   Array

    Object =            "{" _ (String _ ":" _ Value _)* "}"
                        <? var_dump("OBJECT", $_1); die(); ?>

    Array =             "[" _ Value* _ "]"
                        <? var_dump("ARRAY", $_1); ?>

    Number =            /(0|[1-9][0-9]*)(\.[0-9]+)?/
                        <? return floatval($_0); =>

    String =            /"(\\[\\"]|[^"\\]+)*"|'(\\[\\']|[^'\\]+)*"/
                        <? $q = $_0[0]; return strtr(substr($_0, 1, -1), [ "\\".$q => $q ]); ?>

    Boolean =           /true|false/
                        <? return $_0 === 'true'; ?>

    Null =              "null"
                        <? return null; ?>


    CPEG;
}
