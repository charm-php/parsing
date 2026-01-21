<?php return [
    "Charm Language Parser",
    function($input) {
        return Charm\cpeg_parse(<<<'GRAMMAR'
            Charm "charm program"
            =   ^ _ StatementList? $                        <? return $_0; ?>

            StatementList
            =   Statement+                                  <? return [ 'statements', $_0 ]; ?>

            Statement
            =   "{" _ StatementList? "}" _                  <? return [ 'block', $_1 ]; ?>
            |   IfStatement
            |   WhileStatement
            |   DoStatement
            |   ForStatement
            |   ExprStatement
            |   ";" _                                       <? return true; ?>

            IfStatement "if statement"
            =   "if" _ "(" _ test:Expr ")" _ thenStmt:Statement ("else" _ elseStmt:Statement)
                <? return [ 'if', 'test' => $test, 'then' => $thenStmt, 'else' => $_5['elseStmt'] ]; ?>

            WhileStatement "while statement"
            =   "while" _ "(" _ test:Expr ")" _ stmt:Statement
                <? return [ 'while', 'test' => $test, 'statement' => $stmt ]; ?>

            DoStatement "do statement"
            =   "do" _ stmt:Statement "while" _ "(" _ test:Expr ")" _ ";"
                <? return [ 'do', 'test' => $test, 'statement' => $stmt ]; ?>

            ForStatement "for statement"
            =   "for" _ "(" _ init:Expr ";" _ test:Expr ";" _ step:Expr ")" _ stmt:Statement
                <? return [ 'for', 'init' => $init, 'test' => $test, 'step' => $step, 'statement' => $stmt ]; ?>

            ExprStatement "expression statement"
            =   expr:Expr ";" _
                <? return [ 'expression', 'expression' => $expr ]; ?>

            Expr "expression"
            =   Expr "**" _ Expr                                                        %assoc=right        <? return [$_1, $_0, $_2]; ?>
            |   ("++" | "--" | [-+~@]) _ Expr                                                               <? return ['prefix', $_0, $_1]; ?>
            |   Expr ("++" | "--") _                                                                        <? return ['suffix', $_1, $_0]; ?>
            |   Expr "instanceof" _ Expr                                                                    <? return [$_1, $_0, $_2]; ?>
            |   "!" _ Expr                                                                                  <? return ['prefix', $_0, $_1]; ?>
            |   Expr [*/%] _ Expr                                                                           <? return [$_1, $_0, $_2]; ?>
            |   Expr [-+] _ Expr                                                                            <? return [$_1, $_0, $_2]; ?>
            |   Expr /<<|>>/ _ Expr                                                                         <? return [$_1, $_0, $_2]; ?>
            |   Expr /<=|>=|<|>/ _ Expr                                                 %assoc=none         <? return [$_1, $_0, $_2]; ?>
            |   Expr /===|==|!==|!=|<>|<=>/ _ Expr                                      %assoc=none         <? return [$_1, $_0, $_2]; ?>
            |   Expr "&" _ Expr                                                                             <? return [$_1, $_0, $_2]; ?>
            |   Expr "^" _ Expr                                                                             <? return [$_1, $_0, $_2]; ?>
            |   Expr "|" _ Expr                                                                             <? return [$_1, $_0, $_2]; ?>
            |   Expr "&&" _ Expr                                                                            <? return [$_1, $_0, $_2]; ?>
            |   Expr "||" _ Expr                                                                            <? return [$_1, $_0, $_2]; ?>
            |   Expr "??" _ Expr                                                        %assoc=right        <? return [$_1, $_0, $_2]; ?>
            |   Expr "?" _ Expr ":" _ Expr                                              %assoc=none         <? return ['?:', $_0, $_2, $_4]; ?>
            |   Expr /=|\+=|-=|\*\*=|\*=|\/=|\.=|%=|&=|\|=|\^=|<<=|>>=|\?\?=/ _ Expr    %assoc=right        <? return [$_1, $_0, $_2]; ?>
            |   "yield" _ Expr                                                                              <? return ['yield', $_1]; ?>
            |   "print" _ Expr                                                                              <? return ['print', $_1]; ?>
            |   Expr "and" _ Expr                                                                           <? return [$_1, $_0, $_2]; ?>
            |   Expr "xor" _ Expr                                                                           <? return [$_1, $_0, $_2]; ?>
            |   Expr "or" _ Expr                                                                            <? return [$_1, $_0, $_2]; ?>
            |   Primary

            Primary
            =   (FLOAT / INTEGER) _                                                                         <? return $_0; ?>

            FLOAT
            =   /(0|[1-9][0-9]*)(\.[0-9]+)/                                                                 <? return floatval($_0); ?>

            INTEGER
            =   /(0|[1-9][0-9]*)/                                                                           <? return intval($_0); ?>

            CHAR
            =   /'[^']*'/                                                                                   <? return $_0; ?>

            _
            =   /\s*/                                                                                       %silent

            __
            =   /\s+/                                                                                       %silent

            GRAMMAR,

            $input,
        );
    },
    [
        "while(1) 2+2;",
        [0=>'statements',1=>[0=>[0=>'while','test'=>1,'statement'=>[0=>'expression','expression'=>[0=>'+',1=>2,2=>2,],],],],]
    ],
    [
        "while(1) { 2+2; }",
        [0=>'statements',1=>[0=>[0=>'while','test'=>1,'statement'=>[0=>'block',1=>[0=>'statements',1=>[0=>[0=>'expression','expression'=>[0=>'+',1=>2,2=>2,],],],],],],],]
    ],
];
