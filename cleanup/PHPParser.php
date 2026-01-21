<?php
namespace Charm\Parsing\Library;

class PHPParser extends \Charm\Parser {
    const OPTIONS = [ 'trace' => false ];
    const GRAMMAR = <<<'CPEG'

    Program "PHP script" =
        ^ TextMode? ( '<?php' __ Code ( '?>' _ Program)? )? $

    TextMode =
        /((?!<\?php)[\s\S])+/

    Code "PHP script" = Statement*

    Statement "statement" =
        "{" _ Statement* "}" _
    |   IfStatement
    |   DoStatement
    |   WhileStatement
    |   TryStatement
    |   EchoStatement
    |   ClassDecl
    |   NamespaceDecl
    |   ExpressionStatement
    |   DirectStatement
    |   ";" _                                       <? return true; ?>

    EchoStatement =
        "echo" _ Expressions

    IfStatement =
        "if" _ @ Parentheses Statement ("elseif" _ Parentheses Statement)* ("else" _ Statement)?

    DoStatement
      = "do" _ Statement "while" _ Parentheses ";" _

    WhileStatement =
        "while" _ Parentheses Statement

    TryStatement =
        "try" _ Statement ("catch" _ "(" _ TypeDef Variable ")" _ Statement)* ("finally" _ Statement)?
        <?
            var_dump('TryStatement', $context);
            die();
        ?>

    ExpressionStatement =
        Expr ";" _

    DirectStatement =
        STATIC_ID __ Expressions ";"

    ClosureDecl =
        "function" _ "(" ArgumentList? ")" _ ClosureUseDecl? "{" _ Statement* "}" _

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
        "namespace" __
        NAMESPACE_ID _ ";" _

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
        '(' _ Expr ')' _                                        
    |   Expr _ "(" Expressions? ")"                             
    |   Expr _ ('::'|'->') _ Expr                               
    |   ('clone' | 'new') _ Expr                                
    |   Expr '**' _ Expr                                        
    |   ('+' | '-' | '++' | '--' | '~' | '(' _ NAMESPACE_ID _ ')' | '@') _ Expr
                                                                
    |   Expr ("++" | "--") _                                    
    |   'instanceof' _ Expr                                     
    |   '!' _ Expr                                              
    |   Expr ('*' | '/' | '%') _ Expr                           
    |   Expr ('+' | '-') _ Expr                                 
    |   Expr ('<<' | '>>') _ Expr                               
    |   Expr '.' _ Expr                                         
    |   Expr ('<=' | '<' | '>=' | '>') _ Expr                   
    |   Expr ('===' | '==' | '!==' | '!=' | '<>' | '<=>') _ Expr
    |   Expr '&' _ Expr                                         
    |   Expr '^' _ Expr                                         
    |   Expr '|' _ Expr                                         
    |   Expr '&&' _ Expr                                        
    |   Expr '||' _ Expr                                        
    |   Expr ('??' _ Expr)
    |   if:Expr '?' _ then:Expr ':' _ else:Expr                 
    |   Expr (('=' | '+=' | '-=' | '**=' | '*=' | '/=' | '.=' | '&=' | '!=' | '^=' | '<<=' | '>>=' | '??=') _ Expr)
    |   'yield' _ 'from' _ Expr
    |   'yield' _ Expr
    |   'print' _ Expr
    |   Expr 'and' _ Expr
    |   Expr 'xor' _ Expr
    |   Expr 'or' _ Expr
    |   ClosureDecl
    |   Atom
    ;

    Parentheses
    =   "(" _ Expr ")" _                                        <? return $_1; ?>

    Atom =
        Literal
    |   VAR_ID _                                                
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
        /(\s|\/\/[^\n]*)*/                                       <? return true; ?>

    __ "whitespace" =
        /(\s|\/\/[^\n]*)+/                                       <? return true; ?>

CPEG;
}
