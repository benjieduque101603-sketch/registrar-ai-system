<?php
// Shared by dump_freshness.php and hosting_import_check.php.
//
// Lives in tests/ rather than shared/ because nothing in the application uses
// it: it reads the install dump as TEXT and has no business being loadable
// from a web request.

/**
 * How many users rows the dump's staff INSERT actually contains.
 *
 * Read out of the file rather than asserted as a literal, so a test can
 * describe the seed instead of restating it. Both callers once hardcoded 4,
 * which meant every account added or removed required the expectation to be
 * edited by hand to match - and the failure message would then read "want 4"
 * to whoever had to make sense of it, which says nothing.
 *
 * Counted from the file, never from the imported database: that database is
 * the thing being checked, so it cannot also be the source of the expectation.
 */
function countSeededStaffRows(string $dumpPath): int
{
    $sql = (string) file_get_contents($dumpPath);
    // Everything from the INSERT to the end of that statement.
    if (!preg_match('/INSERT\s+INTO\s+`users`.*?;/is', $sql, $m)) { return 0; }
    // Each seeded row is a parenthesised tuple starting a line.
    return preg_match_all('/^\(\d+\s*,/m', $m[0]);
}

/**
 * How many tables the dump declares.
 *
 * Read from the file, for the same reason countSeededStaffRows reads rather
 * than asserts: a literal here stops being true the moment a table is added or
 * dropped, and then the failure message misleads whoever has to act on it.
 */
function countDumpTables(string $dumpPath): int
{
    $sql = (string) file_get_contents($dumpPath);
    return preg_match_all('/^CREATE TABLE `([a-z_][a-z0-9_]*)`/m', $sql);
}