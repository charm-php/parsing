<?php
namespace Charm\Parsing;

use Charm\Parsing\Clause\Terminal;

class Hit {

    public $clause;
    public $value;
    public $offset;
    public $length;

    public function __construct(Clause $clause, $value, int $offset, int $length) {
        if (is_array($value)) {
            foreach ($value as $v) {
                if (!($v instanceof Hit)) {
                    throw new InternalError("Array with non-Hit contents provided");
                }
            }
        } elseif (is_string($value)) {
        } elseif ($value === null) {
        } else {
            if (!($value instanceof Hit)) {
                throw new InternalError("Value must be a string, null, array or Hit instance");
            }
        }
        $this->clause = $clause;
        $this->value = $value;
        $this->offset = $offset;
        $this->length = $length;
    }

    private static $indentation = 0;
    public function dump(): string {
        $suffix = '#'.\spl_object_id($this->clause);
        $in = str_repeat("  ", self::$indentation);
        self::$indentation++;

        $className = explode("\\", get_class($this->clause));
        $className = array_pop($className);

        if ($this->clause instanceof Terminal) {
            self::$indentation--;
            return $in.($this->clause->getName() !== null ? $this->clause->getName().':' : '')."`".(is_array($this->value) ? implode("", $this->value) : (string) $this->value)."` ($className$suffix)\n";
        }
        $res = $in;
        $res .= $className." ".$this->clause."$suffix\n";
        if (is_array($this->value)) {
            foreach ($this->value as $hit) {
                $res .= $hit->dump();
            }
        } elseif (is_string($this->value)) {
            $res .= $in."  `".$this->value."`\n";
        } elseif ($this->value instanceof Hit) {
            $res .= $this->value->dump();
        } elseif ($this->value === null) {
            $res .= $in."NULL\n";
        } else {
var_dump($this->value);
echo $this->clause;
            throw new InternalError("A hit value must be either a string or an array of clauses");
        }
        self::$indentation--;
        return $res;
    }

    public function __debugInfo() {
        return [
            "clause" => (string) $this->clause,
            "value" => $this->value,
            "offset" => $this->offset,
            'length' => $this->length,
        ];
    }

    public function __toString() {
        if (is_string($this->value)) {
            return $this->value;
        } elseif (is_array($this->value)) {
            return implode("", $this->value);
        } elseif ($this->value === NULL) {
            return 'NULL';
        } elseif ($this->value instanceof Hit) {
            return (string) $this->value;
        } else {
            throw new \Exception("WTF?");
        }
    }

}
