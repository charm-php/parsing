<?php
namespace Charm\Parsing\Library;

class PHPParser extends \Charm\Parser {
    const OPTIONS = [ 'trace' => false ];
    const GRAMMAR = <<<'CPEG'

    Program "PHP script" =
        ^ TextMode? ( '<?php' __ Code ( '?>' _ Program<? return node('program', $_1); ?> )? )? $

    TextMode "text section" =
        /((?!<\?php)[\s\S])+/                       <? return ['TextMode', var_export($_0, true)]; ?>

    Code "program code section" = Statement*

    Statement "statement" =
        "{" _ Statement* "}" _
    |   IfStatement
    |   DoStatement
    |   WhileStatement
    |   TryStatement
    |   EchoStatement
    |   ExpressionStatement
    |   DirectStatement
    |   ClassDecl
    |   NamespaceDecl
    |   ";" _                                       <? return ignore(); ?>

    EchoStatement =
        "echo" _ Expressions                        <? return node('Echo', $_1); ?>

    IfStatement =
        "if" _ Parentheses Statement ("elseif" _ Parentheses Statement)* ("else" _ Statement)?
        <?
            var_dump("IfStatement", $context);
            die();
            return node('If', ['test' => $_1, 'then' => $_2]);
        ?>

    DoStatement
      = "do" _ Statement "while" _ Parentheses ";" _
        <?
            return node('Do', ['statement' => $_1, 'test' => $_3]);
        ?>

    WhileStatement =
        "while" _ Parentheses Statement
        <?
            return node('While', ['test' => $_1, 'statement' => $_2]);
        ?>

    TryStatement =
        "try" _ Statement ("catch" _ "(" _ TypeDef Variable ")" _ Statement)* ("finally" _ Statement)?
        <?
            var_dump('TryStatement', $context);
            die();
        ?>

    ExpressionStatement =
        Expr ";" _
        <?
            return node("Expression", $_0);
        ?>

    DirectStatement =
        STATIC_ID __ Expressions ";"

    ClosureDecl =
        "function" _ "(" ArgumentList? ")" _ ClosureUseDecl? "{" Statements* "}" _

    ClosureUseDecl =
        "use" _ "(" "&"? _ Variable ("," _ "&"? _ Variable)* ","? _ ")" _

    ClassDecl "class declaration" =
        "abstract"? __ "class" __ STATIC_ID _ "{" _ ClassDefinitions? _ "}" _

    ClassDefinitions "class definitions" =
        ClassDefinition+

    ClassDefinition =
        ClassMemberDefinition
    |   ClassConstDefinition
    |   ClassUseDefinition

    ClassMemberDefinition =
        AbstractMethodDefinition
    |   MethodDefinition
    |   PropertyDefinition

    AbstractMethodDefinition =
        AbstractVisibilityModifier* "function" _ ArgumentList ";"

    VisibilityModifier =
        "public" _
    |   "private" _
    |   "protected" _

    AbstractVisibilityModifier =
        "abstract" _
    |   VisibilityModifier

    VariableList =
        Variable ("," _ Variable)* ","? _

    ArgumentList =
        ArgumentDef ("," _ ArgumentDef)* ","? _

    ArgumentDef =
        TypeDecl VAR_ID _ ("=" _ Literal)?

    TypeDecl =
        STATIC_ID _

    ClassConstDefinition =
        "const" __ STATIC_ID _ "=" _ StaticExpr ";"

    ClassUseDefinition =
        "use" __ NAMESPACE_ID _ ";" _

    NamespaceDecl "namespace Decl" =
        "namespace" __ NAMESPACE_ID _ ";" _

    Expressions "list of expressions" =
        Expr (_ "," _ Expr)*
        <?
            $res = [ $_0 ];
            foreach ($_1 as $e) {
               $res[] = $e[1];
            }
            return $res;
        ?>
      ;

    Expr "expression" =
        '(' _ Expr ')' _                                        <? return $_1; ?>
    |   Expr _ "(" Expressions? ")"                             <? return node('call', $_0, $_2); ?>
    |   Expr _ ('::'|'->') _ Expr                               <? return node($_1, [$_0, $_2]); ?>
    |   ('clone' | 'new') _ Expr                                <? return node($_0, $_1); ?>
    |   Expr '**' _ Expr                                        <? return node('pow', [$_0, $_1]); ?>
    |   ('+' | '-' | '++' | '--' | '~' | '(' _ NAMESPACE_ID _ ')' | '@') _ Expr
                                                                <? return node('prefix', [$_0, $_1]); ?>
    |   Expr ("++" | "--") _                                    <? return node('suffix', [$_0, $_1]); ?>
    |   'instanceof' _ Expr                                     <? return node('prefix', ['instanceof', $_1]); ?>
    |   '!' _ Expr                                              <? return node('prefix', ['not', $_1]); ?>
    |   Expr ('*' | '/' | '%') _ Expr                           <? return node($_1, [$_0, $_2]); ?>
    |   Expr ('+' | '-') _ Expr                                 <? return node($_1, [$_0, $_2]); ?>
    |   Expr ('<<' | '>>') _ Expr                               <? return node($_1, [$_0, $_2]); ?>
    |   Expr '.' _ Expr                                         <? return node($_1, [$_0, $_2]); ?>
    |   Expr ('<=' | '<' | '>=' | '>') _ Expr                   <? return node($_1, [$_0, $_2]); ?>
    |   Expr ('===' | '==' | '!==' | '!=' | '<>' | '<=>') _ Expr
                                                                <? return node($_1, [$_0, $_2]); ?>
    |   Expr '&' _ Expr                                         <? return node($_1, [$_0, $_2]); ?>
    |   Expr '^' _ Expr                                         <? return node($_1, [$_0, $_2]); ?>
    |   Expr '|' _ Expr                                         <? return node($_1, [$_0, $_2]); ?>
    |   Expr '&&' _ Expr                                        <? return node($_1, [$_0, $_2]); ?>
    |   Expr '||' _ Expr                                        <? return node($_1, [$_0, $_2]); ?>
    |   Expr ('??' _ Expr)
    |   if:Expr '?' _ then:Expr ':' _ else:Expr                 <? return node('?:', [$if, $then, $else]); ?>
    |   Expr (('=' | '+=' | '-=' | '**=' | '*=' | '/=' | '.=' | '&=' | '!=' | '^=' | '<<=' | '>>=' | '??=') _ Expr)
                                                                <? return node($_1[0], [$_0, $_1[1]]); ?>
    |   'yield' _ 'from' _ Expr                                 <? return node('yieldFrom', $_3); ?>
    |   'yield' _ Expr                                          <? return node('yield', $_2); ?>
    |   'print' _ Expr                                          <? return node('print', $_1); ?>
    |   Expr 'and' Expr                                         <? return node('and', [$_0, $_2]); ?>
    |   Expr 'xor' Expr                                         <? return node('xor', [$_0, $_2]); ?>
    |   Expr 'or' Expr                                          <? return node('or', [$_0, $_2]); ?>
    |   ClosureDecl
    |   Atom
      ;

    Parentheses
    =   "(" _ Expr ")" _                                        <? return $_1; ?>

    Atom =
        Literal
    |   VAR_ID _                                                <? return node('var', $_0); ?>
    |   NAMESPACE_ID _

    Literal =
        Number
    |   STRING
    |   NAMESPACE_ID

    Variable =
        VAR_ID _

    Number =
        FLOAT _                                     <? return $_0; ?>
    |   INTEGER _                                   <? return $_0; ?>

    VAR_ID =
        /\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*/
                                                    <? return $_0; ?>

    NAMESPACE_ID =
        /(\\?[a-zA-Z_\x7f-\xff]+[a-zA-Z0-9_\x7f-\xff]*)+/
                                                    <? return $_0; ?>

    STATIC_ID =
        /[a-zA-Z_\x7f-\xff]+[a-zA-Z0-9_\x7f-\xff]*/
                                                    <? return $_0; ?>

    FLOAT =
        /(0|[1-9][0-9]*)(\.[0-9]+)?/                <? return floatval($_0); ?>

    INTEGER =
        /(0|[1-9][0-9]*)(?!\.)/                     <? return intval($_0); ?>

    STRING =
        /"(\\|\"|[^"])*"|'(\\|\'|[^'])*'/
        <?
            $q = $_0[0];
            $body = strtr(substr($_0, 1, -1), [
                "\\\\" => "\\",
                "\\$q" => $q,
            ]);
            return $body;
        ?>

    _ =
        /\s*/                                       <? return ignore(); ?>;

    __ "whitespace" =
        /\s+/                                       <? return ignore(); ?>;

CPEG;
}
