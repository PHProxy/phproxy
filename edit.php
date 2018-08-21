<?php
if (isset($_POST['action']) && $_POST['action'] == "submit") {
    $userAgent = $_POST['userAgent'];
    if ($userAgent != null) {
        setcookie("userAgent", $userAgent, time() + (86400 * 365));
        $_COOKIE["userAgent"] = $userAgent;
    }
}
?>
<html>
<head>
	<meta charset="UTF-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1"/>
	<title>Settings</title>
	<link rel="stylesheet" href="./files/css/index.css"/>
</head>
<body>
<form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post">
	<div class="main">
		<div class="form-title-row">
			<h1>Settings</h1>
		</div>
		<div class="form-row">
			<label>
				<span>User Agent:</span>
				<input value="<?php if (isset($_COOKIE['userAgent'])) {echo (htmlspecialchars($_COOKIE['userAgent']));} else {echo "";}?>" type="text" list="user-agents" id="userAgent" name="userAgent" placeholder=""/>
 				<datalist id="user-agents">
					<option value="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko)" label="MS Edge Running on Windows"/>
					<option value="Mozilla/5.0 (iPhone; CPU iPhone OS 8_0_2 like Mac OS X) AppleWebKit/600.1.4 (KHTML, like Gecko) Version/8.0 Mobile Safari/600.1.4" label="Apple iOS (iPhone)"/>
					<option value="Mozilla/5.0 (Linux; U; Android 4.4.4; Nexus 5 Build/KTU84P) AppleWebkit/534.30 (KHTML, like Gecko) Version/4.0 Mobile Safari/534.30" label="Google Android (Nexus 5)"/>
					<option value="Mozilla/5.0 (compatible; MSIE 9.0; Windows Phone OS 7.5; Trident/5.0; IEMobile/9.0)" label="Microsoft Windows Phone"/>
					<option value="Mozilla/5.0 (Linux; U; Tizen 2.0; en-us) AppleWebKit/537.1 (KHTML, like Gecko) Mobile TizenBrowser/2.0" label="Samsung Tizen OS"/>
					<option value="Nokia5250/10.0.011 (SymbianOS/9.4; U; Series60/5.0 Mozilla/5.0; Profile/MIDP-2.1 Configuration/CLDC-1.1 ) AppleWebKit/525 (KHTML, like Gecko) Safari/525 3gpp-gba" label="Nokia Symbian"/>
					<option value="Mozilla/5.0 (Android 4.4; Mobile; rv:18.0) Gecko/18.0 Firefox/18.0" label="Mozilla Firefox OS"/>
					<option value="Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2228.0 Safari/537.36" label="Chrome Running on MS Windows"/>
					<option value="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10_1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2227.1 Safari/537.36" label="Chrome Running on Linux"/>
					<option value="Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2227.0 Safari/537.36" label="Chrome Running on Mac OS"/>
					<option value="Mozilla/5.0 (X11; CrOS i686 3912.101.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/27.0.1453.116 Safari/537.36" label="Chrome Running on Chrome OS"/>
					<option value="Mozilla/5.0 (X11; FreeBSD amd64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/40.0.2214.115 Safari/537.36" label="Chrome Running on FreeBSD OS"/>
					<option value="." label="Use browser"/>
					<option value="-" label="None"/>
					<option value="" label="Default"/>
				</datalist>
			</label>
		</div>
		<div class="form-row">
			<button class="button-submit" type="submit" name="action" value="submit">Submit</button>
			<button class="button-submit" type="reset" name="action" value="reset">Reset</button>
			<a class="button-submit" href="index.php">Back</a>
		</div>
	</div>
</form>
</body>
</html>
