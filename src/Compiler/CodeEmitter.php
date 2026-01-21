<?php
namespace Charm\Parsing\Compiler;

/**
 * Helper class for generating indented PHP code blocks.
 * Handles indentation levels and provides a fluent interface for building code.
 */
class CodeEmitter
{
    private string $code = '';
    private int $indent = 0;
    private string $indentStr = '    ';

    public function __construct(int $initialIndent = 0, string $indentStr = '    ')
    {
        $this->indent = $initialIndent;
        $this->indentStr = $indentStr;
    }

    /**
     * Add a line of code with current indentation
     */
    public function line(string $line = ''): self
    {
        if ($line === '') {
            $this->code .= "\n";
        } else {
            $this->code .= str_repeat($this->indentStr, $this->indent) . $line . "\n";
        }
        return $this;
    }

    /**
     * Add raw code without indentation processing
     */
    public function raw(string $code): self
    {
        $this->code .= $code;
        return $this;
    }

    /**
     * Add multiple lines, each will be indented
     */
    public function lines(array $lines): self
    {
        foreach ($lines as $line) {
            $this->line($line);
        }
        return $this;
    }

    /**
     * Increase indentation level
     */
    public function indent(): self
    {
        $this->indent++;
        return $this;
    }

    /**
     * Decrease indentation level
     */
    public function dedent(): self
    {
        $this->indent = max(0, $this->indent - 1);
        return $this;
    }

    /**
     * Add opening brace and increase indent
     */
    public function openBlock(string $header = ''): self
    {
        if ($header !== '') {
            $this->line($header . ' {');
        } else {
            $this->line('{');
        }
        return $this->indent();
    }

    /**
     * Decrease indent and add closing brace
     */
    public function closeBlock(string $suffix = ''): self
    {
        return $this->dedent()->line('}' . $suffix);
    }

    /**
     * Execute callback with increased indentation
     */
    public function indented(callable $fn): self
    {
        $this->indent();
        $fn($this);
        $this->dedent();
        return $this;
    }

    /**
     * Execute callback within a block (with braces)
     */
    public function block(string $header, callable $fn, string $suffix = ''): self
    {
        $this->openBlock($header);
        $fn($this);
        return $this->closeBlock($suffix);
    }

    /**
     * Get the generated code
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Reset the emitter
     */
    public function reset(): self
    {
        $this->code = '';
        $this->indent = 0;
        return $this;
    }

    /**
     * Get current indentation level
     */
    public function getIndent(): int
    {
        return $this->indent;
    }

    /**
     * Set indentation level
     */
    public function setIndent(int $level): self
    {
        $this->indent = max(0, $level);
        return $this;
    }

    /**
     * Get the indentation string for current level
     */
    public function getIndentString(): string
    {
        return str_repeat($this->indentStr, $this->indent);
    }
}
