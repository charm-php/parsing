<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\Clause;
use Charm\Parsing\GrammarError;
use Charm\Parsing\ParseError;
use Charm\Parsing\{
    SnippetInstruction,
    SnippetFail,
    SnippetNothing,
    SnippetResult
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
        '&', '!', '[', ']', '+', '-', '*', '/', ';', '(', ')', '{', '}', '=', '<', '>', ',', ':', '.', '"', "'",
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
        T_EXIT
    ];
    const SPECIAL = [
        T_STRING => [
            'intval', 'floatval', 'boolval', 'strval',
            'true', 'false', 'null',
            'var_dump', 'print_r',
        ],
    ];

    private $code;
    private $raw;
    private $invoker;
    private $closures = [];

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
                    $this->closures['_fn_'.$token[1]] = \Closure::fromCallable([$this, $fn]);
                    $code .= '($_'.$fn.')';
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
        $src = <<<PHP
            \$this->invoker = function(&\$result) {

                if (strpos(\$this->raw, '\$_')!==false && \$result === null) {
                    echo "----------------\n".\$this->raw."\n---------------\n";
                    throw new \Exception("null passed but function expects vars");
                }
                if (is_array(\$result)) extract(\$result, EXTR_OVERWRITE | EXTR_REFS | EXTR_PREFIX_INVALID, '');extract(\$this->closures, EXTR_OVERWRITE | EXTR_REFS | EXTR_PREFIX_INVALID, '');
                $code
            };
        PHP;


        try {
            eval($src);
        } catch (ParseError $e) {
            $this->parser = null;
            throw $e;
        } catch (\Throwable $e) {
            throw new GrammarError(strtr("Compile Error in snippet: ".$e->getMessage(), [
                self::class.'::fn_' => '',
                static::class.'::fn_' => '',
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
     * In snippet: return fail();
     *
     * If returned, the entire rule should be presumed failed.
     */
    private function fn_fail() {
        return new SnippetFail();
    }

    /**
     * In snippet: return nothing();
     *
     * If returned, parsing of the rule should resume as if nothing happened.
     */
    private function fn_nothing() {
        return new SnippetNothing();
    }

    /**
     * In snippet: return done($result)
     *
     * If returned, the sequence or clause that the snippet appears in should
     * stop and return the provided result.
     */
    private function fn_done($result) {
        return new SnippetResult($result);
    }

    public function parse(ParserInterface $parser, &$parentResult) {
        $this->parser = $parser;

        try {
            $result = ($this->invoker)($parentResult);

            if ($result instanceof SnippetInstruction) {
                return $result;
            }

            if ($result === false) {
/*
                $parser->traceMiss($this, $parser->offset, "Snippet failed parsing");
*/
                $this->parser = null;
            } else {
                $parser->traceHit($this, $parser->offset, $result);
            }
            $this->parser = null;
            return $result;
        } catch (ParseError $e) {
            $this->parser = null;
            throw $e;
        } catch (\Throwable $e) {
            $this->parser = null;
            throw new GrammarError(strtr("Runtime Error in snippet: ".$e->getMessage(), [
                self::class.'::fn_' => '',
                static::class.'::fn_' => '',
            ]));
        }
    }

    public function asString(): string {
        return '{'.$this->raw.'}';
    }


    public function __debugInfo() {
        if ($this->getModifier() !== 0) {
            return [ 'modifier' => $this->getModifier(), 'code' => $this->code ];
        }
        return ['code' => $this->code];
    }
}
