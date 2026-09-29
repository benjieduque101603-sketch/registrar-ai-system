<?php
// ============================================================
//  SHARED/APP_PATH.PHP
//  Where is this application, as far as a browser is concerned?
//
//  Split out of config.php because it is the one piece of configuration a
//  PUBLIC page needs and config.php is not free: including config.php opens a
//  mysqli connection, which the queue kiosk and the queue monitor — two pages
//  that are loaded on a wall display all day and must render even when the
//  database is unreachable — have no reason to do.
//
//  Everything else stays in config.php. This file defines no database
//  constants, reads no secrets and touches no connection.
//
//  Why it matters: the depth of the app in the URL is a property of the
//  DEPLOYMENT, not of the code, and it is different in every environment:
//
//      localhost/registrar-ai-system/queue/monitor.php   -> /registrar-ai-system
//      registrar.bcpsms2.com/queue/monitor.php          -> (root)
//
//  Any client that works it out by counting slashes in window.location works
//  in one and 404s in the other. That is not a hypothetical: js/queue.js did
//  exactly that, so on the live site every call from the monitor and the
//  serving console went to /queue/api/queue.php and 404'd. The server knows
//  the answer; the client was guessing.
// ============================================================

if (defined('APP_PATH_LOADED')) {
    return;
}
define('APP_PATH_LOADED', true);

// APP_ROOT is the filesystem path to the app root, with a trailing slash.
// config.php defines the same constant; whichever is included first wins and
// both agree, so a page may include this file alone or config.php with it.
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__) . '/');
}

/**
 * Root-relative URL path to the application base, e.g. "/registrar-ai-system".
 * Used for Header('Location: ...') redirects so they resolve from any depth
 * (root pages, registrar/, student/, api/, ai/). Falls back to "/" when the
 * doc root mapping can't be inferred.
 */
if (!function_exists('app_base_path')) {
    function app_base_path(): string {
        static $base = null;
        if ($base !== null) {
            return $base;
        }
        $docRoot = rtrim(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
        $script  = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        // SCRIPT_NAME may already be root-relative; if DOCUMENT_ROOT is usable,
        // derive base by stripping the doc root off APP_ROOT.
        $fsBase = str_replace('\\', '/', APP_ROOT);            // …/htdocs/registrar-ai-system/
        // strncmp, not str_starts_with. This is the one place the app needs a
        // path test on every request that touches app_url(), and str_starts_with
        // is PHP 8.0+ - a fatal on 7.x, not a fallback. registrar/documents.php
        // calls app_url() at the top level of the page, so it was the only
        // registrar page that died on a PHP 7 host while the rest rendered fine.
        if ($docRoot !== '' && strncmp($fsBase, $docRoot, strlen($docRoot)) === 0) {
            $base = rtrim(substr($fsBase, strlen($docRoot)), '/'); // /registrar-ai-system
            return $base;
        }
        // Fallback: no usable DOCUMENT_ROOT (an alias, a docroot that is not
        // this app's parent, a FastCGI box that does not set it).
        //
        // This used to strip only the FILENAME off SCRIPT_NAME:
        //
        //     /registrar-ai-system/queue/monitor.php  ->  /registrar-ai-system/queue
        //
        // which is the directory of the page, not the base of the app. Every
        // caller that asked for app_url('/api') from a page one level down
        // then got /registrar-ai-system/queue/api — a 404 for a path that does
        // not exist. Root pages were the only ones that worked, so the bug was
        // invisible on login.php and fatal on every other page.
        //
        // The app's base is SCRIPT_NAME minus this page's own directory, so
        // both have to be counted. SCRIPT_FILENAME gives the page's directory
        // on disk; APP_ROOT is where the app starts, and the difference is
        // exactly how deep the page sits.
        if ($script !== '' && $script[0] === '/') {
            $parts = explode('/', trim($script, '/'));

            $fsApp  = rtrim(str_replace('\\', '/', APP_ROOT), '/');
            $fsPage = rtrim(str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? '')), '/');

            // How many directories this page sits BELOW the app root, which is
            // what has to come back off the front of SCRIPT_NAME.
            $below = 0;
            if ($fsPage !== '' && strncmp($fsPage, $fsApp . '/', strlen($fsApp) + 1) === 0) {
                $rel     = trim(substr($fsPage, strlen($fsApp) + 1), '/'); // e.g. queue/monitor.php
                $relDir  = trim(str_replace('\\', '/', dirname($rel)), './'); // e.g. queue
                $below   = $relDir === '' || $relDir === '.' ? 0 : substr_count($relDir, '/') + 1;
            }

            // 1 for the filename itself, plus one per directory below the root.
            $parts = array_slice($parts, 0, max(0, count($parts) - 1 - $below));
            $base  = $parts ? '/' . implode('/', $parts) : '';
            return $base;
        }
        return $base = '';
    }
}

/**
 * Root-relative URL to a resource, e.g. app_url('/login.php') → "/registrar-ai-system/login.php".
 */
if (!function_exists('app_url')) {
    function app_url(?string $path = null): string {
        $base = rtrim(app_base_path(), '/');
        if ($path === null || $path === '' || $path === '/') {
            return ($base === '' ? '/' : $base . '/');
        }
        $path = '/' . ltrim($path, '/');
        return $base . $path;
    }
}
