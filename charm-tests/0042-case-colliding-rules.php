<?php
/**
 * Rules whose names differ only in case (`Word` / `WORD`) are different rules in both engines; the
 * compiler gives them distinct PHP method names (method names are case-insensitive in PHP).
 */
require_once __DIR__ . '/support/conformance.php';

return [
    "Rules differing only in case",
    'conformance_case',

    [ ['S = Word "-" WORD
Word = /[a-z]+/
WORD = /[A-Z]+/', 'abc-XYZ'], both(['abc', '-', 'XYZ']) ],
    [ ['S = Word "-" WORD
Word = /[a-z]+/
WORD = /[A-Z]+/', 'abc-xyz'], both('PARSE_ERROR') ],
    [ ['S = Select | SELECT | select
Select = "select" Name
SELECT = "SELECT" Name
select = "sel" "!"
Name = /[a-z]+/', 'SELECTfoo'], both(['SELECT', 'foo']) ],
    [ ['S = Select | SELECT | select
Select = "select" Name
SELECT = "SELECT" Name
select = "sel" "!"
Name = /[a-z]+/', 'sel!'], both(['sel', '!']) ],
];
