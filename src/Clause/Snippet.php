<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\Util\ExceptionTools;
use Charm\Parsing\Error\{
    CustomError,
    ParseError
};
use Charm\Parsing\{
    State,
    Clause,
    BoxedResult
};

/**
 * A special clause with code that can replace the result from this
 * expression.
 *
 * Snippets can access clauses from the current sequence through numeric variables like `$_0` for the first
 * match.
 *
 * The snippet can manipulate earlier matches, for example by doing `$_0 = intval($_0)`.
 */
class Snippet extends Terminal {

    const ERROR_EMPTY_SNIPPET = 255;
    const ERROR_ILLEGAL_KEYWORD = 1;
    const ERROR_VALIDATION = 254;
    const ERROR_BRACE_MISMATCH = 2;
    const ERROR_RUNTIME = 253;

    public function asString(): string {
        return '<? SNIPPET ?>';
        return '<? '.strtr(trim($this->originalSource), ["\n" => "\\n"]).' ?>';
    }

    /**
     * Temporarily holds $state so that it can be used from within custom functions in the
     * provided PHP code.
     */
    private $state;

    protected $originalSource;      // the raw string passed to construct the function
    protected $compiledSource;      // the rewritten code
    protected $invokerSource;       // the full function definition
    protected $invoker;             // the compiled callback function
    protected $variables;           // the variables that the code tries to access

    public function __construct(string $code, int $modifier, ?string $name) {
        parent::__construct($modifier, $name);
        assert(trim($code)!=='');
        $this->originalSource = $code;
        $this->build();
    }

    private function compile(): array {
        $code = $this->originalSource;
        $variables = [];
        $tokens = token_get_all('<?php '.$code);
        $removed = array_shift($tokens); // remove the '<?php' token
        $code = '';
        $stack = []; // for matching braces
        $stackLength = 0;
        $lineNumber = 1;
        $offset = 0;
        $l = count($tokens);
        $inQuotedString = false;
        $token = null;
        for ($i = 0; $i < $l; $i++) {
            if ($token !== null) {
                $offset += isset($token[1]) ? strlen($token[1]) : 1;
            }
            $token = $tokens[$i];
            if (isset($token[1])) {
                // this is not a built-in token
                if (method_exists($this, $fn = 'fn_'.trim($token[1]))) {
                    $code .= '$this->'.$fn;
                    continue;
                }
            }

            /**
             * Check if the code is allowed to pass through
             */
            if ($token[0] === '"') {
                // custom string parsing
                $code .= $token[0];
                $isIn = true;
                for($i++; $i < $l; $i++) {
                    $token = $tokens[$i];
                    switch($token[0]) {
                        case T_ENCAPSED_AND_WHITESPACE:
                            if (!$isIn) {
                                $code .= '."';
                                $isIn = true;
                            }
                            $code .= $token[1];
                            break;
                        case T_VARIABLE:
                            if ($isIn) {
                                $code .= '".';
                                $isIn = false;
                            }
                            $name = substr($token[1], 1);
                            if ($name[0] === '_' && is_numeric(substr($name, 1))) {
                                $name = (int) substr($name, 1);
                            }
                            $code .= '$_context['.var_export($name, true).']';
                            break;
                        case T_CURLY_OPEN:
                            assert($isIn, "Curly open while not \$isIn");
                            $code .= '".';
                            $isIn = false;
                            continue 2;
                        case '}':
                            assert(!$isIn, "Curly close while not \$isIn");
                            $code .= '."';
                            $isIn = true;
                            continue 2;
                        case '[': case ']':
                            $code .= $token[0];
                            break;
                        case T_NUM_STRING:
                            $code .= $token[1];
                            break;
                        case T_STRING:
                        case T_CONSTANT_ENCAPSED_STRING:
                            $code .= $token[1];
                            break;
                        case T_LNUMBER:
                            $code .= $token[1];
                            break;
                        case '"':
                            assert($isIn, "What happens here while validating PHP code?");
                            if ($isIn) {
                                $isIn = false;
                                $code .= $token[0];
                            } else {
//die("QUOTE WHILE NOT IN\n");
//                                echo "OUT\n";
                            }
                            continue 3;
                        default:
                            throw new ParseError($this->originalSource, 3 + $offset, "Illegal string token ".self::tokenString($token)." on :location");
                    }
                }
                assert(false, "Failed parsing quoted string");
            } elseif (isset(self::SPECIAL[$token[0]])) {
                if (!in_array($token[1], self::SPECIAL[$token[0]])) {
                    throw new ParseError($this->originalSource, 3 + $offset, "Illegal token ".self::tokenString($token)." on :location");
                }
            } elseif ($token[0] === ($stack[$stackLength-1] ?? null)) {
                if ($token[0] === '"') {
                    $inQuotedString = false;
                }
                $stackLength--;
            } elseif (isset(self::BRACES[$token[0]])) {
                if ($token[0] === '"') {
                    $inQuotedString = true;
                }
                $stack[$stackLength++] = self::BRACES[$token[0]];
            } elseif (in_array($token[0], self::BRACES)) {
                if ($stackLength > 0) {
                    throw new ParseError($this->originalSource, 3 + $offset, "Unexpected ".self::tokenString($token)." on :location, expected ".self::tokenString($stack[$stackLength-1]));
                } else {
                    throw new ParseError($this->originalSource, 3 + $offset, "Unexpected ".self::tokenString($token)." on :location");
                }
/*
            } elseif ($token[0] === '$' && isset($tokens[$i+1]) && $tokens[$i+1][0] === T_LNUMBER) {
                $tokens[$i+1][0] = T_VARIABLE;
                $tokens[$i+1][1] = '$'.$tokens[$i+1][1];
                $tokens[$i+1][2] = $offset;
                continue;
*/
            } elseif (!in_array($token[0], self::LEGAL_TOKENS)) {
                throw new ParseError($this->originalSource, 3 + $offset, "Illegal token ".self::tokenString($token)." on :location");
            }

            switch ($token[0]) {
                case T_VARIABLE:
                    $name = substr($token[1], 1);
                    if ($name[0] === '_' && is_numeric(substr($name, 1))) {
                        $name = (int) substr($name, 1);
                    }
                    $variables[] = $name;
                    $code .= '$_context['.var_export($name, true).']';
                    break;
                default :
                    if (is_string($token)) {
                        $code .= $token;
                    } else {
                        $code .= $token[1];
                    }
            }
        }
        if ($stackLength > 0) {
            throw new ParseError($this->originalSource, 3 + $offset, "Expected ".self::tokenString($stack[$stackLength-1])." on :location");
        }
        return ['code' => $code, 'variables' => $variables];
    }

    public function __serialize() {
        $res = parent::__serialize();
        unset($res['invoker']);
        return $res;
    }

    public function __unserialize($data) {
        parent::__unserialize($data);
        $this->build();
    }

    private function build() {
        if ($this->invoker !== null) {
            return;
        }
        $errorReporting = error_reporting(E_ALL);
        $compileResult = $this->compile();
        $this->compiledSource = $code = $compileResult['code'];
        $variables = $compileResult['variables'];
        $src =
            // this quoting style is to protect the line numbering in parse error messages
            '$this->invoker = function(Charm\Parsing\Clause\Snippet\Context $_context) {'.
                $code.
            "\n};";

        try {
            set_error_handler(\Closure::fromCallable([$this, 'exceptionErrorHandler']), E_ALL);
            $this->invokerSource = $src;
            eval($src);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $message = strtr($message, [
                '$this->fn_' => '',
            ]);
            throw new CustomError($message, $e->getCode(), $e);
        } finally {
            $this->state = null;
            restore_error_handler();
            error_reporting($errorReporting);
        }
    }

    /**
     * Snippet function for raising a custom parse error.
     */
    private function fn_error(string $reason=null) {
        // in case the snippet caused the parser offset to change
        $this->state->offset = $this->offset;
        throw new CustomError($reason ?? "Unknown parse error");
    }

    /**
     * Snippet function for returning a clause result. When returning a value using
     * this function, the result is processed as if it was returned by any other
     * clause.
     *
     * Without this function, the return value of a snippet becomes the return value
     * of the sequence.
     *
     * `return as_clause(true);`
     */
    private function fn_as_clause($value) {
        return new BoxedResult($value);
    }

    public function interpret(State $state, array $context, array $args=null) {
        // in case of recursion, we'll keep a reference to any previous state
        $previousState = $this->state;
        $offset = $state->offset;
        $this->state = $state;

        $errorReporting = error_reporting(E_ALL);
        set_error_handler(\Closure::fromCallable([$this, 'exceptionErrorHandler']));

        try {
            $ctx = new Snippet\Context($context + [ 'context' => $context, 'state' => &$state->globals ]);
            return ($this->invoker)($ctx);
        } finally {
            $this->state = $previousState;
            restore_error_handler();
            error_reporting($errorReporting);
        }
    }

    private function exceptionErrorHandler($severity, $message, $file, $line, $context = null) {
        if (!(error_reporting() & $severity)) {
            return;
        }
        $message = preg_replace_callback('/Undefined array key "(?<ak>[^"]*)"/', function($m) {
            return 'Undefined variable $'.$m['ak'];
        }, $message);
        $message = preg_replace_callback('/Undefined array key (?<ak>\d+)/', function($m) {
            return 'Undefined variable $_'.$m['ak'];
        }, $message);

        throw new CustomError($message, $severity);
    }

    const BRACES = [
        '(' => ')',
        '[' => ']',
        '{' => '}',
        '"' => '"',
        "'" => "'",
    ];

    const LEGAL_TOKENS = [
        '&', '!', '+', '-', '*', '/', ';', '=', '<', '>', ',', ':', '.', '?', '%', '~', '^', '|',
        T_WHITESPACE,
        T_VARIABLE, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER,
        T_ENCAPSED_AND_WHITESPACE, 
        T_RETURN,
        T_IF, T_ELSEIF, T_ELSE,
        T_EMPTY,
        T_IS_EQUAL, T_IS_IDENTICAL, T_IS_GREATER_OR_EQUAL, T_IS_SMALLER_OR_EQUAL, T_IS_NOT_IDENTICAL,
        T_COALESCE,
        T_BOOLEAN_AND, T_BOOLEAN_OR,
        T_SWITCH, T_CASE, T_BREAK, T_DEFAULT,
        T_OBJECT_CAST,
        T_DOUBLE_ARROW,
        T_FOR,
        T_FOREACH, T_AS,
        T_INC, T_DEC,
        T_ISSET,
        T_EXIT,
        T_ECHO,
        T_COMMENT,
        T_DOUBLE_CAST, T_INT_CAST,
        T_ELLIPSIS,
        T_POW, T_SL, T_SR, T_LOGICAL_AND, T_LOGICAL_XOR, T_LOGICAL_OR,
    ];
    const SPECIAL = [
        T_STRING => [
            'true', 'false', 'null',
            // string functions
            'addcslashes','addslashes','bin2hex','chop','chr','chunk_​split','convert_​uudecode','convert_​uuencode',
            'count_​chars','crc32','crypt','echo','explode','get_​html_​translation_​table','hebrev','hex2bin',
            'html_​entity_​decode','htmlentities','htmlspecialchars_​decode','htmlspecialchars','implode','join',
            'lcfirst','levenshtein','localeconv','ltrim','md5','metaphone','nl_​langinfo','nl2br','number_​format',
            'ord','parse_​str','print','printf','quoted_​printable_​decode','quoted_​printable_​encode','quotemeta',
            'rtrim','sha1','similar_​text','soundex','sprintf','sscanf','str_​contains','str_​ends_​with','str_​getcsv',
            'str_​ireplace','str_​pad','str_​repeat','str_​replace','str_​rot13','str_​shuffle','str_​split',
            'str_​starts_​with','str_​word_​count','strcasecmp','strchr','strcmp','strcoll','strcspn','strip_​tags',
            'stripcslashes','stripos','stripslashes','stristr','strlen','strnatcasecmp','strnatcmp','strncasecmp',
            'strncmp','strpbrk','strpos','strrchr','strrev','strripos','strrpos','strspn','strstr','strtok',
            'strtolower','strtoupper','strtr','substr_​compare','substr_​count','substr_​replace','substr','trim',
            'ucfirst','ucwords','vprintf','vsprintf','wordwrap',


            // bzip2 functions
            'bzcompress', 'bzdecompress',

            // LZF functions
            'lzf_compress', 'lzf_decompress', 'lzf_optimized_for',

            // zlib functions
            'deflate_add','deflate_init','gzcompress','gzdecode','gzdeflate','gzencode','gzinflate','gzuncompress','inflate_add',
            'inflate_get_read_len','inflate_get_status','inflate_init','zlib_decode','zlib_encode','zlib_get_coding_type',

            // hash functions
            'hash_algos', 'hash_copy','hash_equals','hash_final','hash_hkdf','hash_hmac_algos','hash_hmac','hash_init','hash_pbkdf2','hash_update',
            'hash',

            // password_ functions
            'password_algos','password_get_info','password_hash','password_needs_rehash','password_verify',

            // calendar functions
            'cal_days_in_month','cal_from_jd','cal_info','cal_to_jd','easter_date','easter_days','frenchtojd','gregoriantojd','jddayofweek',
            'jdmonthname','jdtofrench','jdtogregorian','jdtojewish','jdtojulian','jdtounix','jewishtojd','juliantojd','unixtojd',

            'checkdate','date_add','date_create_from_format','date_create_immutable_from_format','date_create_immutable','date_create',
            'date_date_set','date_default_timezone_get','date_diff','date_format','date_get_last_errors','date_interval_create_from_date_string',
            'date_interval_format','date_isodate_set','date_modify','date_offset_get','date_parse_from_format','date_parse','date_sub',
            'date_sun_info','date_time_set','date_timestamp_get','date_timestamp_set','date_timezone_get','date_timezone_set',

            'date','getdate','gettimeofday','gmdate','gmmktime','idate','localtime','microtime','mktime','strtotime','time',
            'timezone_abbreviations_list','timezone_identifiers_list','timezone_location_get','timezone_name_from_abbr','timezone_name_get',
            'timezone_offset_get','timezone_open','timezone_transitions_get','timezone_version_get',

            // variable handling functions
            'boolval','debug_zval_dump','doubleval','empty','floatval','get_debug_type','get_defined_vars','gettype','intval','is_array',
            'is_bool','is_callable','is_countable','is_double','is_float','is_int','is_integer','is_iterable','is_long','is_null','is_numeric',
            'is_object','is_real','is_resource','is_scalar','is_string','isset','print_r','serialize','settype','strval','unset','var_dump',
            'var_export',

            'array_change_key_case','array_chunk','array_column','array_combine','array_count_values','array_diff_assoc','array_diff_key',
            'array_diff','array_fill_keys','array_fill','array_filter','array_flip','array_intersect_assoc','array_intersect_key',
            'array_intersect','array_is_list','array_key_exists','array_key_first','array_key_last','array_keys','array_merge_recursive',
            'array_merge','array_multisort','array_pad','array_pop','array_product','array_push','array_rand','array_replace_recursive',
            'array_replace','array_reverse','array_search','array_shift','array_slice','array_splice','array_sum','array_unique','array_unshift',
            'array_values','array','arsort','asort','compact','count','current','end','extract','in_array','key_exists','key','krsort','ksort',
            'list','natcasesort','natsort','next','pos','prev','range','reset','rsort','shuffle','sizeof','sort',

            'get_class','get_mangled_object_vars','get_object_vars','get_parent_class','property_exists',

            'ctype_alnum','ctype_alpha','ctype_cntrl','ctype_digit','ctype_graph','ctype_lower','ctype_print','ctype_punct','ctype_space',
            'ctype_upper','ctype_xdigit',

            'mb_check_encoding','mb_chr','mb_convert_case','mb_convert_encoding','mb_convert_kana','mb_convert_variables','mb_decode_mimeheader',
            'mb_decode_numericentity','mb_detect_encoding','mb_detect_order','mb_encode_mimeheader','mb_encode_numericentity',
            'mb_encoding_aliases','mb_list_encodings','mb_ord','mb_output_handler','mb_parse_str','mb_preferred_mime_name','mb_scrub',
            'mb_str_split','mb_strcut','mb_strimwidth','mb_stripos','mb_stristr','mb_strlen','mb_strpos','mb_strrchr','mb_strrichr','mb_strripos',
            'mb_strrpos','mb_strstr','mb_strtolower','mb_strtoupper','mb_strwidth','mb_substitute_character','mb_substr_count','mb_substr',

            'grapheme_extract','grapheme_stripos','grapheme_stristr','grapheme_strlen','grapheme_strpos','grapheme_strripos','grapheme_strrpos',
            'grapheme_strstr','grapheme_substr',

            'idn_to_ascii','idn_to_utf8',

            'iconv_get_encoding','iconv_mime_decode_headers','iconv_mime_decode','iconv_mime_encode','iconv_strlen','iconv_strpos','iconv_strrpos',
            'iconv_substr','iconv','ob_iconv_handler',

            'bcadd','bccomp','bcdiv','bcmod','bcmul','bcpow','bcpowmod','bcscale','bcsqrt','bcsub',

            'gmp_abs','gmp_add','gmp_and','gmp_binomial','gmp_clrbit','gmp_cmp','gmp_com','gmp_div_q','gmp_div_qr','gmp_div_r','gmp_div',
            'gmp_divexact','gmp_export','gmp_fact','gmp_gcd','gmp_gcdext','gmp_hamdist','gmp_import','gmp_init','gmp_intval','gmp_invert',
            'gmp_jacobi','gmp_kronecker','gmp_lcm','gmp_legendre','gmp_mod','gmp_mul','gmp_neg','gmp_nextprime','gmp_or','gmp_perfect_power',
            'gmp_perfect_square','gmp_popcount','gmp_pow','gmp_powm','gmp_prob_prime','gmp_random_bits','gmp_random_range','gmp_root',
            'gmp_rootrem','gmp_scan0','gmp_scan1','gmp_setbit','gmp_sign','gmp_sqrt','gmp_sqrtrem','gmp_strval','gmp_sub','gmp_testbit',
            'gmp_xor',

            'abs','acos','acosh','asin','asinh','atan2','atan','atanh','base_convert','bindec','ceil','cos','cosh','decbin','dechex','decoct',
            'deg2rad','exp','expm1','fdiv','floor','fmod','getrandmax','hexdec','hypot','intdiv','is_finite','is_infinite','is_nan',
            'lcg_value','log10','log1p','log','max','min','mt_getrandmax','mt_rand','octdec','pi','pow','rad2deg','rand','round','sin','sinh',
            'sqrt','tan','tanh',

            'json_decode','json_encode','json_last_error_msg','json_last_error',

            'hrtime','pack','uniqid','unpack',

            'iterator_count','iterator_to_array','spl_object_hash','spl_object_id',

            'base64_decode','base64_encode','http_build_query','parse_url','rawurldecode','rawurlencode','urldecode','urlencode',

            'preg_filter','preg_grep','preg_last_error_msg','preg_last_error','preg_match_all','preg_match','preg_quote','preg_replace',
            'preg_split',

            'elseif',

            'sleep', 'usleep',

        ],
    ];

    private static function tokenString($token) {
        if (is_string($token)) {
            return '"'.$token.'"';
        } else {
            return "`".$token[1]."` (".token_name($token[0]).")";
        }
    }

    private static function dumpTokens(array $tokens) {
        foreach ($tokens as $i => $t) {
            echo $i."\t";
            if (is_string($t)) {
                echo "$t\n";
            } else {
                echo token_name($t[0])." `".json_encode($t[1])."` `".json_encode($t[2])."`\n";
            }
        }
    }
}

namespace Charm\Parsing\Clause\Snippet;

use Charm\Parsing\Error\CustomError;

class Context implements \ArrayAccess {

    private $data;

    public function __construct(array $data) {
        $this->data = $data;
    }

    public function &offsetGet($offset) {
        if (!key_exists($offset, $this->data)) {
            $this->throwUndefinedVariable($offset);
        }
        return $this->data[$offset];
    }

    public function offsetSet($offset, $value) {
        $this->data[$offset] = $value;
    }

    public function offsetExists($offset) {
        return key_exists($offset, $this->data);
    }

    public function offsetUnset($offset) {
        unset($this->data[$offset]);
    }

    private function throwUndefinedVariable(string $variable) {
        $vars = implode(", ", array_map(function($v) {
            if (is_numeric($v)) {
                $v = '_'.$v;
            }
            return '$'.$v;
        }, array_keys($this->data)));
        throw new CustomError("Undefined variable \$$variable. Available variables are $vars.");
    }
}
