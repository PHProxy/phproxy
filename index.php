<?php

/**
 *
 * PHProxy
 *
 * @author              Miglen; PhoenixPeca; Biojet1
 * @copyright           2002-2007 A.A. (whitefyre)
 * @description         Web based http proxy written on php.
 * @url                 https://phproxy.github.io
 * @license             GNU GPL v3
 * @repo                https://github.com/phproxy/phproxy
 * @docs                http://phproxy.readthedocs.org
 *
 */

/* PRODUCTIVE: */ error_reporting(0);
// DEVELOP: error_reporting(E_ALL); ini_set('display_errors', '1');

//
// CONFIGURABLE OPTIONS
//

$_config            =
                    [
                        'url_var_name'             => '_proxurl',
                        'flags_var_name'           => '_proxfl',
                        'get_form_name'            => '_proxgfn',
                        'basic_auth_var_name'      => '_proxba',
                        'site_name'                => 'PHProxy',
                        'max_file_size'            => -1,
                        'allow_hotlinking'         => 0,
                        'upon_hotlink'             => 1,
                        'compress_output'          => 0,
                    ];
// NOTE on order: new flags MUST be appended to the head of $_flags so the
// bitfield positions of existing flags stay stable (the cookie stores the
// flag bitfield as a left-padded binary string — adding to the tail would
// shift the existing flags and break every saved cookie).
$_flags             =
                    [
                        // new in v1.3.x — anonymity seed URL encryption (on by default)
                        'encrypt_url'     => 1,
                        // new in v1.3.0 (privacy / blocking)
                        'strip_tracking'  => 0,
                        'send_gpc'        => 0,
                        'send_dnt'        => 0,
                        'block_media'     => 0,
                        'block_fonts'     => 0,
                        'block_3p'        => 0,
                        'strip_iframes'   => 0,
                        // original flags (positions preserved)
                        'include_form'    => 1,
                        'remove_scripts'  => 1,
                        'accept_cookies'  => 1,
                        'show_images'     => 1,
                        'show_referer'    => 1,
                        'rotate13'        => 0,
                        'base64_encode'   => 1,
                        'strip_meta'      => 0,
                        'strip_title'     => 1,
                        'session_cookies' => 1,
                    ];
$_frozen_flags      =
                    [
                        'encrypt_url'     => 0,
                        'strip_tracking'  => 0,
                        'send_gpc'        => 0,
                        'send_dnt'        => 0,
                        'block_media'     => 0,
                        'block_fonts'     => 0,
                        'block_3p'        => 0,
                        'strip_iframes'   => 0,
                        'include_form'    => 0,
                        'remove_scripts'  => 0,
                        'accept_cookies'  => 0,
                        'show_images'     => 0,
                        'show_referer'    => 0,
                        'rotate13'        => 0,
                        'base64_encode'   => 0,
                        'strip_meta'      => 0,
                        'strip_title'     => 0,
                        'session_cookies' => 0,
                    ];
$_labels            =
                    [
                        'encrypt_url'     => ['Encrypted (rotating key)',  'AES-CTR encrypt URLs with a 1-hour session seed; old logs go unusable'],
                        'strip_tracking'  => ['Strip tracking params',     'Drop utm_*, fbclid, gclid and friends from URLs'],
                        'send_gpc'        => ['Send Sec-GPC: 1',           'Global Privacy Control signal'],
                        'send_dnt'        => ['Send DNT: 1',               'Do-Not-Track header'],
                        'block_media'     => ['Block media',               'Remove <video> and <audio> from proxied pages'],
                        'block_fonts'     => ['Block web fonts',           'Remove font CDN links and @font-face rules'],
                        'block_3p'        => ['Block 3rd-party resources', 'Don\'t proxy assets from a different host than the page'],
                        'strip_iframes'   => ['Strip iframes',             'Remove <iframe> elements from proxied pages'],
                        'include_form'    => ['Show top bar while browsing', 'Pin the URL bar to the top of every proxied page'],
                        'remove_scripts'  => ['Block JavaScript',          'Strip <script> tags from proxied HTML'],
                        'accept_cookies'  => ['Allow cookies',             'Store and forward cookies from proxied sites'],
                        'show_images'     => ['Load images',               'Show images on proxied pages'],
                        'show_referer'    => ['Send Referer header',       'Forward Referer to the target'],
                        'rotate13'        => ['ROT13',                     'ROT13 the URL in the address bar'],
                        'base64_encode'   => ['Base64',                    'Base64-encode the URL in the address bar'],
                        'strip_meta'      => ['Strip <meta> tags',         'Remove meta tags from proxied pages'],
                        'strip_title'     => ['Hide page title',           'Strip <title> so the browser tab is anonymous'],
                        'session_cookies' => ['Session-only cookies',      'Forget cookies when the browser closes'],
                    ];

$_hosts             =
                    [
                        '#^127\.|192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.|localhost#i',
                    ];
$_hotlink_domains   = [];
$_insert            = [];

//
// END CONFIGURABLE OPTIONS. The ride for you ends here. Close the file.
//

$_iflags            = '';
$_system            =
                    [
                        'ssl'          => extension_loaded('openssl') && version_compare(PHP_VERSION, '4.3.0', '>='),
                        'uploads'      => ini_get('file_uploads'),
                        'gzip'         => extension_loaded('zlib') && !ini_get('zlib.output_compression'),
                        'stripslashes' => true,
                    ];
$_proxify           =
                    [
                        'text/html'             => 1,
                        'application/xml+xhtml' => 1,
                        'application/xhtml+xml' => 1,
                        'text/css'              => 1,
                    ];
$_version           = 'v1.1.1';
$_http_host         = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost');
// https://stackoverflow.com/questions/4504831/serverhttp-host-contains-port-number-too
$pos = strpos($_http_host, ':');
if ($pos) {
    $_http_host = substr($_http_host, 0, $pos);
}
$_script_url        = 'http' . ((isset($_ENV['HTTPS']) && $_ENV['HTTPS'] == 'on') || $_SERVER['SERVER_PORT'] == 443 ? 's' : '') . '://' . $_http_host . ($_SERVER['SERVER_PORT'] != 80 && $_SERVER['SERVER_PORT'] != 443 ? ':' . $_SERVER['SERVER_PORT'] : '') . $_SERVER['PHP_SELF'];
$_script_base       = substr($_script_url, 0, strrpos($_script_url, '/')+1);
$_url               = '';
$_url_parts         = [];
$_base              = [];
$_socket            = null;
$_request_method    = $_SERVER['REQUEST_METHOD'];
$_request_headers   = '';
$_cookie            = '';
$_post_body         = '';
$_response_headers  = [];
$_response_keys     = [];
$_http_version      = '';
$_response_code     = 0;
$_content_type      = 'text/html';
$_content_length    = false;
$_content_disp      = '';
$_set_cookie        = [];
$_retry             = false;
$_quit              = false;
$_basic_auth_header = '';
$_basic_auth_realm  = '';
$_auth_creds        = [];
$_response_body     = '';
$pos = isset($_COOKIE['userAgent']) ? $_COOKIE['userAgent'] : null;
if(!isset($pos) || $pos == ""){ // empty means old method
  $_user_agent = isset($_SERVER['HTTP_X_IORG_FBS']) ? 'SamsungI8910/SymbianOS/6.1 PHProxy/'.$_version : $_SERVER['HTTP_USER_AGENT'];
}else if($pos == '.'){ // dot means use the browsers UA
  $_user_agent = $_SERVER['HTTP_USER_AGENT'];
}else if($pos == '-'){ // dash means dont set UA
  $_user_agent = null;
}else{
  $_user_agent = $pos;
}

# to bind to a specific ip set $_bindip to desired IP
# if you do not need to set a specific port use 0 as default
#   example:
#   $_bindip = '192.168.1.100:0';
# for default ip set value to default
#   $_bindip = 'default';
$_bindip           = 'default';

// Functions declaration
require_once "./files/php/functions.inc.php";

//
// ACTION DISPATCHER (Cookies / Headers / User-Agent management)
// All POSTs to ?action=… are handled here and redirect back to the entry form.
//
if (isset($_GET['action']) && $_SERVER['REQUEST_METHOD'] === 'POST')
{
    $_action   = $_GET['action'];
    $_settings = ['flags', 'userAgent', 'PHPSESSID', 'phproxy-theme', 'phproxy-seed', 'phproxy-seed-ttl', 'phproxy-seed-bits'];

    // Expire a cookie by its exact wire-form name. Uses setrawcookie() so PHP
    // doesn't URL-encode the name again — important for proxy-stored cookies
    // whose wire form is already double-rawurlencoded (e.g.
    // "COOKIE%253B...%253B.host.com").
    $_expire_raw = function ($wire_name) use ($_http_host) {
        $expired = 'Thu, 01 Jan 1970 00:00:01 GMT';
        $headers = [
            $wire_name . "=; expires={$expired}; Max-Age=0; path=/",
            $wire_name . "=; expires={$expired}; Max-Age=0; path=/; domain=." . $_http_host,
            $wire_name . "=; expires={$expired}; Max-Age=0; path=/; domain=" . $_http_host,
        ];
        foreach ($headers as $h) header('Set-Cookie: ' . $h, false);
    };

    $_raw_cookies = phproxy_raw_cookies();
    $_redirect_tab = '';

    switch ($_action)
    {
        case 'clear-cookies':
            foreach ($_raw_cookies as $_ck => $_cv) {
                if (in_array($_ck, $_settings, true)) continue;
                if (strpos($_ck, 'hdr_') === 0) continue;
                $_expire_raw($_ck);
            }
            $_redirect_tab = 'cookies';
            break;

        case 'delete-cookie':
            // $_POST['name'] is the wire-form name as the browser stored it.
            $_name = isset($_POST['name']) ? (string) $_POST['name'] : '';
            if ($_name !== ''
                && !in_array($_name, $_settings, true)
                && strpos($_name, 'hdr_') !== 0
                && strpbrk($_name, "\r\n") === false)
            {
                $_expire_raw($_name);
            }
            $_redirect_tab = 'cookies';
            break;

        case 'add-cookie':
            $_name  = isset($_POST['cookieAddName']) ? trim((string) $_POST['cookieAddName']) : '';
            $_value = isset($_POST['cookieAddValue']) ? (string) $_POST['cookieAddValue'] : '';
            if ($_name !== '' && !in_array($_name, $_settings, true) && strpos($_name, 'hdr_') !== 0 && strpbrk($_name, "\r\n;,= \t") === false) {
                setcookie($_name, $_value, time() + 86400 * 30, '/');
            }
            $_redirect_tab = 'cookies';
            break;

        case 'edit-cookie':
            // Edit a proxy-stored COOKIE-prefixed entry: expire the old wire name,
            // then re-emit with the user-supplied name/path/domain/value/secure.
            $_old = isset($_POST['name']) ? (string) $_POST['name'] : '';
            if ($_old !== '' && !in_array($_old, $_settings, true) && strpos($_old, 'hdr_') !== 0 && strpbrk($_old, "\r\n") === false) {
                $_expire_raw($_old);
            }
            $_n   = isset($_POST['cookieName'])   ? (string) $_POST['cookieName']   : '';
            $_p   = isset($_POST['cookiePath'])   ? (string) $_POST['cookiePath']   : '/';
            $_d   = isset($_POST['cookieDomain']) ? (string) $_POST['cookieDomain'] : '';
            $_v   = isset($_POST['cookieValue'])  ? (string) $_POST['cookieValue']  : '';
            $_sec = !empty($_POST['cookieSecure']) ? 'secure' : '';
            if ($_n !== '' && $_d !== '' && strpbrk($_n . $_p . $_d . $_v, "\r\n") === false) {
                // Build a new COOKIE;name;path;domain id and let add_cookie's double
                // URL-encoding handle the wire form.
                $_id   = 'COOKIE;' . $_n . ';' . $_p . ';' . $_d;
                header('Set-Cookie: ' . add_cookie($_id, $_v . ';' . $_sec, time() + 86400 * 30), false);
            }
            $_redirect_tab = 'cookies';
            break;

        case 'add-header':
            $_name  = isset($_POST['headerAddName']) ? trim((string) $_POST['headerAddName']) : '';
            $_value = isset($_POST['headerAddValue']) ? (string) $_POST['headerAddValue'] : '';
            if (preg_match('/^[A-Za-z0-9-]+$/', $_name) && strpbrk($_value, "\r\n") === false) {
                setcookie('hdr_' . $_name, $_value, time() + 86400 * 365, '/');
            }
            $_redirect_tab = 'headers';
            break;

        case 'edit-header':
            // Rename + re-value: expire old, set new
            $_old = isset($_POST['oldName']) ? (string) $_POST['oldName'] : '';
            $_new = isset($_POST['headerName']) ? trim((string) $_POST['headerName']) : '';
            $_val = isset($_POST['headerValue']) ? (string) $_POST['headerValue'] : '';
            if (preg_match('/^[A-Za-z0-9-]+$/', $_old)) {
                setcookie('hdr_' . $_old, '', time() - 3600, '/');
            }
            if (preg_match('/^[A-Za-z0-9-]+$/', $_new) && strpbrk($_val, "\r\n") === false) {
                setcookie('hdr_' . $_new, $_val, time() + 86400 * 365, '/');
            }
            $_redirect_tab = 'headers';
            break;

        case 'delete-header':
            $_name = isset($_POST['name']) ? (string) $_POST['name'] : '';
            if (preg_match('/^[A-Za-z0-9-]+$/', $_name)) {
                setcookie('hdr_' . $_name, '', time() - 3600, '/');
            }
            $_redirect_tab = 'headers';
            break;

        case 'set-ua':
            $_ua = isset($_POST['userAgent']) ? (string) $_POST['userAgent'] : '';
            if (strpbrk($_ua, "\r\n") === false) {
                setcookie('userAgent', $_ua, time() + 86400 * 365, '/');
            }
            $_redirect_tab = 'headers';
            break;

        case 'rotate-seed':
            // Force a new seed on the next phproxy_seed() call by clearing the cookie
            setcookie('phproxy-seed', '', time() - 3600, '/');
            unset($_COOKIE['phproxy-seed']);
            $_redirect_tab = 'options';
            break;

        case 'set-seed-ttl':
            $_ttl = isset($_POST['seedTtl']) ? (int) $_POST['seedTtl'] : 3600;
            if ($_ttl < 60)        $_ttl = 60;
            if ($_ttl > 86400 * 7) $_ttl = 86400 * 7;
            setcookie('phproxy-seed-ttl', (string) $_ttl, time() + 86400 * 365, '/');
            // Also force seed rotation so the new TTL takes effect on the next encode
            setcookie('phproxy-seed', '', time() - 3600, '/');
            unset($_COOKIE['phproxy-seed']);
            $_redirect_tab = 'options';
            break;

        case 'set-seed-bits':
            $_bits = isset($_POST['seedBits']) ? (int) $_POST['seedBits'] : 256;
            if (!in_array($_bits, [128, 192, 256], true)) $_bits = 256;
            setcookie('phproxy-seed-bits', (string) $_bits, time() + 86400 * 365, '/');
            // Key length changed → existing seed length no longer matches; rotate
            setcookie('phproxy-seed', '', time() - 3600, '/');
            unset($_COOKIE['phproxy-seed']);
            $_redirect_tab = 'options';
            break;

        default:
            // Unknown action — just bounce to entry form
            break;
    }

    $_dest = $_script_url . ($_redirect_tab !== '' ? '?tab=' . $_redirect_tab : '');
    header('Location: ' . $_dest);
    exit(0);
}

//
// URL encoding radio (Options tab) → maps a single radio value back onto
// the two underlying mutually-exclusive flag checkboxes.
//
if (isset($_POST[$_config['flags_var_name']]['__url_enc']))
{
    $_enc = $_POST[$_config['flags_var_name']]['__url_enc'];
    unset($_POST[$_config['flags_var_name']]['rotate13']);
    unset($_POST[$_config['flags_var_name']]['base64_encode']);
    unset($_POST[$_config['flags_var_name']]['encrypt_url']);
    if ($_enc === 'rot13')     $_POST[$_config['flags_var_name']]['rotate13']      = 1;
    if ($_enc === 'base64')    $_POST[$_config['flags_var_name']]['base64_encode'] = 1;
    if ($_enc === 'encrypted') $_POST[$_config['flags_var_name']]['encrypt_url']   = 1;
    unset($_POST[$_config['flags_var_name']]['__url_enc']);
}

//
// SET FLAGS
//

if (isset($_POST[$_config['url_var_name']]) && !isset($_GET[$_config['url_var_name']]) && isset($_POST[$_config['flags_var_name']]))
{
    foreach ($_flags as $flag_name => $flag_value)
    {
        $_iflags .= isset($_POST[$_config['flags_var_name']][$flag_name]) ? (string)(int)(bool)$_POST[$_config['flags_var_name']][$flag_name] : ($_frozen_flags[$flag_name] ? $flag_value : '0');

    }

    $_iflags = base_convert(($_iflags != '' ? $_iflags : '0'), 2, 16);
}
else if (isset($_GET[$_config['flags_var_name']]) && !isset($_GET[$_config['get_form_name']]) && ctype_alnum($_GET[$_config['flags_var_name']]))
{
    $_iflags = $_GET[$_config['flags_var_name']];
}
else if (isset($_COOKIE['flags']) && ctype_alnum($_COOKIE['flags']))
{
    $_iflags = $_COOKIE['flags'];
}

if ($_iflags !== '')
{
    $_set_cookie[] = add_cookie('flags', $_iflags, time()+2419200);
    $_iflags = str_pad(base_convert($_iflags, 16, 2), count($_flags), '0', STR_PAD_LEFT);
    $i = 0;

    foreach ($_flags as $flag_name => $flag_value)
    {
        $_flags[$flag_name] = $_frozen_flags[$flag_name] ? $flag_value : (int)(bool)$_iflags[$i];
        $i++;
    }
}

//
// COMPRESS OUTPUT IF INSTRUCTED
//

if ($_config['compress_output'] && $_system['gzip'])
{
    ob_start('ob_gzhandler');
}

//
// STRIP SLASHES FROM GPC IF NECESSARY
//

if ($_system['stripslashes'])
{
    function _stripslashes(mixed $value): mixed
    {
        return is_array($value) ? array_map('_stripslashes', $value) : (is_string($value) ? stripslashes($value) : $value);
    }

    $_GET    = _stripslashes($_GET);
    $_POST   = _stripslashes($_POST);
    $_COOKIE = _stripslashes($_COOKIE);
}

//
// PATH-STYLE ROUTING (#24): phproxy.example/https://target/path
// Apache collapses consecutive slashes in URL paths, so we accept
// "http:/target" (single slash) and restore "http://target".
//

if (!isset($_POST[$_config['url_var_name']])
    && !isset($_GET[$_config['url_var_name']])
    && !isset($_GET[$_config['get_form_name']])
    && !empty($_SERVER['PATH_INFO'])
    && preg_match('#^/(https?):/+(.*)$#i', $_SERVER['PATH_INFO'], $_path_match))
{
    $_path_target = $_path_match[1] . '://' . $_path_match[2];
    if (!empty($_SERVER['QUERY_STRING'])) {
        $_path_target .= (strpos($_path_target, '?') === false ? '?' : '&') . $_SERVER['QUERY_STRING'];
    }
    $_GET[$_config['url_var_name']] = encode_url($_path_target);
}

//
// FIGURE OUT WHAT TO DO (POST URL-form submit, GET form request, regular request, basic auth, cookie manager, show URL-form)
//

if (isset($_POST[$_config['url_var_name']]) && !isset($_GET[$_config['url_var_name']]))
{
    header('Location: ' . $_script_url . '?' . $_config['url_var_name'] . '=' . encode_url($_POST[$_config['url_var_name']]) . '&' . $_config['flags_var_name'] . '=' . base_convert($_iflags, 2, 16));
    exit(0);
}

if (isset($_GET[$_config['get_form_name']]))
{
    $_url  = decode_url($_GET[$_config['get_form_name']]);
    $qstr = strpos($_url, '?') !== false ? (strpos($_url, '?') === strlen($_url)-1 ? '' : '&') : '?';
    $arr  = explode('&', $_SERVER['QUERY_STRING']);

    if (preg_match('#^\Q' . $_config['get_form_name'] . '\E#', $arr[0]))
    {
        array_shift($arr);
    }

    $_url .= $qstr . implode('&', $arr);
}
else if (isset($_GET[$_config['url_var_name']]))
{
    $_url = decode_url($_GET[$_config['url_var_name']]);
}
else
{
    show_report(['which' => 'index', 'category' => 'entry_form']);
}

// Clean tracking params from the user-typed/incoming URL too, before we visit it
$_url = strip_tracking_params($_url);

if (isset($_GET[$_config['url_var_name']], $_POST[$_config['basic_auth_var_name']], $_POST['username'], $_POST['password']))
{
    $_request_method    = 'GET';
    $_basic_auth_realm  = base64_decode($_POST[$_config['basic_auth_var_name']]);
    $_basic_auth_header = base64_encode($_POST['username'] . ':' . $_POST['password']);
}

//
// SET URL
//

if (strpos($_url, '://') === false)
{
    $_url = 'http://' . $_url;
}

if (url_parse($_url, $_url_parts))
{
    $_base = $_url_parts;

    if (!empty($_hosts))
    {
        foreach ($_hosts as $host)
        {
            if (preg_match($host, $_url_parts['host']))
            {
                show_report(['which' => 'index', 'category' => 'error', 'group' => 'url', 'type' => 'external', 'error' => 1]);
            }
        }
    }
}
else
{
    show_report(['which' => 'index', 'category' => 'error', 'group' => 'url', 'type' => 'external', 'error' => 2]);
}


	/*
	* Check if the hostname is valid otherwise try to convert to idna
	*/
	$chars = str_split($_url_parts['host']);
	foreach($chars as $char){
		if(ord($char)>122){
			require_once "./files/php/idna.class.php";
			$php_idna = new php_idna();
			$_url_parts['host'] = $php_idna->encode($_url_parts['host']);
			break;
		}
	}


//
// HOTLINKING PREVENTION
//

if (!$_config['allow_hotlinking'] && isset($_SERVER['HTTP_REFERER']))
{
    $_hotlink_domains[] = $_http_host;
    $is_hotlinking      = true;

    foreach ($_hotlink_domains as $host)
    {
        if (preg_match('#^https?\:\/\/(www)?\Q' . $host  . '\E(\/|\:|$)#i', trim((string) $_SERVER['HTTP_REFERER'])))
        {
            $is_hotlinking = false;
            break;
        }
    }

    if ($is_hotlinking)
    {
        switch ($_config['upon_hotlink'])
        {
            case 1:
                show_report(['which' => 'index', 'category' => 'error', 'group' => 'resource', 'type' => 'hotlinking']);
                break;
            case 2:
                header('HTTP/1.0 404 Not Found');
                exit(0);
            default:
                header('Location: ' . $_config['upon_hotlink']);
                exit(0);
        }
    }
}

//
// OPEN SOCKET TO SERVER
//

do
{
   $context = stream_context_create();
   if ( $_bindip != 'default') {
      $opts = ['socket' => ['bindto' => $_bindip]];
      $context = stream_context_create($opts);
   }

   $_retry  = false;
   $_socket = @stream_socket_client(($_url_parts['scheme'] === 'https' && $_system['ssl'] ? 'ssl://' : 'tcp://') . $_url_parts['host']. ":". $_url_parts['port'], $err_no, $err_str, 30,STREAM_CLIENT_CONNECT, $context);

    if ($_socket === false)
    {
        show_report(['which' => 'index', 'category' => 'error', 'group' => 'url', 'type' => 'internal', 'error' => $err_no]);
    }

    //
    // SET REQUEST HEADERS
    //
    // Anonymity invariant: the outbound header set is built from scratch
    // out of a known-safe whitelist (method, path, Host, User-Agent, Accept,
    // optional Referer, Cookie, Authorization, and POST body headers).
    // We never forward client-identifying $_SERVER['HTTP_X_FORWARDED_FOR'],
    // 'HTTP_X_REAL_IP', 'HTTP_VIA', 'HTTP_FORWARDED', or any other inbound
    // proxy header to the upstream. Targets see this server's IP only.
    //

    $_request_headers  = $_request_method . ' ' . $_url_parts['path'];

    if (isset($_url_parts['query']))
    {
        $_request_headers .= '?';
        $query = preg_split('#([&;])#', $_url_parts['query'], -1, PREG_SPLIT_DELIM_CAPTURE);
        for ($i = 0, $count = count($query); $i < $count; $_request_headers .= implode('=', array_map('urlencode', array_map('urldecode', explode('=', $query[$i])))) . (isset($query[++$i]) ? $query[$i] : ''), $i++);
    }

    $_request_headers .= " HTTP/1.0\r\n";
    $_request_headers .= 'Host: ' . $_url_parts['host'] . $_url_parts['port_ext'] . "\r\n";

    if (!empty($_user_agent))
    {
        $_request_headers .= 'User-Agent: ' . $_user_agent . "\r\n";
    }
    if (isset($_SERVER['HTTP_ACCEPT']))
    {
        $_request_headers .= 'Accept: ' . $_SERVER['HTTP_ACCEPT'] . "\r\n";
    }
    else
    {
        $_request_headers .= "Accept: */*;q=0.1\r\n";
    }
    if ($_flags['show_referer'] && isset($_SERVER['HTTP_REFERER']) && preg_match('#^\Q' . $_script_url . '?' . $_config['url_var_name'] . '=\E([^&]+)#', $_SERVER['HTTP_REFERER'], $matches))
    {
        $_request_headers .= 'Referer: ' . decode_url($matches[1]) . "\r\n";
    }
    if ($_flags['send_dnt'])
    {
        $_request_headers .= "DNT: 1\r\n";
    }
    if ($_flags['send_gpc'])
    {
        $_request_headers .= "Sec-GPC: 1\r\n";
    }
    // Custom headers (managed from the Headers tab, stored as hdr_<name> cookies)
    foreach ($_COOKIE as $_ck => $_cv)
    {
        if (strpos($_ck, 'hdr_') !== 0) continue;
        $_hdr_name = substr($_ck, 4);
        if (preg_match('/^[A-Za-z0-9-]+$/', $_hdr_name) && strpbrk($_cv, "\r\n") === false) {
            $_request_headers .= $_hdr_name . ': ' . $_cv . "\r\n";
        }
    }
    if (!empty($_COOKIE))
    {
        $_cookie  = '';
        $_auth_creds    = [];

        foreach ($_COOKIE as $cookie_id => $cookie_content)
        {
            $cookie_id      = explode(';', rawurldecode($cookie_id));
            $cookie_content = explode(';', rawurldecode($cookie_content));

            if ($cookie_id[0] === 'COOKIE')
            {
                $cookie_id[3] = str_replace('_', '.', $cookie_id[3]); //stupid PHP can't have dots in var names

                if (count($cookie_id) < 4 || ($cookie_content[1] == 'secure' && $_url_parts['scheme'] != 'https'))
                {
                    continue;
                }

                if ((preg_match('#\Q' . $cookie_id[3] . '\E$#i', $_url_parts['host']) || strtolower($cookie_id[3]) == strtolower('.' . $_url_parts['host'])) && preg_match('#^\Q' . $cookie_id[2] . '\E#', $_url_parts['path']))
                {
                    $_cookie .= ($_cookie != '' ? '; ' : '') . (empty($cookie_id[1]) ? '' : $cookie_id[1] . '=') . $cookie_content[0];
                }
            }
            else if ($cookie_id[0] === 'AUTH' && count($cookie_id) === 3)
            {
                $cookie_id[2] = str_replace('_', '.', $cookie_id[2]);

                if ($_url_parts['host'] . ':' . $_url_parts['port'] === $cookie_id[2])
                {
                    $_auth_creds[$cookie_id[1]] = $cookie_content[0];
                }
            }
        }

        if ($_cookie != '')
        {
            $_request_headers .= "Cookie: $_cookie\r\n";
        }
    }
    if (isset($_url_parts['user'], $_url_parts['pass']))
    {
        $_basic_auth_header = base64_encode($_url_parts['user'] . ':' . $_url_parts['pass']);
    }
    if (!empty($_basic_auth_header))
    {
        $_set_cookie[] = add_cookie("AUTH;{$_basic_auth_realm};{$_url_parts['host']}:{$_url_parts['port']}", $_basic_auth_header);
        $_request_headers .= "Authorization: Basic {$_basic_auth_header}\r\n";
    }
    else if (!empty($_basic_auth_realm) && isset($_auth_creds[$_basic_auth_realm]))
    {
        $_request_headers  .= "Authorization: Basic {$_auth_creds[$_basic_auth_realm]}\r\n";
    }
    else if (!empty($_auth_creds))
    {
        $_basic_auth_realm  = array_key_first($_auth_creds);
        $_basic_auth_header = $_auth_creds[$_basic_auth_realm];

        $_request_headers .= "Authorization: Basic {$_basic_auth_header}\r\n";
    }
    if ($_request_method == 'POST')
    {
        if (!empty($_FILES) && $_system['uploads'])
        {
            $_data_boundary = '----' . md5(uniqid(rand(), true));
            $array = set_post_vars($_POST);

            foreach ($array as $key => $value)
            {
                $_post_body .= "--{$_data_boundary}\r\n";
                $_post_body .= "Content-Disposition: form-data; name=\"$key\"\r\n\r\n";
                $_post_body .= urldecode($value) . "\r\n";
            }

            $array = set_post_files($_FILES);

            foreach ($array as $key => $file_info)
            {
                $_post_body .= "--{$_data_boundary}\r\n";
                $_post_body .= "Content-Disposition: form-data; name=\"$key\"; filename=\"{$file_info['name']}\"\r\n";
                $_post_body .= 'Content-Type: ' . (empty($file_info['type']) ? 'application/octet-stream' : $file_info['type']) . "\r\n\r\n";

                if (is_readable($file_info['tmp_name']))
                {
                    $handle = fopen($file_info['tmp_name'], 'rb');
                    $_post_body .= fread($handle, filesize($file_info['tmp_name']));
                    fclose($handle);
                }

                $_post_body .= "\r\n";
            }

            $_post_body       .= "--{$_data_boundary}--\r\n";
            $_request_headers .= "Content-Type: multipart/form-data; boundary={$_data_boundary}\r\n";
            $_request_headers .= "Content-Length: " . strlen($_post_body) . "\r\n\r\n";
            $_request_headers .= $_post_body;
        }
        else
        {
            $array = set_post_vars($_POST);

            foreach ($array as $key => $value)
            {
                $_post_body .= !empty($_post_body) ? '&' : '';
                $_post_body .= $key . '=' . $value;
            }
            $_request_headers .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $_request_headers .= "Content-Length: " . strlen($_post_body) . "\r\n\r\n";
            $_request_headers .= $_post_body;
            $_request_headers .= "\r\n";
        }

        $_post_body = '';
    }
    else
    {
        $_request_headers .= "\r\n";
    }

    fwrite($_socket, $_request_headers);

    //
    // PROCESS RESPONSE HEADERS
    //

    $_response_headers = $_response_keys = [];

    $line = fgets($_socket, 8192);

    while (strspn($line, "\r\n") !== strlen($line))
    {
        @list($name, $value) = explode(':', $line, 2);
        $name = trim((string) $name);
        $_response_headers[strtolower($name)][] = trim((string) $value);
        $_response_keys[strtolower($name)] = $name;
        $line = fgets($_socket, 8192);
    }

    sscanf(current($_response_keys), '%s %s', $_http_version, $_response_code);

    if (isset($_response_headers['content-type']))
    {
        list($_content_type, ) = explode(';', str_replace(' ', '', strtolower($_response_headers['content-type'][0])), 2);
    }
    if (isset($_response_headers['content-length']))
    {
        $_content_length = $_response_headers['content-length'][0];
        unset($_response_headers['content-length'], $_response_keys['content-length']);
    }
    if (isset($_response_headers['content-disposition']))
    {
        $_content_disp = $_response_headers['content-disposition'][0];
        unset($_response_headers['content-disposition'], $_response_keys['content-disposition']);
    }
    if (isset($_response_headers['set-cookie']) && $_flags['accept_cookies'])
    {
        foreach ($_response_headers['set-cookie'] as $cookie)
        {
            $name = $value = $expires = $path = $domain = $secure = $expires_time = '';

            preg_match('#^\s*([^=;,\s]*)\s*=?\s*([^;]*)#',  $cookie, $match) && list(, $name, $value) = $match;
            preg_match('#;\s*expires\s*=\s*([^;]*)#i',      $cookie, $match) && list(, $expires)      = $match;
            preg_match('#;\s*path\s*=\s*([^;,\s]*)#i',      $cookie, $match) && list(, $path)         = $match;
            preg_match('#;\s*domain\s*=\s*([^;,\s]*)#i',    $cookie, $match) && list(, $domain)       = $match;
            preg_match('#;\s*(secure\b)#i',                 $cookie, $match) && list(, $secure)       = $match;

            $expires_time = empty($expires) ? 0 : intval(@strtotime($expires));
            $expires = ($_flags['session_cookies'] && !empty($expires) && time()-$expires_time < 0) ? '' : $expires;
            $path    = empty($path)   ? '/' : $path;

            if (empty($domain))
            {
                $domain = $_url_parts['host'];
            }
            else
            {
                $domain = '.' . strtolower(str_replace('..', '.', trim((string) $domain, '.')));

                if ((!preg_match('#\Q' . $domain . '\E$#i', $_url_parts['host']) && $domain != '.' . $_url_parts['host']) || (substr_count($domain, '.') < 2 && $domain[0] == '.'))
                {
                    continue;
                }
            }
            if (count($_COOKIE) >= 15 && time()-$expires_time <= 0)
            {
                $_set_cookie[] = add_cookie(current($_COOKIE), '', 1);
            }

            $_set_cookie[] = add_cookie("COOKIE;$name;$path;$domain", "$value;$secure", $expires_time);
        }
    }
    if (isset($_response_headers['set-cookie']))
    {
        unset($_response_headers['set-cookie'], $_response_keys['set-cookie']);
    }
    if (!empty($_set_cookie))
    {
        $_response_keys['set-cookie'] = 'Set-Cookie';
        $_response_headers['set-cookie'] = $_set_cookie;
    }
    if (isset($_response_headers['p3p']) && preg_match('#policyref\s*=\s*[\'"]?([^\'"\s]*)[\'"]?#i', $_response_headers['p3p'][0], $matches))
    {
        $_response_headers['p3p'][0] = str_replace($matches[0], 'policyref="' . complete_url($matches[1]) . '"', $_response_headers['p3p'][0]);
    }
    if (isset($_response_headers['refresh']) && preg_match('#([0-9\s]*;\s*URL\s*=)\s*(\S*)#i', $_response_headers['refresh'][0], $matches))
    {
        $_response_headers['refresh'][0] = $matches[1] . complete_url($matches[2]);
    }
    if (isset($_response_headers['location']))
    {
        $_response_headers['location'][0] = complete_url($_response_headers['location'][0]);
    }
    if (isset($_response_headers['uri']))
    {
        $_response_headers['uri'][0] = complete_url($_response_headers['uri'][0]);
    }
    if (isset($_response_headers['content-location']))
    {
        $_response_headers['content-location'][0] = complete_url($_response_headers['content-location'][0]);
    }
    if (isset($_response_headers['connection']))
    {
        unset($_response_headers['connection'], $_response_keys['connection']);
    }
    if (isset($_response_headers['keep-alive']))
    {
        unset($_response_headers['keep-alive'], $_response_keys['keep-alive']);
    }
    if ($_response_code == 401 && isset($_response_headers['www-authenticate']) && preg_match('#basic\s+(?:realm="(.*?)")?#i', $_response_headers['www-authenticate'][0], $matches))
    {
        if (isset($_auth_creds[$matches[1]]) && !$_quit)
        {
            $_basic_auth_realm  = $matches[1];
            $_basic_auth_header = '';
            $_retry = $_quit = true;
        }
        else
        {
            show_report(['which' => 'index', 'category' => 'auth', 'realm' => $matches[1]]);
        }
    }
}
while ($_retry);

//
// OUTPUT RESPONSE IF NO PROXIFICATION IS NEEDED
//

if (!isset($_proxify[$_content_type]))
{
    @set_time_limit(0);

    $_response_keys['content-disposition'] = 'Content-Disposition';
    $_response_headers['content-disposition'][0] = empty($_content_disp) ? ($_content_type == 'application/octet_stream' ? 'attachment' : 'inline') . '; filename="' . $_url_parts['file'] . '"' : $_content_disp;

    if ($_content_length !== false)
    {
        if ($_config['max_file_size'] != -1 && $_content_length > $_config['max_file_size'])
        {
            show_report(['which' => 'index', 'category' => 'error', 'group' => 'resource', 'type' => 'file_size']);
        }

        $_response_keys['content-length'] = 'Content-Length';
        $_response_headers['content-length'][0] = $_content_length;
    }

    $_response_headers   = array_filter($_response_headers);
    $_response_keys      = array_filter($_response_keys);

    header(array_shift($_response_keys));
    array_shift($_response_headers);

    foreach ($_response_headers as $name => $array)
    {
        foreach ($array as $value)
        {
            header($_response_keys[$name] . ': ' . $value, false);
        }
    }

    do
    {
        $data = fread($_socket, 8192);
        echo $data;
    }
    while (isset($data[0]));

    fclose($_socket);
    exit(0);
}

do
{
    $data = @fread($_socket, 8192); // silenced to avoid the "normal" warning by a faulty SSL connection
    $_response_body .= $data;
}
while (isset($data[0]));

unset($data);
fclose($_socket);

//
// MODIFY AND DUMP RESOURCE
//

if ($_content_type == 'text/css')
{
    $_response_body = proxify_css($_response_body);
}
else
{
    if ($_flags['strip_title'])
    {
        $_response_body = preg_replace('#(<\s*title[^>]*>)(.*?)(<\s*/title[^>]*>)#is', '$1$3', $_response_body);
    }
    if ($_flags['remove_scripts'])
    {
        $_response_body = preg_replace('#<\s*script[^>]*?><\s*\/\s*script\s*>#si', '', $_response_body);
        $_response_body = preg_replace('#<\s*script[^>]*?>(.+?(?=<\/script>))?<\s*\/\s*script\s*>#si', '', $_response_body);
        $_response_body = preg_replace("#([\s])?(onload|onsubmit|onclick|onmouseover|onmouseout|onkeydown|onload)=\"([^\"]*)\"([\s])?#i", ' ', $_response_body);
        $_response_body = preg_replace("/([\s])?href=\"javascript:.+?(?=\")\"([\s])?/", ' ', $_response_body);
        $_response_body = preg_replace('#<noscript>(.*?)</noscript>#si', "$1", $_response_body);
    }
    if (!$_flags['show_images'])
    {
        $_response_body = preg_replace('#<(img|image)[^>]*?>#si', '', $_response_body);
    }
    if ($_flags['strip_iframes'])
    {
        $_response_body = preg_replace('#<\s*iframe\b[^>]*>.*?<\s*/\s*iframe\s*>#si', '', $_response_body);
        $_response_body = preg_replace('#<\s*iframe\b[^>]*/?>#si', '', $_response_body);
    }
    if ($_flags['block_media'])
    {
        $_response_body = preg_replace('#<\s*(video|audio)\b[^>]*>.*?<\s*/\s*\1\s*>#si', '', $_response_body);
        $_response_body = preg_replace('#<\s*(video|audio|source|track)\b[^>]*/?>#si', '', $_response_body);
    }
    if ($_flags['block_fonts'])
    {
        // <link> tags pointing at known font CDNs, or declaring as="font"
        $_response_body = preg_replace('#<\s*link\b[^>]*\b(?:fonts\.googleapis\.com|fonts\.gstatic\.com|use\.typekit\.net|use\.fontawesome\.com|fonts\.cdnfonts\.com|fonts\.bunny\.net|fonts\.adobe\.com)[^>]*/?>#si', '', $_response_body);
        $_response_body = preg_replace('#<\s*link\b[^>]*\bas\s*=\s*["\']?font["\']?[^>]*/?>#si', '', $_response_body);
        // @font-face blocks inside any inline CSS
        $_response_body = preg_replace('#@font-face\s*\{[^}]*\}#si', '', $_response_body);
    }

    //
    // PROXIFY HTML RESOURCE
    //

    $tags = array
    (
        'a'          => ['href', 'data-inbound-url', 'data-href-url'],
        'img'        => ['src', 'longdesc', 'srcset', 'data-src'],
        'image'      => ['src', 'longdesc'],
        'body'       => ['background'],
        'base'       => ['href'],
        'frame'      => ['src', 'longdesc'],
        'iframe'     => ['src', 'longdesc'],
        'head'       => ['profile'],
        'layer'      => ['src'],
        'input'      => ['src', 'usemap'],
        'form'       => ['action'],
        'area'       => ['href'],
        'link'       => ['href', 'src', 'urn', 'integrity'],
        'meta'       => ['content'],
        'param'      => ['value'],
        'applet'     => ['codebase', 'code', 'object', 'archive'],
        'object'     => ['usermap', 'codebase', 'classid', 'archive', 'data'],
        'script'     => ['src'],
        'select'     => ['src'],
        'hr'         => ['src'],
        'table'      => ['background'],
        'tr'         => ['background'],
        'th'         => ['background'],
        'td'         => ['background'],
        'bgsound'    => ['src'],
        'blockquote' => ['cite'],
        'del'        => ['cite'],
        'embed'      => ['src'],
        'fig'        => ['src', 'imagemap'],
        'ilayer'     => ['src'],
        'ins'        => ['cite'],
        'note'       => ['src'],
        'overlay'    => ['src', 'imagemap'],
        'q'          => ['cite'],
        'ul'         => ['src'],
        'use'        => ['xlink:href'],
        'source'     => ['srcset'],
    );

    preg_match_all('#(<\s*style[^>]*>)(.*?)(<\s*/\s*style[^>]*>)#is', $_response_body, $matches, PREG_SET_ORDER);

    for ($i = 0, $count_i = count($matches); $i < $count_i; ++$i)
    {
        $_response_body = str_replace($matches[$i][0], $matches[$i][1]. proxify_css($matches[$i][2]) .$matches[$i][3], $_response_body);
    }

    preg_match_all("#<\s*([a-zA-Z0-9\?-]+)(((?:\s+[a-zA-Z0-9:\-\/]+(?:\s*=\s*(?:(?:\"[^\"]*\")|(?:'[^']*')|[^>\s]+))?)*)\s*(>|\/>))#s", $_response_body, $matches);

    for ($i = 0, $count_i = count($matches[0]); $i < $count_i; ++$i)
    {
        if (!preg_match_all("#([a-zA-Z0-9:\-\/]+)\s*(?:=\s*(?:\"([^\"]*)\"?|'([^']*)'?|([^'\"\s>]*)))?#s", $matches[2][$i], $m, PREG_SET_ORDER))
        {
            continue;
        }

        $rebuild    = false;
        $extra_html = $temp = '';
        $attrs      = [];

        for ($j = 0, $count_j = count($m); $j < $count_j; $attrs[strtolower($m[$j][1])] = (isset($m[$j][4]) ? $m[$j][4] : (isset($m[$j][3]) ? $m[$j][3] : (isset($m[$j][2]) ? $m[$j][2] : false))), ++$j);

        if (isset($attrs['style']))
        {
            $rebuild = true;
            $attrs['style'] = proxify_inline_css($attrs['style']);
        }

        $tag = strtolower($matches[1][$i]);

        if (isset($tags[$tag]))
        {
            switch ($tag)
            {
                case 'a':
                    if (isset($attrs['href']))
                    {
                        $rebuild = true;
                        $attrs['href'] = complete_url($attrs['href']);
                    }
                    if (isset($attrs['data-inbound-url']))
                    {
                        $rebuild = true;
                        $attrs['data-inbound-url'] = complete_url($attrs['data-inbound-url']);
                    }
                    if (isset($attrs['data-href-url']))
                    {
                        $rebuild = true;
                        $attrs['data-href-url'] = complete_url($attrs['data-href-url']);
                    }
                    break;
                case 'link':
                    if (isset($attrs['href']))
                    {
                        $rebuild = true;
                        $attrs['href'] = complete_url($attrs['href']);
                    }
                    if (isset($attrs['src']))
                    {
                        $rebuild = true;
                        $attrs['src'] = complete_url($attrs['src']);
                    }
                    if (isset($attrs['urn']))
                    {
                        $rebuild = true;
                        $attrs['urn'] = complete_url($attrs['urn']);
                    }
                    if (isset($attrs['integrity']))
                    {
                        $rebuild = true;
                        $attrs['integrity'] = '';
                    }
                    break;
                case 'img':
                    if (isset($attrs['src']))
                    {
                        $rebuild = true;
                        $attrs['src'] = complete_url($attrs['src']);
                    }
                    if (isset($attrs['longdesc']))
                    {
                        $rebuild = true;
                        $attrs['longdesc'] = complete_url($attrs['longdesc']);
                    }
                    if (isset($attrs['srcset']))
                    {
                        $rebuild = true;
                        $str = preg_replace('/\s+/', ' ', $attrs['srcset']);
                        $src_set_data = explode(',', $attrs['srcset']);
                        foreach($src_set_data as $item) {
                            $item = trim($item);
                            $_data_ = explode(' ', $item);
                            $src_set_data_2[] = $_data_;
                        }
                        foreach($src_set_data_2 as $item) {
                            foreach($item as $item_2) {
                                if($item_2 == $item[0]) {
                                    $final .= complete_url($item_2);
                                } else {
                                    $final .= ' '.$item_2;
                                }
                            }
                            $final = trim($final).', ';
                        }
                        $attrs['srcset'] = trim(trim($final), ',');
                        unset($final, $src_set_data_2);
                    }
                    if (isset($attrs['data-srcset']))
                    {
                        $rebuild = true;
                        $str = preg_replace('/\s+/', ' ', $attrs['data-srcset']);
                        $src_set_data = explode(',', $attrs['data-srcset']);
                        foreach($src_set_data as $item) {
                            $item = trim($item);
                            $_data_ = explode(' ', $item);
                            $src_set_data_2[] = $_data_;
                        }
                        foreach($src_set_data_2 as $item) {
                            foreach($item as $item_2) {
                                if($item_2 == $item[0]) {
                                    $final .= complete_url($item_2);
                                } else {
                                    $final .= ' '.$item_2;
                                }
                            }
                            $final = trim($final).', ';
                        }
                        $attrs['data-srcset'] = trim(trim($final), ',');
                        unset($final, $src_set_data_2);
                    }
                    if (isset($attrs['data-src']))
                    {
                        $rebuild = true;
                        $attrs['data-src'] = complete_url($attrs['data-src']);
                    }
                    if (isset($attrs['data-cfsrc']))
                    {
                        $rebuild = true;
                        $attrs['data-cfsrc'] = complete_url($attrs['data-cfsrc']);
                    }
                    if (!isset($attrs['src']) && isset($attrs['data-src']))
                    {
                        $rebuild = true;
                        $attrs['src'] = complete_url($attrs['data-src']);
                    }
                    break;
                case 'form':
                    if (isset($attrs['action']))
                    {
                        $rebuild = true;

                        if (trim($attrs['action']) === '' || trim($attrs['action'])[0] === '#')
                        {
                            $attrs['action'] = $_url_parts['path'];
                        }
                        if (!isset($attrs['method']) || strtolower(trim($attrs['method'])) === 'get')
                        {
                            $extra_html = '<input type="hidden" name="' . $_config['get_form_name'] . '" value="' . encode_url(complete_url($attrs['action'], false)) . '" />';
                            $attrs['action'] = complete_url($_url);
                            break;
                        }

                        $attrs['action'] = complete_url($attrs['action']);
                    } else {
                        $rebuild = true;
                        if (!isset($attrs['method']) || strtolower(trim($attrs['method'])) === 'get')
                        {
                            $extra_html = '<input type="hidden" name="' . $_config['get_form_name'] . '" value="' . encode_url(complete_url($_url_parts['path'], false)) . '" />';
                            $attrs['action'] = complete_url($_url);
                            break;
                        }
                    }
                    break;
                case 'base':
                    if (isset($attrs['href']))
                    {
                        $rebuild = true;
                        url_parse($attrs['href'], $_base);
                        $attrs['href'] = complete_url($attrs['href']);
                    }
                    break;
                case 'meta':
                    if ($_flags['strip_meta'] && isset($attrs['name']))
                    {
                        $_response_body = str_replace($matches[0][$i], '', $_response_body);
                    }
                    if (isset($attrs['http-equiv'], $attrs['content']) && preg_match('#\s*refresh\s*#i', $attrs['http-equiv']))
                    {
                        if (preg_match('#^(\s*[0-9]*\s*;\s*url=)(.*)#i', $attrs['content'], $content))
                        {
                            $rebuild = true;
                            $attrs['content'] =  $content[1] . complete_url(trim($content[2], '"\''));
                        }
                    }
                    break;
                case 'head':
                    if (isset($attrs['profile']))
                    {
                        $rebuild = true;
                        $attrs['profile'] = implode(' ', array_map('complete_url', explode(' ', $attrs['profile'])));
                    }
                    break;
                case 'applet':
                    if (isset($attrs['codebase']))
                    {
                        $rebuild = true;
                        $temp = $_base;
                        url_parse(complete_url(rtrim($attrs['codebase'], '/') . '/', false), $_base);
                        unset($attrs['codebase']);
                    }
                    if (isset($attrs['code']) && strpos($attrs['code'], '/') !== false)
                    {
                        $rebuild = true;
                        $attrs['code'] = complete_url($attrs['code']);
                    }
                    if (isset($attrs['object']))
                    {
                        $rebuild = true;
                        $attrs['object'] = complete_url($attrs['object']);
                    }
                    if (isset($attrs['archive']))
                    {
                        $rebuild = true;
                        $attrs['archive'] = implode(',', array_map('complete_url', preg_split('#\s*,\s*#', $attrs['archive'])));
                    }
                    if (!empty($temp))
                    {
                        $_base = $temp;
                    }
                    break;
                case 'object':
                    if (isset($attrs['usemap']))
                    {
                        $rebuild = true;
                        $attrs['usemap'] = complete_url($attrs['usemap']);
                    }
                    if (isset($attrs['codebase']))
                    {
                        $rebuild = true;
                        $temp = $_base;
                        url_parse(complete_url(rtrim($attrs['codebase'], '/') . '/', false), $_base);
                        unset($attrs['codebase']);
                    }
                    if (isset($attrs['data']))
                    {
                        $rebuild = true;
                        $attrs['data'] = complete_url($attrs['data']);
                    }
                    if (isset($attrs['classid']) && !preg_match('#^clsid:#i', $attrs['classid']))
                    {
                        $rebuild = true;
                        $attrs['classid'] = complete_url($attrs['classid']);
                    }
                    if (isset($attrs['archive']))
                    {
                        $rebuild = true;
                        $attrs['archive'] = implode(' ', array_map('complete_url', explode(' ', $attrs['archive'])));
                    }
                    if (!empty($temp))
                    {
                        $_base = $temp;
                    }
                    break;
                case 'param':
                    if (isset($attrs['valuetype'], $attrs['value']) && strtolower($attrs['valuetype']) == 'ref' && preg_match('#^[\w.+-]+://#', $attrs['value']))
                    {
                        $rebuild = true;
                        $attrs['value'] = complete_url($attrs['value']);
                    }
                    break;
                case 'frame':
                case 'iframe':
                    if (isset($attrs['src']))
                    {
                        $rebuild = true;
                        $attrs['src'] = complete_url($attrs['src']) . '&nf=1';
                    }
                    if (isset($attrs['longdesc']))
                    {
                        $rebuild = true;
                        $attrs['longdesc'] = complete_url($attrs['longdesc']);
                    }
                    break;
                case 'source':
                    if (isset($attrs['srcset']))
                    {
                        $rebuild = true;
                        $str = preg_replace('/\s+/', ' ', $attrs['srcset']);
                        $src_set_data = explode(',', $attrs['srcset']);
                        foreach($src_set_data as $item) {
                            $item = trim($item);
                            $_data_ = explode(' ', $item);
                            $src_set_data_2[] = $_data_;
                        }
                        foreach($src_set_data_2 as $item) {
                            foreach($item as $item_2) {
                                if($item_2 == $item[0]) {
                                    $final .= complete_url($item_2);
                                } else {
                                    $final .= ' '.$item_2;
                                }
                            }
                            $final = trim($final).', ';
                        }
                        $attrs['srcset'] = trim(trim($final), ',');
                        unset($final, $src_set_data_2);
                    }
                    break;
                default:
                    foreach ($tags[$tag] as $attr)
                    {
                        if (isset($attrs[$attr]))
                        {
                            $rebuild = true;
                            $attrs[$attr] = complete_url($attrs[$attr]);
                        }
                    }
                    break;
            }
        }

        if ($rebuild)
        {
            $new_tag = "<$tag";
            $unpaired_slash = array_key_exists('/', $attrs) ? true : false ;
            foreach ($attrs as $name => $value)
            {
                if($name !== '/') {
                    $delim = strpos($value, '"') && !strpos($value, "'") ? "'" : '"';
                    $new_tag .= ' ' . $name . ($value !== false ? '=' . $delim . $value . $delim : '');
                }
            }
            $_response_body = str_replace($matches[0][$i], $new_tag . ($unpaired_slash ? '/>' : '>') . $extra_html, $_response_body);
        }
    }

    include './files/php/misc.php';
    require_once "./files/php/misc.override.php";
    if ($_flags['include_form'] && !isset($_GET['nf']))
    {
        // PHProxy top bar injected into proxied pages. id-scoped stylesheet
        // with light + dark variants; inline JS reads localStorage and
        // prefers-color-scheme so the bar follows the theme the user picked
        // on the entry form. `all:initial` keeps the host page's CSS from
        // bleeding in.
        $_url_safe       = htmlspecialchars($_url, ENT_QUOTES);
        $_up_url         = $_script_url . '?' . $_config['url_var_name'] . '=' . encode_url($_url_parts['prev_dir']);
        $_up_url_safe    = htmlspecialchars($_up_url, ENT_QUOTES);
        $_home_safe      = htmlspecialchars($_script_base, ENT_QUOTES);
        $_action_safe    = htmlspecialchars($_script_url, ENT_QUOTES);
        $_url_var_safe   = htmlspecialchars($_config['url_var_name']);

        $_bar_css = <<<CSS
#phproxy-bar{all:initial;display:block;position:sticky;top:0;z-index:2147483647;box-sizing:border-box;width:100%;margin:0;padding:8px 12px;background:#ffffff;color:#0f172a;font:13px/1.4 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;border-bottom:1px solid #e2e6ec;}
#phproxy-bar.dark{background:#0f172a;color:#f1f5f9;border-bottom-color:#334155;}
#phproxy-bar *{box-sizing:border-box;font:inherit;color:inherit;}
#phproxy-bar .row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;}
#phproxy-bar input.url{flex:1 1 320px;min-width:0;padding:6px 10px;background:#f8fafc;color:#0f172a;border:1px solid #e2e6ec;border-radius:6px;}
#phproxy-bar.dark input.url{background:#1e293b;color:#f1f5f9;border-color:#334155;}
#phproxy-bar input.url:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.18);}
#phproxy-bar.dark input.url:focus{border-color:#60a5fa;box-shadow:0 0 0 3px rgba(96,165,250,.25);}
#phproxy-bar button.go{padding:6px 14px;background:#2563eb;color:#fff;border:0;border-radius:6px;font:600 13px inherit;cursor:pointer;}
#phproxy-bar button.go:hover{background:#1d4ed8;}
#phproxy-bar.dark button.go{background:#3b82f6;}
#phproxy-bar.dark button.go:hover{background:#60a5fa;}
#phproxy-bar a.link{color:#2563eb;text-decoration:none;}
#phproxy-bar a.link:hover{text-decoration:underline;}
#phproxy-bar.dark a.link{color:#93c5fd;}
#phproxy-bar details.menu{position:relative;margin-left:auto;}
#phproxy-bar details.menu>summary{list-style:none;cursor:pointer;padding:6px;color:inherit;border-radius:6px;display:inline-flex;align-items:center;gap:4px;}
#phproxy-bar details.menu>summary::-webkit-details-marker{display:none;}
#phproxy-bar details.menu>summary:hover{background:rgba(0,0,0,.06);}
#phproxy-bar.dark details.menu>summary:hover{background:rgba(255,255,255,.08);}
#phproxy-bar details.menu>summary svg{width:16px;height:16px;display:block;stroke:currentColor;fill:none;stroke-width:2;}
#phproxy-bar details.menu .popup{position:absolute;top:100%;right:0;margin-top:4px;min-width:160px;background:#fff;border:1px solid #e2e6ec;border-radius:8px;box-shadow:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -4px rgba(0,0,0,.05);padding:4px;}
#phproxy-bar.dark details.menu .popup{background:#1e293b;border-color:#334155;}
#phproxy-bar details.menu .popup a{display:block;padding:6px 10px;color:#0f172a;text-decoration:none;border-radius:4px;}
#phproxy-bar details.menu .popup a:hover{background:#f1f5f9;}
#phproxy-bar.dark details.menu .popup a{color:#f1f5f9;}
#phproxy-bar.dark details.menu .popup a:hover{background:#2a374e;}
CSS;

        // Inline SVG gear icon
        $_gear = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 0 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 0 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 0 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3h0a1.7 1.7 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 0 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8v0a1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>';

        $_url_form = '<style id="phproxy-bar-style">' . $_bar_css . '</style>'
            . '<div id="phproxy-bar">'
            .   '<form class="row" method="post" action="' . $_action_safe . '">'
            .     '<input class="url" id="____' . $_url_var_safe . '" type="text" name="' . $_url_var_safe . '" value="' . $_url_safe . '"/>'
            .     '<button class="go" type="submit" name="go">Go</button>'
            .     '<a class="link" href="' . $_up_url_safe . '">Up</a>'
            .     '<a class="link" href="' . $_home_safe . '">Home</a>'
            .     '<details class="menu">'
            .       '<summary aria-label="Settings menu" title="Settings">' . $_gear . '</summary>'
            .       '<div class="popup">'
            .         '<a href="' . $_home_safe . 'index.php?tab=options">Options</a>'
            .         '<a href="' . $_home_safe . 'index.php?tab=cookies">Cookies</a>'
            .         '<a href="' . $_home_safe . 'index.php?tab=headers">Headers</a>'
            .       '</div>'
            .     '</details>'
            .   '</form>'
            . '</div>'
            . "<script>(function(){try{var s=localStorage.getItem('phproxy-theme');var b=document.getElementById('phproxy-bar');if(!b)return;if(s==='dark')b.classList.add('dark');else if(s!=='light'&&matchMedia('(prefers-color-scheme: dark)').matches)b.classList.add('dark');}catch(e){}})();</script>";

        $_response_body = preg_replace('#\<\s*body(.*?)\>#si', "$0\n$_url_form" , $_response_body, 1);
    }
}
$_response_keys['content-disposition'] = 'Content-Disposition';
$_response_headers['content-disposition'][0] = empty($_content_disp) ? ($_content_type == 'application/octet_stream' ? 'attachment' : 'inline') . '; filename="' . $_url_parts['file'] . '"' : $_content_disp;
$_response_keys['content-length'] = 'Content-Length';
$_response_headers['content-length'][0] = strlen($_response_body);
$_response_keys['proxx-orig-url'] = 'Proxx-Orig-URL';
$_response_headers['proxx-orig-url'][0] = $_url;
$_response_headers   = array_filter($_response_headers);
$_response_keys      = array_filter($_response_keys);

header(array_shift($_response_keys));
array_shift($_response_headers);

foreach ($_response_headers as $name => $array)
{
    foreach ($array as $value)
    {
        $h_name = $_response_keys[$name];
        if(strtolower($h_name) != 'content-security-policy' &&
         strtolower($h_name) != 'content-security-policy-report-only' &&
         strtolower($h_name) != 'x-xss-protection') {
            header($h_name . ': ' . $value, false);
        }
    }
}

echo $_response_body;
