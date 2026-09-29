<?php
// ============================================================
//  SHARED/SCHEMA.PHP
//  Ask the live database what it actually has.
//
//  Why this exists
//  ---------------
//  The app is deployed to a server whose database is not ours to
//  control, and code reaches the server faster than migrations do.
//  A SELECT that names a column a migration has not added yet is
//  not a warning - PDO is in ERRMODE_EXCEPTION, so it is an
//  uncaught PDOException and the page answers 500 with a blank
//  body, because display_errors is off in production. The reason
//  is in logs/php_errors.log and nowhere the user can reach it.
//
//  The failure is also oddly selective, which is what makes it
//  expensive to diagnose: registrar/documents.php is the only page
//  that SELECTs document_catalog.sla_days, so it is the only page
//  that dies while the archive, the intake form and the public
//  verification card all keep working. "The documents page is
//  broken" and "the database is three migrations behind" look like
//  completely different problems until you diff the SELECT lists.
//
//  What this buys
//  --------------
//  Callers can build a query out of what the server really has, so
//  a missing column degrades one feature (no turnaround target on
//  the clock) instead of taking a whole page down. The proper fix
//  is still to run migrations/*.sql - this is the difference
//  between a desk that works with one number missing and a desk
//  that does not load at all.
// ============================================================

if (defined('SCHEMA_LOADED')) {
    return;
}
define('SCHEMA_LOADED', true);

require_once __DIR__ . '/database.php';

/**
 * Does this table exist on the server we are talking to?
 *
 * Fail-open by design. If the probe itself fails - no permission to
 * read information_schema, a connection hiccup - the honest answer
 * is "assume it is there", because assuming it is MISSING would
 * silently disable a feature, while assuming it is PRESENT only
 * reproduces the behaviour we already had.
 *
 * Results are cached per request: a page that checks four optional
 * columns costs one query, not four.
 */
function db_table_exists(string $table): bool
{
    static $cache = [];

    $key = strtolower($table);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $db  = Database::getInstance();
        $n   = (int) $db->fetchColumn(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
        $cache[$key] = $n > 0;
    } catch (Throwable $e) {
        error_log('[schema] table probe failed for ' . $table . ': ' . $e->getMessage());
        $cache[$key] = true;
    }

    return $cache[$key];
}

/**
 * Does this column exist on this table?
 *
 * Same fail-open rule and same per-request cache as db_table_exists();
 * keyed by table AND column, because two different tables are probed
 * for the same optional name in the course of one request.
 */
function db_column_exists(string $table, string $column): bool
{
    static $cache = [];

    $key = strtolower($table) . '.' . strtolower($column);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $db = Database::getInstance();
        $n  = (int) $db->fetchColumn(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND COLUMN_NAME = ?',
            [$table, $column]
        );
        $cache[$key] = $n > 0;
    } catch (Throwable $e) {
        error_log('[schema] column probe failed for ' . $key . ': ' . $e->getMessage());
        $cache[$key] = true;
    }

    return $cache[$key];
}

/**
 * A SELECT fragment for a column that may not exist yet.
 *
 * Returns `<alias>.<column>` when the server has it and `NULL AS <column>`
 * when it does not, so the row shape - and therefore every caller, every
 * helper and every `?? $row['x']` downstream - is identical either way.
 * The alternative, omitting the key, makes a missing column an
 * "undefined array key" warning in every reader that touches it, which is
 * a worse failure than the one being fixed.
 *
 * The qualification is the ALIAS, and that is not a style preference. Once
 * a table is aliased, MySQL resolves the alias and nothing else:
 *
 *     FROM document_requests dr LEFT JOIN document_catalog c ON c.id = ...
 *     SELECT document_catalog.requirement   ->  1054 Unknown column
 *     SELECT c.requirement                  ->  fine
 *
 * so a helper that qualifies by table name produces a query that fails on
 * EVERY server, including one that has the column. The table name is the
 * lookup key; the alias is what the query has to say.
 *
 * Used only for columns a caller already treats as optional: a missing SLA
 * target or requirement note is a gap, a missing student name is a bug.
 */
function db_optional_column(string $table, string $column, string $alias = ''): string
{
    if (db_column_exists($table, $column)) {
        return ($alias !== '' ? $alias : $table) . '.' . $column;
    }
    return 'NULL AS ' . $column;
}

/**
 * Guarantee the optional keys exist on rows fetched with `SELECT *`.
 *
 * `SELECT *` cannot be made tolerant the way a named projection can: it
 * returns whatever the server happens to have, so on a server missing a
 * column the key is simply ABSENT from every row. Callers that then read
 * `$row['requirement']` directly get an "Undefined array key" warning -
 * one per row, on a list page, which is the kind of noise that makes a
 * real warning impossible to spot.
 *
 * This puts the missing keys back as null, so "the column is not there"
 * and "the column is empty" are the same thing to every reader. That is
 * the honest reading for a column like `requirement`: a document that
 * names no requirement is the normal case, not a defect.
 *
 * @param mixed  $rows    Rows from fetchAll(), or a single row from fetchOne().
 * @param string $table   Table they came from.
 * @param array  $columns Optional column names to guarantee.
 * @return mixed          The same rows, with the keys present.
 */
function db_fill_optional($rows, string $table, array $columns)
{
    $missing = [];
    foreach ($columns as $column) {
        if (!db_column_exists($table, $column)) {
            $missing[$column] = null;
        }
    }
    if (!$missing || !is_array($rows) || $rows === []) {
        return $rows;
    }

    // A single associative row, not a list of them: fetchOne() on no match
    // returns false, and on a hit returns a row with string keys.
    if (!isset($rows[0])) {
        return $rows + $missing;
    }

    foreach ($rows as $i => $row) {
        if (is_array($row)) {
            $rows[$i] = $row + $missing;
        }
    }
    return $rows;
}
