# Changelog

## 0.1.1

- Compiled parsers return `null` again when the goal rule matches with no value, as `cpeg_parse()`
  and 0.0.7 did; 0.1.0 returned `true` from compiled parsers only. Regression test 0021.

## 0.1.0

- Grammar compiler (`charm-compile`, `Charm\Parsing\Compiler\GrammarCompiler`) producing standalone
  PHP parsers, with commit semantics, a rule failure cache and optimizers (single-variance point,
  consecutive terminals), plus a snippet registry in `Grammar`.
- Token mode: `@lexer` directive, `Token` (`T_NAME`) and `Literal` (`"+"`) clauses, `@prec` precedence
  tables, `%prec` / `%silent` pragmas, `parser-token.template.php`, `-t/--template` option,
  `TokensInterface`, `LexerTokens`, `PhpLexer`/`PhpTokens` (nikic/php-parser ^5, suggested).
- `State` now holds `tokens` (`array<int|string>`) and `tokenData` (`TokensInterface`);
  `setStateString()` / `setStateTokens()`.
- Compiled parsers return `true` (was `null`) when the goal rule matches with no value; snippet
  methods receive the rule start offset as a leading `$_offset` parameter.
- Licence: Bison-style output exception (see LICENCE.md); generated parsers carry a header line.
- Tests: `composer test` / `php run-tests.php` prints a summary and exits non-zero on failure;
  removed the unused phpunit.xml.
- Examples: unfinished PHP grammar and its yacc conversion tools in `example-grammars/php/`
  (not library API; the generated parser currently recurses without bound in `parseName()`).
