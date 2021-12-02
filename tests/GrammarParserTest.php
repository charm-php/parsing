<?php

class GrammarParserTest extends PHPUnit\Framework\TestCase {

    public function test_c_grammar() {
        $grammar = file_get_contents(__DIR__ . '/grammars/c.peg');
        $parser = Charm\Parser::fromString($grammar);
        $ast = $parser->parse(<<<'C'
            int main() {

            }
            C);

        echo $ast->dump();
    }

}
