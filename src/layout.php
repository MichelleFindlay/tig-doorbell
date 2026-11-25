<?php
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }


/** @param string $root relative path from the current page to the public root, e.g. '' or '../' */
function page_start(string $title, string $root = '', string $extraHead = ''): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');

    $GLOBALS['TIG_PAGE_ROOT'] = $root; // used by page_end()
    $favicon = asset_url((string) branding('favicon', ''), $root) ?? $root . 'assets/icon.svg';
    $mode = theme_mode();
    $bodyClass = branding('show_icons', true) ? '' : 'no-icons';
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php if ($mode === 'auto'): ?>
<meta name="theme-color" content="<?= h(theme_bar_colour('light')) ?>" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="<?= h(theme_bar_colour('dark')) ?>" media="(prefers-color-scheme: dark)">
<?php else: ?>
<meta name="theme-color" content="<?= h(theme_bar_colour($mode)) ?>">
<?php endif; ?>
<title><?= h($title) ?></title>
<link rel="stylesheet" href="<?= $root ?>assets/style.css?v=<?= asset_version('assets/style.css') ?>">
<style><?= theme_css() ?></style>
<?= theme_head_script() ?>
<link rel="icon" href="<?= h($favicon) ?>">
<link rel="apple-touch-icon" href="<?= $root ?>assets/icon-180.png">
<link rel="manifest" href="<?= $root ?>manifest.php">
<?= $extraHead ?>
</head>
<body class="<?= $bodyClass ?>">
<?= flag_stripe_html() ?>
<?= theme_toggle_html('corner') ?>
<main class="wrap">
<?= logo_html($root) ?>
<?php
}

/** Changes whenever the file changes, so browsers fetch the new version instead of a cached one. */
function asset_version(string $file): string
{
    return (string) (@filemtime(dirname(__DIR__) . '/' . $file) ?: '1');
}

const APP_VERSION = '1.0';
const APP_REPO_URL = 'https://github.com/MichelleFindlay/tig-doorbell';

function page_end(): void
{
    echo '<footer class="site-footer muted">Version ' . APP_VERSION
        . ' · <a href="' . APP_REPO_URL . '" target="_blank" rel="noopener">Source on GitHub</a>'
        . "</footer>\n";
    if (theme_toggle_enabled()) {
        $root = $GLOBALS['TIG_PAGE_ROOT'] ?? '';
        echo '<script src="' . $root . 'assets/theme.js?v=' . asset_version('assets/theme.js') . '"></script>' . "\n";
    }
    echo "</main>\n</body>\n</html>\n";
}

/** Username / display name / password fields shared by setup and team pages. */
function organiser_fields(string $username, string $displayName): void
{
    ?>
    <label for="username">Username <span class="muted">(for logging in)</span></label>
    <input id="username" name="username" type="text" required autocomplete="off" autocapitalize="none"
           pattern="[A-Za-z0-9._\-]{2,40}" value="<?= h($username) ?>" placeholder="alex">
    <label for="display_name">Display name <span class="muted">(shown to visitors)</span></label>
    <input id="display_name" name="display_name" type="text" required maxlength="40" value="<?= h($displayName) ?>" placeholder="Alex">
    <label for="password">Password <span class="muted">(8+ characters)</span></label>
    <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password">
    <label for="password2">Confirm password</label>
    <input id="password2" name="password2" type="password" required minlength="8" autocomplete="new-password">
    <?php
}

/**
 * Menu bar shown at the top of every logged-in organiser page.
 * @param string $active one of 'door', 'events', 'team'
 */
function organiser_nav(array $me, string $active): void
{
    $links = ['door' => ['./', '🚪 Door'], 'events' => ['events.php', '📅 Events'], 'team' => ['team.php', '👥 Team']];
    ?>
    <header class="orgnav">
        <div class="orgnav-who">
            <strong><?= h(app_name()) ?></strong>
            <span class="muted">· <?= h($me['display_name']) ?></span>
        </div>
        <nav class="orgnav-links" aria-label="Organiser menu">
            <?php foreach ($links as $key => [$href, $label]): ?>
                <a href="<?= h($href) ?>" class="<?= $key === $active ? 'active' : '' ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
            <?php endforeach; ?>
            <?= theme_toggle_html() ?>
            <form method="post" action="logout.php" id="logoutForm" class="inline">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <button type="submit" class="logout">Log out</button>
            </form>
        </nav>
    </header>
    <script>
    // Logging out stops door alerts on this device: confirm, then drop the browser's push subscription.
    document.getElementById('logoutForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!confirm('Log out? This device will stop getting door alerts until you log in again.')) return;
        try {
            const reg = navigator.serviceWorker && await navigator.serviceWorker.getRegistration(new URL('../', location.href).pathname);
            const sub = reg && await reg.pushManager.getSubscription();
            if (sub) await sub.unsubscribe();
        } catch (_) { /* the server removes this device's subscriptions anyway */ }
        e.target.submit();
    });
    </script>
    <?php
}
