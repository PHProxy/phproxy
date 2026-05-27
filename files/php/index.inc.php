<?php
if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
    exit(0);
}

$_show_raw_values = !empty($_COOKIE['phproxy-show-raw']);
$_panel_buckets   = phproxy_panel_buckets();
$_visible_cookies = $_panel_buckets['cookies'];
$_custom_headers  = $_panel_buckets['headers'];
$_current_ua      = isset($_COOKIE['userAgent']) ? $_COOKIE['userAgent'] : '';
$_ua_presets      = phproxy_ua_presets();

$_active_tab = isset($_GET['tab']) ? (string) $_GET['tab'] : 'options';
$_valid_tabs = ['options' => 1, 'cookies' => 1, 'headers' => 1];
if (!isset($_valid_tabs[$_active_tab])) $_active_tab = 'options';
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
<?php
$GLOBALS['_show_raw_values']  = $_show_raw_values;
$GLOBALS['_visible_cookies']  = $_visible_cookies;
$GLOBALS['_custom_headers']   = $_custom_headers;
$GLOBALS['_ua_presets']       = $_ua_presets;
$_panel_return_to     = '';
$_panel_show_response = false;
$_panel_active_tab    = $_active_tab;
$_panel_form_id       = 'proxy-main-form';
include __DIR__ . '/_panel_tabs.inc.php';
?>
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
