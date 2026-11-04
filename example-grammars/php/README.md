# PHP grammar (example / starting point)

This directory is an **experiment**, kept as a starting point for a PHP grammar in token mode.
It is **not part of the library API** and is not autoloaded.

**Known problem:** the generated parser (`PhpParser.php`, ~31,000 lines) recurses without
bound in `parseName()` on `<?php echo 1;` because the grammar still contains left recursion.
It will exhaust memory. `PhpPrecParser.php` is an abandoned alternative.

## Contents

- `php.y` - PHP's yacc grammar. Provenance (inferred from its `#if PHP7`/`#if PHP8` sections, not recorded in the file): nikic/PHP-Parser `grammar/php.y`,
  itself derived from php-src `Zend/zend_language_parser.y` (Zend Engine / PHP License). No licence
  header is embedded in the file; nikic/PHP-Parser's BSD-3-Clause licence is kept in
  `LICENSE-php-y.txt`, and the Zend/PHP licence notice applies to the original grammar.
- `php.cpeg` - the grammar converted to Charm CPEG (token mode, `@lexer Charm\Parsing\Lexers\PhpLexer`).
- `tools/` - the yacc to CPEG conversion pipeline (`generate-php-cpeg.php` regenerates `php.cpeg`
  from `php.y` using `../yacc.cpeg`).
- `php-parser.template.php` - template used when compiling `php.cpeg` (`charm-compile -t`).
- `PhpParser.php`, `PhpPrecParser.php` - generated parsers (namespace `Charm\Parsing\Library`).
- `token-parsing-prototype.php` - early prototype of token-mode parsing.

`Charm\Parsing\Lexers\PhpLexer` needs `nikic/php-parser` ^5 (a dev dependency).
