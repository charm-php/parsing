<?php
namespace Charm\Parsing\Compiler\Portable;

/**
 * Generates a CommonJS module for Node.js from a Program: the engine in runtime/parser.cjs.tpl plus the
 * grammar tables.
 */
final class JsGenerator
{
    public function generate(Program $p, string $name): string
    {
        $vars = ['@NAME@' => $name, '@GRAMMAR@' => $p->filename !== '' ? basename($p->filename) : $name, '@TABLES@' => $this->tables($p)];
        return strtr(file_get_contents(__DIR__ . '/runtime/parser.cjs.tpl'), $vars);
    }

    private function ints(string $name, array $a, string $type = 'Int32Array'): string
    {
        $lines = array_map(fn($chunk) => '    ' . implode(', ', $chunk), array_chunk($a, 24));
        return "const $name = new $type([\n" . implode(",\n", $lines) . "\n]);\n";
    }

    private function tables(Program $p): string
    {
        $setIndex = [];
        $setRanges = [];
        $setBits = [];
        foreach ($p->sets as $ranges) {
            $setIndex[] = intdiv(count($setRanges), 2);
            $setIndex[] = count($ranges);
            $bits = [0, 0, 0, 0];
            foreach ($ranges as [$lo, $hi]) {
                $setRanges[] = $lo;
                $setRanges[] = $hi;
                for ($c = $lo; $c <= min($hi, 127); $c++) {
                    $bits[$c >> 5] |= 1 << ($c & 31);
                }
            }
            array_push($setBits, ...$bits);
        }
        $guards = [];
        foreach ($p->guards as $g) {
            foreach (str_split($g) as $ch) {
                $guards[] = ord($ch);
            }
        }
        $maxRegs = $p->regexes ? max(array_map(fn($r) => $r[1], $p->regexes)) : 0;
        $out = "const MAXREGS = $maxRegs, GOAL_DESCRIPTION = {$p->goalDescription};\n";
        $out .= 'const STR = ' . json_encode($p->strings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . ";\n";
        $out .= 'const STRB = ' . json_encode(array_map(fn($s) => array_map('ord', str_split($s)), $p->strings)) . ";\n";
        $out .= $this->ints('NODES', $p->nodes) . $this->ints('KIDS', $p->kids ?: [0]) . $this->ints('RULES', $p->rules)
            . $this->ints('PALTS', $p->palts ?: [0]) . $this->ints('GUARDS', $guards ?: [0], 'Uint8Array')
            . $this->ints('SET_BITS', array_map(fn($x) => $x & 0xFFFFFFFF, $setBits) ?: [0], 'Uint32Array')
            . $this->ints('SET_INDEX', $setIndex ?: [0]) . $this->ints('SET_RANGES', $setRanges ?: [0])
            . $this->ints('EXPRS', $p->exprs ?: [0]) . $this->ints('EKIDS', $p->ekids ?: [0])
            . $this->ints('RXCODE', $p->rxcode ?: [0]) . $this->ints('RX_START', array_map(fn($r) => $r[0], $p->regexes) ?: [0]);
        return $out;
    }
}
