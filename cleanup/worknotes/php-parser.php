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
        =   expr:Expression ";" _ {{ return node('expressionstatement', [ 'expression' => $expr ]); }}
    Expression
        =   "(" _ Expression ")" _
        /   CloneExpr
        /   NewExpr
        /   ExpExpr
        /   Primary

    CloneExpr
        =   "clone" __ what:Expression {{ return node('clone', ['what' => $what]); }}

    NewExpr
        =   "new" __ what:Expression {{ return node('new', ['what' => $what]); }}

    ExpExpr
        =   left:Primary "**" _ right:Expression {{ return node('exp', ['left' => $left, 'right' => $right]); }}


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
        =   Chain
        /   Literal

    AnyIdentifier
        =   VariableName
        /   StaticIdentifier

    Identifier
        =   left:IdentifierName "\\" right:NamespacedIdentifier     {{ return $left.'\\'.$right; }}
        /   IdentifierName                                          {{ return $_0; }}

    VariableName
        =   "$" Identifier                              {{ return '$'.$_1; }}

    Identifiername
        =   /[a-zA-Z_][a-zA-Z0-9_]*/ _                  {{ return $_0; }}

    Literal =
        /   Float
        /   Int

    Float
        =   /(0|[1-9][0-9]*)(\.[0-9]+)/ _
    Int
        =   /(0|[1-9][0-9]*)(?!\.)/ _                   {{ return intval($_0); }}
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

$ast = $parser->parse(<<<'PHP'
    text here
    <?php
    clone clone $theObject;
    PHP);

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
