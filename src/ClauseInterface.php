<?php
namespace Charm\Parsing;

/**
 * Abstract superclass of all PEG operators and terminals
 */
interface ClauseInterface {
    public function getModifier(): int;
    public function setModifier(int $modifier);

    public function isRepeating(): bool;
    public function isOptional(): bool;
    public function hasAndPredicate(): bool;
    public function hasNotPredicate(): bool;

    public function isKleeneStar(): bool;
    public function isKleeneCross(): bool;

    public function __toString();
}
