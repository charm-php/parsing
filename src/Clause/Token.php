<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\State;
use Charm\Parsing\Clause;
use Charm\Parsing\Signal;

/**
 * Matches a token by type name (e.g., T_LNUMBER, T_STRING).
 *
 * The token name is resolved via the lexer's symbol map at parse time.
 * Returns the token's value (string) on match.
 */
class Token extends Terminal {

    private string $tokenName;
    private ?int $tokenId = null;

    public function __construct(string $tokenName, int $modifier, ?string $name) {
        parent::__construct($modifier, $name);
        $this->tokenName = $tokenName;
    }

    public function toGrammarString(): string {
        return $this->getPredicateString() . $this->tokenName . $this->getOperatorString();
    }

    public function asString(): string {
        return $this->tokenName;
    }

    public function interpret(State $state, array $context) {
        if ($state->tokens === null) {
            throw new \LogicException("Token clause can only be used in token mode");
        }

        if ($state->offset >= $state->length) {
            return Signal::MISS;
        }

        // Resolve token ID from symbol map (cached after first lookup)
        if ($this->tokenId === null) {
            $symbolMap = $context['symbolMap'] ?? [];
            if (!isset($symbolMap[$this->tokenName])) {
                throw new \LogicException("Unknown token type: {$this->tokenName}");
            }
            $this->tokenId = $symbolMap[$this->tokenName];
        }

        $token = $state->tokens[$state->offset];

        // Match: token is int (type ID) and matches our expected type
        if ($token === $this->tokenId) {
            $state->offset++;
            // Return the token's string value via tokenData
            return $state->tokenData->getValue($state->offset - 1);
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
     * Get the token type name this clause matches.
     */
    public function getTokenName(): string {
        return $this->tokenName;
    }
}
