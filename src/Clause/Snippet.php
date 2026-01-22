<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\Util\ExceptionTools;
use Charm\Parsing\Error\{
    CustomError,
    ParseError,
    SnippetError
};
use Charm\Parsing\{
    State,
    Clause,
    Signal
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
    protected ?string $snippetName = null;  // registry name assigned by Grammar

    public function __construct(string $code, int $modifier, ?string $name, string $namespace='') {
        parent::__construct($modifier, $name);
        $this->namespace = rtrim($namespace, "\\")."\\";
        assert(trim($code)!=='');
        $this->originalSource = $code;
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

    private function compile(): array {
        $variables = [];
        $tokens = token_get_all('<?php '.$this->originalSource);
        array_shift($tokens); // remove the '<?php' token
        $code = '';
        $localVars = []; // variables that should stay local (fn params, foreach vars, for vars)
        $parenDepth = 0;
        $inFnParams = false;
        $inForeach = false; // after 'as' in foreach

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];
            $type = is_array($token) ? $token[0] : $token;

            // Track function/fn - their parameters become local variables
            if ($type === T_FUNCTION || $type === T_FN) {
                $code .= is_array($token) ? $token[1] : $token;
                $inFnParams = 'pending'; // will become true at next '('
                continue;
            }

            // Track foreach - variables after 'as' are local
            if ($type === T_FOREACH) {
                $code .= 'foreach';
                continue;
            }
            if ($type === T_AS) {
                $code .= ' as ';
                $inForeach = true;
                continue;
            }

            // Track for loop - variables in init/condition/increment are local
            if ($type === T_FOR) {
                $code .= 'for';
                // Scan ahead to find the for(;;) and collect any assigned variables
                $forDepth = 0;
                while (++$i < $n) {
                    $t = $tokens[$i];
                    $tt = is_array($t) ? $t[0] : $t;
                    $code .= is_array($t) ? $t[1] : $t;
                    if ($tt === '(') $forDepth++;
                    elseif ($tt === ')') {
                        $forDepth--;
                        if ($forDepth === 0) break;
                    }
                    // Collect variables being assigned in for() header
                    elseif ($tt === T_VARIABLE) {
                        $localVars[substr($t[1], 1)] = true;
                    }
                }
                continue;
            }

            // Track parentheses for fn parameter detection
            if ($type === '(') {
                if ($inFnParams === 'pending') {
                    $inFnParams = true;
                    $parenDepth = 1;
                } elseif ($inFnParams === true) {
                    $parenDepth++;
                }
                $code .= '(';
                continue;
            }
            if ($type === ')') {
                if ($inFnParams === true) {
                    $parenDepth--;
                    if ($parenDepth === 0) {
                        $inFnParams = false;
                    }
                }
                $inForeach = false; // end of foreach header
                $code .= ')';
                continue;
            }

            // Collect function parameter variables
            if ($inFnParams === true && $type === T_VARIABLE) {
                $localVars[substr($token[1], 1)] = true;
                $code .= $token[1];
                continue;
            }

            // Collect foreach loop variables (after 'as')
            if ($inForeach && $type === T_VARIABLE) {
                $localVars[substr($token[1], 1)] = true;
                $code .= $token[1];
                continue;
            }

            // Transform variables: local vars stay local, everything else goes to $_context
            if ($type === T_VARIABLE) {
                $name = substr($token[1], 1);
                if (isset($localVars[$name])) {
                    $code .= $token[1]; // keep local variable as-is
                } else {
                    if ($name[0] === '_' && is_numeric(substr($name, 1))) {
                        $name = (int) substr($name, 1);
                    }
                    $variables[] = $name;
                    $code .= '$_context['.var_export($name, true).']';
                }
            } elseif (is_array($token)) {
                $code .= $token[1];
            } else {
                $code .= $token;
            }
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

    /**
     * Snippet function for raising a custom parse error.
     */
    private function fn_error(string $reason=null) {
        // in case the snippet caused the parser offset to change
        $this->state->offset = $this->offset;
        throw new CustomError($reason ?? "Unknown parse error");
    }

    public function interpret(State $state, array $context, array $args=null) {
        // in case of recursion, we'll keep a reference to any previous state
        $previousState = $this->state;
        $offset = $state->offset;
        $this->state = $state;

        $errorReporting = error_reporting(E_ALL);
        set_error_handler(\Closure::fromCallable([$this, 'exceptionErrorHandler']));

        try {
            $ctx = new Snippet\Context($context + [
                'context' => $context,
                'state' => &$state->globals,
                'HIT' => Signal::HIT,
                'MISS' => Signal::MISS,
            ]);
            return ($this->invoker)($ctx);
        } catch (\Throwable $e) {
            // Wrap the exception with snippet context for better error messages
            throw new SnippetError(
                "Error in snippet: " . $e->getMessage(),
                $this->invokerSource,
                $e
            );
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

    public function getMinByteLength(): int {
        return 0; // Snippets don't consume input
    }

    public function getFirstBytes(): ?string {
        return null; // Snippets don't consume input
    }

    /**
     * Get the registry name assigned to this snippet by Grammar.
     */
    public function getSnippetName(): ?string {
        return $this->snippetName;
    }

    /**
     * Set the registry name for this snippet (called by Grammar during prepare).
     */
    public function setSnippetName(string $name): void {
        $this->snippetName = $name;
    }

    /**
     * Get the compiled source code (for use by compiler).
     */
    public function getCompiledSource(): string {
        return $this->compiledSource;
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
        T_NEW,
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
