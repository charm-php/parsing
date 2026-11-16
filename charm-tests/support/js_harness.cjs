// Test driver for generated JavaScript parsers: node js_harness.cjs parser.cjs < subject
const fs = require('fs');
const { parse, ParseError } = require(process.argv[2]);
const src = fs.readFileSync(0);
try {
    const tree = parse(src);
    process.stdout.write('OK\t' + JSON.stringify(tree) + '\n');
} catch (e) {
    if (!(e instanceof ParseError)) throw e;
    process.stdout.write(['ERR', e.committed ? 1 : 0, e.sourceLine, e.sourceColumn, e.offset, e.found, e.expected, e.message].join('\t') + '\n');
}
