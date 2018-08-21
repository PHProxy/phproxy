<?php
if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
    exit(0);
}
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en-US" xml:lang="en-US">
	<head>
		<title><?php echo htmlspecialchars($GLOBALS['_config']['site_name']); ?></title>
<style>
		* {
			padding: 0;
			margin: 0
		}
		body {
			background: #f3f3f3;
			font: 400 16px sans-serif;
			color: #555;
		}
		.main {
			box-sizing: border-box;
			width: 100%;
			max-width: 500px;
			min-width: 350px;
			margin: 50px auto;
			padding: 55px;
			background-color: #fff;
			box-shadow: 6px 6px 20px 0px rgba(0, 0, 0, 0.5);
			font: 400 14px sans-serif;
			text-align: center;
		}
		.main-auth-box {
			box-shadow: 6px 6px 20px 0px rgba(255, 0, 0, 0.29)!important;
		}
		.form-title-row {
			margin: 0 auto 40px auto;
			text-align: left;
		}
		.form-title-row h1 {
			display: block;
			box-sizing: border-box;
			color: #4C565E;
			font-size: 24px;
			padding: 0 0 3px;
			margin: 0;
			border-bottom: 2px solid #6CAEE0;
		}
		.form-row {
			text-align: left;
		}
		.form-row label span{
			display: block;
			box-sizing: border-box;
			color: #5f5f5f;
			padding: 0 0 10px;
			font-weight: 700;
		}
		form input {
			color: #5f5f5f;
			box-sizing: border-box;
			box-shadow: 1px 2px 4px 0 rgba(0, 0, 0, .08);
			padding: 12px 18px;
			border: 1px solid #dbdbdb;
			margin-bottom: 10px;
		}

		form input[type=email],
		form input[type=username],
		form input[type=password],
		form input[type=text],
		form textarea {
			width: 100%
		}

		form input[type=number] {
			max-width: 100px
		}

		form input[type=checkbox],
		form input[type=radio] {
			box-shadow: none;
			width: auto
		}

		form textarea {
			color: #5f5f5f;
			box-sizing: border-box;
			box-shadow: 1px 2px 4px 0 rgba(0, 0, 0, .08);
			padding: 12px 18px;
			border: 1px solid #dbdbdb;
			resize: none;
			min-height: 80px;
		}

		form select {
			background-color: #fff;
			color: #5f5f5f;
			box-sizing: border-box;
			width: 240px;
			box-shadow: 1px 2px 4px 0 rgba(0, 0, 0, .08);
			padding: 12px 18px;
			border: 1px solid #dbdbdb
		}

		form .form-radio-buttons>div {
			margin-bottom: 10px
		}

		form .form-radio-buttons label span {
			margin-left: 8px;
			color: #5f5f5f
		}

		form .form-radio-buttons input {
			width: auto
		}

		.button-submit {
			border-radius: 2px;
			background-color: #6caee0;
			color: #fff;
			font: 700 13.3333px Arial;
			box-shadow: 1px 2px 4px 0 rgba(0, 0, 0, .08);
			padding: 14px 22px;
			border: 0;
			margin-top: 10px;
			cursor: pointer;
			text-decoration: none;
		}
		.button-cancel {
			border-radius: 2px;
			background-color: #a4bbcc;
			color: #fff;
			font: 700 13.3333px Arial;
			box-shadow: 1px 2px 4px 0 rgba(0, 0, 0, .08);
			padding: 14px 22px;
			border: 0;
			margin-top: 10px;
			cursor: pointer;
			text-decoration: none;
		}
		p.explanation {
			padding: 15px 20px;
			line-height: 1.5;
			background-color: #FFFFE0;
			font-size: 13px;
			text-align: center;
			margin-top: 40px;
			color: #6B6B48;
			border-radius: 3px;
			border-bottom: 2px solid #ECECD0;
			border-right: 2px solid #ECECD0;
			text-align: left
		}
		p.error {
			padding: 15px 20px;
			line-height: 1.5;
			background-color: #ff7272;
			font-size: 13px;
			text-align: center;
			margin-top: 40px;
			color: #ffffff;
			border-radius: 3px;
			border-bottom: 2px solid #fd3333;
			border-right: 2px solid #c1294c;
			text-align: left;
		}

		p.info {
			padding: 15px 20px;
			line-height: 1.5;
			background-color: #56dcb1;
			font-size: 13px;
			text-align: center;
			margin-top: 40px;
			color: #ffffff;
			border-radius: 3px;
			border-bottom: 2px solid #76dc75;
			border-right: 2px solid #30cc2e;
			text-align: left;
		}

		.auth-header {
			border-bottom: 2px solid #ff8100 !important;
		}

		.auth {
			margin-top: 10px;
		}

		.prx-opt-menu {
			list-style: none;
			text-align: initial;
			padding-left: 4%;
		}
		.option label input {
			margin-right: 10px;
		}

		@media (max-width:600px) {
			.main {
				padding: 30px
			}
			body {
				background: #fff;
			}
			.main {
				box-shadow: none;
			}
		}
		#proxopttogl {
			position: absolute;
			left: -12em;
		}

		#proxopttogl ~ #proxoptmenu {
			display : none;
		}

		#proxopttogl:checked ~ #proxoptmenu {
			display : block;
		}
		</style>
		<meta name="viewport" content="width=device-width, initial-scale=1"/>
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
					<input type="text" name="<?php echo htmlspecialchars($GLOBALS['_config']['url_var_name']) ?>" value="<?php echo isset($_GET[$GLOBALS['_config']['url_var_name']]) ? htmlspecialchars(decode_url($_GET[$GLOBALS['_config']['url_var_name']])) : (isset($_GET['__iv']) ? htmlspecialchars($_GET['__iv']) : ''); ?>" placeholder="http://www.example.com/index.html?ref=PHProxy" required="required"/>
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
			<div class="prx-opt-menu">
				<li id="newWin" class="option" style="display: none;"><label><input type="checkbox"/>Open URL in a new window</label></li>
<?php
foreach ($GLOBALS['_flags'] as $flag_name => $flag_value) {
    if (!$GLOBALS['_frozen_flags'][$flag_name]) {
        echo '<li class="option"><label><input type="checkbox" name="' . $GLOBALS['_config']['flags_var_name'] . '[' . $flag_name . ']"' . ($flag_value ? ' checked="checked"' : '') . ' />' . htmlspecialchars($GLOBALS['_labels'][$flag_name][1]) . '</label></li>' . "\n";
    }
}
?>
				</div>
				<a href="edit.php">MORE...</a>
			</div>
		<?php endif;?>
</form>
		<?php elseif ($data['category'] == 'auth'): ?>

			<form class="auth" method="post" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
		<div class="main main-auth-box">
			<div class="form-title-row">
				<h1 class="auth-header">Authentication Required</h1>
			</div>
				<input type="hidden" name="<?php echo htmlspecialchars($GLOBALS['_config']['basic_auth_var_name']) ?>" value="<?php echo base64_encode($data['realm']) ?>" />
				<div class="form-row">
					<label>
						<span>Enter username:</span>
						<input type="username" name="username" placeholder="Username">
					</label>
					<label>
						<span>Enter password:</span>
						<input type="password" name="password" placeholder="Password">
					</label>
				</div>

				<div class="form-row">
					<button class="button-submit" type="submit">Login</button>
					<a class="button-cancel" href="index.php<?php echo '?__iv=' . rawurlencode($GLOBALS['_url']); ?>">Cancel</a>
				</div>
			<?php if (!empty($_POST['username']) || !empty($_POST['password'])): ?>
				<p class="error"><b>Authentication Required: </b>The supplied credentials were unauthorized to access the specified content.</p>
			<?php else: ?>
				<p class="info"><b>Authentication Required: </b>Enter your username and password for "<?php echo htmlspecialchars($data['realm']); ?>" on <?php echo htmlspecialchars($GLOBALS['_url_parts']['host']); ?></p>
			<?php endif;?>
		</div>
</form>
<?php endif;?>
<center><small><a href="https://github.com/PHProxy/phproxy" style="text-decoration: none;">PHProxy</a> <?=$GLOBALS['_version'];?></small></center>
	</body>
</html>
