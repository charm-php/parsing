<?php
/**
 * The portable targets run regexes with their own matcher (see REFERENCE.md, "Portable targets"). Each row is a
 * regex and a subject; the row passes when the interpreter, the PHP compiled parser (both use PCRE) and
 * every portable target return the same tree or fail at the same place.
 */
require_once __DIR__ . '/support/conformance.php';

function regex_agree(array $args): mixed {
    [$re, $input] = $args;
    $r = conformance_position(['S = /' . $re . '/', $input]);
    return $r[0] === $r[1] ? 'agree' : $r;
}

$cases = [
    ['a+', 'aaab'], ['a*?b', 'aab'], ['a+?', 'aaa'], ['a{2,3}', 'aaaa'], ['a{2,3}?', 'aaaa'], ['a{2}', 'a'], ['a{,2}', 'a{,2}'],
    ['x{', 'x{'], ['(a|ab)(c|bcd)', 'abcd'], ['(a|ab)*c', 'abababc'], ['(?:a|b)*c', 'ababx'], ['(a*)*b', 'aab'], ['(a*)+', 'b'], ['(a?)*?b', 'ab'],
    ['[a-c]+', 'abcd'], ['[^a-c]+', 'xyza'], ['[]a]+', ']a]b'], ['[a\\-z]+', 'a-zb'], ['[a-]+', 'a-b'], ['[\\d\\s]+', "1 2\n3x"],
    ['\\w+', 'héllo wörld'], ['\\w+', 'ab_9 '], ['\\d+', 'x١٢٣'], ['\\d+', '١٢٣x'], ['\\s+', "a \u{00a0}\u{2003}\t b"], ['\\s+', " \u{00a0}\u{2003}\tb"],
    ['\\W+', "  !!é"], ['\\S+', 'ab cd'], ['\\bfoo\\b', 'foo bar'], ['\\bfoo\\b', 'foobar'], ['foo\\B', 'foobar'], ['\\bé', 'é'],
    ['.+', "ab\ncd"], ['(?s).+', "ab\ncd"], ['.', 'é'], ['.', "\n"], ['^a', 'a'], ['a$', 'a'], ['a$', "a\n"], ['a\\z', "a\n"], ['a\\Z', "a\n"], ['(?m)a$', "a\nb"],
    ['(?i)abc', 'ABC'], ['(?i:ab)c', 'ABc'], ['(?i:ab)c', 'ABC'], ['é', 'é'], ['(?i)é', 'É'], ['(?i)k', "\u{212a}"], ['(?i)s', "\u{17f}"], ['(?i)[a-z]+', 'AbCdE'],
    ['(?i)[^a-z]+', 'ABC1'], ['(?i)\\x{3c3}', "\u{3c2}"], ['\\p{Lu}+', 'ÉCOLE'], ['\\p{L}+', 'héllo1'], ['\\P{L}+', '12é'], ['\\pL+', 'abc1'], ['[[:alpha:]]+', 'abé1'], ['[[:^digit:]]+', 'ab1'],
    ['a(?=b)', 'ab'], ['a(?=b)', 'ac'], ['a(?!b)', 'ac'], ['a(?!b)', 'ab'], ['(?<=a)b', 'ab'], ['a(?<=a)b', 'ab'], ['(?<!a)b', 'cb'], ['(?<=é)b', 'éb'], ['(?<=ab|cd)e', 'cde'],
    ['(?>a+)ab', 'aaab'], ['a++ab', 'aaab'], ['a*+b', 'aab'], ['(?>a|ab)c', 'abc'], ['[^"\\\\]*+"', 'abc"'],
    ['(?:[^"\\\\]|\\\\.)*', 'ab\\"cd"e'], ['(?:\\s+|//[^\\n]*|/\\*[\\s\\S]*?\\*/)*', " // x\n /* y */ z"], ['0x[0-9a-fA-F_]+|[0-9][0-9_]*(\\.[0-9][0-9_]*)?([eE][+-]?[0-9]+)?', '12.5e3x'],
    ['\\x41\\x{263a}', "A\u{263a}"], ['\\t\\n', "\t\n"], ['\\.\\*\\/', './*/'], ['(?#comment)a', 'a'], ['(?<n>a)b', 'ab'], ['(?P<n>a)b', 'ab'], ['(a)(b)', 'ab'],
    ['a|', 'b'], ['|a', 'a'], ['(|a)b', 'ab'], ['a.*?b.*c', 'axxbyyc'], ['(a+)+b', 'aaaaaaaaaaaaaac'], ['[\\x{100}-\\x{10ffff}]+', "x\u{100}\u{1f600}"],
    ['[\\w-]+', 'a-b_c d'], ['[\\w.-]+@\\w+', 'a.b-c@x'], ['(?i)straSSe', 'STRASSE'], ['x*', 'y'], ['x+', 'y'], ['', 'y'],
];

// random regexes over a tiny alphabet, seeded: the structure is what matters (nested groups, alternation, lazy and empty loops)
mt_srand(20261116);
$atoms = ['a', 'b', 'c', '.', '[ab]', '[^a]', '\\w', '(?:a|b)', '(a|bc)', '(?:a*)', '(?:b?)', '\\b', '(?=a)', '(?!b)', 'ab'];
$quant = ['', '', '', '*', '+', '?', '*?', '+?', '??', '{1,2}', '{2}', '*+', '++'];
for ($i = 0; $i < 40; $i++) {
    $re = '';
    for ($j = mt_rand(1, 4); $j > 0; $j--) {
        $re .= $atoms[mt_rand(0, count($atoms) - 1)] . $quant[mt_rand(0, count($quant) - 1)];
        if (mt_rand(0, 6) === 0) {
            $re .= '|';
        }
    }
    $subject = '';
    for ($j = mt_rand(0, 7); $j > 0; $j--) {
        $subject .= ['a', 'b', 'c', ' ', 'é'][mt_rand(0, 4)];
    }
    $cases[] = [$re, $subject];
}

$test = ["Portable regex matcher agrees with PCRE", 'regex_agree'];
foreach ($cases as $c) {
    $test[] = [$c, 'agree'];
}
return $test;
