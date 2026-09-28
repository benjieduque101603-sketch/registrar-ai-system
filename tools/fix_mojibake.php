<?php
// Repair double-encoded characters in a text file.
//
//   php tools/fix_mojibake.php <file> [--apply]
//
// A handful of files in this project were saved once as Windows-1252 after
// being read as UTF-8. The result is that an em dash typed as three
// UTF-8 bytes came back as three characters, each itself UTF-8 encoded, so
// the file holds nine bytes where three were meant. It renders as "â€""
// on the page: a visible defect, not just untidy source.
//
// Only the specific known sequences are repaired. A blanket
// encode-and-re-decode is tempting and wrong: it also rewrites characters
// that were already correct, and anything outside the code page is lost
// rather than recovered. Here each pattern is listed with the bytes it
// should have been, so an unexpected sequence is left alone and reported.

$file = $argv[1] ?? '';
$apply = in_array('--apply', $argv, true);
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "usage: php tools/fix_mojibake.php <file> [--apply]\n");
    exit(1);
}

$src = file_get_contents($file);
if ($src === false) {
    fwrite(STDERR, "cannot read $file\n");
    exit(1);
}

// Broken sequence => what it was meant to be.
//
// The third character in the em-dash sequence is U+201D, not U+2122: the
// original E2 80 94 was read as Windows-1252, where byte 0x94 is a curly
// quote, so that is what it came back as. Getting this wrong silently
// repairs nothing and reports a clean file.
$fixes = [
    "\u{00E2}\u{20AC}\u{201D}"   => "\u{2014}", // mojibake em dash
    "\u{00E2}\u{20AC}\u{00A6}"   => "\u{2026}", // mojibake ellipsis
    "\u{00C2}\u{00B7}"           => "\u{00B7}", // mojibake middot
    "\u{00E2}\u{2020}\u{2019}"   => "\u{2019}", // mojibake right quote
    // Encoded twice on top of that, in the original file header.
    "\u{00C3}\u{00A2}\u{00E2}\u{201A}\u{00AC}\u{00E2}\u{20AC}\u{009D}" => "\u{2014}",
];

$before = $src;
$total  = 0;
foreach ($fixes as $bad => $good) {
    $n = substr_count($src, $bad);
    if ($n > 0) {
        $src = str_replace($bad, $good, $src);
        $total += $n;
        printf("  %2d x  %s -> %s\n", $n, $bad, $good);
    }
}

// A byte-order mark at the very start is not an error, but it is emitted
// as text if anything reads the file as a string rather than as bytes, and
// it is a reliable sign that a file has been through the wrong toolchain.
if (strncmp($src, "\xEF\xBB\xBF", 3) === 0) {
    $src = substr($src, 3);
    $total++;
    printf("   1 x  BOM removed\n");
}

if ($total === 0) {
    printf("  clean: %s\n", basename($file));
    exit(0);
}

if ($apply) {
    file_put_contents($file, $src);
    printf("  repaired %d sequence(s) in %s\n", $total, basename($file));
} else {
    printf("  %d sequence(s) would be repaired in %s (re-run with --apply)\n", $total, basename($file));
}

// Report anything still outside ASCII, so an unrecognised sequence is
// visible rather than assumed harmless. The /u matters: without it the
// pattern matches bytes rather than characters and the inventory is
// meaningless.
$rest = preg_match_all('/[^\x00-\x7F]/u', $src, $m);
if ($rest) {
    printf("  %d non-ascii character(s) remain:\n", $rest);
    foreach (array_count_values($m[0]) as $ch => $n) {
        printf("    U+%04X  x%d\n", mb_ord($ch), $n);
    }
}
exit($apply ? 0 : 2);
