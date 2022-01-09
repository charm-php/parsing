<?php
require(dirname(__DIR__).'/vendor/autoload.php');

$parser = Charm\Parser::fromString(file_get_contents(__DIR__.'/grammars/charm.peg'));

var_dump($parser);
