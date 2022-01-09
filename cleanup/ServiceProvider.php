<?php
namespace Charm\Parsing;

use Psr\Log\LoggerInterface;

class ServiceProvider {

    public function logger(): LoggerInterface {
        return new FallbackServices\Logger();
    }

}
