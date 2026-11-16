/* Test driver for generated C parsers: reads the subject from stdin, prints OK<TAB>json or ERR<TAB>fields. */
#include "@n@.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

int main(void) {
    size_t cap = 1 << 16, n = 0, r;
    char *src = (char *)malloc(cap), *json;
    @n@_result *res;
    @n@_error err;
    size_t need;
    while ((r = fread(src + n, 1, cap - n, stdin)) > 0) {
        n += r;
        if (n == cap) { cap *= 2; src = (char *)realloc(src, cap); }
    }
    res = @n@_parse(src, n, &err);
    if (!res) {
        printf("ERR\t%d\t%u\t%u\t%lu\t%s\t%s\t%s\n", err.committed, err.line, err.column, (unsigned long)err.offset,
               err.found, err.expected, err.message);
        free(src);
        return 0;
    }
    need = @n@_to_json(@n@_result_value(res), NULL, 0);
    json = (char *)malloc(need + 1);
    @n@_to_json(@n@_result_value(res), json, need + 1);
    printf("OK\t%s\n", json);
    free(json);
    @n@_result_free(res);
    free(src);
    return 0;
}
