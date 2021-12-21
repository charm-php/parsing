<?php
namespace Charm\Parsing;

class Debug {

    public static function validateClauseTree(Clause $clause, Clause $parent=null) {
        if ($parent !== $clause->getParent()) {
            throw new \Exception("Clause '".$clause->getClauseName()."' has misreference

Expected:   ".$parent->getClauseName()."
Is:         ".$clause->getParent()->getClauseName()."

");
        }
        if (is_iterable($clause)) {
            foreach($clause as $subClause) {
                self::validateClauseTree($subClause, $clause);
            }
        }
    }

    public static function call_trace() {
        $root = dirname(__DIR__);
        $rootL = strlen($root) + 1;
        $bt = debug_backtrace();
        $res = str_repeat("-", 84)."\n";
//        array_shift($bt);
        foreach ($bt as $t) {
/*
array(6) {
  ["file"]=>
  string(42) "/root/lib/dev/charm-parsing/src/Clause.php"
  ["line"]=>
  int(70)
  ["function"]=>
  string(10) "call_trace"
  ["class"]=>
  string(19) "Charm\Parsing\Debug"
  ["type"]=>
  string(2) "::"
  ["args"]=>
  array(0) {
  }
}
*/
            $args = array_map(function($a) {
                if (is_object($a)) return get_class($a);
                elseif (is_array($a)) return 'array('.sizeof($a).')';
                elseif (is_string($a)) return substr(json_encode($a), 0, 20);
                else return json_encode($a);
            }, $t['args']);
            $args = implode(",", $args);

            $className = isset($t['object']) ? get_class($t['object']).'#'.spl_object_id($t['object']) : ($t['class'] ?? '');

            $call = $className.($t['type'] ?? '').$t['function']."($args)";


            $res .=
                str_pad(substr($t['file']??'', $rootL), 40).
                "| ".str_pad($t['line']??'', 4, " ", STR_PAD_LEFT).
                "| ".str_pad($call, 50).
                "\n";
        }
        $res .= str_repeat("-", 84)."\n";
        return $res;
    }
}
