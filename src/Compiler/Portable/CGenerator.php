<?php
namespace Charm\Parsing\Compiler\Portable;

/**
 * Generates a C99 parser (a .c and a .h file) from a Program: the engine in runtime/parser.c.tpl plus the
 * grammar tables.
 */
final class CGenerator
{
    /**
     * @param string $name C identifier prefix: types are `{name}_value`, functions `{name}_parse`, ...
     * @return array{c: string, h: string}
     */
    public function generate(Program $p, string $name, string $headerFile): array
    {
        $rt = __DIR__ . '/runtime/';
        $vars = [
            '@NAME@' => $name,
            '@GRAMMAR@' => $p->filename !== '' ? basename($p->filename) : $name,
            '@HEADER@' => $headerFile,
            '@n@' => $name,
            '@N@' => strtoupper($name),
        ];
        $c = strtr(file_get_contents($rt . 'parser.c.tpl'), $vars + ['@TABLES@' => $this->tables($p)]);
        $h = strtr(file_get_contents($rt . 'parser.h.tpl'), $vars);
        return ['c' => $c, 'h' => $h];
    }

    private function ints(string $decl, array $a): string
    {
        while (count($a) < 12) { // never a tiny array: the engine indexes tables only on paths that need them, but compilers cannot know
            $a[] = 0;
        }
        $lines = array_map(fn($chunk) => '    ' . implode(', ', $chunk), array_chunk($a, 16));
        return "static const $decl = {\n" . implode(",\n", $lines) . "\n};\n";
    }

    private function tables(Program $p): string
    {
        $str = '';
        $off = [0];
        $bytes = [];
        foreach ($p->strings as $s) {
            foreach (str_split($s . "\0") as $ch) {
                $bytes[] = ord($ch) > 127 ? ord($ch) - 256 : ord($ch);
            }
            $off[] = count($bytes);
        }
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
        $rxStart = array_map(fn($r) => $r[0], $p->regexes);
        $maxRegs = $p->regexes ? max(array_map(fn($r) => $r[1], $p->regexes)) : 0;

        $out = "#define MAXREGS $maxRegs\n#define GOAL_DESCRIPTION {$p->goalDescription}\n";
        $out .= $this->ints('char STR[]', $bytes);
        $out .= $this->ints('int32_t STR_OFF[]', $off);
        $out .= $this->ints('int32_t NODES[]', $p->nodes);
        $out .= $this->ints('int32_t KIDS[]', $p->kids);
        $out .= $this->ints('int32_t RULES[]', $p->rules);
        $out .= $this->ints('int32_t PALTS[]', $p->palts);
        $out .= $this->ints('unsigned char GUARDS[]', $guards);
        $out .= $this->ints('uint32_t SET_BITS[]', array_map(fn($x) => $x & 0xFFFFFFFF, $setBits));
        $out .= $this->ints('int32_t SET_INDEX[]', $setIndex);
        $out .= $this->ints('int32_t SET_RANGES[]', $setRanges);
        $out .= $this->ints('int32_t EXPRS[]', $p->exprs);
        $out .= $this->ints('int32_t EKIDS[]', $p->ekids);
        $out .= $this->ints('int32_t RXCODE[]', $p->rxcode);
        $out .= $this->ints('int32_t RX_START[]', $rxStart);
        return $out;
    }
}
