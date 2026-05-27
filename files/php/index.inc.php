<?php
if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
    exit(0);
}

// Cookies the proxy itself owns (settings), excluded from the user-facing list
$_proxy_settings_cookies = ['flags', 'userAgent', 'PHPSESSID'];
$_visible_cookies = [];
foreach ($_COOKIE as $_k => $_v) {
    if (!in_array($_k, $_proxy_settings_cookies, true)) {
        $_visible_cookies[$_k] = $_v;
    }
}

$_current_ua = isset($_COOKIE['userAgent']) ? $_COOKIE['userAgent'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title><?php echo htmlspecialchars($GLOBALS['_config']['site_name']); ?></title>
    <link rel="stylesheet" href="./files/css/index.css"/>
    <script>
    (function () {
        var s = null;
        try { s = localStorage.getItem('phproxy-theme'); } catch (e) {}
        if (s === 'dark' || s === 'light') {
            document.documentElement.setAttribute('data-theme', s);
        }
    })();
    </script>
</head>
<body>
<div class="page">
    <header class="appbar">
        <h1><?php echo htmlspecialchars($GLOBALS['_config']['site_name']); ?><span class="dot">.</span></h1>
        <button type="button" class="theme-toggle" aria-label="Toggle colour theme" title="Toggle colour theme">
            <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="4"/>
                <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>
            </svg>
            <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
            </svg>
        </button>
    </header>

<?php if ($data['category'] != 'auth'): ?>
    <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
        <div class="card">
            <div class="form-row url-row">
                <input class="url-input" type="text" name="<?php echo htmlspecialchars($GLOBALS['_config']['url_var_name']) ?>" value="<?php echo isset($_GET[$GLOBALS['_config']['url_var_name']]) ? htmlspecialchars(decode_url($_GET[$GLOBALS['_config']['url_var_name']])) : (isset($_GET['__iv']) ? htmlspecialchars($_GET['__iv']) : ''); ?>" placeholder="https://www.example.com/" required autofocus autocomplete="off"/>
                <button class="button-submit" type="submit">Proxify</button>
            </div>
<?php
switch ($data['category']) {
    case 'error':
        echo '            <p class="error">';

        switch ($data['group']) {
            case 'url':
                echo '<b>URL Error (' . htmlspecialchars($data['error']) . ')</b>: ';
                switch ($data['type']) {
                    case 'internal':
                        $message = 'Failed to connect to the specified host. '
                            . 'Possible problems are that the server was not found, the connection timed out, or the connection refused by the host. '
                            . 'Try connecting again and check if the address is correct.';
                        break;
                    case 'external':
                        switch ($data['error']) {
                            case 1:
                                $message = 'The URL you\'re attempting to access is blacklisted by this server. Please select another URL.';
                                break;
                            case 2:
                                $message = 'The URL you entered is malformed. Please check whether you entered the correct URL or not.';
                                break;
                        }
                        break;
                }
                break;
            case 'resource':
                echo '<b>Resource Error:</b> ';
                switch ($data['type']) {
                    case 'file_size':
                        $message = 'The file your are attempting to download is too large.<br/>'
                        . 'Maxiumum permissible file size is <b>' . number_format($GLOBALS['_config']['max_file_size'] / 1048576, 2) . ' MB</b><br/>'
                        . 'Requested file size is <b>' . number_format($GLOBALS['_content_length'] / 1048576, 2) . ' MB</b>';
                        break;
                    case 'hotlinking':
                        $message = 'It appears that you are trying to access a resource through this proxy from a remote Website.<br/>'
                            . 'For security reasons, please use the form below to do so.';
                        break;
                }
                break;
        }

        echo 'An error has occured while trying to browse through the proxy. <br/>' . $message . '</p>';
        break;
}
?>
        </div>

        <?php if (in_array(0, $GLOBALS['_frozen_flags'])): ?>
        <div class="card">
            <div class="tabs-wrap">
                <input type="radio" name="tab" id="tab-options" checked/>
                <input type="radio" name="tab" id="tab-cookies"/>
                <input type="radio" name="tab" id="tab-headers"/>

                <nav class="tabs">
                    <label for="tab-options">Options</label>
                    <label for="tab-cookies">Cookies</label>
                    <label for="tab-headers">Headers</label>
                </nav>

                <section class="tab-panel" data-tab="options">
                    <ul class="prx-opt-menu">
<?php
foreach ($GLOBALS['_flags'] as $flag_name => $flag_value) {
    if (!$GLOBALS['_frozen_flags'][$flag_name]) {
        echo '                        <li class="option"><label><input type="checkbox" name="' . $GLOBALS['_config']['flags_var_name'] . '[' . $flag_name . ']"' . ($flag_value ? ' checked="checked"' : '') . '/>' . htmlspecialchars($GLOBALS['_labels'][$flag_name][1]) . '</label></li>' . "\n";
    }
}
?>
                    </ul>
                </section>

                <section class="tab-panel" data-tab="cookies">
<?php if (empty($_visible_cookies)): ?>
                    <p class="tab-help">No proxied cookies stored.</p>
                    <ul class="cookie-list"><li class="empty">Nothing here yet</li></ul>
<?php else: ?>
                    <p class="tab-help"><?php echo count($_visible_cookies); ?> cookie<?php echo count($_visible_cookies) === 1 ? '' : 's'; ?> held for proxied sites.</p>
                    <ul class="cookie-list">
<?php foreach ($_visible_cookies as $_name => $_value): ?>
                        <li><span><?php echo htmlspecialchars($_name); ?></span></li>
<?php endforeach; ?>
                    </ul>
<?php endif; ?>
                    <div class="tab-actions">
                        <button class="button-ghost" type="submit" formaction="?action=clear-cookies" formmethod="post" formnovalidate>Clear all cookies</button>
                    </div>
                </section>

                <section class="tab-panel" data-tab="headers">
                    <p class="tab-help">Outgoing request headers sent on your behalf. Empty means "use browser default", <code>.</code> means "use my real browser User-Agent", <code>-</code> means "send no User-Agent".</p>
                    <div class="form-row">
                        <label>
                            <span>User-Agent</span>
                            <input type="text" name="userAgent" list="user-agents" value="<?php echo htmlspecialchars($_current_ua); ?>" placeholder="Default browser User-Agent" autocomplete="off"/>
                            <datalist id="user-agents">
                                <option value="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36" label="Chrome on Windows"/>
                                <option value="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15" label="Safari on macOS"/>
                                <option value="Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1" label="Safari on iPhone"/>
                                <option value="Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36" label="Chrome on Android"/>
                                <option value="Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36" label="Chrome on Linux"/>
                                <option value="." label="Use my real browser User-Agent"/>
                                <option value="-" label="Send no User-Agent at all"/>
                            </datalist>
                        </label>
                    </div>
                    <div class="tab-actions">
                        <button class="button-cancel" type="submit" formaction="edit.php" formmethod="post" formnovalidate name="action" value="submit">Save headers</button>
                    </div>
                </section>
            </div>
        </div>
        <?php endif; ?>
    </form>
<?php elseif ($data['category'] == 'auth'): ?>
    <form class="auth" method="post" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
        <div class="card main-auth-box">
            <div class="form-title-row">
                <h2 class="auth-header">Authentication Required</h2>
            </div>
            <input type="hidden" name="<?php echo htmlspecialchars($GLOBALS['_config']['basic_auth_var_name']) ?>" value="<?php echo base64_encode($data['realm']) ?>"/>
            <div class="form-row">
                <label>
                    <span>Username</span>
                    <input type="text" name="username" placeholder="Username" autocomplete="username"/>
                </label>
            </div>
            <div class="form-row">
                <label>
                    <span>Password</span>
                    <input type="password" name="password" placeholder="Password" autocomplete="current-password"/>
                </label>
            </div>
            <div class="tab-actions">
                <button class="button-submit" type="submit">Login</button>
                <a class="button-cancel" href="index.php<?php echo '?__iv=' . rawurlencode($GLOBALS['_url']); ?>">Cancel</a>
            </div>
            <?php if (!empty($_POST['username']) || !empty($_POST['password'])): ?>
            <p class="error"><b>Authentication Required: </b>The supplied credentials were unauthorized to access the specified content.</p>
            <?php else: ?>
            <p class="info"><b>Authentication Required: </b>Enter your username and password for &quot;<?php echo htmlspecialchars($data['realm']); ?>&quot; on <?php echo htmlspecialchars($GLOBALS['_url_parts']['host']); ?></p>
            <?php endif; ?>
        </div>
    </form>
<?php endif; ?>

    <p class="footer"><a href="https://github.com/PHProxy/phproxy">PHProxy</a> <?= $GLOBALS['_version']; ?></p>
</div>

<script>
(function () {
    var root = document.documentElement;
    var btn = document.querySelector('.theme-toggle');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var cur = root.getAttribute('data-theme');
        if (!cur) {
            cur = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        var next = cur === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem('phproxy-theme', next); } catch (e) {}
    });
})();
</script>
</body>
</html>
