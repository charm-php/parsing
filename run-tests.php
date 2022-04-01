<?php
use Charm\Parsing\Util\CSI;

/**
 * Format of test files:
 * <?php return [
 *    "Title of the test",                                          // the test title
 *    function($data) { return json_encode($data); },               // the test function
 *    [ "argument value", "expected result" ],                      // an array with input and output data
 *    [ "another argument value", "another expected result" ],      // another array with input and output data
 * ];
 */

require(__DIR__.'/vendor/autoload.php');

out("<!bwhite>Charm/Parsing regression test suite report<!>\n");
out("<!bwhite>------------------------------------------<!>\n\n");
out("<!white>Tests started <!bwhite>".gmdate('Y-m-d H:i:s')." GMT<!><!>\n");
out("\n");

$testFiles = glob(__DIR__.'/charm-tests/*.php'); /**/

foreach ($testFiles as $number => $testFile) {
    $test = require($testFile);
    out("<!byellow>## Test $number: <!italic>".$test[0]."<!><!>\n\n");

    $numberOfTests = count($test) - 2;

    if ($numberOfTests > 1) {
        out("This test contains <!bwhite>$numberOfTests<!> sub-tests.\n\n");
    }

    for ($j = 2; $j < count($test); $j++) {
        $testData = $test[$j];
        if (!is_array($testData) || !key_exists(0, $testData) || !key_exists(1, $testData)) {
            $received = var_export($testData, true);
            out(<<<ERROR
                <!bred><!underline>ERROR IN TEST DATA!<!><!>

                The test data must be provided as an in the form:

                ```<!bwhite>
                [
                    \$argument,             // the value which will be passed to the test function
                    \$expectedResult,       // the value we're expecting in return, a class name
                                            // for an exception or an exception instance)
                ]
                <!>```

                The provided value was:

                ```<!bred>
                $received
                <!>```

                ERROR);
            continue;
        }
        $forked = false;
        if (function_exists('pcntl_fork')) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                out("<!white>Running test ".($j-1)."/$numberOfTests <!red>without pcntl_fork()<!> <!bwhite>".str_pad(basename($testFile), 40)."<!>:<!> ");
                goto run_test;
            } elseif ($pid !== 0) {
                $startTime = microtime(true);
                do {
                    $runTime = microtime(true) - $startTime;
                    out("\r<!white>Running <!bwhite>".str_pad(basename($testFile), 40)."<!>:<!> <!byellow>".number_format($runTime, 3)." sec<!>");
                    usleep(10000);
                    $result = pcntl_waitpid($pid, $status, WUNTRACED|WNOHANG);
                    if ($result !== 0) {
                        $statusCode = pcntl_wexitstatus($status);
                        $resultStr = $statusCode === 42 ? "<!bgreen>OK<!>" : "<!bred>ERROR<!>";
                        out("\r<!white>Finished <!bwhite>".str_pad(basename($testFile)." ".($j-1)."/$numberOfTests", 40)."<!>:<!> $resultStr (test completed in ".number_format($runTime, 2)." seconds)\n\n");
    //<!byellow>".number_format($runTime, 3)." sec<!>");
                        pcntl_wait($status);
                        break;
                    }
                } while (true);
                continue;
            } else {
                $forked = true;
            }
        }

        run_test:

            $startTime = microtime(true);
            $outputBuffer = '';
            $outputStarted = false;
            ob_start(function($buffer) use (&$outputBuffer, &$outputStarted) {
                if (!$outputStarted && $buffer !== '') {
                    $outputStarted = true;
                    out("\n\n<!byellow>UNEXPECTED OUTPUT\n=================<!>\n");
                }

                $lastLinePos = strrpos($outputBuffer, "\n");
                if (is_int($lastLinePos)) {
                    $lastLine = substr($outputBuffer, $lastLinePos + 1);
                } else {
                    $lastLine = false;
                }

                $outputBuffer .= $buffer;

                if ($lastLine !== false) {
                    $buffer = "\r".CSI::clearRight().$lastLine.$buffer;
                } else {
                    $buffer = strtr($buffer, [
                        "\n" => "\n".CSI::clearRight()
                    ]);
                }

                fwrite(STDERR, $buffer);
            }, 1, PHP_OUTPUT_HANDLER_REMOVABLE);

            try {
                $result = $test[1]($testData[0]);
            } catch (\Throwable $e) {
                $result = $e;
            }
//            $outputBuffer = ob_get_contents();
            ob_end_clean();
            if ($outputStarted) {
                out("\n<!byellow>=================<!>\n");
            }
            $success = var_export($result, true) === var_export($testData[1], true);

            if (!$success) {
                if (is_object($result) && is_string($testData[1]) && class_exists($testData[1]) && is_a($result, $testData[1])) {
                    $success = true;
                }
            }

            if ($outputBuffer !== '') {
/*

die("OWERWER




wer");
*/
            }

            if (!$success) {
                out("\r".CSI::clearRight()."<!bred><!underline>TEST FAILED<!><!>\n\n");
                compare($result, $testData[1]);
            }

        if ($forked) {
            exit($success ? 42 : 1);
        } else {
            $runTime = microtime(true) - $startTime;
            $resultStr = $success ? "<!bgreen>OK<!>" : "<!bred>ERROR<!>";
            out("\r<!white>Finished <!bwhite>".str_pad(basename($testFile)." ".($j-1)."/$numberOfTests", 40)."<!>:<!> $resultStr (test completed in ".number_format($runTime, 2)." seconds)\n\n");
        }
    }
}

function out(string $out) {
    if (!CSI::isTTY()) {
        // remove lines that contain a \r without a \n at the end of the line
        $out = preg_replace('/^[^\r\n]*\r[^\n]*(?!\n)$/m', '', $out);
    }
    fwrite(STDERR, CSI::parse($out));
}

function compare($funcResult, $expected) {
    if ($funcResult instanceof \Throwable) {
        $a = (string) $funcResult;
    } else {
        $a = \Charm\php_encode($funcResult, true);
    }
    if ($expected instanceof \Throwable) {
        $b = (string) $expected;
    } else {
        $b = \Charm\php_encode($expected, true);
    }

    $resultColor = $a === $b ? '<!bgreen>' : '<!bred>';

    $a = wordwrap($a);
    $b = wordwrap($b);

    $aLines = explode("\n", strtr($a, ["\r\n" => "\n"]));
    $bLines = explode("\n", strtr($b, ["\r\n" => "\n"]));
    $aWidth = array_reduce($aLines, function($carry, $line) { return max($carry, mb_strlen($line)); }, 20);
    $bWidth = array_reduce($bLines, function($carry, $line) { return max($carry, mb_strlen($line)); }, 20);
    $result = [
        "+-".str_repeat("-", 5)."-|-".str_repeat("-", $aWidth)."-|-".str_repeat("-", $bWidth)."-+",
        "| ".CSI::str_pad("<!bwhite>Line<!>", 5)." | ".CSI::str_pad("<!bwhite>Result<!>", $aWidth)." | ".CSI::str_pad("<!bwhite>Expected Result<!>", $bWidth)." |",
        "+-".str_repeat("-", 5)."-|-".str_repeat("-", $aWidth)."-|-".str_repeat("-", $bWidth)."-+",
    ];


    $lineCount = max(count($aLines), count($bLines));
    $aSkew = 0;
    $bSkew = 0;
    for ($i = 0; $i < $lineCount; $i++) {
        $aLine = $aLines[$i] ?? null;
        $bLine = $bLines[$i] ?? null;
        $similarity = '';
        if (($aLines[$i + $aSkew] ?? null) === ($bLines[$i + $bSkew] ?? null)) {
        } elseif (($aLines[$i + $aSkew] ?? null) === null) {
            $bLine = "<!bred>$bLine<!>";
        } elseif (($bLines[$i + $bSkew] ?? null) === null) {
            $aLine = "<!bred>$aLine<!>";
        } else {
            similar_text($aLines[$i+$aSkew], $bLines[$i+$bSkew], $similarity);
            $aLine = "<!bred>$aLine<!>";
            $bLine = "<!bgreen>$bLine<!>";
            if ($similarity < 10) {
                $bSkew--;
            }
        }
        $result[] =
            "| ".CSI::str_pad((string) $i+1, 5, ' ', STR_PAD_LEFT).
            " | ".CSI::str_pad($aLine ?? '', $aWidth).
            " | ".CSI::str_pad($bLine ?? '', $bWidth).
            " |";
    }

    $result[] = $result[0];

    out("\r".CSI::clearRight().implode("\n", $result)."\n");

    if (is_array($funcResult)) {
        $phpArray = \Charm\php_encode($funcResult);
        $phpSerialized = serialize($funcResult);
        $jsonArray = json_encode($funcResult);
        out(<<<RES
            Result value:
            php array:
            <!bgreen>$phpArray<!>

            serialized:
            <!bgreen>$phpSerialized<!>

            json:
            <!bgreen>$jsonArray<!>

            RES);
        out("\n array: `<!bwhite>".strtr(json_encode($funcResult), [ "\/" => "/" ])."<!>`\n\n");
    }

}


/**
 * Quick and dirty PHP short array serializer based on JSON encode (which makes it less useful for objects)
 */
function php_encode($value, bool $pretty=false) {
    // captures all braces outside of quoted string literals
    $re = '/(?<key>"\d+":\s?)|"(\\[\\"]|[^"])*"|[{}[\]]|:\s?/';
    $encoded1 = json_encode($value, ($pretty ? JSON_PRETTY_PRINT : 0) | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_LINE_TERMINATORS);
    $stack = [];
    $stackLength = -1;
    return preg_replace_callback($re, function($matches) use (&$stack, &$stackLength) {
        if ($matches[0] === ':') {
            return '=>';
        }
        if ($matches[0] === "{" || $matches[0] === '[') {
            $stack[++$stackLength] = 0;
            return "[";
        }
        if ($matches[0] === "}" || $matches[0] === ']') {
            --$stackLength;
            return "]";
        }
        if (key_exists('key', $matches) && "" !== $matches['key']) {
            $id = intval(substr($matches['key'], 1, -1));
            if ($stack[$stackLength] === $id) {
                return '';
            } else {
                return $id;
            }
        }
        if ($matches[0][0] === '"') {
            $s = json_decode($matches[0]);
            return var_export($s, true);
        }
        if ($matches[0][0] === ':') {
            return '=>';
        }
        return $matches[0];
    }, $encoded1);
    return $encoded2;
}
