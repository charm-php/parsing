<?php
namespace Charm\Parsing\Util;

/**
 * Analyzes a parsed ECMA-262 regex AST to compute:
 * - minByteLength: minimum bytes the pattern must match
 * - firstBytes: set of possible first bytes (null if any byte possible)
 */
class RegexAnalyzer
{
    /**
     * Analyze an AST node and return [minByteLength, firstBytes]
     */
    public static function analyze(array $ast): array
    {
        return [
            self::minByteLength($ast),
            self::firstBytes($ast),
        ];
    }

    /**
     * Compute minimum byte length the pattern must match
     */
    public static function minByteLength(array $ast): int
    {
        switch ($ast['type'] ?? null) {
            case 'empty':
                return 0;

            case 'literal':
                return strlen($ast['char']);

            case 'any':
                return 1; // . matches one char (at least 1 byte)

            case 'sequence':
                $total = 0;
                foreach ($ast['terms'] as $term) {
                    $total += self::minByteLength($term);
                }
                return $total;

            case 'disjunction':
                $min = PHP_INT_MAX;
                foreach ($ast['alternatives'] as $alt) {
                    $altMin = self::minByteLength($alt);
                    if ($altMin < $min) {
                        $min = $altMin;
                    }
                }
                return $min === PHP_INT_MAX ? 0 : $min;

            case 'quantified':
                $atomMin = self::minByteLength($ast['atom']);
                $qMin = $ast['quantifier']['min'];
                return $atomMin * $qMin;

            case 'charClass':
            case 'charClassEscape':
                return 1; // Character classes match exactly one char

            case 'capturingGroup':
            case 'nonCapturingGroup':
                return self::minByteLength($ast['pattern']);

            case 'anchor':
            case 'lookahead':
            case 'lookbehind':
                return 0; // Anchors and lookarounds don't consume input

            case 'backreference':
            case 'decimalEscape':
                return 0; // Can't know what backreference/decimal escape will match

            default:
                return 0; // Unknown - be conservative
        }
    }

    /**
     * Compute set of possible first bytes, or null if any byte is possible
     */
    public static function firstBytes(array $ast): ?string
    {
        switch ($ast['type'] ?? null) {
            case 'empty':
                return null; // Empty matches nothing, no first byte

            case 'literal':
                // Return first byte of the literal char
                return $ast['char'][0] ?? null;

            case 'any':
                return null; // . matches any byte

            case 'sequence':
                $bytes = '';
                foreach ($ast['terms'] as $term) {
                    $termMin = self::minByteLength($term);
                    $termBytes = self::firstBytes($term);

                    if ($termMin > 0) {
                        // Required term - include its bytes and stop
                        if ($termBytes === null) {
                            return null; // Required term with unknown first bytes
                        }
                        $bytes .= $termBytes;
                        break;
                    } else {
                        // Optional term (min=0) - include its bytes but continue
                        if ($termBytes === null) {
                            // Optional term can match anything - but it might also
                            // match empty, so continue to see what else could start
                            continue;
                        }
                        $bytes .= $termBytes;
                        // Continue to next term since this one is optional
                    }
                }
                return $bytes !== '' ? self::dedup($bytes) : null;

            case 'disjunction':
                $bytes = '';
                foreach ($ast['alternatives'] as $alt) {
                    $altBytes = self::firstBytes($alt);
                    if ($altBytes === null) {
                        return null; // One alternative can match anything
                    }
                    $bytes .= $altBytes;
                }
                return $bytes !== '' ? self::dedup($bytes) : null;

            case 'quantified':
                // Return the atom's firstBytes - the sequence logic handles optionality
                return self::firstBytes($ast['atom']);

            case 'charClass':
                if ($ast['negated']) {
                    return null; // Negated class - too many possibilities
                }
                return self::charClassBytes($ast['ranges']);

            case 'charClassEscape':
                return self::escapeBytes($ast['kind']);

            case 'capturingGroup':
            case 'nonCapturingGroup':
                return self::firstBytes($ast['pattern']);

            case 'anchor':
            case 'lookahead':
            case 'lookbehind':
                return ''; // Anchors don't consume, return empty (continue to next)

            case 'backreference':
            case 'decimalEscape':
                return null; // Can't know what backreference/decimal escape will match

            default:
                return null; // Unknown
        }
    }

    /**
     * Extract bytes from character class ranges
     */
    private static function charClassBytes(array $ranges): ?string
    {
        $bytes = '';
        foreach ($ranges as $range) {
            if ($range['type'] === 'literal') {
                $bytes .= $range['char'][0]; // First byte
            } elseif ($range['type'] === 'range') {
                $start = ord($range['start']['char']);
                $end = ord($range['end']['char']);
                if ($end - $start > 127) {
                    return null; // Range too large
                }
                for ($c = $start; $c <= $end; $c++) {
                    $bytes .= chr($c);
                }
            } elseif ($range['type'] === 'charClassEscape') {
                $escBytes = self::escapeBytes($range['kind']);
                if ($escBytes === null) {
                    return null;
                }
                $bytes .= $escBytes;
            } else {
                return null; // Unknown range type
            }
        }
        return self::dedup($bytes);
    }

    /**
     * Get bytes for character class escapes
     */
    private static function escapeBytes(string $kind): ?string
    {
        switch ($kind) {
            case 'digit':
                return '0123456789';
            case 'word':
                return 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';
            case 'whitespace':
                return " \t\n\r\f\v";
            case 'nonDigit':
            case 'nonWord':
            case 'nonWhitespace':
                return null; // Negated classes - too many possibilities
            default:
                return null;
        }
    }

    /**
     * Deduplicate and sort bytes
     */
    private static function dedup(string $bytes): string
    {
        return count_chars($bytes, 3);
    }
}
