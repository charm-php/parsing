<?php
namespace Charm\Parsing\Clause;

/**
 * Interface denotes that the terminal clause can provide a regex for the tokenizer
 */
interface RegexInterface {

    public function getRegex(): string;

    // return an integer where lower values will cause the pattern to be tokenized first
    public function getRegexPriority(): int;

}
