<?php
namespace Charm\Parsing\Util;

class StringTools {

    private $s;

    public function __construct(string $s) {
        $this->s = $s;
        $this->length = strlen($s);
    }

    public function indent(int $s=4): static {
        $indent = str_repeat(" ", $s);
        $s = $indent.str_replace("\n", "\$n$indent", $this->s);
        return new static($s);
    }

    public function first(): static {
        preg_match_all('/^\w+|^\s+|\^\W+|[\s\S]+\b/u', $this->s, $matches);
        foreach ($matches as $match) {
            if (key_exists(0, $match)) {
                return new static($match[0]);
            }
        }
        return new static('');
    }

    public function trimDots(int $length, int $offset=0): static {
        if (mb_strlen($this->s) > $length) {
            return new static(mb_substr($this->s, $offset, $length - 3).'...');
        }
        return new static($this->s);
    }

    /**
     * Provides a human readable description of the string found at the
     * given offset for debug messages and logging.
     */
    public function describeOffset(int $offset): string {
        if ($offset === $this->length) {
            return 'end of file';
        } elseif ($offset > $this->length) {
            return 'invalid offset after end of file';
        } elseif ($offset === 0) {
            return 'start of file';
        } elseif ($offset < 0) {
            return 'invalid negative offset';
        }

        preg_match('/(?<whitespace>^\s+)|(?<string>^\w+)|(?<symbol>^.)/u', substr($this->s, $offset, 80), $matches);
        $key = array_search($matches[0], array_slice($matches, 1, null, true));

        $loc = ' on line '.$this->getLineNumberAt($offset).' column '.$this->getColumnNumberAt($offset);

        if ($key === 'whitespace') {
            return $key.$loc;
        }
        return "$key `".$matches[0]."`".$loc;
    }

    public function getLineAt(int $offset): static {
        $this->assertValidOffset($offset);
        $lineStartOffset = $this->getLineStartOffset($offset);
        $lineEndOffset = $this->getLineEndOffset($offset);
        return new static(substr($this->s, $lineStartOffset, $lineEndOffset - $lineStartOffset));
    }

    public function escape(): static {
        return new static(strtr($this->s, [
            "\n" => "\\n",
            "\r" => "\\r",
            "\0" => "\\0",
        ]));
    }

    public function getLineStartOffset(int $offset): int {
        $this->assertValidOffset($offset);
        $lineStartOffset = strrpos(substr($this->s, 0, $offset), "\n");;
//        $lineStartOffset = strrpos($this->s, "\n", -($this->length - $offset));
        if (!is_int($lineStartOffset) || $lineStartOffset === 0) {
            return 0;
        } else {
            return $lineStartOffset + 1;
        }
    }

    public function getLineEndOffset(int $offset): int {
        $this->assertValidOffset($offset);
        $lineEndOffset = strpos($this->s, "\n", $offset);

//echo json_encode(substr($this->s, $offset, 100))."\n";

        if (!is_int($lineEndOffset)) {
            return $this->length;
        }
        return $lineEndOffset;
    }

    public function getLineNumberAt(int $offset): int {
        $this->assertValidOffset($offset);
        if ($offset < 0 || $offset > $this->length) {
            return null;
        }
        return substr_count($this->s, "\n", 0, $offset);
    }

    public function getColumnNumberAt(int $offset): int {
        $this->assertValidOffset($offset);
        $lineStartOffset = $this->getLineStartOffset($offset);

        return $offset - $lineStartOffset;
    }

    private function assertValidOffset(int $offset) {
        if ($offset < 0 || $offset > $this->length) {
            throw new \LogicException("Invalid offset $offset in string with length {$this->length}");
        }
    }

    public function __toString() {
        return $this->s;
    }

}
