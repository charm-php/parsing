<?php
namespace Charm\Parsing;

use Charm\Parsing\Util\ExceptionTools;

class Exception extends \Exception implements ExceptionInterface {
    use ExceptionTrait;

    private $info;

    public function __construct(string $message, int $code, ErrorInfo $info=null) {
        parent::__construct($message, $code);
        $this->info = $info ?? new ErrorInfo();

        $et = new ExceptionTools($this);
        if ($this->info->filename) {
            $et->setFile($this->info->filename);
        }

    }

    public function getInfo(): ErrorInfo {
        return $this->info;
    }

}
