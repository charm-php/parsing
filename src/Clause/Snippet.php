<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\Clause;
use Charm\Parsing\GrammarError;
use Charm\Parsing\ParseError;
use Charm\Parsing\{
    SequenceInstruction,
    SequenceIgnore,
    SequenceResume,
    Node,
    Leaf,
    InternalError,
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

    const LEGAL_TOKENS = [
        '&', '!', '[', ']', '+', '-', '*', '/', ';', '(', ')', '{', '}', '=', '<', '>', ',', ':', '.', '"', "'", '?',
        T_WHITESPACE,
        T_VARIABLE, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER,
        T_ENCAPSED_AND_WHITESPACE, 
        T_RETURN,
        T_IF, T_ELSEIF, T_ELSE,
        T_EMPTY,
        T_IS_EQUAL, T_IS_IDENTICAL, T_IS_GREATER_OR_EQUAL, T_IS_SMALLER_OR_EQUAL,
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
    ];
    const SPECIAL = [
        T_STRING => [
            'intval', 'floatval', 'boolval', 'strval',
            'true', 'false', 'null',
            'var_dump', 'print_r',
            'substr', 'str_replace', 'strtr', 'strcmp',
            'is_array', 'is_numeric',
        ],
    ];

    protected $code;
    protected $raw;
    protected $invoker;

    public function __construct(string $code, int $modifier, ?string $name) {
        parent::__construct($modifier, $name);
        $this->raw = $code;

        $variables = [];
        $tokens = token_get_all('<?php '.$code);
        array_shift($tokens);
        $code = '';
        foreach ($tokens as $token) {
            if (isset($token[1])) {
                if (method_exists($this, $fn = 'fn_'.trim($token[1]))) {
                    $code .= '$this->'.$fn;
                    continue;
                }
            }
            if (isset(self::SPECIAL[$token[0]])) {
                if (!in_array($token[1], self::SPECIAL[$token[0]])) {
                    throw new GrammarError("Illegal keyword `".trim($token[1])."` in grammar snippet.");
                }
            } elseif (!in_array($token[0], self::LEGAL_TOKENS)) {
                throw new GrammarError("Illegal token `".(is_string($token[0]) ? $token[0] : token_name($token[0]).':'.json_encode($token[1]))."` in grammar snippet.");
            }
            switch ($token[0]) {
                case T_VARIABLE:
                    $variables[] = $token[1];
                    $code .= $token[1];
                    break;
                default :
                    if (is_string($token)) {
                        $code .= $token;
                    } else {
                        $code .= $token[1];
                    }
            }
        }
        $this->code = $code;
        $this->build();
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
        $code = $this->code;
        $src =
            // this quoting style is to protect the line numbering in parse error messages
            '$this->invoker = function(array &$context, array $args=[]) {'.
            '   if (is_array($context))'.
            '       extract($context, EXTR_REFS | EXTR_OVERWRITE | EXTR_PREFIX_INVALID, "");'.
            '   '.$code.
            '};';

        try {
            set_error_handler(function($errno, $errstr, $errfile, $errline, $errcontext) use (&$oldErrorHandler) {
                echo <<<TEXT
                    ERROR: $errstr
                    ==================================================
                    Number: $errno File: $errfile Line: $errline
                    Context:
                    TEXT;
                $oldErrorHandler = null;
            }, E_ALL);
            eval($src);
            restore_error_handler();
        } catch (\ParseError $e) {
            $this->parser = null;
            $codeForError = preg_replace_callback('/\n|^/A', function() use (&$lineNum) {
                return str_pad(++$lineNum, 5, ' ', STR_PAD_LEFT).' | ';
            }, $code);
            throw new GrammarError($e->getMessage()."\nGrammar Source:\n".rtrim($codeForError)."\n", $e->getCode());
        } catch (\Throwable $e) {
            throw new GrammarError(strtr("Compile Error in snippet: '".$e->getMessage()."'", [
                '$this->fn_' => '',
            ]));
        }
    }

    /**
     * Hold a reference to the current parser for functions made available to scripts
     */
    private $parser;

    /**
     * In snippet: error("Some fatal parsing error");
     */
    private function fn_error(string $reason=null) {
        throw new ParseError(($reason ?? "General parse error")." on offset ".$this->parser->offset);
    }

    /**
     * In snippet: return resume();
     *
     * If returned, parsing of the sequence should resume as if nothing happened.
     */
    private function fn_resume() {
        return new SequenceResume();
    }

    /**
     * In snippet: return ignore();
     *
     * If returned, parsing of the sequence should stop and return true as if it was
     * a success without capturing.
     */
    private function fn_ignore() {
        return new SequenceIgnore();
    }

    private function fn_node(string $kind, array $children) {
        return new Node($kind, $children);
    }

    public function parse(ParserInterface $parser, array &$context, array $args=null) {
        $parser->traceStart($this, $parser->offset);
        $this->parser = $parser;

        try {
            $result = ($this->invoker)($context, $args ?? []);

            if ($result instanceof SequenceInstruction) {
                $parser->traceEnd($this, $result);
                return $result;
            } elseif ($result instanceof BoxedResult) {
                // A snippet may return its result boxed. This way, snippets can act as ordinary clauses in a sequence.
                $result = $result->value;
            } elseif (is_bool($result)) {
                // Prevent any side effects
                $result = new BoxedResult($result);
            }

            $this->parser = null;
            $parser->traceEnd($this, $result);
            return $result;
        } catch (ParseError $e) {
            $this->parser = null;
            throw $e;
        } catch (\Throwable $e) {
            $this->parser = null;
            throw new GrammarError(strtr("Runtime error in snippet: ".$e->getMessage(), [
                self::class.'::fn_' => '',
                static::class.'::fn_' => '',
            ]));
        }
    }

    public function asString(): string {
        return '{{'.strtr(trim($this->raw), ["\n" => "\\n"]).'}}';
    }

}
