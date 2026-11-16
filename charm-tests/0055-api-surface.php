<?php
/**
 * Documented API behaviour not covered elsewhere: the Charm\Parser GOAL constant and parse() arguments,
 * rule descriptions in error messages (both engines), GrammarCompiler options, and the charm-compile CLI.
 */
use Charm\Parsing\Compiler\GrammarCompiler;
use Charm\Parsing\GrammarParser;

interface Test55Marker {}
class Test55Base { public function hello() { return 'hi'; } }

class Test55Parser extends \Charm\Parser {
    const GRAMMAR = 'A = "a"
B "a digit" = /[0-9]/ <? $state["n"] = ($state["n"] ?? 0) + 1; return [$_0, $state["n"]]; ?>';
    const GOAL = 'B';
}

return [
    'API surface: Parser constants, descriptions, compiler options, charm-compile',
    function (string $case) {
        $grammar = 'B "a digit" = /[0-9]/ <? return $_0 . ($state["k"] ?? "-"); ?>';
        $compile = fn(array $options, string $class) => (new GrammarCompiler())->compile((new GrammarParser())->parse($grammar, 'x'), $class, $options);
        switch ($case) {
            case 'goal-and-initial-state': // GOAL picks the rule; the 5th argument of parse() seeds $state
                return json_encode((new Test55Parser())->parse('7', 'f', 0, 0, ['n' => 5]));
            case 'description-interpreted':
                try { (new Test55Parser())->parse('x', 'f'); } catch (\RuntimeException $e) { return $e->getMessage(); }
                return 'no error';
            case 'compiled-options':
                eval('?>' . $compile(['namespace' => 'App\\T55', 'implements' => ['\\Test55Marker'], 'globals' => ['k' => 'K']], 'Opt55'));
                $p = new \App\T55\Opt55();
                try { $p->parse('x'); } catch (\RuntimeException $e) { $msg = $e->getMessage(); $class = get_class($e); }
                return [$p instanceof \Test55Marker, $p->parse('3'), $p->parse('3', ['k' => 'S']), $class, $msg];
            case 'compiled-base-class':
                eval('?>' . $compile(['baseClass' => '\\Test55Base'], 'Base55'));
                return (new \Base55())->parse('4') . (new \Base55())->hello();
            case 'cli':
                $dir = sys_get_temp_dir() . '/charm55_' . getmypid();
                mkdir($dir);
                file_put_contents("$dir/g.cpeg", 'S = /[0-9]+/ <? return (int) $_0 * 2; ?>');
                $bin = escapeshellarg(dirname(__DIR__) . '/bin/charm-compile');
                exec("php $bin $dir/g.cpeg -q -n Demo\\\\Gen -c Doubler -o $dir/out/Doubler.php 2>&1", $o, $code);
                $run = shell_exec('php -n -r ' . escapeshellarg("require '$dir/out/Doubler.php'; echo (new Demo\\Gen\\Doubler())->parse('21');"));
                exec("php $bin $dir/g.cpeg -q -c Bad-Name 2>&1", $o2, $code2);
                array_map('unlink', glob("$dir/out/*")); rmdir("$dir/out"); unlink("$dir/g.cpeg"); rmdir($dir);
                return [$code, $run, $code2 !== 0];
        }
    },
    [ 'goal-and-initial-state', '["7",6]' ],
    [ 'description-interpreted', 'Failed parsing a digit on parsed source:1:1, unexpected "x", expected a digit' ],
    [ 'compiled-options', [true, '3K', '3S', 'App\\T55\\Opt55ParseError', "Parse error at line 1, column 1: expected a digit, found 'x'"] ],
    [ 'compiled-base-class', '4-hi' ],
    [ 'cli', [0, '42', true] ],
];
