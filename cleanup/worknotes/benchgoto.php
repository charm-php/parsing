<?php
declare(strict_types=1);

function main() {
    for ($i = 0; $i < 10000000; $i++) {
        $local = testFunction($i + 1);
        if ($local === -1) die("SHOULD NOT HAPPEN");
    }
}

function mainInlined() {
    for ($i = 0; $i < 10000000; $i++) {
        $local = (($i + 1) * 2) | 0;
        if ($local === -1) die("SHOULD NOT HAPPEN");
    }
}

function testFunction($i) {
    return ($i * 2) | 0;
}

function testCompiledPseudoAsm() {
    $_returnAddress = 1000|0;       // special trick to set the return address = address of main()
    $_returnValue = null;           // pre-init $_returnValue

    _return: switch ($_returnAddress) {
case 1000: _addr_1000: // main()
            $i = 0|0;                                 // for-loop initialization
            goto _addr_1004;                        // for-loop first iteration

case 1002: _addr_1002:                              // for-loop body

            $_returnAddress = 1003;                 // function-call return address
            $_arg0 = $i+1;                          // function-call argument 0
            goto _addr_2000;                        // invoke function
case 1003: _addr_1003:                              // function-call return address
            $local = $_returnValue;                 // take the return value
            if ($local === -1) die("SHOULD NOT HAPPEN");

            $i++;                                   // for-loop step
case 1004: _addr_1004:                              // for-loop starting point
            if ($i < 10000000) goto _addr_1002;     // for-loop repeat?
case 1005: _addr_1005:                              // for-loop done
echo "I = $i\n";
            return;                                 // end of main()

case 2000: _addr_2000: // testFunction($_arg0)
            $_returnValue = ($_arg0 * 2) | 0;
            goto _return;


    }
}

function testCompiledPseudoAsmInlined() {
    $_returnAddress = 1000|0;       // special trick to set the return address = address of main()
    $_returnValue = null;           // pre-init $_returnValue

    _return: switch ($_returnAddress) {
case 1000: _addr_1000: // main()
            $i = 0|0;                                 // for-loop initialization
            goto _addr_1004;                        // for-loop first iteration

case 1002: _addr_1002:                              // for-loop body

            $local = (($i + 1) * 2) | 0;
            if ($local === -1) die("SHOULD NOT HAPPEN");

            $i++;                                   // for-loop step
case 1004: _addr_1004:                              // for-loop starting point
            if ($i < 10000000) goto _addr_1002;     // for-loop repeat?
case 1005: _addr_1005:                              // for-loop done
echo "I = $i\n";
            return;                                 // end of main()

    }
}

function testCompiledClosure() {

    $testFunction = function($i) {
        return ($i * 2) | 0;
    };

    $main = function() use ($testFunction) {
        $i = 0;
        goto _addr_0;

        _addr_1:
        // loop body
        $local = $testFunction($i + 1);
        if ($local === -1) die("SHOULD NOT HAPPEN");

        $i++;
        _addr_0:
        if ($i < 10000000) goto _addr_1;
    };

    $main();

}

function testCompiledClosureInt() {

    $testFunction = function($i) {
        return ($i * 2) | 0;
    };

    $main = function() use ($testFunction) {
        $i = 0|0;
        goto _addr_0;

        _addr_1:
        // loop body
        $local = $testFunction($i + 1) | 0;
        if ($local === -1) die("SHOULD NOT HAPPEN");

        $i++;
        _addr_0:
        if ($i < 10000000) goto _addr_1;
    };

    $main();

}


$t = microtime(true);
testCompiledPseudoAsm(0);
echo "Time compiled asm: ".(microtime(true)-$t)."\n";

$t = microtime(true);
testCompiledPseudoAsmInlined(0);
echo "Time compiled asm hard jump: ".(microtime(true)-$t)."\n";

$t = microtime(true);
testCompiledClosure(0);
echo "Time compiled closure: ".(microtime(true)-$t)."\n";

$t = microtime(true);
testCompiledClosureInt(0);
echo "Time compiled closure int: ".(microtime(true)-$t)."\n";

$t = microtime(true);
main();
echo "Time normal: ".(microtime(true)-$t)."\n";

$t = microtime(true);
mainInlined();
echo "Time normal inlined: ".(microtime(true)-$t)."\n";
die("HER");


/*
_for0_body:
            $_jmp = 2000; $_returnAddress = 1001; $_arg0 = $i + 1; goto _2000;
*/
