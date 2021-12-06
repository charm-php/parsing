<?php
namespace Charm\Parsing\Terminal;

/**
 * Renders tabular data for the command line
 */
class Table {
    protected $data;

    public function __construct(iterable $data = []) {
        $this->data = [];
        foreach ($data as $row) {
            $newRow = [];
            foreach ($row as $col) {
                $newRow[] = $col;
            }
            $this->data[] = $newRow;
        }
    }

    public function __toString() {
        $res = '';
        foreach ($this->render() as $line) {
            $res .= $line."\n";
        }
        return $res;
    }

    public function render(): iterable {
        // Get column widths for each row
        $lengths = [];
        $aligns = [];
        foreach ($this->data as $r => $row) {
            foreach ($row as $c => $col) {
                if (is_bool($col)) {
                    $col = $this->data[$r][$c] = $col ? 'true' : 'false';
                }
                $length = mb_strlen($col);
                if (!isset($lengths[$c]) || $lengths[$c] < $length) {
                    $lengths[$c] = $length;
                }
                if (!isset($aligns[$c])) {
                    if (floatval((string) $col) != 0) {
                        $aligns[$c] = STR_PAD_LEFT;
                    } else {
                        $aligns[$c] = STR_PAD_RIGHT;
                    }
                }
            }
        }

        // Check if the total width of all colums is less than viewport
        $optimalWidth = CSI::getCols();

        // Minimum one character between each column
        $longestLine = sizeof($lengths) - 1;
        foreach ($lengths as $length) {
            $longestLine += $length;
        }

        if ($longestLine < $optimalWidth) {
            // Find the number of extra spaces between each column
            $finalWidth = $optimalWidth;
            $extraSpace = $finalWidth - $longestLine;
            $extraSpaceCount = intval($extraSpace / (sizeof($lengths)-1));
        } else {
            // We can't use more spacing
            $extraSpaceCount = 0;
        }
        $extraSpaceCount = min(8, $extraSpaceCount);

        foreach ($this->data as $row) {
            $line = '';
            foreach ($row as $c => $column) {
                $utf8Extra = strlen($column) - mb_strlen($column);
                $line .= "| ".str_pad($column, $utf8Extra + $lengths[$c], " ", $aligns[$c]);
                // Don't add extra spacing on the last column
                if ($c < sizeof($lengths)-1) {
                    $line .= str_pad(" ", $extraSpaceCount);
                } else {
                    $line .= "|";
                }
            }
            yield $line;
        }
    }
}
