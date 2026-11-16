<?php
namespace Charm\Parsing\Compiler\Portable;

/**
 * Generates a Python 3 module from a Program: the engine in runtime/parser.py.tpl plus the grammar tables.
 */
final class PythonGenerator
{
    public function generate(Program $p, string $name): string
    {
        $vars = ['@NAME@' => $name, '@GRAMMAR@' => $p->filename !== '' ? basename($p->filename) : $name, '@TABLES@' => $this->tables($p)];
        return strtr(file_get_contents(__DIR__ . '/runtime/parser.py.tpl'), $vars);
    }

    private function ints(string $name, array $a): string
    {
        $lines = array_map(fn($chunk) => '    ' . implode(', ', $chunk), array_chunk($a ?: [0], 24));
        return "$name = (\n" . implode(",\n", $lines) . ",\n)\n";
    }

    private function pystr(string $s): string
    {
        $out = '';
        foreach (mb_str_split($s, 1, 'UTF-8') as $ch) {
            $o = mb_ord($ch, 'UTF-8');
            $out .= match (true) {
                $ch === '\\' => '\\\\', $ch === "'" => "\\'",
                $o < 32 || $o === 127 => sprintf('\\x%02x', $o),
                $o > 0xFFFF => sprintf('\\U%08x', $o),
                $o > 126 => sprintf('\\u%04x', $o),
                default => $ch,
            };
        }
        return "'" . $out . "'";
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
        $out = "MAXREGS = $maxRegs\nGOAL_DESCRIPTION = {$p->goalDescription}\n";
        $out .= 'STR = (' . implode(', ', array_map([$this, 'pystr'], $p->strings)) . ",)\n";
        $out .= 'STRB = (' . implode(', ', array_map(fn($s) => 'bytes((' . implode(', ', array_map('ord', str_split($s))) . ($s === '' ? '' : ',') . '))', $p->strings)) . ",)\n";
        $out .= $this->ints('NODES', $p->nodes) . $this->ints('KIDS', $p->kids) . $this->ints('RULES', $p->rules)
            . $this->ints('PALTS', $p->palts) . $this->ints('GUARDS', $guards)
            . $this->ints('SET_BITS', array_map(fn($x) => $x & 0xFFFFFFFF, $setBits)) . $this->ints('SET_INDEX', $setIndex)
            . $this->ints('SET_RANGES', $setRanges) . $this->ints('EXPRS', $p->exprs) . $this->ints('EKIDS', $p->ekids) . $this->ints('RXCODE', $p->rxcode)
            . $this->ints('RX_START', array_map(fn($r) => $r[0], $p->regexes));
        return $out;
    }
}
