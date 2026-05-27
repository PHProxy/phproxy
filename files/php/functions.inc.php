<?php
function show_report(array $data): void
{
    require_once "./files/php/index.inc.php";
    exit(0);
}

function add_cookie(string $name, mixed $value, int $expires = 0): string
{
    return rawurlencode(rawurlencode($name)) . '=' . rawurlencode(rawurlencode($value)) . (empty($expires) ? '' : '; expires=' . gmdate('D, d-M-Y H:i:s \G\M\T', $expires)) . '; path=/; domain=.' . $GLOBALS['_http_host'];
}

function set_post_vars(array $array, ?string $parent_key = null): array
{
    $temp = [];

    foreach ($array as $key => $value) {
        $key = isset($parent_key) ? sprintf('%s[%s]', $parent_key, urlencode($key)) : urlencode($key);
        if (is_array($value)) {
            $temp = array_merge($temp, set_post_vars($value, $key));
        } else {
            $temp[$key] = urlencode($value);
        }
    }

    return $temp;
}

function set_post_files(array $array, ?string $parent_key = null): array
{
    $temp = [];

    foreach ($array as $key => $value) {
        $key = isset($parent_key) ? sprintf('%s[%s]', $parent_key, urlencode($key)) : urlencode($key);
        if (is_array($value)) {
            $temp = array_merge_recursive($temp, set_post_files($value, $key));
        } else if (preg_match('#^([^\[\]]+)\[(name|type|tmp_name)\]#', $key, $m)) {
            $temp[str_replace($m[0], $m[1], $key)][$m[2]] = $value;
        }
    }

    return $temp;
}

function url_parse(string $url, array &$container): bool
{
    $temp = @parse_url($url);

    if (!empty($temp)) {
        $temp['port_ext'] = '';
        $temp['base']     = $temp['scheme'] . '://' . $temp['host'];

        if (isset($temp['port'])) {
            $temp['base'] .= $temp['port_ext'] = ':' . $temp['port'];
        } else {
            $temp['port'] = $temp['scheme'] === 'https' ? 443 : 80;
        }

        $temp['path'] = isset($temp['path']) ? $temp['path'] : '/';
        $path         = [];
        $temp['path'] = explode('/', $temp['path']);

        foreach ($temp['path'] as $dir) {
            if ($dir === '..') {
                array_pop($path);
            } else if ($dir !== '.') {
                for ($dir = rawurldecode($dir), $new_dir = '', $i = 0, $count_i = strlen($dir); $i < $count_i; $new_dir .= strspn($dir[$i], 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789$-_.+!*\'(),?:@&;=') ? $dir[$i] : rawurlencode($dir[$i]), ++$i);
                $path[] = $new_dir;
            }
        }

        $temp['path'] = str_replace('/%7E', '/~', '/' . ltrim(implode('/', $path), '/'));
        $temp['file'] = substr($temp['path'], strrpos($temp['path'], '/') + 1);
        $temp['dir']  = substr($temp['path'], 0, strrpos($temp['path'], '/'));
        $temp['base'] .= $temp['dir'];
        $temp['prev_dir'] = substr_count($temp['path'], '/') > 1 ? substr($temp['base'], 0, strrpos($temp['base'], '/') + 1) : $temp['base'] . '/';
        $container        = $temp;

        return true;
    }

    return false;
}

/**
 * Parse the raw Cookie request header into wire-form (name, value) pairs.
 * Unlike $_COOKIE, this preserves the exact wire-form name as the browser
 * sent it — no URL decoding, no PHP dot→underscore mangling. We need this
 * to be able to expire proxy-stored cookies via setrawcookie() with the
 * matching name (PHP's setcookie() would URL-encode again and produce a
 * triple-encoded name that doesn't match the browser's stored cookie).
 */
function phproxy_raw_cookies(): array
{
    $out = [];
    $raw = isset($_SERVER['HTTP_COOKIE']) ? (string) $_SERVER['HTTP_COOKIE'] : '';
    if ($raw === '') return $out;
    foreach (explode(';', $raw) as $pair) {
        $pair = ltrim($pair);
        if ($pair === '') continue;
        $eq = strpos($pair, '=');
        if ($eq === false) {
            $out[$pair] = '';
        } else {
            $out[substr($pair, 0, $eq)] = substr($pair, $eq + 1);
        }
    }
    return $out;
}

/**
 * Decode a wire-form cookie name that follows the proxy's
 * COOKIE;<name>;<path>;<domain> format. Returns [name, path, domain] or
 * null if the wire form doesn't match. Walks back the double URL-encoding
 * that add_cookie() applies on the way out.
 */
function phproxy_decode_proxy_cookie_id(string $wire_name): ?array
{
    // Double-rawurldecode the wire form to get the human-readable id
    $decoded = rawurldecode(rawurldecode($wire_name));
    if (strpos($decoded, 'COOKIE;') !== 0) {
        return null;
    }
    $parts = explode(';', $decoded, 4);
    if (count($parts) !== 4) {
        return null;
    }
    return [
        'name'   => $parts[1],
        'path'   => $parts[2],
        'domain' => $parts[3],
    ];
}

/**
 * Decode the value half of a proxy-stored cookie (which has the format
 * "<value>;<secure_flag>" where secure_flag is "secure" or empty).
 * Walks back the double-rawurlencode applied on the way out.
 */
function phproxy_decode_proxy_cookie_value(string $wire_value): array
{
    $decoded = rawurldecode(rawurldecode($wire_value));
    $semi    = strrpos($decoded, ';');
    if ($semi === false) {
        return ['value' => $decoded, 'secure' => false];
    }
    return [
        'value'  => substr($decoded, 0, $semi),
        'secure' => strtolower(trim(substr($decoded, $semi + 1))) === 'secure',
    ];
}

/**
 * Shared User-Agent preset list used by both the entry form's Headers tab
 * and the inline panel injected onto proxied pages.
 */
function phproxy_ua_presets(): array
{
    return [
        ''  => '— Default browser User-Agent —',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36' => 'Chrome on Windows',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15' => 'Safari on macOS',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' => 'Safari on iPhone',
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36' => 'Chrome on Android',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36' => 'Chrome on Linux',
        'Mozilla/5.0 (X11; CrOS x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36' => 'Chrome on ChromeOS',
        'curl/8.5.0'  => 'curl 8.5',
        'Wget/1.21.4' => 'wget 1.21',
        '.' => '★ Use my real browser User-Agent',
        '-' => '★ Send no User-Agent at all',
    ];
}

/**
 * Parse $_SERVER['HTTP_COOKIE'] and bucket the non-settings entries into
 * the structure the panel template expects. Used by both the entry form
 * and the inline panel injection.
 */
function phproxy_panel_buckets(): array
{
    $settings = ['flags', 'userAgent', 'PHPSESSID', 'phproxy-theme', 'phproxy-seed', 'phproxy-seed-ttl', 'phproxy-seed-bits', 'phproxy-show-raw', 'phproxy-panel-tab'];
    $visible  = [];
    $headers  = [];
    foreach (phproxy_raw_cookies() as $wire => $val) {
        if (in_array($wire, $settings, true)) continue;
        if (strpos($wire, 'hdr_') === 0) {
            $headers[substr($wire, 4)] = rawurldecode($val);
            continue;
        }
        $parsed = phproxy_decode_proxy_cookie_id($wire);
        if ($parsed !== null) {
            $v = phproxy_decode_proxy_cookie_value($val);
            $visible[$wire] = [
                'display_name' => $parsed['name'],
                'host'         => ltrim($parsed['domain'], '.'),
                'domain'       => $parsed['domain'],
                'path'         => $parsed['path'],
                'value'        => $v['value'],
                'raw_value'    => $val,
                'raw_name'     => $wire,
                'secure'       => $v['secure'],
                'is_proxy'     => true,
            ];
        } else {
            $visible[$wire] = [
                'display_name' => rawurldecode($wire),
                'host'         => '',
                'domain'       => '',
                'path'         => '',
                'value'        => rawurldecode($val),
                'raw_value'    => $val,
                'raw_name'     => $wire,
                'secure'       => false,
                'is_proxy'     => false,
            ];
        }
    }
    return ['cookies' => $visible, 'headers' => $headers];
}

/**
 * Anonymity seed configuration — TTL (seconds) and key length (bytes).
 * Both adjustable from the Options tab; stored in long-lived settings
 * cookies that survive seed rotation.
 *
 *   phproxy-seed-ttl   3600 default, clamped to [60, 7 days]
 *   phproxy-seed-bits  256  default, accepts 128 / 192 / 256
 */
function phproxy_seed_ttl(): int
{
    $ttl = isset($_COOKIE['phproxy-seed-ttl']) ? (int) $_COOKIE['phproxy-seed-ttl'] : 3600;
    if ($ttl < 60)         $ttl = 60;
    if ($ttl > 86400 * 7)  $ttl = 86400 * 7;
    return $ttl;
}

function phproxy_seed_bits(): int
{
    $bits = isset($_COOKIE['phproxy-seed-bits']) ? (int) $_COOKIE['phproxy-seed-bits'] : 256;
    return in_array($bits, [128, 192, 256], true) ? $bits : 256;
}

function phproxy_seed_cipher(): string
{
    return 'aes-' . phproxy_seed_bits() . '-ctr';
}

/**
 * Master seed for URL encryption. Random bytes, lives for ttl seconds,
 * regenerates on access when missing/malformed. Stored base64 in the
 * phproxy-seed cookie. No external libs — pure PHP (random_bytes, hash,
 * hash_hmac, openssl_encrypt, all in core).
 */
function phproxy_seed(): string
{
    $want_bytes = phproxy_seed_bits() / 8;
    if (!empty($_COOKIE['phproxy-seed'])) {
        $seed = base64_decode((string) $_COOKIE['phproxy-seed'], true);
        if ($seed !== false && strlen($seed) === $want_bytes) {
            return $seed;
        }
    }
    $seed = random_bytes($want_bytes);
    $b64  = base64_encode($seed);
    setcookie('phproxy-seed', $b64, [
        'expires'  => time() + phproxy_seed_ttl(),
        'path'     => '/',
        'samesite' => 'Lax',
        'httponly' => false,
    ]);
    $_COOKIE['phproxy-seed'] = $b64;
    return $seed;
}

/**
 * AES-CTR (128/192/256-bit per phproxy-seed-bits) encrypt the URL with a
 * key derived from the session seed, then HMAC-SHA-256 the (iv||ciphertext)
 * and append a 128-bit tag. Result is base64-url-encoded (no padding) so
 * it goes into the address bar cleanly.
 */
function phproxy_encrypt_url(string $url): string
{
    $seed    = phproxy_seed();
    $cipher  = phproxy_seed_cipher();
    $klen    = phproxy_seed_bits() / 8;
    // SHA-256 derives a 32-byte stream; truncate to AES key length
    $key_enc = substr(hash('sha256', $seed . "\x01enc", true), 0, $klen);
    $key_mac = hash('sha256', $seed . "\x02mac", true);
    $iv      = random_bytes(16);
    $ct      = openssl_encrypt($url, $cipher, $key_enc, OPENSSL_RAW_DATA, $iv);
    if ($ct === false) return '';
    $mac     = substr(hash_hmac('sha256', $iv . $ct, $key_mac, true), 0, 16);
    return rtrim(strtr(base64_encode($iv . $ct . $mac), '+/', '-_'), '=');
}

/**
 * Reverse of phproxy_encrypt_url. Returns null when:
 *   - the seed cookie is missing or malformed (typically because it rotated)
 *   - the base64-url token is malformed
 *   - the HMAC tag doesn't verify (tampering or wrong key)
 *   - openssl_decrypt fails
 */
function phproxy_decrypt_url(string $token): ?string
{
    if (empty($_COOKIE['phproxy-seed'])) return null;
    $bits = phproxy_seed_bits();
    $klen = $bits / 8;
    $seed = base64_decode((string) $_COOKIE['phproxy-seed'], true);
    if ($seed === false || strlen($seed) !== $klen) return null;

    $b64 = strtr($token, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad) $b64 .= str_repeat('=', 4 - $pad);
    $blob = base64_decode($b64, true);
    if ($blob === false || strlen($blob) < 32) return null;

    $iv  = substr($blob, 0, 16);
    $ct  = substr($blob, 16, -16);
    $tag = substr($blob, -16);

    $key_enc  = substr(hash('sha256', $seed . "\x01enc", true), 0, $klen);
    $key_mac  = hash('sha256', $seed . "\x02mac", true);
    $expected = substr(hash_hmac('sha256', $iv . $ct, $key_mac, true), 0, 16);
    if (!hash_equals($expected, $tag)) return null;

    $pt = openssl_decrypt($ct, 'aes-' . $bits . '-ctr', $key_enc, OPENSSL_RAW_DATA, $iv);
    return $pt === false ? null : $pt;
}

/**
 * Drop tracking query parameters (utm_*, fbclid, gclid, ...) from a URL.
 * No-op unless the strip_tracking flag is on. Called both at dispatch
 * (clean the user-typed URL) and during in-page link rewriting.
 */
function strip_tracking_params(string $url): string
{
    if (empty($GLOBALS['_flags']['strip_tracking'])) {
        return $url;
    }
    $parts = @parse_url($url);
    if (empty($parts) || empty($parts['query'])) {
        return $url;
    }
    parse_str($parts['query'], $params);
    $tracking = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term',
        'utm_content', 'utm_id', 'utm_name', 'utm_referrer', 'utm_pubreferrer',
        'fbclid', 'gclid', 'gclsrc', 'msclkid', 'dclid', 'yclid',
        'mc_eid', 'mc_cid',
        '_ga', '_gid',
        'vero_id', 'vero_conv',
        'hsCtaTracking',
        'oly_anon_id', 'oly_enc_id',
        '__hstc', '__hssc', '__hsfp',
        'igshid', 'twclid', 'ttclid',
        'mkt_tok',
        'wickedid', 'wickedsource',
    ];
    foreach ($tracking as $k) {
        foreach ([$k, strtoupper($k)] as $variant) {
            unset($params[$variant]);
        }
    }
    $new_query = http_build_query($params);
    $rebuilt   = (isset($parts['scheme']) ? $parts['scheme'] . '://' : '')
               . (isset($parts['user']) ? $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@' : '')
               . ($parts['host'] ?? '')
               . (isset($parts['port']) ? ':' . $parts['port'] : '')
               . ($parts['path'] ?? '')
               . ($new_query !== '' ? '?' . $new_query : '')
               . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    return $rebuilt;
}

function complete_url(string $url, bool $proxify = true): string
{
    $url = html_entity_decode(trim($url));

    if ($url === '') {
        return '';
    }

    if (substr($url, 0, 5) == 'data:' ||
        substr($url, 0, 11) == 'javascript:' ||
        substr($url, 0, 6) == 'about:' ||
        substr($url, 0, 7) == 'magnet:' ||
        substr($url, 0, 4) == 'tel:' ||
        substr($url, 0, 8) == 'ios-app:' ||
        substr($url, 0, 12) == 'android-app:' ||
        substr($url, 0, 7) == 'mailto:' ||
        substr($url, 0, 6) == 'rms://') {
        return $url;
    }

    $hash_pos                   = strrpos($url, '#');
    $fragment                   = $hash_pos !== false ? substr($url, $hash_pos) : '';
    $sep_pos                    = strpos($url, '://');
    $BASE_ORIGIN                = parse_url($GLOBALS['_url']);
    $GLOBALS['_base']['scheme'] = empty($GLOBALS['_base']['scheme']) ? $BASE_ORIGIN['scheme'] : $GLOBALS['_base']['scheme'];
    $GLOBALS['_base']['host']   = empty($GLOBALS['_base']['host']) ? $BASE_ORIGIN['host'] : $GLOBALS['_base']['host'];

    if ($sep_pos === false || $sep_pos > 5) {
        switch ($url[0]) {
        case '/':
            $url = substr($url, 0, 2) === '//' ? $GLOBALS['_base']['scheme'] . ':' . $url : $GLOBALS['_base']['scheme'] . '://' . $GLOBALS['_base']['host'] . $GLOBALS['_base']['port_ext'] . $url;
            break;
        case '?':
            $url = $GLOBALS['_base']['base'] . '/' . $GLOBALS['_base']['file'] . $url;
            break;
        case '#':
            $proxify = false;
            break;
        default:
            $url = $GLOBALS['_base']['base'] . '/' . $url;
        }
    }

    // Block 3rd-party resources: if the resolved URL's host differs from the
    // page's host (modulo a leading "www."), neutralize the link so the proxy
    // never fetches the resource.
    if ($proxify && !empty($GLOBALS['_flags']['block_3p']) && !empty($GLOBALS['_url_parts']['host'])) {
        $resolved_host = strtolower((string) (@parse_url($url, PHP_URL_HOST) ?: ''));
        $page_host     = strtolower((string) $GLOBALS['_url_parts']['host']);
        if ($resolved_host !== '' && $page_host !== '') {
            $strip_www = function ($h) { return preg_replace('/^www\./', '', $h); };
            if ($strip_www($resolved_host) !== $strip_www($page_host)) {
                return 'about:blank';
            }
        }
    }

    // Drop tracking query parameters from links rendered in proxied pages
    $url = strip_tracking_params($url);

    return $proxify ? "{$GLOBALS['_script_url']}?{$GLOBALS['_config']['url_var_name']}=" . encode_url($url) . $fragment : $url;
}

function proxify_inline_css(string $css): string
{
    preg_match_all('#url\s*\(\s*(.+?(?=\)[f;,}!\s*]))\)#i', $css, $matches, PREG_SET_ORDER);

    for ($i = 0, $count = count($matches); $i < $count; ++$i) {
        $css = str_replace($matches[$i][0], 'url(' . proxify_css_url($matches[$i][1]) . ')', $css);
    }

    return $css;
}

function proxify_css(string $css): string
{
    $css = proxify_inline_css($css);

    preg_match_all("#@import\s*(?:\"([^\">]*)\"?|'([^'>]*)'?)([^;]*)(;|$)#i", $css, $matches, PREG_SET_ORDER);

    for ($i = 0, $count = count($matches); $i < $count; ++$i) {
        $delim = '"';
        $url   = $matches[$i][2];

        if (isset($matches[$i][3])) {
            $delim = "'";
            $url   = $matches[$i][3];
        }

        $css = str_replace($matches[$i][0], '@import ' . $delim . proxify_css_url($matches[$i][1]) . $delim . (isset($matches[$i][4]) ? $matches[$i][4] : ''), $css);
    }

    return $css;
}

function proxify_css_url(string $url): string
{
    $url   = trim($url);
    $delim = strpos($url, '"') === 0 ? '"' : (strpos($url, "'") === 0 ? "'" : '');
    if ($delim !== '') {
        $url = trim($url, $delim);
    }
    if (substr($url, 0, 5) == 'data:' ||
        substr($url, 0, 11) == 'javascript:' ||
        substr($url, 0, 6) == 'about:' ||
        substr($url, 0, 7) == 'magnet:' ||
        substr($url, 0, 4) == 'tel:' ||
        substr($url, 0, 8) == 'ios-app:' ||
        substr($url, 0, 12) == 'android-app:' ||
        substr($url, 0, 7) == 'mailto:' ||
        substr($url, 0, 6) == 'rms://') {
        return $delim . $url . $delim;
    }

    return $delim . preg_replace('#([\(\),\s\'"\\\])#', '\\$1', complete_url(trim(preg_replace('#\\\(.)#', '$1', $url)))) . $delim;
}

function encode_url(string $url): string
{
    global $_flags;

    // Encrypted mode produces a base64-url token already safe for the address
    // bar; skip the surrounding rawurlencode so the cipherblob isn't double
    // encoded.
    if (!empty($_flags['encrypt_url'])) {
        return phproxy_encrypt_url($url);
    }
    if ($_flags['rotate13']) {
        $url = str_rot13($url);
    } elseif ($_flags['base64_encode']) {
        $url = base64_encode($url);
    }

    return rawurlencode($url);
}

function decode_url(string $url): string
{
    global $_flags;

    if (!empty($_flags['encrypt_url'])) {
        $plain = phproxy_decrypt_url($url);
        // Null = seed rotated / tampered / malformed — surface as an empty
        // URL so the dispatcher renders the entry form instead of trying to
        // proxy garbage.
        return $plain === null ? '' : str_replace(['&amp;', '&#38;'], '&', $plain);
    }

    $url = rawurldecode($url);
    if ($_flags['rotate13']) {
        $url = str_rot13($url);
    } elseif ($_flags['base64_encode']) {
        $url = base64_decode($url);
    }

    return str_replace(['&amp;', '&#38;'], '&', $url);
}
