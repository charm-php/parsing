<?php
namespace Charm\Parsing;

trait ExceptionTrait {

    private $data = [];

    public function getTags(): array {
        return $this->data;
    }

    public function set(string $key, $value) {
        $this->data[$key] = $value;
    }

    public function get(string $key) {
        return $this->data[$key] ?? null;
    }

}
