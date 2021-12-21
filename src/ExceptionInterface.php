<?php
namespace Charm\Parsing;

interface ExceptionInterface {

    // get all metadata
    public function getTags(): array;

    // get metadata from the exception
    public function get(string $key);

    // set metadata on the exception
    public function set(string $key, $value);

}
