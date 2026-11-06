<?php
/**
 * docs/tutorial-sql.md is the specification: every grammar step, every input with its AST (both
 * engines), every error message and every runnable PHP example in the tutorial is a row here, so the
 * tutorial cannot drift from the library. Also checks that example-grammars/sql.cpeg holds exactly
 * the rules the tutorial ends with, and that the compiled parser runs standalone.
 * See support/tutorial.php for the markup the tutorial uses.
 */
require_once __DIR__ . '/support/tutorial.php';

return array_merge(
    ['SQL tutorial: docs/tutorial-sql.md runs through both engines', 'tutorial_case'],
    tutorial_rows(dirname(__DIR__) . '/docs/tutorial-sql.md')['rows']
);
