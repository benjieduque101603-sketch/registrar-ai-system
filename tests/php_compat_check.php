<?php
// Guard against PHP 8-only syntax anywhere in the app.
//
//   php tests/php_compat_check.php
//
// The app is deployed to a host whose PHP version is not ours to choose.
// A single `match` expression, constructor promotion or `str_contains()`
// call is not a runtime error on 7.x - it is a PARSE error, so the page
// dies with a blank 500 before a single row of output. Nothing local
// reveals it, because the dev box runs 8.0.
//
// That is not hypothetical: registrar/documents.php carried the only
// `match` in the codebase, and it was the one page that failed to load
// on the deployed host while every other page worked. A bug that
// selective is very hard to see from a diff, and very easy to pin down
// once it is named.
//
// The rule this enforces is narrower than "support PHP 7" and that is
// deliberate: the app already uses arrow functions and `??` (7.0+), so
// the floor is 7.0. Nothing above it is worth a blank page.

$root = dirname(__DIR__);

// Scanned: what the web server actually executes. A test script or a
// one-off migration only ever runs on a developer's machine, where PHP
// 8 is a given, so flagging str_contains() in tests/ reports a risk that
// does not exist. Everything under these trees is served.
$skip = [
    '/vendor/', '/node_modules/', '/backups/', '/tests/', '/uploads/', '/logs/', '/assets/',
];
// Root-level scripts are maintenance tools, not pages: seed.php,
// fix_bad_email_domains.php and friends. A host that is old enough to
// choke on them will never run them.
$rootScripts = [
    'seed.php', 'fix_bad_email_domains.php', 'index.php', 'login.php',
    'logout.php',
];

// Each probe is a PHP 8.0+ construct. Kept as separate patterns because
// several of them have innocent twins - preg_match for match, "readonly"
// in a comment, `: false` in a JavaScript block - and a combined pattern
// reports those as failures until they are all individually whittled
// down, at which point it stops being obvious what it forbids.
$probes = [
    'match expression'      => '/(?<![\w$>])match\s*\(\s*[\$\'"]/',
    'nullsafe operator'     => '/\?->/',
    'constructor promotion' => '/function\s+__construct\s*\([^)]*\b(?:public|private|protected)\s+\$/',
    'named argument'        => '/\(\s*[a-z_]\w*\s*:\s*[\$\'"](?!\s*\))/',
    'attribute'             => '/^\s*#\[/m',
    'enum declaration'      => '/(?<![\w])enum\s+[A-Z]\w*\s*(?::|\{)/',
    'readonly property'     => '/(?<![\w])readonly\s+(?:public|private|protected|static)\s/',
    'union type'            => '/[:\(,]\s*(?:[A-Za-z_\\\\]+\|[A-Za-z_\\\\|]+)\s+\$/',
    'intersection type'     => '/[:\(]\s*[A-Za-z_\\\\]+\s*&\s*[A-Za-z_\\\\]+\s+\$/',
    'static return type'    => '/\)\s*:\s*static\b/',
    'never / false type'    => '/[:\(]\s*(?:never|false)\s+\$/',
    'first-class callable'  => '/\(\s*\.\.\.\s*\)/',
    // PHP 8.0 and 8.1 functions. The names are distinctive enough that a
    // false positive here is not worth suppressing.
    'PHP 8 function'        => '/\b(?:str_contains|str_starts_with|str_ends_with|fdiv|get_debug_type|preg_last_error_msg|array_is_list|enum_exists|fsync|fdatasync|json_validate|json_validate_syntax)\s*\(/',
];

$fail = 0;
$scanned = 0;
$found  = [];

// Built on the tokenizer rather than regexes over raw source.
//
// A regex sees through string literals and so reports `echo "(type: "`
// as a named argument, and "readonly" in a comment as a PHP 8.1
// readonly property. Both happened here, and a check that cries wolf
// gets ignored, which makes it worse than no check at all.
// token_get_all() hands back comments and strings as distinct token
// types, so dropping them is exact rather than approximate.
function php_served_source(string $path): string {
    $tokens = @token_get_all(file_get_contents($path));
    $out = '';
    foreach ($tokens as $t) {
        if (is_string($t)) { $out .= $t; continue; }
        [$id, $text] = $t;
        if (in_array($id, [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
            $out .= ' ';           // keep a separator so words do not fuse
            continue;
        }
        if ($id === T_VARIABLE || $id === T_STRING || $id === T_NAME_QUALIFIED) {
            $out .= $text;
            continue;
        }
        $out .= $text;
    }
    return $out;
}

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($it as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
    $path = $file->getPathname();
    $rel  = str_replace('\\', '/', substr($path, strlen($root) + 1));
    foreach ($skip as $s) {
        if (strpos('/' . $rel, $s) !== false) { continue 2; }
    }
    // Root-level maintenance scripts are never served; see $rootScripts.
    if (!strpos($rel, '/') && in_array($rel, $rootScripts, true)) { continue; }
    $src = php_served_source($path);
    $scanned++;
    foreach ($probes as $label => $re) {
        if (preg_match($re, $src)) {
            $found[$label][] = $rel;
        }
    }
}

printf("  scanned %d php files\n", $scanned);
if (!$found) {
    echo "  ok    no PHP 8-only syntax; the app parses on PHP 7.0\n";
} else {
    foreach ($found as $label => $files) {
        $fail++;
        printf("  FAIL  %-22s %d file(s): %s\n", $label, count($files), implode(', ', array_slice(array_unique($files), 0, 4)));
    }
}
printf("\n  %d failed\n", $fail);
exit($fail === 0 ? 0 : 1);
