<?php
require('vendor/autoload.php');
$t = microtime(true);


$parser = Charm\Parser::fromString(<<<'PEG'
    Program
        =   BodyText (PhpOpenTag Code (PhpCloseTag Program)? )? $
    BodyText
        =   /(\A|(?<=\?>))((?!<)[\s\S])*/
    PhpOpenTag
        =   "<?php" __
    PhpCloseTag
        =   "?>"
    Code
        =   StatementList
    StatementList
        =   Statement*
    Statement
        =   Expression ";" _

    Expression
        =   "(" _ Expression ")" _
        =   CloneExpr
        /   NewExpr
        /   ExpExpr
        /   Primary

    CloneExpr
        =   "clone" __ what:Expression {{ return node('clone', ['what' => $what]); }}

    NewExpr
        =   "new" __ what:Expression {{ return node('new', ['what' => $what]); }}

    ExpExpr
        =   Primary "**" _ Expression


    #    Expression
    #        =   "(" _ Expression ")" _
    #        /   Unary (/<<=|>>=|\?\?=|\*\*=|===|!==|<=>|\*\*|<<|>>|<=|>=|==|!=|<>|&&|\|\||\?\?|\+=|-=|\*=|\/=|\.=|%=|&=|\|=|^=|\*|\/|%|\+|-|\.|<|>|&|^|\||=|and|xor|or/ _ Expression)?

    #    Unary
    #        =   "(" _ type:/int|float|string|object|array|bool/ _ ")" _ what:Unary
    #            {{
    #                return node('cast', ['what' => $what, 'type' => $type]);
    #            }}
    #        =   ( /clone|new|\+\+|--|\+|-|~|@|!|yield\s+from|yield|print/ | "(" _ /int|float|string|object|array|bool/ _ ")" ) _ Unary
    #        /   Primary

    Primary
        =   VariableName
        /   Float
        /   Int
        /   "(" _ Expression ")" _

    VariableName
        =   /\$[a-zA-Z_][a-zA-Z0-9_]*/ _                {{ return value($_0); }}

    Float
        =   /(0|[1-9][0-9]*)(\.[0-9]+)/ _
    Int
        =   /(0|[1-9][0-9]*)(?!\.)/ _                   {{ return value(intval($_0)); }}
    __
        =   /\s+/                                       {{ return ignore(); }}
    _
        =   /\s*/                                       {{ return ignore(); }}
    PEG, new Charm\Parsing\Options(['trace' => true, 'auto_tokenize' => true]));

$grammarTime = floor(10000 * (microtime(true) - $t)) / 10;

echo "GRAMMAR:\n";
echo $parser->getGrammar();
echo "---------------------------------\n";

$t = microtime(true);

for ($i = 0; $i < 1; $i++) {
    $ast = $parser->parse(<<<'PHP'
        text here
        <?php
        clone 1 ** clone 2
        PHP);
}

var_dump($ast);
die();
$parsingTime = floor(10000*(microtime(true) - $t)) / 10;

if ($ast === false) {
    echo "Parsing failed\n";
} else {
    print_r($ast);
}
echo "Time parsing the grammar: ".$grammarTime." ms\n";
echo "Time parsing source: ".($parsingTime / 1)." ms\n";

echo $parser->getGrammar();
