<?php
if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
    exit(0);
}

// Cookies the proxy itself owns (settings) — excluded from the user-facing list
$_proxy_settings_cookies = ['flags', 'userAgent', 'PHPSESSID'];

$_visible_cookies = [];
$_custom_headers  = [];
foreach ($_COOKIE as $_k => $_v) {
    if (in_array($_k, $_proxy_settings_cookies, true)) continue;
    if (strpos($_k, 'hdr_') === 0) {
        $_custom_headers[substr($_k, 4)] = $_v;
    } else {
        $_visible_cookies[$_k] = $_v;
    }
}

$_current_ua = isset($_COOKIE['userAgent']) ? $_COOKIE['userAgent'] : '';

$_ua_presets = [
    ''  => '— Default browser User-Agent —',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36' => 'Chrome on Windows',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15' => 'Safari on macOS',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' => 'Safari on iPhone',
    'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36' => 'Chrome on Android',
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36' => 'Chrome on Linux',
    'Mozilla/5.0 (X11; CrOS x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36' => 'Chrome on ChromeOS',
    'curl/8.5.0' => 'curl 8.5',
    'Wget/1.21.4' => 'wget 1.21',
    '.' => '★ Use my real browser User-Agent',
    '-' => '★ Send no User-Agent at all',
];
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
                <button class="button-submit" type="submit">Go</button>
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
                    <p class="tab-help">Cookies held for proxied sites. Settings cookies (theme, flags, User-Agent) are not listed.</p>
<?php if (empty($_visible_cookies)): ?>
                    <ul class="kv-list"><li class="empty">No cookies yet</li></ul>
<?php else: ?>
                    <ul class="kv-list">
<?php foreach ($_visible_cookies as $_name => $_value): ?>
                        <li>
                            <span><span class="kv-name"><?php echo htmlspecialchars($_name); ?></span><span class="kv-sep">=</span><?php echo htmlspecialchars(mb_strimwidth($_value, 0, 80, '…')); ?></span>
                            <button class="button-icon" type="submit" formaction="?action=delete-cookie" formmethod="post" formnovalidate name="name" value="<?php echo htmlspecialchars($_name); ?>" title="Delete cookie" aria-label="Delete <?php echo htmlspecialchars($_name); ?>">&times;</button>
                        </li>
<?php endforeach; ?>
                    </ul>
<?php endif; ?>
                    <p class="section-label">Add a cookie</p>
                    <div class="kv-add">
                        <input type="text" name="cookieAddName" placeholder="Name" autocomplete="off"/>
                        <input type="text" name="cookieAddValue" placeholder="Value" autocomplete="off"/>
                        <button type="submit" formaction="?action=add-cookie" formmethod="post" formnovalidate>Add</button>
                    </div>
                    <div class="tab-actions">
                        <button class="button-ghost" type="submit" formaction="?action=clear-cookies" formmethod="post" formnovalidate>Clear all cookies</button>
                    </div>
                </section>

                <section class="tab-panel" data-tab="headers">
                    <p class="section-label">User-Agent</p>
                    <p class="tab-help">Sent on every proxied request. Pick a preset or type a custom value below. <code>.</code> means &ldquo;use my real browser&rsquo;s User-Agent&rdquo;, <code>-</code> means &ldquo;send no User-Agent at all&rdquo;.</p>
                    <div class="ua-picker">
                        <select id="ua-preset" aria-label="User-Agent preset">
<?php foreach ($_ua_presets as $_val => $_lbl): ?>
                            <option value="<?php echo htmlspecialchars($_val); ?>"<?php echo $_current_ua === $_val ? ' selected' : ''; ?>><?php echo htmlspecialchars($_lbl); ?></option>
<?php endforeach; ?>
                            <option value="__custom__"<?php echo (!array_key_exists($_current_ua, $_ua_presets) && $_current_ua !== '') ? ' selected' : ''; ?>>Custom…</option>
                        </select>
                        <input type="text" id="ua-input" name="userAgent" value="<?php echo htmlspecialchars($_current_ua); ?>" placeholder="Custom User-Agent string" autocomplete="off"/>
                    </div>
                    <div class="tab-actions" style="margin-top:12px">
                        <button class="button-submit" type="submit" formaction="?action=set-ua" formmethod="post" formnovalidate>Save User-Agent</button>
                    </div>

                    <hr class="divider"/>

                    <p class="section-label">Custom headers</p>
                    <p class="tab-help">Extra HTTP headers sent on every outbound request. Header names must be ASCII letters, digits, and hyphens.</p>
<?php if (empty($_custom_headers)): ?>
                    <ul class="kv-list"><li class="empty">No custom headers</li></ul>
<?php else: ?>
                    <ul class="kv-list">
<?php foreach ($_custom_headers as $_name => $_value): ?>
                        <li>
                            <span><span class="kv-name"><?php echo htmlspecialchars($_name); ?></span><span class="kv-sep">:</span><?php echo htmlspecialchars(mb_strimwidth($_value, 0, 80, '…')); ?></span>
                            <button class="button-icon" type="submit" formaction="?action=delete-header" formmethod="post" formnovalidate name="name" value="<?php echo htmlspecialchars($_name); ?>" title="Delete header" aria-label="Delete header <?php echo htmlspecialchars($_name); ?>">&times;</button>
                        </li>
<?php endforeach; ?>
                    </ul>
<?php endif; ?>
                    <p class="section-label">Add a header</p>
                    <div class="kv-add">
                        <input type="text" name="headerAddName" placeholder="Accept-Language" pattern="[A-Za-z0-9-]+" autocomplete="off"/>
                        <input type="text" name="headerAddValue" placeholder="en-GB,en;q=0.9" autocomplete="off"/>
                        <button type="submit" formaction="?action=add-header" formmethod="post" formnovalidate>Add</button>
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
    if (btn) {
        btn.addEventListener('click', function () {
            var cur = root.getAttribute('data-theme');
            if (!cur) cur = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            var next = cur === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            try { localStorage.setItem('phproxy-theme', next); } catch (e) {}
        });
    }

    // User-Agent picker: select preset auto-fills the text input
    var sel = document.getElementById('ua-preset');
    var inp = document.getElementById('ua-input');
    if (sel && inp) {
        sel.addEventListener('change', function () {
            if (sel.value === '__custom__') {
                inp.focus();
                inp.select();
            } else {
                inp.value = sel.value;
            }
        });
    }
})();
</script>
</body>
</html>
