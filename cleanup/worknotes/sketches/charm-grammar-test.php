<?php
require 'vendor/autoload.php';

use Charm\Parsing\Grammar;
use Charm\Parsing\Rule;
use Charm\Parsing\Clause\{Nothing, Start, Sequence};

$t = microtime(true);
$g = Grammar::fromString(<<<'PEG'
JSON <- S? ( Object / Array / String / True / False / Null / Number ) S?

Object <- "{"
             ( String ":" JSON ( "," String ":" JSON )*
             / S? )
         "}"

Array <- "["
            ( JSON ( "," JSON )*
            / S? )
        "]"

String <- S? '"' ( '\\' / '\"' / . )* '"' S?

True <- "true"

False <- "false"

Null <- "null"

Number <- Minus? IntegralPart FractionalPart? ExponentPart?

Minus <- "-"

IntegralPart <- "0" / [1-9] [0-9]*

FractionalPart <- "." [0-9]+

ExponentPart <- ( "e" / "E" ) ( "+" / "-" )? [0-9]+ . $ ^

S <- /\s/+
PEG);

echo "Time parsing grammar: ".(microtime(true) - $t)."\n";

echo $g;
