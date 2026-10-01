<?php
// shared/config.php - Adapted for your database

if (defined('CONFIG_LOADED')) {
    return;
}
define('CONFIG_LOADED', true);

// Database Configuration
// Database settings can be overridden per environment via env vars:
//   DB_HOST, DB_PORT, DB_USER, DB_PASSWORD, DB_CHARSET, DB_NAME
// PaaS platforms often use the Laravel-style names instead, so those are
// accepted as fallbacks: DB_DATABASE (→ DB_NAME), DB_USERNAME (→ DB_USER).
$host = env('DB_HOST') ?: 'localhost';
$user = env('DB_USER') ?: 'root';
// Accept DB_PASSWORD (docker-compose / PaaS standard) and DB_PASS (legacy).
$pass = env('DB_PASSWORD') ?: env('DB_PASS') ?: '';
$db   = env('DB_NAME') ?: 'registrar_ai';
$port = (int)(env('DB_PORT') ?: 3306);
$charset = env('DB_CHARSET') ?: 'utf8mb4';

// Constants used by shared/database.php (PDO layer)
define('DB_HOST', $host);
define('DB_USER', $user);
define('DB_PASS', $pass);
define('DB_PASSWORD', $pass);   // alias — database.php uses DB_PASSWORD
define('DB_NAME', $db);
define('DB_PORT', $port);
define('DB_CHARSET', $charset);

// Legacy mysqli connection ($conn) — used by seed.php and some helpers.
// The main app uses PDO via shared/database.php, so this is optional.
// If the mysqli extension is not installed the app still works; only
// seed.php and any legacy code that touches $conn directly will fail.
$conn = null;
if (extension_loaded('mysqli')) {
    $conn = @new mysqli($host, $user, $pass, $db, $port);
    if ($conn->connect_error) {
        error_log('[config.php] mysqli connect error: ' . $conn->connect_error);
        $conn = null;
    }
} else {
    error_log('[config.php] mysqli extension not loaded — PDO-only mode.');
}

// Application Configuration
define('APP_NAME', 'BCP Registrar System');
define('APP_VERSION', '1.0.0');
// Environment: 'production' or 'development'.
// DEFAULT IS PRODUCTION (fail closed). This default used to be
// 'development', which meant a host that forgot to set APP_ENV silently
// ran in the permissive mode — most visibly, it turned the on-screen OTP
// fallback ON, handing a password-reset code straight back in the JSON
// response. A security-relevant default must be the strict one.
//
// Override with APP_ENV in the environment for local development.
define('APP_ENV', getenv('APP_ENV') ?: 'production');
define('APP_ROOT', dirname(__DIR__) . '/');

// app_base_path() and app_url() live in shared/app_path.php so that a public
// page can work out where the app is mounted without pulling in this file and
// opening a mysqli connection it has no use for (the queue kiosk and monitor
// are exactly that). The functions are guarded against redeclaration there, so
// including both is safe and order does not matter.
require_once __DIR__ . '/app_path.php';

// Idle session timeout in seconds. Users are logged out after this long
// with no activity (default 20 minutes). Override with the
// SESSION_IDLE_TIMEOUT env var, or a shared/session_timeout.local file
// (gitignored). Set to 0 to disable idle logout entirely.
$__sessionTimeout = getenv('SESSION_IDLE_TIMEOUT');
if ($__sessionTimeout === false || $__sessionTimeout === '') {
    $__sessionTimeoutFile = __DIR__ . '/session_timeout.local';
    if (is_file($__sessionTimeoutFile)) {
        $__sessionTimeout = trim((string) file_get_contents($__sessionTimeoutFile));
    }
}
define('SESSION_IDLE_TIMEOUT', $__sessionTimeout !== false && $__sessionTimeout !== '' ? max(0, (int) $__sessionTimeout) : 20 * 60);

/**
 * CORS headers for session/cookie-authenticated endpoints.
 *
 * These API endpoints authenticate via cookies, so a wildcard
 * Access-Control-Allow-Origin would let any site read/trigger them
 * cross-origin. Instead we only echo back a request's Origin when it
 * matches this app's own host (allowed in development where the app
 * may be served from a different port/device). Any other origin gets
 * NO Access-Control-Allow-Origin, which blocks the cross-origin
 * browser preflight/read entirely while same-origin calls keep working.
 *
 * Public endpoints that genuinely need wide CORS (queue kiosk/monitor,
 * mock services) do not use this and keep their explicit wildcard.
 */
function corsSameOrigin(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $allowed = parse_url($origin, PHP_URL_HOST);
        $selfHost   = parse_url(app_url('/'), PHP_URL_HOST);
        $scriptHost = parse_url((string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
        $ok = ($allowed !== null && $allowed === $selfHost)
            || ($allowed !== null && $scriptHost !== null && $allowed === $scriptHost)
            || isset($_SERVER['SERVER_ADDR']) && $allowed === $_SERVER['SERVER_ADDR'];
        // Localhost / dev-loopback origins (http://localhost, http://127.0.0.1)
        // are always allowed so local front-ends can call the API cross-port.
        $loopback = in_array($allowed, ['localhost', '127.0.0.1', '::1'], true);
        if ($ok || $loopback) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Credentials: true');
        }
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Authorization');
    header('Access-Control-Max-Age: 3600');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// Error reporting
error_reporting(E_ALL);
// Only show errors on screen in development; log them everywhere else.
// Set APP_ENV to 'production' before going live.
ini_set('display_errors', APP_ENV === 'production' ? 0 : 1);
ini_set('display_startup_errors', APP_ENV === 'production' ? 0 : 1);
ini_set('log_errors', 1);
ini_set('error_log', APP_ROOT . 'logs/php_errors.log');

/** Maximum enrollees per section when auto-generating masterlists (e.g. BSIT 11001 = 50). Section codes are [year][semester][number], e.g. 11001 = yr 1 sem 1 section 1. */
define('MAX_STUDENTS_PER_SECTION', 50);

// Logs directory — used by shared/functions.php logError() / logApiRequest()
define('LOGS_PATH', APP_ROOT . 'logs/');

// Allowed file extensions for uploads — used by shared/functions.php isAllowedFile()
define('ALLOWED_FILE_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx']);

// Security
// Secrets must come from the environment (or a git-ignored local file).
// In production we FAIL CLOSED: a missing secret aborts startup loudly
// instead of silently falling back to a public, filesystem-known value.
// In development we keep the documented defaults so the app still boots
// locally (these must never be treated as safe for a live deployment).
function secretFromEnvOrLocal(string $env, string $defaultDev): string {
    $v = getenv($env);
    if ($v !== false && $v !== '') {
        return $v;
    }
    $file = __DIR__ . '/secrets.local';
    if (is_file($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach (is_array($lines) ? $lines : [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            [$k, $val] = array_pad(explode('=', $line, 2), 2, '');
            if (trim($k) === $env && trim($val) !== '') {
                return trim($val);
            }
        }
    }
    if (APP_ENV === 'production') {
        // Fail closed for anything that serves HTTP: a web request must
        // never run on an insecure default. This guard is deliberate and
        // stays.
        //
        // CLI invocations are exempt. Maintenance and test scripts
        // (php scripts/purge_fake_emails.php, the PHPUnit suite, the
        // migration dry-runs) boot this file in a terminal, where an
        // exit(1) would abort the tool rather than expose a secret to a
        // browser. They also legitimately run on a developer machine that
        // has no production secrets configured.
        $isWebRequest = PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg';
        if ($isWebRequest) {
            error_log("[config] FAILING CLOSED: $env is not set for production. Refusing to run with an insecure default.");
            http_response_code(500);
            header('Content-Type: text/plain');
            echo "Server configuration error: $env is not set. Set it before going live.";
            exit;
        }
        error_log("[config] WARNING: $env is unset; using the development default for this CLI run. Never serve HTTP in this state.");
    }
    return $defaultDev;
}
define('JWT_SECRET', secretFromEnvOrLocal('JWT_SECRET', 'your-super-secret-key-change-in-production'));
// Public RFID kiosk access token (overridable per environment). Set
// KIOSK_ACCESS_TOKEN on live stations; do not rely on the default.
define('KIOSK_ACCESS_TOKEN', secretFromEnvOrLocal('KIOSK_ACCESS_TOKEN', 'kiosk-tap-2024'));

// ── AI Configuration ───────────────────────────────────────────────────────────
// Default: OpenRouter (OpenAI-compatible API gateway)
//   - Get your API key from https://openrouter.ai/keys
//   - Key format: sk-or-v1-...
//   - Models use provider prefix: openai/gpt-4o, anthropic/claude-3-opus, etc.
//     Do NOT also prefix "openrouter/" — the API rejects that form (400).
//
// Environment variables:
//   OPENROUTER_API_KEY or AI_API_KEY - your OpenRouter API key
//     (or paste the key into the git-ignored shared/ai_key.local file)
//   AI_MODEL - model to use (default: qwen/qwen3.8-27b:free; NO "openrouter/" prefix)
//   AI_API_URL - override URL if needed (default: https://openrouter.ai/api/v1/chat/completions)

$aiProvider    = env('AI_PROVIDER') ?: 'openrouter';
$aiApiUrl      = env('AI_API_URL') ?: 'https://openrouter.ai/api/v1/chat/completions';
$aiApiKey      = '';
$aiGeminiModel = env('GEMINI_MODEL') ?: env('AI_GEMINI_MODEL') ?: 'gemini-2.0-flash';
$aiModel       = env('AI_MODEL') ?: ($aiProvider === 'gemini' ? $aiGeminiModel : 'qwen/qwen3.8-27b:free');
$aiCacheTtl    = (int) (env('AI_CACHE_TTL') ?: 3600);   // seconds

// Optional OpenRouter (or gateway) failover chain, comma-separated:
//   AI_MODELS="openai/gpt-4o-mini,google/gemini-2.0-flash-001"
// ai_client.php walks this list in order when a model fails, so one
// overloaded model never takes the AI Insight report down with it.
$aiModels    = [];
$aiModelsEnv = trim((string) (env('AI_MODELS') ?: ''));
if ($aiModelsEnv !== '') {
    foreach (explode(',', $aiModelsEnv) as $m) {
        $m = trim($m);
        if ($m !== '') $aiModels[] = $m;
    }
}
if (empty($aiModels)) {
    // ponytail: env AI_MODELS overrides this list entirely.
    $aiModels = [
        $aiModel,
        'nvidia/nemotron-3-ultra-550b-a55b:free',
        'z-ai/glm-5.2:free',
        'google/gemma-4-31b-it:free',
        'inclusionai/ling-3.0-flash-sante:free',
        'nvidia/nemotron-3-super-120b-a12b:free',
    ];
}
if (!in_array($aiModel, $aiModels, true)) {
    array_unshift($aiModels, $aiModel);
}

// Load API key from env or local file
$aiApiKey = env('OPENROUTER_API_KEY') ?: env('AI_API_KEY') ?: '';
if ($aiApiKey === '' && is_file(__DIR__ . '/ai_key.local')) {
    $aiApiKey = trim((string) file_get_contents(__DIR__ . '/ai_key.local'));
}

// Gemini provider key (used only when AI_PROVIDER=gemini). Read from
// GEMINI_API_KEY (Google's official env name) first, then AI_GEMINI_API_KEY,
// then the same git-ignored shared/ai_key.local fallback.
$aiGeminiKey = env('GEMINI_API_KEY') ?: env('AI_GEMINI_API_KEY') ?: '';
if ($aiGeminiKey === '' && is_file(__DIR__ . '/ai_key.local')) {
    $aiGeminiKey = trim((string) file_get_contents(__DIR__ . '/ai_key.local'));
}

define('AI_PROVIDER',     $aiProvider);
define('AI_API_URL',      $aiApiUrl);
define('AI_API_KEY',      $aiApiKey);
define('AI_GEMINI_API_KEY', $aiGeminiKey);
define('AI_GEMINI_MODEL',  $aiGeminiModel);
define('AI_MODEL',        $aiModel);
define('AI_MODELS',       $aiModels);
define('AI_CACHE_TTL',    $aiCacheTtl);
define('AI_CACHE_ENABLED', true);

// ── PayMongo (real GCash payments, sandbox/test mode) ─────────
// Empty secret key ⇒ the document-request payment gateway stays on
// the built-in mock (the demo works without any keys). Keys are
// read from env vars first, then from a git-ignored KEY=VALUE file
// shared/paymongo_secret.local (see .gitignore *.local rule):
//   PAYMONGO_SECRET_KEY=sk_test_...
//   PAYMONGO_PUBLIC_KEY=pk_test_...
//   PAYMONGO_WEBHOOK_SECRET=whsec_...
function paymongoSecretFromLocal(string $key): string {
    static $parsed = null;
    if ($parsed === null) {
        $parsed = [];
        $file = __DIR__ . '/paymongo_secret.local';
        if (is_file($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach (is_array($lines) ? $lines : [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                $pair = array_pad(explode('=', $line, 2), 2, '');
                $parsed[trim($pair[0])] = trim($pair[1]);
            }
        }
    }
    return $parsed[$key] ?? '';
}
define('PAYMONGO_SECRET_KEY',    getenv('PAYMONGO_SECRET_KEY') ?: paymongoSecretFromLocal('PAYMONGO_SECRET_KEY'));
define('PAYMONGO_PUBLIC_KEY',    getenv('PAYMONGO_PUBLIC_KEY') ?: paymongoSecretFromLocal('PAYMONGO_PUBLIC_KEY'));
define('PAYMONGO_WEBHOOK_SECRET',getenv('PAYMONGO_WEBHOOK_SECRET') ?: paymongoSecretFromLocal('PAYMONGO_WEBHOOK_SECRET'));
define('PAYMONGO_API_BASE', rtrim((string) getenv('PAYMONGO_API_BASE') ?: 'https://api.paymongo.com', '/'));

// ── Email (SMTP) — Gmail App Password transport ────────────────
// Used by the Emergency & Contacts module (shared/mail_client.php)
// to deliver verification, invoice, grade-snapshot, transcript and
// emergency-blast emails. Read from env vars first, then from the
// git-ignored shared/email_secret.local (KEY=VALUE lines, same layout
// as paymongo_secret.local):
//   SMTP_HOST=smtp.gmail.com
//   SMTP_PORT=587
//   SMTP_USER=you@gmail.com
//   SMTP_PASS=xxxx xxxx xxxx xxxx   (Gmail App Password, not the login password)
//   MAIL_FROM=you@gmail.com
//   MAIL_FROM_NAME=BCP Registrar System
// Missing credentials ⇒ EMAIL_CONFIGURED=false and every sender no-ops
// (the app keeps working, exactly like the PayMongo mock fallback).
//
// Env var lookup: shared hosting (cPanel, PHP-FPM, suPHP) often hides
// env vars from getenv(). This helper checks getenv(), $_ENV, $_SERVER,
// and apache_getenv() — whichever one the host exposes.
function env(string $key, ?string $default = null): ?string {
    // 1) getenv() (CGI / CLI / some FPM setups)
    if (function_exists('getenv')) {
        $v = getenv($key);
        if ($v !== false && $v !== '') return $v;
    }
    // 2) $_ENV (php.ini: variables_order must include 'E')
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string) $_ENV[$key];
    // 3) $_SERVER (cPanel SetEnv, .htaccess SetEnv, some FPM fastcgi_param)
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return (string) $_SERVER[$key];
    // 4) apache_getenv() (mod_php only)
    if (function_exists('apache_getenv')) {
        $v = apache_getenv($key, true);
        if ($v !== false && $v !== '') return $v;
    }
    return $default;
}

function emailSecretFromLocal(string $key): string {
    static $parsed = null;
    if ($parsed === null) {
        $parsed = [];
        $file = __DIR__ . '/email_secret.local';
        if (is_file($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach (is_array($lines) ? $lines : [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                $pair = array_pad(explode('=', $line, 2), 2, '');
                $parsed[trim($pair[0])] = trim($pair[1]);
            }
        }
    }
    return $parsed[$key] ?? '';
}
define('SMTP_HOST',     env('SMTP_HOST') ?: emailSecretFromLocal('SMTP_HOST'));
define('SMTP_PORT',     (int) (env('SMTP_PORT') ?: emailSecretFromLocal('SMTP_PORT')) ?: 587);
define('SMTP_USER',     env('SMTP_USER') ?: emailSecretFromLocal('SMTP_USER'));
define('SMTP_PASS',     env('SMTP_PASS') ?: emailSecretFromLocal('SMTP_PASS'));
define('MAIL_FROM',     env('MAIL_FROM') ?: emailSecretFromLocal('MAIL_FROM'));
define('MAIL_FROM_NAME',env('MAIL_FROM_NAME') ?: emailSecretFromLocal('MAIL_FROM_NAME'));

// ── Gmail API (OAuth2) — preferred over SMTP for free Gmail accounts ─
// When GMAIL_API_CLIENT_ID + GMAIL_API_CLIENT_SECRET + GMAIL_REFRESH_TOKEN
// are all set, emails are sent via Gmail API (no SMTP bounce issues).
// Get these from Google Cloud Console → APIs & Services → Credentials.
define('GMAIL_API_CLIENT_ID',     env('GMAIL_API_CLIENT_ID') ?: emailSecretFromLocal('GMAIL_API_CLIENT_ID'));
define('GMAIL_API_CLIENT_SECRET', env('GMAIL_API_CLIENT_SECRET') ?: emailSecretFromLocal('GMAIL_API_CLIENT_SECRET'));
define('GMAIL_REFRESH_TOKEN',     env('GMAIL_REFRESH_TOKEN') ?: emailSecretFromLocal('GMAIL_REFRESH_TOKEN'));
define('GMAIL_SENDER_EMAIL',      env('GMAIL_SENDER_EMAIL') ?: emailSecretFromLocal('GMAIL_SENDER_EMAIL') ?: MAIL_FROM);

define('GMAIL_API_CONFIGURED', GMAIL_API_CLIENT_ID !== '' && GMAIL_API_CLIENT_SECRET !== '' && GMAIL_REFRESH_TOKEN !== '');

// ── Brevo (Sendinblue) transactional API — preferred for reliability ─
// Sign up at https://app.brevo.com → SMTP & API → API Keys → Generate.
// Verify your sender email in Brevo (one-click link, no DNS needed).
define('BREVO_API_KEY', env('BREVO_API_KEY') ?: emailSecretFromLocal('BREVO_API_KEY'));
define('BREVO_CONFIGURED', BREVO_API_KEY !== '');

// EMAIL_CONFIGURED = true when ANY transport is set up (Brevo > Gmail API > SMTP)
define('EMAIL_CONFIGURED', BREVO_CONFIGURED || GMAIL_API_CONFIGURED || (SMTP_HOST !== '' && SMTP_USER !== '' && SMTP_PASS !== ''));

// Timezone
date_default_timezone_set('Asia/Manila');

/**
 * Return a generic JSON error to the client and log the real details
 * server-side. Never expose exception messages (table/column names,
 * connection details) to the browser.
 */
function json_error(Throwable $e, string $prefix = 'Internal server error.'): never {
    error_log('[json_error] ' . $prefix . ' ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['success' => false, 'message' => $prefix]);
    exit;
}
?>
