<?php
require('vendor/autoload.php');
$t = microtime(true);


$parser = Charm\Parser::fromString(<<<'PEG'
    Program
        =   BodyText (PhpOpenTag Code (PhpCloseTag Program)? )? $
    BodyText
        =   /((?!<\?php)[\s\S])*/
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
        =   AddExpr
    AddExpr
        =   MulExpr (("+" / "-") _ MulExpr)* {{
            if (!isset($_1)) {
                return done($_0);
            }
            $node = $_0;
            foreach ($_1 as $op) {
                $node = ['operator' => $op[0], 'left' => $node, 'right' => $op[1]];
            }
            return done($node);
        }}
    MulExpr
        =   UnaryExpr (("*" / "/" / "%") _ UnaryExpr)* {{
            if (!isset($_1)) {
                return done($_0);
            }
            $node = $_0;
            foreach ($_1 as $op) {
                $node = ['operator' => $op[0], 'left' => $node, 'right' => $op[1]];
            }
            return done($node);
        }}
    UnaryExpr
        =   (
                "+" / "-" /
                "++" / "--" /
                "~" /
                "(" _ ( "int" / "float" / "string" / "array" / "object" / "bool" ) _ ")" _ /
                "@"
            ) _ PowExpr {{
                var_dump($_0, $_1);
                die();
            }}
        /   PowExpr
    PowExpr
        =   CloneNewExpression ("**" _ CloneNewExpression)* {{
            if (!isset($_1)) {
                return done($_0);
            }
            $root = null;
            foreach ($_1 as $op) {
                if ($root === null) {
                    $root = ['operator' => $op[0], 'left' => $_0, 'right' => $op[1]];
                    $node = &$root;
                } else {
                    $node['right'] = [ 'operator' => $op[0], 'left' => $node['right'], 'right' => $op[1] ];
                    $node = &$node['right'];
                }
            }
            return done((object) $root);
        }}
    CloneNewExpression
        =   ("clone" / "new") __ Primary {{ return done(['operator' => $_0, 'operand' => $_1]); }}
        /   Primary
    Primary
        =   "(" _ Expression ")" _
        /   VariableName
        /   Float
        /   Int
    VariableName
        =   /\$[a-zA-Z_][a-zA-Z0-9_]*/ _  {{ return done($_0); }}

    Float
        =   /(0|[1-9][0-9]*)(\.[0-9]+)/ _
    Int
        =   /(0|[1-9][0-9]*)(?!\.)/ _ {{ return done(intval($_0)); }}
    __
        =   /\s+/ {{ return nothing(); }}
    _
        =   /\s*/ {{ return nothing(); }}
    PEG, new Charm\Parsing\Options(['trace' => true]));

$grammarTime = floor(10000 * (microtime(true) - $t)) / 10;


echo "GRAMMAR:\n";
echo $parser->getGrammar();
echo "---------------------------------\n";

$t = microtime(true);

for ($i = 0; $i < 1; $i++) {
    $ast = $parser->parse(<<<'PHP'
        text here
        <?php
        $a ** (object) new $b ** $c ** $d + 123 ** 2 * 4;
        PHP);
}
$parsingTime = floor(10000*(microtime(true) - $t)) / 10;

if ($ast === false) {
    echo "Parsing failed\n";
} else {
    print_r($ast);
}
echo "Time parsing the grammar: ".$grammarTime." ms\n";
echo "Time parsing source: ".($parsingTime / 1)." ms\n";

