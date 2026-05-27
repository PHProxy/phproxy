<?php
if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
    exit(0);
}

// Cookies the proxy itself owns (settings) — excluded from the user-facing list
$_proxy_settings_cookies = ['flags', 'userAgent', 'PHPSESSID', 'phproxy-theme', 'phproxy-seed'];

// Parse the raw Cookie header so we get the exact wire-form names the
// browser is storing. $_COOKIE keys go through PHP's legacy dot→underscore
// mangling which would prevent us from emitting matching Set-Cookie deletes.
$_raw_cookies = phproxy_raw_cookies();

$_visible_cookies = [];  // wire_name => ['display_name','host','path','value','secure','is_proxy']
$_custom_headers  = [];  // header_name => header_value
foreach ($_raw_cookies as $_wire_name => $_wire_value) {
    // wire names of settings/header cookies don't contain %25, so URL-decoding is identity
    if (in_array($_wire_name, $_proxy_settings_cookies, true)) continue;
    if (strpos($_wire_name, 'hdr_') === 0) {
        $_custom_headers[substr($_wire_name, 4)] = rawurldecode($_wire_value);
        continue;
    }

    $_parsed = phproxy_decode_proxy_cookie_id($_wire_name);
    if ($_parsed !== null) {
        // Proxy-stored cookie set by an upstream site
        $_val = phproxy_decode_proxy_cookie_value($_wire_value);
        $_visible_cookies[$_wire_name] = [
            'display_name' => $_parsed['name'],
            'host'         => ltrim($_parsed['domain'], '.'),
            'path'         => $_parsed['path'],
            'value'        => $_val['value'],
            'secure'       => $_val['secure'],
            'is_proxy'     => true,
        ];
    } else {
        // User-added or other cookie on the proxy's own domain
        $_visible_cookies[$_wire_name] = [
            'display_name' => rawurldecode($_wire_name),
            'host'         => '',
            'path'         => '',
            'value'        => rawurldecode($_wire_value),
            'secure'       => false,
            'is_proxy'     => false,
        ];
    }
}

$_current_ua    = isset($_COOKIE['userAgent']) ? $_COOKIE['userAgent'] : '';
$_active_tab    = isset($_GET['tab']) ? (string) $_GET['tab'] : 'options';
$_valid_tabs    = ['options' => 1, 'cookies' => 1, 'headers' => 1];
if (!isset($_valid_tabs[$_active_tab])) $_active_tab = 'options';

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
    <form id="proxy-main-form" method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
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
    </form>
    <?php /* Main URL form ends here. Tabs below have their own per-row forms;
             Options-tab inputs use form="proxy-main-form" so they ride along
             on URL submission and persist as the 'flags' cookie. */ ?>

    <?php if (in_array(0, $GLOBALS['_frozen_flags'])): ?>
    <div class="card">
        <div class="tabs-wrap">
            <input type="radio" name="tab" id="tab-options"<?php echo $_active_tab === 'options' ? ' checked' : ''; ?>/>
            <input type="radio" name="tab" id="tab-cookies"<?php echo $_active_tab === 'cookies' ? ' checked' : ''; ?>/>
            <input type="radio" name="tab" id="tab-headers"<?php echo $_active_tab === 'headers' ? ' checked' : ''; ?>/>

            <nav class="tabs">
                <label for="tab-options">Options</label>
                <label for="tab-cookies">Cookies <span class="tab-count"><?php echo count($_visible_cookies); ?></span></label>
                <label for="tab-headers">Headers <span class="tab-count"><?php echo count($_custom_headers); ?></span></label>
            </nav>

            <section class="tab-panel" data-tab="options">
<?php
$_option_groups = [
    'Browsing' => ['include_form', 'remove_scripts', 'show_images', 'strip_iframes', 'block_3p', 'block_fonts', 'block_media'],
    'Privacy'  => ['show_referer', 'send_dnt', 'send_gpc', 'strip_title', 'strip_meta', 'strip_tracking'],
    'Cookies'  => ['accept_cookies', 'session_cookies'],
];
foreach ($_option_groups as $_group_name => $_group_flags):
    $_visible = false;
    foreach ($_group_flags as $_f) {
        if (!$GLOBALS['_frozen_flags'][$_f]) { $_visible = true; break; }
    }
    if (!$_visible) continue;
?>
                <fieldset class="option-group">
                    <legend><?php echo htmlspecialchars($_group_name); ?></legend>
                    <ul class="prx-opt-menu">
<?php foreach ($_group_flags as $_f): ?>
<?php if (!$GLOBALS['_frozen_flags'][$_f]): ?>
                        <li class="option"><label><input form="proxy-main-form" type="checkbox" name="<?php echo $GLOBALS['_config']['flags_var_name']; ?>[<?php echo $_f; ?>]"<?php echo $GLOBALS['_flags'][$_f] ? ' checked' : ''; ?>/><span><?php echo $GLOBALS['_labels'][$_f][0]; ?></span></label></li>
<?php endif; ?>
<?php endforeach; ?>
                    </ul>
                </fieldset>
<?php endforeach; ?>
<?php
$_url_encoding = 'none';
if (!empty($GLOBALS['_flags']['encrypt_url']))      $_url_encoding = 'encrypted';
elseif ($GLOBALS['_flags']['rotate13'])             $_url_encoding = 'rot13';
elseif ($GLOBALS['_flags']['base64_encode'])        $_url_encoding = 'base64';
?>
                <fieldset class="option-group">
                    <legend>Address bar</legend>
                    <div class="radio-row">
                        <span class="radio-row-label">URL encoding</span>
                        <label><input form="proxy-main-form" type="radio" name="<?php echo $GLOBALS['_config']['flags_var_name']; ?>[__url_enc]" value="none"<?php echo $_url_encoding === 'none' ? ' checked' : ''; ?>/> None</label>
                        <label><input form="proxy-main-form" type="radio" name="<?php echo $GLOBALS['_config']['flags_var_name']; ?>[__url_enc]" value="rot13"<?php echo $_url_encoding === 'rot13' ? ' checked' : ''; ?>/> ROT13</label>
                        <label><input form="proxy-main-form" type="radio" name="<?php echo $GLOBALS['_config']['flags_var_name']; ?>[__url_enc]" value="base64"<?php echo $_url_encoding === 'base64' ? ' checked' : ''; ?>/> Base64</label>
                        <label><input form="proxy-main-form" type="radio" name="<?php echo $GLOBALS['_config']['flags_var_name']; ?>[__url_enc]" value="encrypted"<?php echo $_url_encoding === 'encrypted' ? ' checked' : ''; ?>/> Encrypted <span class="hint">(1h rotating key)</span></label>
                    </div>
                </fieldset>
            </section>

            <section class="tab-panel" data-tab="cookies">
                <p class="tab-help">Cookies held for proxied sites. Click a row to edit; settings cookies (theme, flags, User-Agent) are excluded.</p>
<?php if (empty($_visible_cookies)): ?>
                <ul class="kv-list"><li class="empty">No cookies yet</li></ul>
<?php else: ?>
                <ul class="kv-list">
<?php foreach ($_visible_cookies as $_wire => $_c): ?>
                    <li class="kv-row">
                        <details class="kv-card">
                            <summary>
                                <span class="kv-display">
                                    <?php if ($_c['is_proxy']): ?><span class="kv-host"><?php echo htmlspecialchars($_c['host']); ?></span><span class="kv-sep">·</span><?php endif; ?>
                                    <span class="kv-name"><?php echo htmlspecialchars($_c['display_name']); ?></span><span class="kv-sep">=</span><span class="kv-val"><?php echo htmlspecialchars(mb_strimwidth($_c['value'], 0, 72, '…')); ?></span>
                                </span>
                                <span class="kv-chevron" aria-hidden="true">▾</span>
                            </summary>
                            <form class="kv-edit" method="post" action="?action=edit-cookie">
                                <input type="hidden" name="name" value="<?php echo htmlspecialchars($_wire); ?>"/>
                                <label class="kv-edit-field"><span>Name</span><input type="text" name="cookieName" value="<?php echo htmlspecialchars($_c['display_name']); ?>" autocomplete="off"/></label>
                                <label class="kv-edit-field"><span>Value</span><input type="text" name="cookieValue" value="<?php echo htmlspecialchars($_c['value']); ?>" autocomplete="off"/></label>
                                <label class="kv-edit-field"><span>Host</span><input type="text" name="cookieDomain" value="<?php echo $_c['is_proxy'] ? htmlspecialchars('.' . $_c['host']) : ''; ?>" placeholder=".example.com" autocomplete="off"/></label>
                                <label class="kv-edit-field"><span>Path</span><input type="text" name="cookiePath" value="<?php echo htmlspecialchars($_c['path'] ?: '/'); ?>" placeholder="/" autocomplete="off"/></label>
                                <label class="kv-edit-check"><input type="checkbox" name="cookieSecure"<?php echo $_c['secure'] ? ' checked' : ''; ?>/> Secure</label>
                                <div class="kv-edit-actions">
                                    <button class="button-submit" type="submit">Save</button>
                                </div>
                            </form>
                        </details>
                        <form class="kv-delete" method="post" action="?action=delete-cookie">
                            <input type="hidden" name="name" value="<?php echo htmlspecialchars($_wire); ?>"/>
                            <button class="button-icon" type="submit" title="Delete cookie" aria-label="Delete <?php echo htmlspecialchars($_c['display_name']); ?>">&times;</button>
                        </form>
                    </li>
<?php endforeach; ?>
                </ul>
<?php endif; ?>
                <p class="section-label">Add a cookie</p>
                <form class="kv-add" method="post" action="?action=add-cookie">
                    <input type="text" name="cookieAddName" placeholder="Name" autocomplete="off"/>
                    <input type="text" name="cookieAddValue" placeholder="Value" autocomplete="off"/>
                    <button type="submit">Add</button>
                </form>
                <form class="tab-actions" method="post" action="?action=clear-cookies">
                    <button class="button-ghost" type="submit">Clear all cookies</button>
                </form>
            </section>

            <section class="tab-panel" data-tab="headers">
                <p class="section-label">User-Agent</p>
                <p class="tab-help">Sent on every proxied request. <code>.</code> means &ldquo;use my real browser&rsquo;s User-Agent&rdquo;, <code>-</code> means &ldquo;send no User-Agent at all&rdquo;.</p>
                <form class="ua-form" method="post" action="?action=set-ua">
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
                        <button class="button-submit" type="submit">Save User-Agent</button>
                    </div>
                </form>

                <hr class="divider"/>

                <p class="section-label">Custom headers</p>
                <p class="tab-help">Extra HTTP headers sent on every outbound request. Header names: ASCII letters, digits, hyphens.</p>
<?php if (empty($_custom_headers)): ?>
                <ul class="kv-list"><li class="empty">No custom headers</li></ul>
<?php else: ?>
                <ul class="kv-list">
<?php foreach ($_custom_headers as $_h_name => $_h_value): ?>
                    <li class="kv-row">
                        <details class="kv-card">
                            <summary>
                                <span class="kv-display">
                                    <span class="kv-name"><?php echo htmlspecialchars($_h_name); ?></span><span class="kv-sep">:</span><span class="kv-val"><?php echo htmlspecialchars(mb_strimwidth($_h_value, 0, 72, '…')); ?></span>
                                </span>
                                <span class="kv-chevron" aria-hidden="true">▾</span>
                            </summary>
                            <form class="kv-edit" method="post" action="?action=edit-header">
                                <input type="hidden" name="oldName" value="<?php echo htmlspecialchars($_h_name); ?>"/>
                                <label class="kv-edit-field"><span>Name</span><input type="text" name="headerName" value="<?php echo htmlspecialchars($_h_name); ?>" pattern="[A-Za-z0-9-]+" autocomplete="off"/></label>
                                <label class="kv-edit-field"><span>Value</span><input type="text" name="headerValue" value="<?php echo htmlspecialchars($_h_value); ?>" autocomplete="off"/></label>
                                <div class="kv-edit-actions">
                                    <button class="button-submit" type="submit">Save</button>
                                </div>
                            </form>
                        </details>
                        <form class="kv-delete" method="post" action="?action=delete-header">
                            <input type="hidden" name="name" value="<?php echo htmlspecialchars($_h_name); ?>"/>
                            <button class="button-icon" type="submit" title="Delete header" aria-label="Delete header <?php echo htmlspecialchars($_h_name); ?>">&times;</button>
                        </form>
                    </li>
<?php endforeach; ?>
                </ul>
<?php endif; ?>
                <p class="section-label">Add a header</p>
                <form class="kv-add" method="post" action="?action=add-header">
                    <input type="text" name="headerAddName" placeholder="Accept-Language" pattern="[A-Za-z0-9-]+" autocomplete="off"/>
                    <input type="text" name="headerAddValue" placeholder="en-GB,en;q=0.9" autocomplete="off"/>
                    <button type="submit">Add</button>
                </form>
            </section>
        </div>
    </div>
    <?php endif; ?>
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
