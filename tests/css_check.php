<?php
// Catch unbalanced braces in a CSS file.
//
//   php tests/css_check.php [css/status-tracker.css]
//
// A missing brace does not throw a parse error in a browser. It swallows
// every rule after it into the unterminated block, so the selectors still
// look present in the file while none of their declarations apply. That is
// exactly what happened here: an unclosed .st-rail-head swallowed the
// whole rail, and both the work queue and the status list rendered with no
// layout at all while the stylesheet still read correctly by eye.
//
// Counting braces is crude, and it is the point: it is the cheapest check
// that would have caught this before it shipped.

$file = $argv[1] ?? __DIR__ . '/../css/status-tracker.css';
if (!is_file($file)) {
    fwrite(STDERR, "  no such file: $file\n");
    exit(1);
}
$src = file_get_contents($file);
if ($src === false) {
    fwrite(STDERR, "  cannot read: $file\n");
    exit(1);
}

// Strip comments and string literals before counting, so a brace inside
// them cannot disguise an imbalance or invent one.
//
// Line numbers are reported against the ORIGINAL file, not the stripped
// copy. Replacing a comment with nothing shifts every line after it, so
// counting the stripped text reports positions that do not exist in the
// file being debugged. Each line is therefore blanked rather than
// removed, which preserves every line number.
$lines = preg_split('/\R/', $src);
$clean = [];
foreach ($lines as $i => $line) {
    $line = preg_replace('#/\*.*?\*/#s', '', $line);
    $line = preg_replace('#"(?:\\\\.|[^"\\\\])*"#s', '""', $line);
    $line = preg_replace("#'(?:\\\\.|[^'\\\\])*'#s", "''", $line);
    $clean[$i] = $line;
}

$depth    = 0;
$stack    = [];
$errors   = 0;

foreach ($clean as $idx => $line) {
    $lineNo = $idx + 1;
    $chars  = str_split($line);
    foreach ($chars as $ch) {
        if ($ch === '{') {
            $stack[] = $lineNo;
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth < 0) {
                printf("  FAIL  line %d: a closing brace with no opener\n", $lineNo);
                $errors++;
                $depth = 0;
                $stack = [];
            }
        }
    }
}

if ($depth !== 0) {
    printf("  FAIL  %d block(s) never closed; last opened at line %d\n",
        $depth, $stack[count($stack) - 1] ?? 0);
    $errors++;
}

if ($errors === 0) {
    printf("  ok    %s is balanced\n", basename($file));
}

// Every selector in the rail must resolve, which is the specific thing
// that was silently broken. Checked by name rather than by eye because the
// eye read this file as correct.
// A selector mapped to false is one that was deliberately removed; the
// rest must all resolve. The glyph tile is gone because the composition
// bar now carries the status colour at a size worth reading, and the key
// rows use a chip instead of repeating it 26px at a time.
$required = [
    '.st-rail', '.st-rail-sec', '.st-rail-head', '.st-rail-list', '.st-rail-item',
    '.st-rail-clear', '.st-rail-flist', '.st-rail-f', '.st-head', '.st-desk-grid',
    '.st-rail-glyph' => false,
    '.st-rail-empty' => false,   // replaced by dimmed rows; the footnote is gone
    '.st-rail-chip', '.st-rail-fpct', '.st-rail-fnum',
    '.st-comp', '.st-comp-bar', '.st-comp-seg', '.st-comp-figure',
];
if (basename($file) === 'status-tracker.css') {
    foreach ($required as $sel => $mustExist) {
        $present = strpos($src, $sel) !== false;
        if ($mustExist === false) {
            // Deliberately removed. Still reported, so that resurrecting one
            // by accident is visible rather than silent.
            if ($present) {
                printf("  note  %s is back, but was marked removed\n", $sel);
            }
            continue;
        }
        if (!$present) {
            printf("  FAIL  selector %s is absent\n", $sel);
            $errors++;
        }
    }
}

printf("\n  %d failed\n", $errors);
exit($errors === 0 ? 0 : 1);
