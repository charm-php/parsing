<?php
namespace Charm\Parsing;

use Psr\Log\LoggerInterface;

interface ServiceProviderInterface {
    public function logger(): LoggerInterface;
}
