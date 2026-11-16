# Test driver for generated Python parsers: python3 py_harness.py parser.py < subject
import importlib.util, json, sys
spec = importlib.util.spec_from_file_location('gen', sys.argv[1])
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
src = sys.stdin.buffer.read()
try:
    tree = mod.parse(src)
    print('OK\t' + json.dumps(tree, ensure_ascii=False))
except mod.ParseError as e:
    print('\t'.join(['ERR', '1' if e.committed else '0', str(e.source_line), str(e.source_column), str(e.offset), e.found, e.expected, str(e)]))
