<?php
namespace Charm\Parsing;

/**
 * Represents a parse call during parsing.
 */
class Trace {
    public
        $ts,
        $offset,
        $clause,
        $parent,
        $children = [],
        $haveResult = false,
        $result = null;

    public function __construct($clause, $offset) {
        $this->clause = $clause;
        $this->offset = $offset;
    }

    public function __debugInfo() {
        $res = [
            'term' => $this->clause->getClauseName(),
        ];
        if ($this->haveResult) {
            $res['result'] = $this->result;
        }
        if ($this->children !== []) {
            $res['children'] = $this->children;
        }
        return $res;
    }
}
