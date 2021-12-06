<?php
namespace Charm\Parsing\Terminal;

class TTYniu {

    public static function isTTY() {
        if (!function_exists('posix_isatty')) {
            return false;
        }
        return posix_isatty(STDOUT) && posix_isatty(STDERR);
    }

    /**
     * Get the number of columns for the viewport.
     */
    public static function getCols(): int
    {
        return min(75, (int) (exec('tput cols') ?? 75));
    }

    public static function bell()
    {
        if( static::isTTY() && Config::$bell ) {
            echo "\x07";
        }
    }

    protected static $mostRecentHeader = null;

    public static function debug(string $message, string $header=null)
    {
        if (Config::$debug) {
            static::stdlog(static::csiDebug().$message.static::csiReset().static::csiEraseInLine(), $header);
        }
    }

    public static function success(string $message, string $header=null)
    {
        static::stdlog(static::csiSuccess().$message.static::csiReset().static::csiEraseInLine(), $header);
    }

    public static function info(string $message, string $header=null)
    {
        static::stdlog(static::csiInfo().$message.static::csiReset().static::csiEraseInLine(), $header);
    }

    public static function notice(string $message, string $header=null)
    {
        static::stdlog(static::csiNotice().$message.static::csiReset().static::csiEraseInLine(), $header);
    }

    public static function warn(string $message, string $header=null)
    {
        static::stdlog(static::csiWarn().$message.static::csiReset().static::csiEraseInLine(), $header);
    }

    public static function error(string $message, string $header=null)
    {
        static::stdlog(static::csiError().$message.static::csiReset().static::csiEraseInLine(), $header);
    }

    public static function stdlog(string $message, string $header=null)
    {
        if ($header !== static::$mostRecentHeader) {
            static::stdout($header."\n");
        }
        static::$mostRecentHeader = $header;
        return static::stdout(static::time().' '.$message."\n");
    }

    public static function stderr(string $message)
    {
        static::$mostRecentHeader = null;
        fwrite(STDERR, $message);
    }

    public static function stdout(string $message)
    {
        fwrite(STDOUT, $message);
    }

    public static function csi($code)
    {
die("CSI ");
        if( static::isTTY() ) {
            return "\e[$code";
        }
        return "";
    }

    public static function csiDebug()
    {
        return static::csi("33m");
    }

    public static function csiSuccess()
    {
        return static::csi("32;1m");
    }

    public static function csiInfo()
    {
        return static::csi("37;1m");
    }

    public static function csiNotice()
    {
        return static::csi("37m");
    }

    public static function csiHighlight() {
        return static::csi("37;1m");
    }

    public static function csiWarn()
    {
        return static::csi("33;1m");
    }

    public static function csiError()
    {
        return static::csi("31;1m");
    }

    public static function csiReset()
    {
        return static::csi("0m");
    }

    public static function csiEraseInLine() {
        return static::csi("0K");
    }

    public static function time()
    {
        return date('Y-m-d H:i:s');
    }

    protected static $iteratorOffset = 0;
    protected static $lastIteratorTime = 0;
    public static function iterator() {
        if( !static::isTTY() ) {
            return '';
        }
        $chars = ['â”','â”“','â”ƒ','â”›','â”','â”—','â”ƒ','â”'];
        $chars = ['â ‹', 'â ™', 'â ¹', 'â ¸', 'â ¼', 'â ´', 'â ¦', 'â §', 'â ‡', 'â '];
        $chars = [
            "â ‹",
            "â ™",
            "â š",
            "â ’",
            "â ‚",
            "â ‚",
            "â ’",
            "â ²",
            "â ´",
            "â ¦",
            "â –",
            "â ’",
            "â ",
            "â ",
            "â ’",
            "â “",
            "â ‹"
        ];
        if(static::$lastIteratorTime < microtime(true)-0.05) {
            static::$iteratorOffset++;
            static::$lastIteratorTime = microtime(true);
        }
        if(static::$iteratorOffset >= sizeof($chars)) {
            static::$iteratorOffset = 0;
        }
        return $chars[static::$iteratorOffset];
    }

    public static function csiCursorLeft($num=1) {
        return static::csi($num.'D');
    }

    /**
     * returns TTY size in characters [ width, height, updated_microtime ]
     */
    public static function getTTYSize() {
        static $cache;
        if(isset($cache[2]) && $cache[2] > microtime(true)-0.5) {
            return $cache;
        }
        if(!static::isTTY()) {
            throw new Exception("Not a TTY");
        }
        preg_match_all("/rows.([0-9]+);.columns.([0-9]+);/", strtolower(exec('stty -a |grep columns')), $output);
        if(sizeof($output) == 3) {
            return $cache = [intval($output[2][0]), intval($output[1][0]), microtime(true)];
        }
        return [80, 25];
    }
}
