<?php
namespace Charm\Parsing\FallbackServices;

use Psr\Log\AbstractLogger;
use Charm\Parsing\Util\CSI;

class Logger extends AbstractLogger {
    public function log($level, $message, array $context = []) {
        echo CSI::parse($this->interpolate($message, $context));
    }

    private function interpolate($message, array $context = array())
    {
        // build a replacement array with braces around the context keys
        $replace = array();
        foreach ($context as $key => $val) {
            // check that the value can be cast to string
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }

        // interpolate replacement values into the message and return
        return strtr($message, $replace);
    }
}
