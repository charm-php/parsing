<?php
namespace Charm\Parsing\Util;

class CSI {
    const INTENSITY_BOLD = 1;
    const INTENSITY_FAINT = 2;
    const INTENSITY_OFF = 22;

    const ITALIC_ON = 3;
    const ITALIC_OFF = 23;

    const UNDERLINE_ON = 4;
    const UNDERLINE_OFF = 24;

    const BLINK_SLOW = 5;
    const BLINK_RAPID = 6;
    const BLINK_OFF = 25;

    const BLACK = 30;
    const RED = 31;
    const GREEN = 32;
    const YELLOW = 33;
    const BLUE = 34;
    const PURPLE = 35;
    const TEAL = 36;
    const WHITE = 37;
    const GRAY = 90;
    const BRED = 91;
    const BGREEN = 92;
    const BYELLOW = 93;
    const BBLUE = 94;
    const BPURPLE = 95;
    const BTEAL = 96;
    const BWHITE = 97;

    public static function isTTY() {
return true;
        if (!function_exists('posix_isatty')) {
            return false;
        }
        return posix_isatty(STDOUT) && posix_isatty(STDERR);
    }

    public static function strip(string $str) {
        return preg_replace('/<![-_a-zA-Z0-9 ]*>/', '', $str);
    }

    public static function strlen(string $str) {
        return mb_strlen(self::strip($str));
    }

    public static function substr(string $str, int $start, int $length = 100000) {
        preg_match_all('/(?<token><![-_a-zA-Z0-9 ]*>)|(?<text>[\s\S]*?(?=<|$))/', $str, $matches, PREG_SET_ORDER);

        $res = '';
        $captured = 0;
        $skipped = 0;
        $stack = [];
        foreach ($matches as $token) {
            if ($token['token'] !== '') {
                // buffer any tokens that may not affect the result, and remove useless tokens
                $instructions = preg_split("/\s+/", trim(substr($token[0], 2, -1)));
                if ($instructions[0] === '') {
                    if ($stack === []) die("STACK MISMATCH");
                    array_pop($stack);
                } else {
                    $stack[] = $token[0];
                }

                if ($skipped < $start) {
                    // not outputting before start (stack is output when capture starts)
                } elseif ($captured >= $length) {
                    assert(false, "BUG, SHOULD NOT COME HERE");
                    // not outputting after start (stack will be emptied when capture ends)
                } else {
                    $res .= $token[0];
                }
            } else {
                $tl = mb_strlen($token[0]);

                if ($skipped + $tl < $start) {
                    // will be in skipped phase after skip
                    $skipped += $tl;
                } else {
                    if ($skipped < $start) {
                        // will begin output after skip
                        $token[0] = mb_substr($token[0], $start-$skipped);
                        $tl -= $start - $skipped;
                        $skipped = $start;
                        $res .= implode('', $stack);
                    }
                    if ($captured + $tl > $length) {
                        $res .= mb_substr($token[0], 0, $length - $captured);
                        $captured += $length - $captured;
                    } else {
                        $res .= $token[0];
                        $captured += $tl;
                    }
                    if ($captured === $length) {
                        break;
                    }
                }
            }
        }
        foreach ($stack as $null) {
            $res .= '<!>';
        }
        return $res;
    }

    public static function parse(string $str) {
        preg_match_all('/(?<token><![-_a-zA-Z0-9 ]*>)|(?<text>[\s\S]*?(?=<|$))/', $str, $matches, PREG_SET_ORDER);

        $res = '';
        $parse = [];
        $stack = [];

        $state = [
            'color' => self::WHITE,
            'intensity' => self::INTENSITY_OFF,
            'italic' => self::ITALIC_OFF,
            'underline' => self::UNDERLINE_OFF,
            'blink' => self::BLINK_OFF,
        ];

        $res .= self::c(implode(";", $state)."m");

        foreach ($matches as $match) {
            $parse[] = $match[0];
            if (!empty($match['token'])) {
                $instructions = preg_split("/\s+/", trim(substr($match[0], 2, -1)));

                if ($instructions[0] === '') {
                    if (sizeof($stack) === 0) {
                        throw new \Exception("Pop <!> mismatch");
                    }
                    $state = array_pop($stack);
                } else {
                    $stack[] = $state;
                    $currentState = $state;
                    foreach ($instructions as $instruction) {
                        switch ($instruction) {
                            case 'bold':
                                $state['intensity'] = self::INTENSITY_BOLD;
                                break;
                            case 'italic':
                                $state['italic'] = self::ITALIC_ON;
                                break;
                            case 'underline':
                                $state['underline'] = self::UNDERLINE_ON;
                                break;
                            case 'blink' :
                                $state['blink'] = self::BLINK_SLOW;
                                break;
                            case 'fastblink' :
                                $state['blink'] = self::BLINK_RAPID;
                                break;
                            case 'red':
                                $state['color'] = self::RED;
                                break;
                            case 'green':
                                $state['color'] = self::GREEN;
                                break;
                            case 'yellow':
                                $state['color'] = self::YELLOW;
                                break;
                            case 'blue':
                                $state['color'] = self::BLUE;
                                break;
                            case 'purple':
                                $state['color'] = self::PURPLE;
                                break;
                            case 'teal':
                                $state['color'] = self::TEAL;
                                break;
                            case 'white':
                                $state['color'] = self::WHITE;
                                break;
                            case 'bred':
                                $state['color'] = self::BRED;
                                break;
                            case 'bgreen':
                                $state['color'] = self::BGREEN;
                                break;
                            case 'byellow':
                                $state['color'] = self::BYELLOW;
                                break;
                            case 'bblue':
                                $state['color'] = self::BBLUE;
                                break;
                            case 'bpurple':
                                $state['color'] = self::BPURPLE;
                                break;
                            case 'bteal':
                                $state['color'] = self::BTEAL;
                                break;
                            case 'bwhite':
                                $state['color'] = self::BWHITE;
                                break;
                        }
                    }
                }
                $res .= self::c(implode(";", $state)."m");
            } else {
                $res .= $match[0];
            }
        }
        return $res;
    }


    /**
     * Get the number of columns for the viewport.
     */
    public static function getCols(): int
    {
        return min(75, (int) (exec('tput cols') ?? 75));
    }

    protected static function c($str) {
        if (self::isTTY()) {
            return "\x1B[".$str;
        }
    }

    public static function reset() {
        return "\x1B[0m";
    }

    public static function clearRight() {
        return self::c('K');
    }

    public static function clearLeft() {
        return self::c('1K');
    }

    public static function clearLine() {
        return self::c('2K');
    }

    public static function store() {
        return self::c("s");
    }

    public static function restore() {
        return self::c("u");
    }

    public static function clear() {
        return self::c("2J");
    }

    public static function insertLine($count) {
        return self::c($count."L");
    }

    public static function deleteLine($count) {
        return self::c($count."M");
    }

    public static function cursorTo($row, $col) {
        return self::c($row.";".$col."f");
    }

    public static function backgroundColor($color) {
        return self::c((40 + $color).'m');
    }

    private static $colorStack = [];
    private static $currentColor = self::WHITE;
    public static function pushColor($color) {
        self::$colorStack[] = self::$currentColor;
        self::$currentColor = $color;
        return self::color($color);
    }
    public static function popColor() {
        $previousColor = array_pop(self::$colorStack);
        if (!is_int($previousColor)) {
            throw new \Exception("No color on stack");
        }
        self::$currentColor = $previousColor;
        return self::color(self::$currentColor);
    }

    public static function color($color) {
        if($color <= 7) {
            return self::c((30 + $color).'m');
        } elseif ($color <= 15) {
            return self::c((90 + $color - 8).'m');
        } else {
            return self::c($color."m");
        }
    }
}
