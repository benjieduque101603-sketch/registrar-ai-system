<?php
// ============================================================
//  FOOTER.PHP  (includes/)
//  Reusable page footer with scripts and closing tags.
//  Include this at the bottom of every page.
//
//  Usage:
//    include 'includes/footer.php';
// ============================================================

// Add any page-specific scripts
$page_scripts = $page_scripts ?? [];
?>
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Common Scripts -->
    <script src="<?= $APP_ROOT ?>js/sidebar.js"></script>
    <script src="<?= $APP_ROOT ?>js/auth.js"></script>
    <script src="<?= $APP_ROOT ?>js/logout.js"></script>
    <script src="<?= $APP_ROOT ?>js/searchable-select.js"></script>

    <!-- Idle session warning (auto logout with grace period) -->
    <script>
        window.BcpSessionTimeout = <?= defined('SESSION_IDLE_TIMEOUT') ? (int) SESSION_IDLE_TIMEOUT : 1200 ?>;
        window.BcpAppRoot = "<?= isset($APP_ROOT) ? addslashes((string) $APP_ROOT) : './' ?>";
    </script>
    <script src="<?= $APP_ROOT ?>js/session-warning.js"></script>

    <!-- Chart.js (if needed) -->
    <?php if (isset($use_chart) && $use_chart === true): ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <?php endif; ?>

    <!-- Page-specific scripts -->
    <?php foreach ($page_scripts as $script): ?>
        <?php
        // Cache-bust from the file's mtime. Without this, js/queue.js is served
        // with Cache-Control: max-age=2592000 (30 days) and a browser that
        // loaded it before a fix keeps running the old code for a month - the
        // wall displays showed the pre-fix "Network error" long after the fix
        // was deployed, and the only way through was a manual hard refresh.
        // Appending the mtime makes each deploy a new URL, so the normal
        // refresh picks up the change. filemtime() is cached per-request with
        // static so a page that loads the same file twice only stats it once.
        static $_scriptMtimeCache = [];
        $_scriptPath = __DIR__ . '/../js/' . $script;
        if (!array_key_exists($script, $_scriptMtimeCache)) {
            $_scriptMtimeCache[$script] = is_file($_scriptPath) ? (int) filemtime($_scriptPath) : 0;
        }
        $_scriptVer = $_scriptMtimeCache[$script] > 0 ? '?v=' . $_scriptMtimeCache[$script] : '';
        ?>
        <script src="<?= $APP_ROOT ?>js/<?= $script ?><?= $_scriptVer ?>"></script>
    <?php endforeach; ?>

    <!-- Custom page scripts -->
    <?php if (isset($inline_scripts)): ?>
        <script>
            <?= $inline_scripts ?>
        </script>
    <?php endif; ?>

    <!-- Student AI chat widget (student role only) -->
    <?php if (($_SESSION['role'] ?? '') === 'student'): ?>
        <?php include __DIR__ . '/student-chat.php'; ?>
    <?php endif; ?>

</body>
</html>
<!-- Footer Links (added before closing body) -->
<style>
    .page-footer-links {
        text-align: center;
        padding: 20px;
        border-top: 1px solid #e2e8f0;
        font-size: 12px;
        color: #64748b;
        margin-top: 40px;
    }
    .page-footer-links a {
        color: #2563eb;
        text-decoration: none;
        margin: 0 8px;
    }
    .page-footer-links a:hover {
        text-decoration: underline;
    }
</style>
