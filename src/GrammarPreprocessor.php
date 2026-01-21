<?php
namespace Charm\Parsing;

/**
 * Preprocessor for grammar templates/generics.
 *
 * Expands template definitions like `Paren<T> = "(" T ")"` into concrete
 * rules when instantiated as `Paren<Expr>`.
 *
 * Example:
 *   Input:  Paren<T> = "(" T ")"
 *           Expr = Paren<Number>
 *   Output: Paren__Number = "(" Number ")"
 *           Expr = Paren__Number
 */
class GrammarPreprocessor
{
    /** @var array<string, array{params: string[], body: string}> */
    private array $templates = [];

    /** @var array<string, string> Generated rules (mangled name => body) */
    private array $generatedRules = [];

    /**
     * Preprocess a grammar string, expanding all template instantiations.
     */
    public function process(string $grammar): string
    {
        $this->templates = [];
        $this->generatedRules = [];

        // Extract template definitions
        $grammar = $this->extractTemplates($grammar);

        // Expand template usages until none remain
        $maxIterations = 100; // Prevent infinite loops
        for ($i = 0; $i < $maxIterations; $i++) {
            $expanded = $this->expandTemplateUsages($grammar);

            // Also expand any template usages in generated rule bodies
            $changed = false;
            foreach ($this->generatedRules as $name => $body) {
                $expandedBody = $this->expandTemplateUsages($body);
                if ($expandedBody !== $body) {
                    $this->generatedRules[$name] = $expandedBody;
                    $changed = true;
                }
            }

            if ($expanded === $grammar && !$changed) {
                break;
            }
            $grammar = $expanded;
        }

        // Append generated rules
        if (!empty($this->generatedRules)) {
            $grammar .= "\n\n# Generated template instantiations\n";
            foreach ($this->generatedRules as $name => $body) {
                $grammar .= "$name = $body\n";
            }
        }

        return $grammar;
    }

    /**
     * Extract template definitions from the grammar and remove them.
     */
    private function extractTemplates(string $grammar): string
    {
        $lines = explode("\n", $grammar);
        $result = [];
        $currentTemplate = null;
        $currentBody = [];

        foreach ($lines as $line) {
            // Check for template definition start: Name<Param, Param> = body
            // Allow optional leading whitespace
            if (preg_match('/^\s*(\w+)\s*<\s*(\w+(?:\s*,\s*\w+)*)\s*>\s*(::?=|:=|->|=)\s*(.*)$/', $line, $match)) {
                // Save previous template if any
                if ($currentTemplate !== null) {
                    $this->templates[$currentTemplate['name']]['body'] = trim(implode("\n", $currentBody));
                }

                $name = $match[1];
                $params = array_map('trim', explode(',', $match[2]));
                $body = $match[4];

                $this->templates[$name] = [
                    'params' => $params,
                    'body' => '',
                ];

                $currentTemplate = ['name' => $name];
                $currentBody = [$body];
            }
            // Check for new rule (non-template) - ends current template
            // Match both flush-left and indented rule definitions
            elseif ($currentTemplate !== null && preg_match('/^\s*\w+\s*(::?=|:=|->|=)/', $line) && !preg_match('/^\s*\w+\s*</', $line)) {
                $this->templates[$currentTemplate['name']]['body'] = trim(implode("\n", $currentBody));
                $currentTemplate = null;
                $currentBody = [];
                $result[] = $line;
            }
            // Continuation of current template body (choice line only, not indented rules)
            elseif ($currentTemplate !== null && preg_match('/^\s*\|/', $line)) {
                $currentBody[] = $line;
            }
            // Regular line
            else {
                if ($currentTemplate !== null) {
                    $this->templates[$currentTemplate['name']]['body'] = trim(implode("\n", $currentBody));
                    $currentTemplate = null;
                    $currentBody = [];
                }
                $result[] = $line;
            }
        }

        // Don't forget last template
        if ($currentTemplate !== null) {
            $this->templates[$currentTemplate['name']]['body'] = trim(implode("\n", $currentBody));
        }

        return implode("\n", $result);
    }

    /**
     * Find and expand template usages in the grammar.
     */
    private function expandTemplateUsages(string $grammar): string
    {
        // Find template usages: Name<Arg, Arg> where Arg can contain nested <>
        // We need to handle nesting properly
        return preg_replace_callback(
            '/\b(\w+)\s*<([^<>]*(?:<[^<>]*>[^<>]*)*)>/',
            function ($match) {
                return $this->expandTemplateUsage($match[0], $match[1], $match[2]);
            },
            $grammar
        );
    }

    /**
     * Expand a single template usage.
     */
    private function expandTemplateUsage(string $full, string $name, string $argsString): string
    {
        // If not a known template, return unchanged
        if (!isset($this->templates[$name])) {
            return $full;
        }

        $template = $this->templates[$name];
        $args = $this->parseArguments($argsString);

        if (count($args) !== count($template['params'])) {
            throw new \RuntimeException(
                "Template '$name' expects " . count($template['params']) .
                " arguments, got " . count($args)
            );
        }

        // Generate mangled name
        $mangledName = $this->mangleName($name, $args);

        // Generate the rule if not already generated
        if (!isset($this->generatedRules[$mangledName])) {
            $body = $template['body'];

            // Replace parameters with arguments
            foreach ($template['params'] as $i => $param) {
                // Use word boundary to avoid partial replacements
                $body = preg_replace('/\b' . preg_quote($param, '/') . '\b/', $args[$i], $body);
            }

            $this->generatedRules[$mangledName] = $body;
        }

        return $mangledName;
    }

    /**
     * Parse comma-separated arguments, respecting nested angle brackets and quoted strings.
     */
    private function parseArguments(string $argsString): array
    {
        $args = [];
        $current = '';
        $depth = 0;
        $inString = false;
        $stringChar = null;

        for ($i = 0; $i < strlen($argsString); $i++) {
            $char = $argsString[$i];

            // Handle string literals
            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
                $current .= $char;
            } elseif ($inString && $char === $stringChar) {
                // Check for escape
                if ($i > 0 && $argsString[$i - 1] === '\\') {
                    $current .= $char;
                } else {
                    $inString = false;
                    $stringChar = null;
                    $current .= $char;
                }
            } elseif ($inString) {
                $current .= $char;
            } elseif ($char === '<') {
                $depth++;
                $current .= $char;
            } elseif ($char === '>') {
                $depth--;
                $current .= $char;
            } elseif ($char === ',' && $depth === 0) {
                $args[] = trim($current);
                $current = '';
            } else {
                $current .= $char;
            }
        }

        if (trim($current) !== '') {
            $args[] = trim($current);
        }

        return $args;
    }

    /**
     * Generate a mangled name for a template instantiation.
     */
    private function mangleName(string $name, array $args): string
    {
        $parts = [$name];
        foreach ($args as $arg) {
            // For identifiers, use them directly
            if (preg_match('/^\w+$/', $arg)) {
                $parts[] = $arg;
            }
            // For string literals, extract the content and encode
            elseif (preg_match('/^["\'](.+)["\']$/', $arg, $m)) {
                $parts[] = $this->encodeStringArg($m[1]);
            }
            // For complex args (like nested templates), use a hash
            else {
                $parts[] = 'h' . substr(md5($arg), 0, 8);
            }
        }
        return implode('__', $parts);
    }

    /**
     * Encode a string literal argument into a valid identifier component.
     */
    private function encodeStringArg(string $str): string
    {
        // Common punctuation mappings
        $map = [
            '(' => 'lparen', ')' => 'rparen',
            '[' => 'lbrack', ']' => 'rbrack',
            '{' => 'lbrace', '}' => 'rbrace',
            '<' => 'lt', '>' => 'gt',
            ',' => 'comma', '.' => 'dot',
            ':' => 'colon', ';' => 'semi',
            '+' => 'plus', '-' => 'minus',
            '*' => 'star', '/' => 'slash',
            '=' => 'eq', '!' => 'bang',
            '?' => 'qmark', '|' => 'pipe',
            '&' => 'amp', '@' => 'at',
            '#' => 'hash', '$' => 'dollar',
            '%' => 'pct', '^' => 'caret',
            '~' => 'tilde', '`' => 'btick',
            ' ' => 'sp', "\t" => 'tab',
            "\n" => 'nl', "\r" => 'cr',
        ];

        $result = '';
        for ($i = 0; $i < strlen($str); $i++) {
            $char = $str[$i];
            if (isset($map[$char])) {
                $result .= ($result !== '' ? '_' : '') . $map[$char];
            } elseif (preg_match('/[a-zA-Z0-9_]/', $char)) {
                $result .= $char;
            } else {
                // Fallback: use hex code
                $result .= ($result !== '' ? '_' : '') . 'x' . dechex(ord($char));
            }
        }
        return $result ?: 'empty';
    }
}
