<?php
namespace Charm;

use Exception;

class Parser {

    private $rules = [];
    private $terminals = [];

    public function addRules(array $rules) {
        foreach ($rules as $ruleDef) {
            if (is_string($ruleDef[1])) {
                $this->addTerminal($ruleDef[0], $ruleDef[1], $ruleDef[2] ?? null);
            } else {
                $this->addRule($ruleDef[0], $ruleDef[1], $ruleDef[2] ?? null);
            }
        }
    }

    public function addTerminal(string $name, string $regex, callable $tokenizer=null) {
        if (array_key_exists($name, $this->terminals)) {
            throw new Exception("Terminal '$name' already exists");
        }
        if (array_key_exists($name, $this->rules)) {
            throw new Exception("A rule with the name '$name' already exists");
        }
        $this->terminals[$name] = [$regex, $tokenizer];
    }

    public function addRule(string $name, array $tokens, callable $tokenizer=null) {
        if (array_key_exists($name, $this->terminals)) {
            throw new Exception("A terminal with the name '$name' already exists");
        }
        $this->rules[$name][] = [$tokens, $tokenizer];
    }

    public function parse(string $source) {
        $parser = $this->buildParser();

        $result = [];

        foreach ($parser(function() use ($source) {
            while ($source !== '') {
                yield substr($source, 0, 1);
                $source = substr($source, 1);
            }
        }) as $token) {
            $result[] = $token;
        }
print_r($result);
        die("DONE\n");
    }

    private function buildParser() {
        $parser = $this->buildTokenizer();

        // This wrapper will invoke any processors for terminal symbols
        $parser = function(callable $source) use ($parser) {
            foreach ($parser($source) as $token) {
                if ($this->terminals[$token->kind][1] !== null) {
                    yield from ($this->terminals[$token->kind][1])($token);
                } else {
                    yield $token;
                }
            }
        };

        // Must organize productions graph
        $rules = [];
        $queue = [];
        foreach ($this->rules as $ruleName => $productions) {
            foreach ($productions as $i => $production) {
                $queue[] = [$ruleName, $production[0], $production[1] ?? null];
            }
        }
        while (null !== ($rule = array_shift($queue))) {
            $rulePasses = true;
            foreach ($rule[1] as $ti => $term) {
                if ($term === $rule[0]) {
                    if ($ti === 0) {
                        echo $rule[0]." term $term is left recursive and will be processed last\n";
                        continue;
                    } else {
                        echo $rule[0]." term $term is not left recursive and is OK\n";
                        $queue[] = $rule;
                        continue 2;
                    }
                }

                if (isset($this->terminals[$term])) {
echo $rule[0]." term $term is a terminal and is OK\n";
                    continue;
                }

                foreach ($rules as $eRule) {
                    if ($eRule[0] === $term) {
echo $rule[0]." term $term is OK\n";
                        continue 2;
                    }
                }
echo $rule[0]." not satisfiable\n";
                $queue[] = $rule;
                continue 2;
            }
echo $rule[0]." accepted\n";
            $rules[] = $rule;
        }

print_r($rules);die();

        foreach (array_reverse($this->rules, true) as $ruleName => $productions) {
            foreach ($productions as $i => $production) {
                $parser = $this->buildProduction($parser, $ruleName."[$i]", $production[0], $production[1]);
            }
        }

        return $parser;
    }

    private function buildProduction(callable $parser, string $ruleName, array $production, callable $processor=null) {
        return function(callable $source) use ($parser, $ruleName, $production, $processor) {
            $stack = [];
            $depth = 0;
            $ruleLength = count($production);
            $buffer = [];
            foreach ($parser($source) as $token) {
                process_token:
echo "$ruleName (stack=$depth/$ruleLength) PROCESSING ".$token->kind."\n";
                if ($token->kind === $production[$depth]) {
                    $stack[$depth++] = $token;
                    if ($depth === $ruleLength) {
                        echo " - $ruleName is a full match\n";
                        // the stack is a full match
                        $token = (object) [
                            'kind' => $ruleName,
                            'tokens' => $stack,
                        ];
                        $depth = 0;
                        $buffer[] = $token;
                    } else {
                        echo " - $ruleName is a partial ($depth/$ruleLength) match\n";
                    }
                } else {
                    // the stack will not match
                    if ($depth === 0) {
echo "$ruleName (stack=$depth/$ruleLength) WILL NOT MATCH ".$token->kind.", YIELDING\n";
                        // we don't have any tokens in the stack, so we forward this
                        yield $token;
                    } else {
echo "$ruleName (stack=$depth/$ruleLength) WILL NOT MATCH ".$token->kind.", YIELDING ONE FROM STACK\n";
                        // the stack does not match, so we yield one and start over
                        for ($i = 1; $i < $depth; $i++) {
echo " - RESTART WITH stack[$i]: ".$stack[$i]->kind."\n";
                            $buffer[] = $stack[$i];
                        }
                        $depth = 0;
                        yield $stack[0];
                    }
                }
                foreach ($buffer as $i => $token) {
                    echo "- reinserting from buffer $i to $ruleName\n";
                    unset($buffer[$i]);
                    goto process_token;
                }
            }
            for ($i = 0; $i < $depth; $i++) {
echo " - $ruleName can't match without any more tokens, yielding $i\n";
                yield from $stack;
            }
echo "$ruleName FINISHED (buffer=".count($buffer)." stack=$depth)\n";
        };
    }

    private function buildTokenizer() {
        $regexes = [];
        foreach ($this->terminals as $kind => $expr) {
            if (substr($expr[0], 0, 1) === substr($expr[0], -1)) {
                if ($expr[0][0] === '"' || $expr[0][0] === "'") {
                    $regexes[] = '(?<'.$kind.'>'.preg_quote(strtr(substr($expr[0], 1, -1), [ '\\'.substr($expr[0], -1) => substr($expr[0], -1)]), '/').')';
                    continue;
                } elseif ($expr[0][0] === '/') {
                    $regexes[] = '(?<'.$kind.'>'.substr($expr[0], 1, -1).')';
                    continue;
                } elseif ($expr[0][0] === '[' && substr($expr[0], -1) === ']') {
                    $regexes[] = '(?<'.$kind.'>'.substr($expr[0], 1, -1).')';
                    continue;
                }
            }
            throw new Exception("Invalid terminal '".$expr[0]."'");
        }
        $regexes[] = '(?<__FAIL__>.+?)';
        $regex = '/'.implode('|', $regexes).'/';
        return function(callable $chunkGenerator) use ($regex) {
            $offset = 0;
            $buffer = '';
            $finalChunk = false;

            $tokenizer = function($matches) use (&$buffer, &$offset) {
                $rebuffer = '';
                foreach ($matches as $index => $match) {
                    if ($rebuffer !== '') {
                        // we started buffering
                        $rebuffer .= $match[0];
                    } elseif (!empty($match['__FAIL__'])) {
                        $rebuffer = $match[0];
                        // to avoid generating tokens from partial matches, we'll start buffering
                    } else {
                        yield (object) [
                            'str' => $match[0],
                            'kind' => array_search($match[0], array_slice($match, 1, null, true), true),
                            'offset' => $offset,
                        ];
                        $offset += strlen($match[0]);
                    }
                }
            };

            foreach ($chunkGenerator() as $chunk) {
                if ($chunk === '') {
                    usleep(0);
                    continue;
                }
                $buffer .= $chunk;
                if (strlen($buffer) < 4096) {
                    // try to get more data
                    continue;
                }
                $buffer = '';
                yield from $tokenizer($matches);
                $matches = null;
            }
            if ($buffer !== '') {
                preg_match_all($regex, $buffer, $matches, PREG_SET_ORDER);
                yield from $tokenizer($matches);
            }
        };
    }

}


$parser = new Parser();

$parser->addRules([
    ['json',            [ 'object' ]],
    ['object',          [ 'CBR_OPEN', 'members', 'CBR_CLOSE' ]],
    ['object',          [ 'CBR_OPEN', 'CBR_CLOSE' ]],
    ['array',           [ 'SBR_OPEN', 'elements', 'SBR_CLOSE' ]],
    ['array',           [ 'SBR_OPEN', 'SBR_CLOSE' ]],
    ['members',         [ 'pair', 'COMMA', 'members' ]],
    ['members',         [ 'pair' ]],
    ['pair',            [ 'STRING', 'COLON', 'value' ]],
    ['elements',        [ 'value', 'COMMA', 'elements' ]],
    ['elements',        [ 'value' ]],
    ['value',           [ 'STRING' ]],
    ['value',           [ 'NUMBER' ]],
    ['value',           [ 'object' ]],
    ['value',           [ 'array' ]],
    ['STRING',          '/"(\\"|[^"])*"|\'(\\\'|[^\'])*\'/'],
    ['NUMBER',          '/(?<num>\b([1-9][0-9]*|[1-9])(\.[0-9]+)?)/'],
    ['CBR_OPEN',        '"["'],
    ['CBR_CLOSE',       '"]"'],
    ['SBR_OPEN',        '"{"'],
    ['SBR_CLOSE',       '"}"'],
    ['COMMA',           '","'],
    ['COLON',           '":"'],
]);

/*
$parser->addRules([
    ['Value',           ['Array']],
    ['Value',           ['NUMBER']],
    ['Value',           ['STRING']],
    ['Array',           ['SBR_OPEN', 'List', 'SBR_CLOSE']],
    ['List',            ['Value']],
    ['List',            ['List', ',', 'Value']],
    ['STRING',          '/"(\\"|[^"])*"|\'(\\\'|[^\'])*\'/'],
    ['NUMBER',          '/(?<num>\b([1-9][0-9]*|[1-9])(\.[0-9]+)?)/'],
    ['OP_ADD_SUB',      '/[+-]/'],
    ['OP_MUL_DIV',      '/[*\/]/'],
    ['SBR_OPEN',        '"["'],
    ['SBR_CLOSE',       '"]"'],
    ['WHITESPACE',      '/\s+/', function($token) { return []; }],
]);
*/
print_r($parser->parse('[1,2,3,4]'));

/*
$parser->addRules([
    ['Value',       ['Dict']],
    ['Value',       ['List']],
    ['Value',       ['String']],
    ['Value',       ['Number']],
    ['Value',       ['True']],
    ['Value',       ['False']],
    ['Value',       ['Null']],
    ['Dict',        ['"{"', 'Pairs', '"}"']],
    ['Pairs',       ['Pair', ',', 'Pairs']],
    ['Pairs',       ['Pair']],
    ['List',        ['"', 'ListVals', '"']],
    ['ListVals',    ['Value']],
    ['ListVals',    ['Value', '","', 'ListVals']],

    ['String',  '/"(\\"|[^"])*"/'],
    ['Number',  '/([0-9]|[1-9][0-9]*)(\.[0-9]+)?/'],
    ['True',    '/true/'],
    ['False',   '/false/'],
    ['Null',    '/null/'],
]);
*/
