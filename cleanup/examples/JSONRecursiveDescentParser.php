<?php

class JSONRecursiveDescentParser extends RecursiveDescentParser {

    public function __construct($options=null) {
        $this->addIgnore('WHITESPACE', '\s+');
        $this->addTerminal('NUMBER', '(0|[1-9][0-9]*+)(\.[0-9]++)?', function($match) {
            if (is_int(strpos($match->text, '.'))) {
                return (float) $match->text;
            }
            return (int) $match->text;
        });
        $this->addTerminal('IDENTIFIER', '[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+');
        $this->addTerminal('TOKEN', '[\][{}:,]', function($token) { return $token->text; });
        $this->addTerminal('STRING', <<<'RE'
            "(\\[\\"]|[^"\\]+|[^"])*+")|(?>'(\\[\\']|[^'\\]+|[^'])*+'
            RE, function($match): string {
                $q = $match->text[0];
                $match = substr($match->text, 1, -1);
                return strtr($match, [ "\\\\" => "\\", "\\$q" => $q ]);
            }
        );
    }

    protected function start() {
        return $this->_Value();
    }

    /**
     * Value := STRING | NUMBER | IDENTIFIER | Array | Object
     */
    protected function _Value() {
        if ($this->token->type === 'STRING') {
            return $this->accept('STRING');
        }
        if ($this->token->type === 'NUMBER') {
            return $this->accept('NUMBER');
        }
        if ($this->token->type === 'IDENTIFIER') {
            return $this->accept('IDENTIFIER');
        }
        if ($this->token->text === '[') {
            return $this->_Array();
        }
        if ($this->token->text === '{') {
            return $this->_Object();
        }
        throw new \Exception("Expected a value");
    }

    /**
     * Array := "[" (Value ("," Value)*)* "]"
     */
    protected function _Array() {
        if ($this->token->text === '[') {
            $this->accept('TOKEN');

            $matches = [];
            repeat:
                if ($this->token->text === ']') {
                    $this->accept('TOKEN');
                    goto done;
                }
                $matches[] = $this->_Value();
                if ($this->token->text === ',') {
                    $this->accept('TOKEN');
                    goto repeat;
                }
                if ($this->token->text === ']') {
                    $this->accept('TOKEN');
                    goto done;
                }
                goto fail;
            done:
                return $matches;
        }
        fail:
            throw new \Exception("Expected an array");
    }

    /**
     * Object := "{" Pair* "}"
     */
    protected function _OBJECT() {
        if ($this->token->text === '{') {
            $this->accept('TOKEN');

        $matches = [];
            $matches = [];
            repeat:
                if ($this->token->text === '}') {
                    $this->accept('TOKEN');
                    goto done;
                }
                $pair = $this->_Pair();
                $matches[$pair->key] = $pair->value;
                if ($this->token->text === ',') {
                    $this->accept('TOKEN');
                    goto repeat;
                }
                if ($this->token->text === '}') {
                    $this->accept('TOKEN');
                    goto done;
                }
                goto fail;
            done:
                return (object) $matches;
        }
        fail:
            throw new \Exception("Expected an array");
    }

    /**
     * Pair := STRING ":" Value
     */
    protected function _Pair() {
        $key = $this->accept('STRING');
        if ($this->accept('TOKEN') !== ':') {
            throw new Exception('Expected :');
        }
        $value = $this->_Value();

        return (object) ['key' => $key, 'value' => $value];
    }
}


