<?php
namespace Charm\Parsing\Util;

class StringTools {

    private $s;

    public function __construct(string $s) {
        $this->s = $s;
        $this->length = strlen($s);
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
        return substr_count($this->s, "\n", 0, $offset) + 1;
    }

    public function getColumnNumberAt(int $offset): int {
        $this->assertValidOffset($offset);
        $lineStartOffset = $this->getLineStartOffset($offset);

        return 1 + $offset - $lineStartOffset;
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
