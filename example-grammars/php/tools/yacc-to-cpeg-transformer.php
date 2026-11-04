<?php
/**
 * Transform Yacc AST to CPEG-compatible form.
 *
 * Main transformation: reorder alternatives so that longer/more specific
 * matches come before shorter prefix matches.
 */

/**
 * Check if $a's symbols are a prefix of $b's symbols.
 */
function isPrefix(array $aSymbols, array $bSymbols): bool {
    if (count($aSymbols) >= count($bSymbols)) {
        return false;
    }

    for ($i = 0; $i < count($aSymbols); $i++) {
        if ($aSymbols[$i] !== $bSymbols[$i]) {
            return false;
        }
    }

    return true;
}

/**
 * Reorder alternatives so prefix matches come after their longer forms.
 *
 * @param array $alternatives Array of [null, symbols[], action]
 * @return array Reordered alternatives
 */
function reorderAlternatives(array $alternatives): array {
    // Build dependency graph: if A is prefix of B, A depends on B (B must come first)
    $count = count($alternatives);
    $mustComeBefore = array_fill(0, $count, []);

    for ($i = 0; $i < $count; $i++) {
        for ($j = 0; $j < $count; $j++) {
            if ($i === $j) continue;

            $iSymbols = $alternatives[$i][1] ?? [];
            $jSymbols = $alternatives[$j][1] ?? [];

            // Filter out %prec directives for comparison
            $iSymbols = array_filter($iSymbols, fn($s) => !is_array($s) || $s[0] !== '%prec');
            $jSymbols = array_filter($jSymbols, fn($s) => !is_array($s) || $s[0] !== '%prec');
            $iSymbols = array_values($iSymbols);
            $jSymbols = array_values($jSymbols);

            if (isPrefix($iSymbols, $jSymbols)) {
                // i is prefix of j, so j must come before i
                $mustComeBefore[$i][] = $j;
            }
        }
    }

    // Topological sort
    $result = [];
    $visited = array_fill(0, $count, false);
    $inStack = array_fill(0, $count, false);

    $visit = function($i) use (&$visit, &$result, &$visited, &$inStack, $mustComeBefore, $alternatives) {
        if ($inStack[$i]) {
            // Cycle - just return, we'll handle it
            return;
        }
        if ($visited[$i]) {
            return;
        }

        $inStack[$i] = true;

        foreach ($mustComeBefore[$i] as $j) {
            $visit($j);
        }

        $visited[$i] = true;
        $inStack[$i] = false;
        $result[] = $alternatives[$i];
    };

    for ($i = 0; $i < $count; $i++) {
        $visit($i);
    }

    return $result;
}

/**
 * Transform a Yacc AST to CPEG-compatible form.
 */
function transformYaccAst(array $ast): array {
    $result = $ast;

    // Transform each rule
    foreach ($result['rules'] as &$rule) {
        $alternatives = $rule[2];

        // Flatten any conditional alternatives (like %if PHP8)
        $flattened = [];
        foreach ($alternatives as $alt) {
            if (is_array($alt) && isset($alt[0]) && $alt[0] === 'if') {
                // Conditional block - include the alternatives
                foreach ($alt[2] as $condAlt) {
                    $flattened[] = $condAlt;
                }
            } else {
                $flattened[] = $alt;
            }
        }

        // Reorder alternatives
        $rule[2] = reorderAlternatives($flattened);
    }

    return $result;
}
