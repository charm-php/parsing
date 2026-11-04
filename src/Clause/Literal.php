<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\State;
use Charm\Parsing\Clause;
use Charm\Parsing\Signal;

/**
 * Matches a literal token (single-character operators like ";", "+", "(").
 *
 * In token mode, these are stored as strings in the tokens array.
 * Returns the literal string on match.
 */
class Literal extends Terminal {

    private string $literal;

    public function __construct(string $literal, int $modifier, ?string $name) {
        parent::__construct($modifier, $name);
        $this->literal = $literal;
    }

    public function toGrammarString(): string {
        return $this->getPredicateString() . '"' . addslashes($this->literal) . '"' . $this->getOperatorString();
    }

    public function asString(): string {
        return '"' . addslashes($this->literal) . '"';
    }

    public function interpret(State $state, array $context) {
        if ($state->tokens === null) {
            throw new \LogicException("Literal clause can only be used in token mode");
        }

        if ($state->offset >= $state->length) {
            return Signal::MISS;
        }

        $token = $state->tokens[$state->offset];

        // Match: token is string and matches our literal
        if ($token === $this->literal) {
            $state->offset++;
            return $this->literal;
        }

        return Signal::MISS;
    }

    public function getMinByteLength(): int {
        return 1; // Minimum 1 token
    }

    public function getFirstBytes(): ?string {
        return null; // Not applicable for token mode
    }

    /**
     * Get the literal this clause matches.
     */
    public function getLiteral(): string {
        return $this->literal;
    }
}
