<?php
namespace Charm\Parsing;

interface NodeInterface {
    public function getKind(): string;
    public function __toString();
}
