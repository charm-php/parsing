<?php
namespace Charm\Parsing\Traits;

trait Logger {
    abstract protected function outputLog(string $line): void;

    public function log(...$parts): void {
        $line = [];
        foreach ($parts as $part) {
            $line[] = $this->stringifyLogPart($part);
        }
        $this->outputLog('<!white>'.gmdate('Y-m-d H:i:s').'<!>\t'.implode(" ", $line));
    }

    private function stringifyLogPart($part): string {
        return json_encode($part);
    }


}

