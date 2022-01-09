<?php
namespace Charm\Parsing;

use Charm\Parsing\Error\ParseError;

/**
 * A very fast string tokenizer based on regular expressions.
 */
class Lexer implements LexerInterface{

    private array $patterns;

    /**
     * @param array<LexerPattern> $patterns
     */
    public function __construct(array $patterns) {
        $this->patterns = $patterns;
    }

    /**
     * Tokenize a string
     */
    public function tokenize(string $source, string $filename, int $lineOffset=0, int $columnOffset=0): array {
        $reParts = [];
        foreach ($this->patterns as $pattern) {
            $reParts[] = '(?<'.preg_quote('type'.str_replace('-', '_', (string) $pattern->type), '/').'>(?>'.$pattern->regex.'))';
        }
        // a final pattern which are not captured by any other patterns
        $reParts[] = '(?<__UNKNOWN__>[\s\S])';
        $re = '/'.implode('|', $reParts).'/ux';
        $count = preg_match_all($re, $source, $matches, PREG_PATTERN_ORDER|PREG_UNMATCHED_AS_NULL);

        if (!is_int($count)) {
            if (function_exists('preg_last_error_msg')) {
                $message = preg_last_error_msg();
            } else {
                $message = preg_last_error();
            }
            throw new ParseError($re, 0, "LexerPattern error: ".$message);
        }

        // calculate offsets
        $offsets = [];
        $length = 0;
        foreach ($matches[0] as $s) {
            $offsets[] = $length;
            $length += strlen($s);
        }

        // check for unknown matches
        foreach ($matches['__UNKNOWN__'] as $offset => $text) {
            if ($text !== null) {
                throw new ParseError($source, $offsets[$offset], "Lexing error: Unrecognized `".substr(json_encode($text), 1, -1)."` at :location");
            }
        }

        // Create token objects for all patterns we'll be keeping
        foreach ($this->patterns as $pattern) {
            if ($pattern->type < 0) {
                // skip patterns that are going to be removed
                continue;
            }
            $typeString = 'type'.str_replace('-', '_', (string) $pattern->type);
            // Remove patterns with a negative type number. The removed length is
            // added to the previous token.
            foreach (array_filter($matches[$typeString]) as $offset => $text) {
                $matches[0][$offset] = new LexerToken($pattern, $text, $offsets[$offset]);
            }
        }

        // Add length the tokens of all patterns we'll be removing to the preceding token
        foreach ($this->patterns as $pattern) {
            if ($pattern->type >= 0) {
                // processed above
                continue;
            }
            $typeString = 'type'.str_replace('-', '_', (string) $pattern->type);
            // Remove patterns with a negative type number. The removed length is
            // added to the previous token.
            foreach (array_filter($matches[$typeString]) as $offset => $text) {
                // find previous token
                $previousToken = null;
                for ($prev = $offset-1; $prev >= 0; $prev--) {
                    if (isset($matches[0][$prev]) && $matches[0][$prev] instanceof LexerToken) {
                        $matches[0][$prev]->length += strlen($text);
                        break;
                    }
                }
                unset($matches[0][$offset]);
            }
        }

        return array_values($matches[0]);
//        return array_values(array_filter($matches[0]));
    }


}
