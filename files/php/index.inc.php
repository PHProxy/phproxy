<?php
if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
    exit(0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title><?php echo htmlspecialchars($GLOBALS['_config']['site_name']); ?></title>
    <link rel="stylesheet" href="./files/css/index.css"/>
    <script src="./files/js/index.js"></script>
</head>
<body>
<?php if ($data['category'] != 'auth'): ?>
<form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
    <div class="main">
        <div class="form-title-row">
            <h1><?php echo htmlspecialchars($GLOBALS['_config']['site_name']); ?></h1>
        </div>
        <div class="form-row">
            <label>
                <span>Enter full URL:</span>
                <input type="text" name="<?php echo htmlspecialchars($GLOBALS['_config']['url_var_name']) ?>" value="<?php echo isset($_GET[$GLOBALS['_config']['url_var_name']]) ? htmlspecialchars(decode_url($_GET[$GLOBALS['_config']['url_var_name']])) : (isset($_GET['__iv']) ? htmlspecialchars($_GET['__iv']) : ''); ?>" placeholder="https://www.example.com/" required="required" autocomplete="off" autofocus/>
            </label>
        </div>
        <div class="form-row">
            <button class="button-submit" type="submit">Proxify</button>
            <label class="button-cancel" for="proxopttogl">Options</label>
        </div>
<?php
switch ($data['category']) {
    case 'error':
        echo '<p class="error">';

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
                        $message = 'The file your are attempting to download is too large.<br />'
                        . 'Maxiumum permissible file size is <b>' . number_format($GLOBALS['_config']['max_file_size'] / 1048576, 2) . ' MB</b><br />'
                        . 'Requested file size is <b>' . number_format($GLOBALS['_content_length'] / 1048576, 2) . ' MB</b>';
                        break;
                    case 'hotlinking':
                        $message = 'It appears that you are trying to access a resource through this proxy from a remote Website.<br />'
                            . 'For security reasons, please use the form below to do so.';
                        break;
                }
                break;
        }

        echo 'An error has occured while trying to browse through the proxy. <br />' . $message . '</p>';
        break;
}
?>
    </div>
    <?php if (in_array(0, $GLOBALS['_frozen_flags'])): ?>
    <input type="checkbox" id="proxopttogl"/>
    <div id="proxoptmenu" class="main">
        <div class="form-title-row">
            <h1>Options</h1>
        </div>
        <ul class="prx-opt-menu">
            <li id="newWin" class="option" style="display: none;"><label><input type="checkbox"/>Open URL in a new window</label></li>
<?php
foreach ($GLOBALS['_flags'] as $flag_name => $flag_value) {
    if (!$GLOBALS['_frozen_flags'][$flag_name]) {
        echo '            <li class="option"><label><input type="checkbox" name="' . $GLOBALS['_config']['flags_var_name'] . '[' . $flag_name . ']"' . ($flag_value ? ' checked="checked"' : '') . ' />' . htmlspecialchars($GLOBALS['_labels'][$flag_name][1]) . '</label></li>' . "\n";
    }
}
?>
        </ul>
        <a class="more-link" href="edit.php">More settings &rarr;</a>
    </div>
    <?php endif; ?>
</form>
<?php elseif ($data['category'] == 'auth'): ?>
<form class="auth" method="post" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
    <div class="main main-auth-box">
        <div class="form-title-row">
            <h1 class="auth-header">Authentication Required</h1>
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
        <div class="form-row">
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
</body>
</html>
