<?php

/**
 * PHProxy v1.2.0
 *
 * Single-file web HTTP/HTTPS proxy in PHP. Drop into any PHP 8.1+ web root
 * and open it — no dependencies, no build step, no `vendor/`, no `files/`
 * directory. Works under any filename (rename to proxy.php if you like).
 *
 * @version    v1.2.0
 * @author     Miglen Evlogiev (revive), PhoenixPeca, Biojet1, Quix0r, dacendo
 * @copyright  2002-2007 A.A. (whitefyre); 2015-2019 contributors; 2025-2026 revive
 * @license    GNU GPL v3
 * @repo       https://github.com/PHProxy/phproxy
 */

/* PRODUCTIVE: */ error_reporting(0);
// DEVELOP: error_reporting(E_ALL); ini_set('display_errors', '1');

// --- ASSET DISPATCHER --------------------------------------------------
// Serves the proxy's own CSS via ?asset=<name>. Keeps the script
// self-contained — no files/ directory needed.
if (isset($_GET['asset'])) {
    header('Cache-Control: public, max-age=3600');
    switch ((string) $_GET['asset']) {
        case 'index.css':   header('Content-Type: text/css; charset=utf-8');         echo phproxy_index_css(); exit;
        case 'panel.css':   header('Content-Type: text/css; charset=utf-8');         echo phproxy_panel_css(); exit;
        case 'netcheck.js': header('Content-Type: application/javascript; charset=utf-8'); echo phproxy_netcheck_js(); exit;
    }
    http_response_code(404);
    exit;
}

// --- JSON API DISPATCHER (?api=fetch) -----------------------------------
// POST with a JSON body — proxy makes the HTTP request and returns either
// JSON (default) or the raw upstream response.
//
// Body schema:
//   {
//     "url":              "https://example.com/" (required),
//     "method":           "GET" | "POST" | ...   (default: GET),
//     "headers":          { "Name": "value", ... },
//     "cookies":          { "name": "value", ... },
//     "body":             "string",
//     "timeout":          30,
//     "follow_redirects": false,
//     "max_redirects":    5,
//     "verify_ssl":       true,
//     "return":           "json" | "raw"         (default: json)
//   }
// Helper used by the new network-check APIs (dns / portcheck / cert).
$phproxy_api_validate_host = function (string $host): bool {
    if ($host === '' || strlen($host) > 253) return false;
    if (!preg_match('/^[a-zA-Z0-9._:-]+$/', $host)) return false;
    $block = '#^127\.|192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.|^localhost$|^::1$|^0+\.0+\.0+\.0+$#i';
    if (preg_match($block, $host)) return false;
    return true;
};

// --- DNS LOOKUP API (?api=dns&host=example.com&type=A) -----------------
if (isset($_GET['api']) && $_GET['api'] === 'dns') {
    header('Content-Type: application/json; charset=utf-8');
    $host = (string) ($_GET['host'] ?? '');
    $type = strtoupper((string) ($_GET['type'] ?? 'A'));
    if (!$phproxy_api_validate_host($host)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid or blacklisted host']);
        exit;
    }
    $types = [
        'A'     => DNS_A,     'AAAA'  => DNS_AAAA, 'MX'    => DNS_MX,
        'TXT'   => DNS_TXT,   'NS'    => DNS_NS,   'SOA'   => DNS_SOA,
        'CAA'   => DNS_CAA,   'CNAME' => DNS_CNAME,'PTR'   => DNS_PTR,
        'SRV'   => DNS_SRV,   'ANY'   => DNS_ANY,
    ];
    if (!isset($types[$type])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown record type', 'supported' => array_keys($types)]);
        exit;
    }
    $started = microtime(true);
    $records = @dns_get_record($host, $types[$type]);
    $ms      = (int) ((microtime(true) - $started) * 1000);
    if ($records === false) {
        echo json_encode(['ok' => false, 'host' => $host, 'type' => $type, 'error' => 'Lookup failed', 'duration_ms' => $ms]);
        exit;
    }
    echo json_encode(['ok' => true, 'host' => $host, 'type' => $type, 'records' => $records, 'duration_ms' => $ms], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// --- PORT CHECK API (?api=portcheck&host=example.com&port=443) ---------
// `port` accepts a single port (443), an inclusive range (80-443) or a
// comma-separated mix (22,80,443,8000-8010). Hard cap is 128 ports per
// request; default per-port timeout is 1.0s, override with ?timeout=…
// (clamped 0.1–5.0). Response always carries a `results` array.
if (isset($_GET['api']) && $_GET['api'] === 'portcheck') {
    header('Content-Type: application/json; charset=utf-8');
    $host      = (string) ($_GET['host'] ?? '');
    $port_spec = (string) ($_GET['port'] ?? '');
    $timeout   = (float)  ($_GET['timeout'] ?? 1.0);
    if ($timeout < 0.1) $timeout = 0.1;
    if ($timeout > 5.0) $timeout = 5.0;
    if (!$phproxy_api_validate_host($host)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid or blacklisted host']);
        exit;
    }
    $ports = phproxy_parse_port_spec($port_spec);
    if ($ports === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid port spec. Examples: 443, 80-443, 22,80,443,8000-8010', 'received' => $port_spec]);
        exit;
    }
    if (count($ports) > 128) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Too many ports requested (max 128)', 'requested' => count($ports)]);
        exit;
    }

    // Streaming mode: emit NDJSON — one event per line, flushed immediately,
    // so the UI can show partial progress as each port comes back. Triggered
    // by ?stream=1. Default remains the batched JSON envelope so existing
    // CLI consumers don't break.
    $stream = !empty($_GET['stream']);
    if ($stream) {
        while (ob_get_level() > 0) @ob_end_clean();
        @ob_implicit_flush(true);
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('X-Accel-Buffering: no');   // nginx hint
        header('Cache-Control: no-cache, no-store');
        $emit = function (array $obj): void {
            echo json_encode($obj, JSON_UNESCAPED_SLASHES), "\n";
            @flush();
        };
        $emit([
            'event'     => 'start',
            'host'      => $host,
            'port_spec' => $port_spec,
            'count'     => count($ports),
            'ports'     => $ports,
            'timeout_s' => $timeout,
        ]);
        $scan_started = microtime(true);
        $open = $closed = 0;
        foreach ($ports as $p) {
            if (connection_aborted()) break;
            $t0   = microtime(true);
            $sock = @stream_socket_client("tcp://$host:$p", $errno, $errstr, $timeout);
            $ms   = (int) ((microtime(true) - $t0) * 1000);
            if ($sock === false) {
                $closed++;
                $emit(['event' => 'result', 'port' => $p, 'reachable' => false, 'error' => $errstr ?: 'connect failed', 'errno' => (int) $errno, 'latency_ms' => $ms]);
            } else {
                fclose($sock);
                $open++;
                $emit(['event' => 'result', 'port' => $p, 'reachable' => true, 'latency_ms' => $ms]);
            }
        }
        $emit([
            'event'       => 'end',
            'open'        => $open,
            'closed'      => $closed,
            'duration_ms' => (int) ((microtime(true) - $scan_started) * 1000),
        ]);
        exit;
    }

    // Batched mode (default) — one JSON envelope at the end.
    $scan_started = microtime(true);
    $results = [];
    foreach ($ports as $p) {
        $t0   = microtime(true);
        $sock = @stream_socket_client("tcp://$host:$p", $errno, $errstr, $timeout);
        $ms   = (int) ((microtime(true) - $t0) * 1000);
        if ($sock === false) {
            $results[] = ['port' => $p, 'reachable' => false, 'error' => $errstr ?: 'connect failed', 'errno' => (int) $errno, 'latency_ms' => $ms];
        } else {
            fclose($sock);
            $results[] = ['port' => $p, 'reachable' => true, 'latency_ms' => $ms];
        }
    }
    $duration = (int) ((microtime(true) - $scan_started) * 1000);
    $open     = 0;
    foreach ($results as $r) if (!empty($r['reachable'])) $open++;
    echo json_encode([
        'ok'           => true,
        'host'         => $host,
        'port_spec'    => $port_spec,
        'count'        => count($results),
        'open'         => $open,
        'closed'       => count($results) - $open,
        'timeout_s'    => $timeout,
        'duration_ms'  => $duration,
        'results'      => $results,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Parse a port spec — single, range, or comma list — into a sorted,
 * deduped array of ints. Returns false on any syntax error or out-of-range.
 *   "443"            → [443]
 *   "80-443"         → [80, 81, …, 443]
 *   "22,80,443"      → [22, 80, 443]
 *   "80,8000-8010"   → [80, 8000, …, 8010]
 */
function phproxy_parse_port_spec(string $spec): array|false
{
    $spec = trim($spec);
    if ($spec === '') return false;
    $set = [];
    foreach (explode(',', $spec) as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') continue;
        if (strpos($chunk, '-') !== false) {
            $parts = explode('-', $chunk, 2);
            $a = (int) trim($parts[0]);
            $b = (int) trim($parts[1]);
            if ($a < 1 || $a > 65535 || $b < 1 || $b > 65535 || $b < $a) return false;
            for ($i = $a; $i <= $b; $i++) $set[$i] = true;
        } else {
            if (!ctype_digit($chunk)) return false;
            $p = (int) $chunk;
            if ($p < 1 || $p > 65535) return false;
            $set[$p] = true;
        }
        if (count($set) > 1024) return false;  // hard ceiling to prevent memory abuse; dispatcher rejects >128 with a clearer error
    }
    if (empty($set)) return false;
    ksort($set);
    return array_keys($set);
}

// --- SSL CERT INSPECTOR API (?api=cert&host=example.com&port=443) ------
if (isset($_GET['api']) && $_GET['api'] === 'cert') {
    header('Content-Type: application/json; charset=utf-8');
    $host = (string) ($_GET['host'] ?? '');
    $port = (int)    ($_GET['port'] ?? 443);
    if (!$phproxy_api_validate_host($host)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid or blacklisted host']);
        exit;
    }
    if ($port < 1 || $port > 65535) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid port (1–65535)']);
        exit;
    }
    // First handshake — let OpenSSL negotiate freely so we capture the
    // server's preferred TLS version + cipher AND the peer cert chain.
    $ctx = stream_context_create([
        'ssl' => [
            'capture_peer_cert'       => true,
            'capture_peer_cert_chain' => true,
            'verify_peer'             => false,
            'verify_peer_name'        => false,
            'SNI_enabled'             => true,
            'peer_name'               => $host,
        ],
    ]);
    $started = microtime(true);
    $sock    = @stream_socket_client("ssl://$host:$port", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    $ms      = (int) ((microtime(true) - $started) * 1000);
    if ($sock === false) {
        echo json_encode(['ok' => false, 'host' => $host, 'port' => $port, 'error' => $errstr ?: 'SSL handshake failed', 'errno' => (int) $errno, 'latency_ms' => $ms]);
        exit;
    }
    $meta_main = stream_get_meta_data($sock);
    $params    = stream_context_get_params($sock);
    fclose($sock);
    $leaf  = $params['options']['ssl']['peer_certificate']       ?? null;
    $chain = $params['options']['ssl']['peer_certificate_chain'] ?? [];
    if (!$leaf) {
        echo json_encode(['ok' => false, 'host' => $host, 'port' => $port, 'error' => 'No peer certificate received']);
        exit;
    }
    $parse_cert = function ($cert) {
        $p = @openssl_x509_parse($cert);
        if (!is_array($p)) return null;

        // SAN entries — flatten DNS:/IP Address: prefixes for display
        $san = [];
        if (!empty($p['extensions']['subjectAltName'])) {
            foreach (explode(',', $p['extensions']['subjectAltName']) as $entry) {
                $entry = trim($entry);
                if (str_starts_with($entry, 'DNS:'))    $san[] = substr($entry, 4);
                elseif (str_starts_with($entry, 'IP Address:')) $san[] = substr($entry, 11);
                elseif ($entry !== '')                  $san[] = $entry;
            }
        }

        // Public key — type, size, curve (EC), and the public-key PEM block
        $key_type = ''; $key_bits = 0; $key_curve = ''; $pub_pem = '';
        $pub = @openssl_pkey_get_public($cert);
        if ($pub !== false) {
            $details = @openssl_pkey_get_details($pub);
            if (is_array($details)) {
                $key_bits = (int) ($details['bits'] ?? 0);
                $pub_pem  = (string) ($details['key'] ?? '');
                $type_const = $details['type'] ?? -1;
                if ($type_const === OPENSSL_KEYTYPE_RSA) $key_type = 'RSA';
                elseif ($type_const === OPENSSL_KEYTYPE_DSA) $key_type = 'DSA';
                elseif ($type_const === OPENSSL_KEYTYPE_DH)  $key_type = 'DH';
                elseif (defined('OPENSSL_KEYTYPE_EC') && $type_const === OPENSSL_KEYTYPE_EC) {
                    $key_type  = 'EC';
                    $key_curve = (string) ($details['ec']['curve_name'] ?? '');
                }
            }
        }

        // Fingerprints — colon-separated like every cert tool prints them
        $fmt_fp = function (string $hex): string {
            $hex = strtoupper($hex);
            return implode(':', str_split($hex, 2));
        };
        $fp_sha256 = @openssl_x509_fingerprint($cert, 'sha256') ?: '';
        $fp_sha1   = @openssl_x509_fingerprint($cert, 'sha1')   ?: '';
        $fp_md5    = @openssl_x509_fingerprint($cert, 'md5')    ?: '';

        // Cert in PEM form (raw, full)
        $pem = '';
        @openssl_x509_export($cert, $pem);

        $valid_from = $p['validFrom_time_t'] ?? 0;
        $valid_to   = $p['validTo_time_t']   ?? 0;
        return [
            'version'        => (int) ($p['version'] ?? 0) + 1,   // X.509 reports 0/1/2; humans say v1/v2/v3
            'serial_hex'     => $p['serialNumberHex'] ?? '',
            'serial_dec'     => (string) ($p['serialNumber'] ?? ''),
            'subject'        => $p['name']    ?? '',
            'subject_parts'  => $p['subject'] ?? [],   // full DN as keyed array (CN, O, OU, C, ST, L, …)
            'subject_cn'     => $p['subject']['CN'] ?? '',
            'subject_org'    => $p['subject']['O']  ?? '',
            'issuer_dn'      => isset($p['issuer']) ? phproxy_dn_join($p['issuer']) : '',
            'issuer_parts'   => $p['issuer'] ?? [],
            'issuer_cn'      => $p['issuer']['CN'] ?? '',
            'issuer_org'     => $p['issuer']['O']  ?? '',
            'valid_from'     => $valid_from ? gmdate('Y-m-d\TH:i:s\Z', $valid_from) : '',
            'valid_to'       => $valid_to   ? gmdate('Y-m-d\TH:i:s\Z', $valid_to)   : '',
            'days_left'      => $valid_to ? (int) floor(($valid_to - time()) / 86400) : 0,
            'san'            => $san,
            'sig_algorithm'  => $p['signatureTypeSN'] ?? '',
            'sig_algorithm_long' => $p['signatureTypeLN'] ?? '',
            'sig_oid'        => isset($p['signatureTypeNID']) ? (string) $p['signatureTypeNID'] : '',
            'purposes'       => $p['purposes'] ?? [],
            'extensions'     => $p['extensions'] ?? [],   // all extensions, raw text per OpenSSL
            'key_type'       => $key_type,
            'key_bits'       => $key_bits,
            'key_curve'      => $key_curve,
            'public_key_pem' => $pub_pem,
            'fingerprints'   => [
                'sha256' => $fp_sha256 ? $fmt_fp($fp_sha256) : '',
                'sha1'   => $fp_sha1   ? $fmt_fp($fp_sha1)   : '',
                'md5'    => $fp_md5    ? $fmt_fp($fp_md5)    : '',
            ],
            'pem'            => $pem,
        ];
    };
    $cert_info  = $parse_cert($leaf);
    $chain_info = [];
    foreach ($chain as $c) {
        $ci = $parse_cert($c);
        if ($ci !== null) $chain_info[] = $ci;
    }

    // Probe TLS versions individually. For each, force the version via
    // crypto_method and record which the server actually accepts plus
    // the cipher it negotiates. ~4 extra ~100-500ms handshakes.
    $tls_probes = [
        'TLSv1.3' => defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0,
        'TLSv1.2' => defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT : 0,
        'TLSv1.1' => defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT : 0,
        'TLSv1.0' => defined('STREAM_CRYPTO_METHOD_TLSv1_CLIENT')   ? STREAM_CRYPTO_METHOD_TLSv1_CLIENT   : 0,
    ];
    $tls_results = [];
    foreach ($tls_probes as $label => $method) {
        if ($method === 0) {
            $tls_results[] = ['version' => $label, 'supported' => false, 'error' => 'OpenSSL on this PHP build cannot speak ' . $label];
            continue;
        }
        $pctx = stream_context_create([
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
                'SNI_enabled'      => true,
                'peer_name'        => $host,
                'crypto_method'    => $method,
            ],
        ]);
        $pt0 = microtime(true);
        $psock = @stream_socket_client("ssl://$host:$port", $en, $es, 5, STREAM_CLIENT_CONNECT, $pctx);
        $pms = (int) ((microtime(true) - $pt0) * 1000);
        if ($psock === false) {
            $tls_results[] = ['version' => $label, 'supported' => false, 'error' => $es ?: 'handshake refused', 'latency_ms' => $pms];
            continue;
        }
        $pmeta  = stream_get_meta_data($psock);
        @fclose($psock);
        $crypto = $pmeta['crypto'] ?? [];
        $tls_results[] = [
            'version'        => $label,
            'supported'      => true,
            'cipher_name'    => (string) ($crypto['cipher_name']    ?? ''),
            'cipher_bits'    => (int)    ($crypto['cipher_bits']    ?? 0),
            'cipher_version' => (string) ($crypto['cipher_version'] ?? ''),
            'protocol'       => (string) ($crypto['protocol']       ?? ''),
            'latency_ms'     => $pms,
        ];
    }

    // Negotiated handshake summary (what the server picked when given free choice).
    $main_crypto = $meta_main['crypto'] ?? [];

    echo json_encode([
        'ok'           => true,
        'host'         => $host,
        'port'         => $port,
        'negotiated'   => [
            'protocol'       => (string) ($main_crypto['protocol']       ?? ''),
            'cipher_name'    => (string) ($main_crypto['cipher_name']    ?? ''),
            'cipher_bits'    => (int)    ($main_crypto['cipher_bits']    ?? 0),
            'cipher_version' => (string) ($main_crypto['cipher_version'] ?? ''),
        ],
        'tls_versions' => $tls_results,
        'leaf'         => $cert_info,
        'chain'        => $chain_info,
        'chain_length' => count($chain_info),
        'duration_ms'  => $ms,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// --- IP INFO API (?api=ipinfo[&ip=1.2.3.4]) ------------------------------
// Returns information about the client, the server, and the server's
// outgoing IP — plus reverse DNS, the X-Forwarded-For chain, request
// headers and host info. Pure local PHP — no external lookups, no
// library, no API key. Geo / ASN / city are intentionally not included.
if (isset($_GET['api']) && $_GET['api'] === 'ipinfo') {
    header('Content-Type: application/json; charset=utf-8');
    $started = microtime(true);

    // If the caller passed ?ip=…, that's the single IP we describe.
    $custom_ip = trim((string) ($_GET['ip'] ?? ''));
    if ($custom_ip !== '' && !filter_var($custom_ip, FILTER_VALIDATE_IP)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid IP address', 'received' => $custom_ip]);
        exit;
    }

    $remote  = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $xff_raw = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    $xff_chain = [];
    if ($xff_raw !== '') {
        foreach (explode(',', $xff_raw) as $h) {
            $h = trim($h);
            if ($h !== '' && filter_var($h, FILTER_VALIDATE_IP)) $xff_chain[] = $h;
        }
    }
    // Prefer the first XFF entry (original client) when behind a reverse proxy.
    $client_ip = !empty($xff_chain) ? $xff_chain[0] : $remote;

    // Server's own primary IP via gethostbyname(hostname). Often the
    // internal Docker IP — useful but not "what the internet sees".
    $hostname  = gethostname() ?: '';
    $server_ip = '';
    if ($hostname !== '') {
        $resolved = @gethostbyname($hostname);
        if ($resolved !== false && $resolved !== $hostname) $server_ip = $resolved;
    }

    // Real outgoing IP — what the internet actually sees when this server
    // makes outbound HTTP calls. UDP "connect" trick: no packets sent,
    // but the kernel picks the outgoing interface so the local socket
    // name gives us the right answer.
    $outgoing_ip = phproxy_outgoing_ip();

    $shape = function (string $ip): array {
        if ($ip === '') return ['ip' => ''];
        $is_priv = !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        return [
            'ip'         => $ip,
            'family'     => str_contains($ip, ':') ? 'IPv6' : 'IPv4',
            'is_private' => (bool) $is_priv,
            'reverse'    => $is_priv ? '' : (@gethostbyaddr($ip) ?: ''),
        ];
    };

    if ($custom_ip !== '') {
        echo json_encode([
            'ok'          => true,
            'lookup'      => $shape($custom_ip),
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    echo json_encode([
        'ok'        => true,
        'client'    => $shape($client_ip),
        'server'    => $shape($server_ip),
        'outgoing'  => $shape($outgoing_ip),
        'forwarded' => array_map($shape, $xff_chain),
        'request'   => [
            'method'          => $_SERVER['REQUEST_METHOD']      ?? '',
            'protocol'        => isset($_SERVER['HTTPS']) || ($_SERVER['SERVER_PORT'] ?? '') == '443' ? 'HTTPS' : 'HTTP',
            'remote_addr'     => $remote,
            'host_header'     => $_SERVER['HTTP_HOST']            ?? '',
            'user_agent'      => $_SERVER['HTTP_USER_AGENT']      ?? '',
            'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            'accept'          => $_SERVER['HTTP_ACCEPT']          ?? '',
            'referer'         => $_SERVER['HTTP_REFERER']         ?? '',
            'dnt'             => $_SERVER['HTTP_DNT']             ?? '',
            'sec_gpc'         => $_SERVER['HTTP_SEC_GPC']         ?? '',
            'sec_ch_ua'       => $_SERVER['HTTP_SEC_CH_UA']       ?? '',
            'sec_ch_ua_mobile'=> $_SERVER['HTTP_SEC_CH_UA_MOBILE']?? '',
            'sec_ch_ua_platform' => $_SERVER['HTTP_SEC_CH_UA_PLATFORM'] ?? '',
        ],
        'host'      => [
            'hostname'        => $hostname,
            'php_version'     => PHP_VERSION,
            'php_os'          => PHP_OS,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? '',
            'server_port'     => $_SERVER['SERVER_PORT']     ?? '',
        ],
        'duration_ms' => (int) ((microtime(true) - $started) * 1000),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Build a single-line DN string from the keyed array openssl_x509_parse()
 * returns. Order matches what `openssl x509 -text` prints.
 */
function phproxy_dn_join(array $parts): string
{
    $order = ['C', 'ST', 'L', 'O', 'OU', 'CN', 'emailAddress', 'serialNumber'];
    $out = [];
    foreach ($order as $k) {
        if (isset($parts[$k]) && $parts[$k] !== '') $out[] = "$k=" . (is_array($parts[$k]) ? implode('+', $parts[$k]) : $parts[$k]);
    }
    foreach ($parts as $k => $v) {
        if (in_array($k, $order, true)) continue;
        if ($v === '' || $v === null) continue;
        $out[] = "$k=" . (is_array($v) ? implode('+', $v) : $v);
    }
    return implode(', ', $out);
}

/**
 * Determine the outgoing IPv4 address this server uses when reaching out
 * to the wider internet. UDP "connect" trick: no packets are actually
 * sent, but the kernel picks the outgoing interface so stream_socket_get_name
 * on the local side returns the right answer.
 */
function phproxy_outgoing_ip(): string
{
    $sock = @stream_socket_client('udp://1.1.1.1:53', $en, $es, 1);
    if ($sock === false) {
        $sock = @stream_socket_client('udp://8.8.8.8:53', $en, $es, 1);
    }
    if ($sock === false) return '';
    $name = @stream_socket_get_name($sock, false);  // local side
    @fclose($sock);
    if (!is_string($name) || $name === '') return '';
    // "1.2.3.4:54321" or "[::1]:54321" — strip port.
    if (preg_match('/^\[([^\]]+)\]:\d+$/', $name, $m)) return $m[1];
    if (preg_match('/^([0-9.]+):\d+$/', $name, $m))    return $m[1];
    return $name;
}

if (isset($_GET['api']) && $_GET['api'] === 'fetch') {
    // Helper for any JSON error response in this dispatcher
    $api_json_err = function (int $status, string $msg, array $extra = []): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg] + $extra, JSON_UNESCAPED_SLASHES);
        exit;
    };

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $api_json_err(405, 'POST required');
    $raw_in = file_get_contents('php://input') ?: '';
    $req_in = json_decode($raw_in, true);
    if (!is_array($req_in) || empty($req_in['url'])) $api_json_err(400, 'Invalid JSON body or missing "url"');

    // SSRF guard — refuse the same blacklisted host ranges the browser flow
    // blocks (loopback / RFC1918). Replicate the regex here to fail fast.
    $api_host_block = '#^127\.|192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.|localhost$|^::1$#i';
    $api_url_host = (string) (@parse_url((string) $req_in['url'], PHP_URL_HOST) ?: '');
    if ($api_url_host === '' || preg_match($api_host_block, $api_url_host)) {
        $api_json_err(400, 'Target host blacklisted or unparseable', ['host' => $api_url_host]);
    }

    $api_result = phproxy_api_fetch($req_in);

    if (($req_in['return'] ?? 'json') === 'raw') {
        // Emit the upstream response verbatim. Drop hop-by-hop and encoding
        // headers we've already decoded for the client.
        if (isset($api_result['status'])) http_response_code($api_result['status']);
        $first_ct = true;
        foreach (($api_result['headers'] ?? []) as $hname => $hvalue) {
            $lhname = strtolower($hname);
            if (in_array($lhname, ['transfer-encoding', 'content-encoding', 'content-length', 'connection'], true)) continue;
            // For Content-Type, replace any previous (default Apache sets one);
            // for everything else, append.
            $replace = ($lhname === 'content-type' && $first_ct);
            header($hname . ': ' . $hvalue, $replace);
            if ($lhname === 'content-type') $first_ct = false;
        }
        echo $api_result['body_raw'] ?? '';
        exit;
    }

    // JSON envelope. UTF-8 bodies go in directly, binary gets base64'd.
    header('Content-Type: application/json; charset=utf-8');
    $body_raw = $api_result['body_raw'] ?? '';
    if ($body_raw !== '' && mb_check_encoding($body_raw, 'UTF-8')) {
        $api_result['body']          = $body_raw;
        $api_result['body_encoding'] = 'utf8';
    } else {
        $api_result['body']          = base64_encode($body_raw);
        $api_result['body_encoding'] = 'base64';
    }
    unset($api_result['body_raw']);
    echo json_encode($api_result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

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
$_version           = 'v1.3.1';
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
function show_report(array $data): void
{
    phproxy_render_entry_form($data);
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
        case 'edit-cookie':
            // For edit, $_POST['name'] carries the old wire-form name to be expired.
            if ($_action === 'edit-cookie') {
                $_old = isset($_POST['name']) ? (string) $_POST['name'] : '';
                if ($_old !== '' && !in_array($_old, $_settings, true) && strpos($_old, 'hdr_') !== 0 && strpbrk($_old, "\r\n") === false) {
                    $_expire_raw($_old);
                }
            }

            $_n        = isset($_POST['cookieName'])    ? trim((string) $_POST['cookieName']) : '';
            $_v        = isset($_POST['cookieValue'])   ? (string) $_POST['cookieValue']  : '';
            $_d        = isset($_POST['cookieDomain'])  ? (string) $_POST['cookieDomain'] : '';
            $_p        = isset($_POST['cookiePath'])    ? (string) $_POST['cookiePath']   : '/';
            $_days     = isset($_POST['cookieExpires']) ? (int) $_POST['cookieExpires']   : 30;
            $_secure   = !empty($_POST['cookieSecure']);
            $_httponly = !empty($_POST['cookieHttpOnly']);
            $_ss_in    = isset($_POST['cookieSameSite']) ? (string) $_POST['cookieSameSite'] : '';
            $_samesite = in_array($_ss_in, ['Lax', 'Strict', 'None'], true) ? $_ss_in : '';

            if ($_n === '' || in_array($_n, $_settings, true) || strpos($_n, 'hdr_') === 0 || strpbrk($_n . $_v . $_d . $_p, "\r\n") !== false) {
                $_redirect_tab = 'cookies';
                break;
            }

            $_treat_as_proxy = $_d !== '';  // Cookie targets a specific host → store in proxy COOKIE; format

            if ($_treat_as_proxy) {
                // Re-encode as COOKIE;name;path;domain (the format the proxy's outbound
                // cookie forwarder looks for) using setrawcookie so the double-encoded
                // name lands on the wire matching what the browser will read back.
                $_id_plain  = 'COOKIE;' . $_n . ';' . $_p . ';' . $_d;
                $_val_plain = $_v . ';' . ($_secure ? 'secure' : '');
                $_wire_name = rawurlencode(rawurlencode($_id_plain));
                $_wire_val  = rawurlencode(rawurlencode($_val_plain));
                $_opts = [
                    'path'     => '/',
                    'domain'   => '.' . $_http_host,
                    'secure'   => false,
                    'httponly' => $_httponly,
                ];
                if ($_days > 0) $_opts['expires'] = time() + $_days * 86400;
                if ($_samesite !== '') $_opts['samesite'] = $_samesite;
                @setrawcookie($_wire_name, $_wire_val, $_opts);
            } else {
                $_opts = [
                    'path'     => $_p !== '' ? $_p : '/',
                    'secure'   => $_secure,
                    'httponly' => $_httponly,
                ];
                if ($_days > 0) $_opts['expires'] = time() + $_days * 86400;
                if ($_samesite !== '') $_opts['samesite'] = $_samesite;
                @setcookie($_n, $_v, $_opts);
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

        case 'toggle-raw':
            $_now = !empty($_COOKIE['phproxy-show-raw']);
            if ($_now) {
                setcookie('phproxy-show-raw', '', time() - 3600, '/');
            } else {
                setcookie('phproxy-show-raw', '1', time() + 86400 * 365, '/');
            }
            $_redirect_tab = 'cookies';
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

    // Honor return_to so saves inside the inline panel on a proxied page
    // don't bounce the user back to the entry form. Only same-origin URLs
    // (must start with $_script_base) are accepted, to prevent open-redirect.
    $_rt = isset($_POST['return_to']) ? (string) $_POST['return_to'] : '';
    if ($_rt !== '' && strpos($_rt, $_script_base) === 0) {
        // Set a short-lived hint cookie so the injected panel re-opens on the
        // same tab the user just edited
        if ($_redirect_tab !== '') {
            setcookie('phproxy-panel-tab', $_redirect_tab, time() + 60, '/');
        }
        $_dest = $_rt;
    } else {
        $_dest = $_script_url . ($_redirect_tab !== '' ? '?tab=' . $_redirect_tab : '');
    }
    header('Location: ' . $_dest);
    exit(0);
}

// Start a session for the Network tab log. Done after the early-exit
// dispatchers so asset/api/action requests don't pay the session cost.
if (session_status() === PHP_SESSION_NONE) {
    @session_start([
        'cookie_lifetime' => 0,
        'cookie_samesite' => 'Lax',
        'cookie_httponly' => false,
    ]);
}
if (!isset($_SESSION['phproxy_network']) || !is_array($_SESSION['phproxy_network'])) {
    $_SESSION['phproxy_network'] = [];
}

// Clear-network action handler (lives outside the main dispatcher so it can
// touch $_SESSION, which only just opened).
if (isset($_GET['action']) && $_GET['action'] === 'clear-network' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['phproxy_network'] = [];
    $_rt = isset($_POST['return_to']) ? (string) $_POST['return_to'] : '';
    if ($_rt !== '' && strpos($_rt, $_script_base) === 0) {
        setcookie('phproxy-panel-tab', 'network', time() + 60, '/');
        header('Location: ' . $_rt);
    } else {
        header('Location: ' . $_script_url);
    }
    exit(0);
}

//
// Legacy /edit.php compatibility — accepts the old userAgent POST shape
// from any caller that still wires forms to action=submit&userAgent=…
// (bare GET to /edit.php now 404s; that path is no longer rewritten).
if (isset($_POST['action']) && $_POST['action'] === 'submit' && isset($_POST['userAgent']) && !isset($_GET['action']))
{
    $_ua_legacy = (string) $_POST['userAgent'];
    if (strpbrk($_ua_legacy, "\r\n") === false) {
        setcookie('userAgent', $_ua_legacy, time() + 86400 * 365, '/');
    }
    header('Location: ' . $_script_url);
    exit(0);
}

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
/**
 * A Bootstring encoding of Unicode for Internationalized Domain Names in Applications (IDNA) for PHP
 * Punycode implementation as described in RFC 3492
 *
 * @link http://tools.ietf.org/html/rfc3492
 * @repo https://github.com/true/php-punycode
 *
 *  LICENSE
 * 	Copyright (c) 2014 TrueServer B.V.
 *
 * 	Permission is hereby granted, free of charge, to any person obtaining a copy
 * 	of this software and associated documentation files (the "Software"), to deal
 *	in the Software without restriction, including without limitation the rights
 * 	to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * 	copies of the Software, and to permit persons to whom the Software is furnished
 *	to do so, subject to the following conditions:
 * 
 * 	The above copyright notice and this permission notice shall be included in all
 *	copies or substantial portions of the Software.
 * 
 * 	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *	IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * 	FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * 	AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *	LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * 	OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *	THE SOFTWARE.
 *
 */
class php_idna
{

    /**
     * Bootstring parameter values
     *
     */
    const BASE         = 36;
    const TMIN         = 1;
    const TMAX         = 26;
    const SKEW         = 38;
    const DAMP         = 700;
    const INITIAL_BIAS = 72;
    const INITIAL_N    = 128;
    const PREFIX       = 'xn--';
    const DELIMITER    = '-';

    /**
     * Encode table
     *
     * @param array
     */
    protected static $encodeTable = array(
        'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l',
        'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x',
        'y', 'z', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
    );

    /**
     * Decode table
     *
     * @param array
     */
    protected static $decodeTable = array(
        'a' =>  0, 'b' =>  1, 'c' =>  2, 'd' =>  3, 'e' =>  4, 'f' =>  5,
        'g' =>  6, 'h' =>  7, 'i' =>  8, 'j' =>  9, 'k' => 10, 'l' => 11,
        'm' => 12, 'n' => 13, 'o' => 14, 'p' => 15, 'q' => 16, 'r' => 17,
        's' => 18, 't' => 19, 'u' => 20, 'v' => 21, 'w' => 22, 'x' => 23,
        'y' => 24, 'z' => 25, '0' => 26, '1' => 27, '2' => 28, '3' => 29,
        '4' => 30, '5' => 31, '6' => 32, '7' => 33, '8' => 34, '9' => 35
    );

    /**
     * Character encoding
     *
     * @param string
     */
    protected $encoding;

    /**
     * Constructor
     *
     * @param string $encoding Character encoding
     */
    public function __construct($encoding = 'UTF-8')
    {
        $this->encoding = $encoding;
    }

    /**
     * Encode a domain to its Punycode version
     *
     * @param string $input Domain name in Unicode to be encoded
     * @return string Punycode representation in ASCII
     */
    public function encode($input)
    {
        $parts = explode('.', $input);
        foreach ($parts as &$part) {
            $part = $this->encodePart($part);
        }

        return implode('.', $parts);
    }

    /**
     * Encode a part of a domain name, such as tld, to its Punycode version
     *
     * @param string $input Part of a domain name
     * @return string Punycode representation of a domain part
     */
    protected function encodePart($input)
    {
        $codePoints = $this->listCodePoints($input);

        $n = static::INITIAL_N;
        $bias = static::INITIAL_BIAS;
        $delta = 0;
        $h = $b = count($codePoints['basic']);

        $output = '';
        foreach ($codePoints['basic'] as $code) {
            $output .= $this->codePointToChar($code);
        }
        if ($input === $output) {
            return $output;
        }
        if ($b > 0) {
            $output .= static::DELIMITER;
        }

        $codePoints['nonBasic'] = array_unique($codePoints['nonBasic']);
        sort($codePoints['nonBasic']);

        $i = 0;
        $length = mb_strlen($input, $this->encoding);
        while ($h < $length) {
            $m = $codePoints['nonBasic'][$i++];
            $delta = $delta + ($m - $n) * ($h + 1);
            $n = $m;

            foreach ($codePoints['all'] as $c) {
                if ($c < $n || $c < static::INITIAL_N) {
                    $delta++;
                }
                if ($c === $n) {
                    $q = $delta;
                    for ($k = static::BASE;; $k += static::BASE) {
                        $t = $this->calculateThreshold($k, $bias);
                        if ($q < $t) {
                            break;
                        }

                        $code = $t + (($q - $t) % (static::BASE - $t));
                        $output .= static::$encodeTable[$code];

                        $q = intdiv($q - $t, static::BASE - $t);
                    }

                    $output .= static::$encodeTable[$q];
                    $bias = $this->adapt($delta, $h + 1, ($h === $b));
                    $delta = 0;
                    $h++;
                }
            }

            $delta++;
            $n++;
        }

        return static::PREFIX . $output;
    }

    /**
     * Decode a Punycode domain name to its Unicode counterpart
     *
     * @param string $input Domain name in Punycode
     * @return string Unicode domain name
     */
    public function decode($input)
    {
        $parts = explode('.', $input);
        foreach ($parts as &$part) {
            if (strpos($part, static::PREFIX) !== 0) {
                continue;
            }

            $part = substr($part, strlen(static::PREFIX));
            $part = $this->decodePart($part);
        }

        return implode('.', $parts);
    }

    /**
     * Decode a part of domain name, such as tld
     *
     * @param string $input Part of a domain name
     * @return string Unicode domain part
     */
    protected function decodePart($input)
    {
        $n = static::INITIAL_N;
        $i = 0;
        $bias = static::INITIAL_BIAS;
        $output = '';

        $pos = strrpos($input, static::DELIMITER);
        if ($pos !== false) {
            $output = substr($input, 0, $pos++);
        } else {
            $pos = 0;
        }

        $outputLength = strlen($output);
        $inputLength = strlen($input);
        while ($pos < $inputLength) {
            $oldi = $i;
            $w = 1;

            for ($k = static::BASE;; $k += static::BASE) {
                $digit = static::$decodeTable[$input[$pos++]];
                $i = $i + ($digit * $w);
                $t = $this->calculateThreshold($k, $bias);

                if ($digit < $t) {
                    break;
                }

                $w = $w * (static::BASE - $t);
            }

            $bias = $this->adapt($i - $oldi, ++$outputLength, ($oldi === 0));
            $n = $n + (int) ($i / $outputLength);
            $i = $i % ($outputLength);
            $output = mb_substr($output, 0, $i, $this->encoding) . $this->codePointToChar($n) . mb_substr($output, $i, $outputLength - 1, $this->encoding);

            $i++;
        }

        return $output;
    }

    /**
     * Calculate the bias threshold to fall between TMIN and TMAX
     *
     * @param integer $k
     * @param integer $bias
     * @return integer
     */
    protected function calculateThreshold($k, $bias)
    {
        if ($k <= $bias + static::TMIN) {
            return static::TMIN;
        } elseif ($k >= $bias + static::TMAX) {
            return static::TMAX;
        }
        return $k - $bias;
    }

    /**
     * Bias adaptation
     *
     * @param integer $delta
     * @param integer $numPoints
     * @param boolean $firstTime
     * @return integer
     */
    protected function adapt($delta, $numPoints, $firstTime)
    {
        $delta = (int) (
            ($firstTime)
                ? $delta / static::DAMP
                : $delta / 2
            );
        $delta += (int) ($delta / $numPoints);

        $k = 0;
        while ($delta > ((static::BASE - static::TMIN) * static::TMAX) / 2) {
            $delta = (int) ($delta / (static::BASE - static::TMIN));
            $k = $k + static::BASE;
        }
        $k = $k + (int) (((static::BASE - static::TMIN + 1) * $delta) / ($delta + static::SKEW));

        return $k;
    }

    /**
     * List code points for a given input
     *
     * @param string $input
     * @return array Multi-dimension array with basic, non-basic and aggregated code points
     */
    protected function listCodePoints($input)
    {
        $codePoints = array(
            'all'      => array(),
            'basic'    => array(),
            'nonBasic' => array(),
        );

        $length = mb_strlen($input, $this->encoding);
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($input, $i, 1, $this->encoding);
            $code = $this->charToCodePoint($char);
            if ($code < 128) {
                $codePoints['all'][] = $codePoints['basic'][] = $code;
            } else {
                $codePoints['all'][] = $codePoints['nonBasic'][] = $code;
            }
        }

        return $codePoints;
    }

    /**
     * Convert a single or multi-byte character to its code point
     *
     * @param string $char
     * @return integer
     */
    protected function charToCodePoint($char)
    {
        $code = ord($char[0]);
        if ($code < 128) {
            return $code;
        } elseif ($code < 224) {
            return (($code - 192) * 64) + (ord($char[1]) - 128);
        } elseif ($code < 240) {
            return (($code - 224) * 4096) + ((ord($char[1]) - 128) * 64) + (ord($char[2]) - 128);
        } else {
            return (($code - 240) * 262144) + ((ord($char[1]) - 128) * 4096) + ((ord($char[2]) - 128) * 64) + (ord($char[3]) - 128);
        }
    }

    /**
     * Convert a code point to its single or multi-byte character
     *
     * @param integer $code
     * @return string
     */
    protected function codePointToChar($code)
    {
        if ($code <= 0x7F) {
            return chr($code);
        } elseif ($code <= 0x7FF) {
            return chr(($code >> 6) + 192) . chr(($code & 63) + 128);
        } elseif ($code <= 0xFFFF) {
            return chr(($code >> 12) + 224) . chr((($code >> 6) & 63) + 128) . chr(($code & 63) + 128);
        } else {
            return chr(($code >> 18) + 240) . chr((($code >> 12) & 63) + 128) . chr((($code >> 6) & 63) + 128) . chr(($code & 63) + 128);
        }
    }
}			$php_idna = new php_idna();
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
   // Timing for the Network tab — captured per retry attempt; the LAST values
   // are the ones that end up in the session log.
   $_net_start = microtime(true);
   $_net_ttfb  = null;
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
    if ($_net_ttfb === null) $_net_ttfb = microtime(true);

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

    $_stream_size = 0;
    do
    {
        $data = fread($_socket, 8192);
        echo $data;
        $_stream_size += strlen((string) $data);
    }
    while (isset($data[0]));

    fclose($_socket);
    phproxy_net_log($_url, $_request_method, (int) $_response_code, $_content_type, $_stream_size, $_net_start, $_net_ttfb, microtime(true));
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

phproxy_net_log($_url, $_request_method, (int) $_response_code, $_content_type, strlen($_response_body), $_net_start, $_net_ttfb, microtime(true));

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

if(preg_match('/http[s]?:\/\/(ipv[46]|www).google\.[a-z]+(\.)?[a-z]+\/+sorry\/+index/', $_url)) {
	$_response_body = 'We\'re sorry but Google Search is temporarily unavailable in our service due to high demand. We recommend you to use Microsoft Bing Search instead.';
}
if(preg_match('/facebook\.com[.]?$/', $_url_parts['host']) && $_content_type == 'application/xhtml+xml') {
	$_content_type = 'text/html';
	$_response_headers['content-type'] = $_content_type.'; charset=utf-8';
}
    if ($_flags['include_form'] && !isset($_GET['nf']))
    {
        // PHProxy top bar + inline management panel injected into proxied pages.
        // The bar is a self-contained id-scoped <style> island (`all:initial`).
        // The panel uses the regular index.css loaded via <link>; that loads it
        // into the proxied page's global CSS scope which can in rare cases
        // clash with the host page's class names — we accept the trade-off
        // because the alternative is duplicating ~500 lines of CSS here.
        $_url_safe       = htmlspecialchars($_url, ENT_QUOTES);
        $_up_url         = $_script_url . '?' . $_config['url_var_name'] . '=' . encode_url($_url_parts['prev_dir']);
        $_up_url_safe    = htmlspecialchars($_up_url, ENT_QUOTES);
        $_home_safe      = htmlspecialchars($_script_base, ENT_QUOTES);
        $_action_safe    = htmlspecialchars($_script_url, ENT_QUOTES);
        $_url_var_safe   = htmlspecialchars($_config['url_var_name']);
        $_current_proxy_url  = $_script_url . '?' . $_config['url_var_name'] . '=' . encode_url($_url);
        $_current_proxy_safe = htmlspecialchars($_current_proxy_url, ENT_QUOTES);
        // The panel uses a *scoped* stylesheet (every selector prefixed with
        // #phproxy-panel) so loading it on the proxied page can't rewrite the
        // host page's elements. Loading index.css here instead would leak
        // global rules like `* { box-sizing }` and `body { font }` onto the
        // proxied content.
        // Bust the browser cache on every release — the version string is
        // bumped in the file's $_version line, and that string travels with
        // the URL so the browser fetches the new CSS instead of using a
        // stale Cache-Control'd copy from a previous release.
        $_css_link_safe  = htmlspecialchars($_script_base . '?asset=panel.css&v=' . $_version, ENT_QUOTES);

        // Panel open/active-tab state — set by the dispatcher right after each
        // save action so the panel re-opens on the same tab the user just
        // edited. Self-destructing: we clear the cookie after reading it.
        $_panel_tab_hint = isset($_COOKIE['phproxy-panel-tab']) ? (string) $_COOKIE['phproxy-panel-tab'] : '';
        $_panel_open     = $_panel_tab_hint !== '';
        $_panel_active_tab = in_array($_panel_tab_hint, ['options','cookies','headers','response'], true)
            ? $_panel_tab_hint
            : 'cookies';
        if ($_panel_tab_hint !== '') {
            setcookie('phproxy-panel-tab', '', time() - 3600, '/');
        }

        // Response-headers list for the new Trace tab (read-only).
        $_panel_response_pairs = [];
        foreach ($_response_keys as $_kl => $_ko) {
            if (!isset($_response_headers[$_kl])) continue;
            foreach ((array) $_response_headers[$_kl] as $_rv) {
                $_panel_response_pairs[] = [$_ko, $_rv];
            }
        }

        // Parse the outgoing request we just sent so the user can see the
        // exact wire-form headers (method, path, Host, User-Agent, Cookie,
        // any custom hdr_* entries we forwarded, etc.).
        $_panel_request_pairs = [];
        $_panel_request_line  = '';
        $_req_lines = explode("\r\n", $_request_headers);
        $_first = true;
        foreach ($_req_lines as $_rl) {
            // Stop at the empty line (end of headers; body follows for POSTs)
            if ($_rl === '') break;
            if ($_first) {
                // Request line: "METHOD /path?query HTTP/1.0"
                $_panel_request_line = $_rl;
                $_first = false;
                continue;
            }
            $_colon = strpos($_rl, ':');
            if ($_colon === false) {
                $_panel_request_pairs[] = [$_rl, ''];
            } else {
                $_panel_request_pairs[] = [substr($_rl, 0, $_colon), ltrim(substr($_rl, $_colon + 1))];
            }
        }

        // Cookies + headers in the structure the shared panel template expects
        $_panel_b = phproxy_panel_buckets();
        $GLOBALS['_visible_cookies'] = $_panel_b['cookies'];
        $GLOBALS['_custom_headers']  = $_panel_b['headers'];
        $GLOBALS['_show_raw_values'] = !empty($_COOKIE['phproxy-show-raw']);
        $GLOBALS['_ua_presets']      = phproxy_ua_presets();

        $_bar_css = <<<CSS
#phproxy-bar{all:initial;display:block;position:sticky;top:0;z-index:2147483647;box-sizing:border-box;width:100%;margin:0;padding:8px 12px;background:#ffffff;color:#0f172a;font:13px/1.4 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;border-bottom:1px solid #e2e6ec;}
#phproxy-bar[data-theme="dark"]{background:#0f172a;color:#f1f5f9;border-bottom-color:#334155;}
#phproxy-bar *{box-sizing:border-box;font:inherit;color:inherit;}
#phproxy-bar .row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;}
#phproxy-bar input.url{flex:1 1 320px;min-width:0;padding:6px 10px;background:#f8fafc;color:#0f172a;border:1px solid #e2e6ec;border-radius:6px;}
#phproxy-bar[data-theme="dark"] input.url{background:#1e293b;color:#f1f5f9;border-color:#334155;}
#phproxy-bar input.url:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.18);}
#phproxy-bar[data-theme="dark"] input.url:focus{border-color:#60a5fa;box-shadow:0 0 0 3px rgba(96,165,250,.25);}
#phproxy-bar button.go{padding:6px 14px;background:#2563eb;color:#fff;border:0;border-radius:6px;font:600 13px inherit;cursor:pointer;}
#phproxy-bar button.go:hover{background:#1d4ed8;}
#phproxy-bar[data-theme="dark"] button.go{background:#3b82f6;}
#phproxy-bar[data-theme="dark"] button.go:hover{background:#60a5fa;}
#phproxy-bar .icon{cursor:pointer;padding:6px;color:inherit;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;}
#phproxy-bar .icon:hover{background:rgba(0,0,0,.06);}
#phproxy-bar[data-theme="dark"] .icon:hover{background:rgba(255,255,255,.08);}
#phproxy-bar .icon svg{width:16px;height:16px;display:block;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;}
#phproxy-bar label.gear{margin-left:auto;}
#phproxy-panel-toggle{position:absolute !important;left:-9999px !important;width:0 !important;height:0 !important;opacity:0 !important;pointer-events:none !important;}
CSS;

        $_gear = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 0 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 0 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 0 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3h0a1.7 1.7 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 0 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8v0a1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>';
        $_home_icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v10h14V10"/></svg>';

        // Build the inline tabs panel via the shared template function.
        // Proxied pages don't need the IP/Port/DNS/SSL tabs — those are
        // host-level utilities, not per-page. Hide them here.
        ob_start();
        phproxy_render_panel_tabs(
            return_to:           $_current_proxy_url,
            show_response:       true,
            response_pairs:      $_panel_response_pairs,
            request_pairs:       $_panel_request_pairs,
            request_line:        $_panel_request_line,
            active_tab:          $_panel_active_tab,
            form_id:             '',
            show_cli:            true,
            cli_url:             $_url,
            cli_method:          $_request_method,
            cli_headers:         $_panel_request_pairs,
            cli_body:            (string) ($_post_body ?? ''),
            cli_api_url:         $_script_url . '?api=fetch',
            show_network:        true,
            network_entries:     $_SESSION['phproxy_network'] ?? [],
            network_current_url: $_url,
            show_netchecks:      false
        );
        $_panel_inner_html = ob_get_clean();

        // Compose the entire injection: bar + (hidden) toggle checkbox + panel
        $_netcheck_js_safe = htmlspecialchars($_script_base . '?asset=netcheck.js&v=' . $_version, ENT_QUOTES);
        $_url_form = '<style id="phproxy-bar-style">' . $_bar_css . '</style>'
            . '<link rel="stylesheet" href="' . $_css_link_safe . '"/>'
            . '<script src="' . $_netcheck_js_safe . '" defer></script>'
            . '<input type="checkbox" id="phproxy-panel-toggle"' . ($_panel_open ? ' checked' : '') . '/>'
            . '<div id="phproxy-bar">'
            .   '<form class="row" method="post" action="' . $_action_safe . '">'
            .     '<a class="icon home" href="' . $_home_safe . '" title="PHProxy home" aria-label="PHProxy home">' . $_home_icon . '</a>'
            .     '<input class="url" id="____' . $_url_var_safe . '" type="text" name="' . $_url_var_safe . '" value="' . $_url_safe . '"/>'
            .     '<button class="go" type="submit" name="go">Go</button>'
            .     '<label class="icon gear" for="phproxy-panel-toggle" title="Open settings panel" aria-label="Open settings panel">' . $_gear . '</label>'
            .   '</form>'
            . '</div>'
            . '<div id="phproxy-panel" role="dialog" aria-label="PHProxy settings">'
            .   '<div class="panel-inner">'
            .     '<div class="panel-head">'
            .       '<h2>Settings</h2>'
            .       '<label class="close" for="phproxy-panel-toggle">Close</label>'
            .     '</div>'
            .     $_panel_inner_html
            .     '<div class="panel-refresh">'
            .       '<small>Saves apply on click and reload this URL automatically.</small>'
            .       '<a class="button-submit" href="' . $_current_proxy_safe . '">Refresh page</a>'
            .     '</div>'
            .   '</div>'
            . '</div>'
            . "<script>(function(){"
            .   "var theme='light';"
            .   "try{var s=localStorage.getItem('phproxy-theme');"
            .     "if(s==='dark'||s==='light')theme=s;"
            .     "else if(matchMedia('(prefers-color-scheme: dark)').matches)theme='dark';"
            .   "}catch(e){}"
            .   "var bar=document.getElementById('phproxy-bar');"
            .   "var pnl=document.getElementById('phproxy-panel');"
            .   "if(bar)bar.setAttribute('data-theme',theme);"
            .   "if(pnl)pnl.setAttribute('data-theme',theme);"
            .   "var sel=document.getElementById('ua-preset');"
            .   "var inp=document.getElementById('ua-input');"
            .   "if(sel&&inp){sel.addEventListener('change',function(){if(sel.value==='__custom__'){inp.focus();inp.select();}else{inp.value=sel.value;}});}"
            .   "document.querySelectorAll('.cli-copy').forEach(function(btn){"
            .     "btn.addEventListener('click',function(){"
            .       "var t=document.getElementById(btn.dataset.target);"
            .       "if(!t)return;"
            .       "var text=t.textContent;"
            .       "var done=function(){var orig=btn.textContent;btn.textContent='Copied!';setTimeout(function(){btn.textContent=orig;},1500);};"
            .       "if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(text).then(done,done);}else{"
            .         "var ta=document.createElement('textarea');ta.value=text;document.body.appendChild(ta);ta.select();try{document.execCommand('copy');done();}catch(e){}document.body.removeChild(ta);"
            .       "}"
            .     "});"
            .   "});"
            .   "var nbase=" . json_encode($_script_base, JSON_UNESCAPED_SLASHES) . ";"
            .   "function renderPort(out,s){var L=[s.host+'   '+s.results.length+'/'+s.count+' port'+(s.count===1?'':'s')+'   open: '+s.open+'   closed: '+s.closed+(s.done?'   ('+(s.duration_ms||0)+' ms)':'   …scanning'),''];"
            .     "s.results.forEach(function(r){var ps=String(r.port);while(ps.length<s.maxPort)ps=' '+ps;L.push(ps+(r.reachable?'  OPEN    '+r.latency_ms+' ms':'  CLOSED  '+(r.error||'unreachable')+'   '+(r.latency_ms||0)+' ms'));});"
            .     "out.textContent=L.join('\\n');}"
            .   "async function streamPort(f,out){var fd=new FormData(f),p=new URLSearchParams();p.set('api','portcheck');p.set('stream','1');fd.forEach(function(v,k){p.set(k,v);});"
            .     "out.hidden=false;out.className='netcheck-result busy';out.textContent='Connecting…';"
            .     "var resp=await fetch(nbase+'?'+p.toString(),{headers:{'Accept':'application/x-ndjson'}});"
            .     "if(!resp.ok){var m='HTTP '+resp.status;try{var j=await resp.json();if(j&&j.error)m='ERROR  '+j.error;}catch(e){}out.className='netcheck-result err';out.textContent=m;return;}"
            .     "var rdr=resp.body.getReader(),dec=new TextDecoder(),buf='';"
            .     "var s={host:'',count:0,open:0,closed:0,results:[],maxPort:0,done:false,duration_ms:0};"
            .     "while(true){var c=await rdr.read();if(c.done)break;buf+=dec.decode(c.value,{stream:true});var n;"
            .       "while((n=buf.indexOf('\\n'))>=0){var ln=buf.slice(0,n);buf=buf.slice(n+1);if(!ln.trim())continue;var e;try{e=JSON.parse(ln);}catch(x){continue;}"
            .         "if(e.event==='start'){s.host=e.host;s.count=e.count;(e.ports||[]).forEach(function(pp){var l=String(pp).length;if(l>s.maxPort)s.maxPort=l;});renderPort(out,s);}"
            .         "else if(e.event==='result'){s.results.push(e);if(e.reachable)s.open++;else s.closed++;renderPort(out,s);}"
            .         "else if(e.event==='end'){s.done=true;s.duration_ms=e.duration_ms;var ao=s.open>0,ac=s.closed>0;out.className='netcheck-result '+(ao&&!ac?'ok':(ao?'warn':'err'));renderPort(out,s);}"
            .       "}"
            .     "}}"
            .   "function escH(s){return String(s==null?'':s).replace(/[&<>]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;'}[c];});}"
            .   "function ipCard(role,d){"
            .     "if(!d||!d.ip)return '<div class=\"ipinfo-card\"><div class=\"ipinfo-card-title\">'+role+'</div><div class=\"ipinfo-row\"><span class=\"k\">(not available)</span></div></div>';"
            .     "var fam=d.family?'<span class=\"ipinfo-family\">'+d.family+'</span>':'';"
            .     "var priv=d.is_private?'<span class=\"ipinfo-private\">private</span>':'';"
            .     "var r=d.reverse?'<div class=\"ipinfo-row\"><span class=\"k\">Reverse</span><span class=\"v\">'+escH(d.reverse)+'</span></div>':'';"
            .     "return '<div class=\"ipinfo-card\"><div class=\"ipinfo-card-title\">'+role+(priv?'  '+priv:'')+'</div><div class=\"ipinfo-ip\">'+escH(d.ip)+fam+'</div>'+r+'</div>';"
            .   "}"
            .   "function ipKv(title,o){if(!o)return '';var r='';Object.keys(o).forEach(function(k){var v=o[k];if(v==null)return;var lb=k.replace(/_/g,' ');r+='<div class=\"k\">'+lb+'</div><div class=\"v'+(v===''?' empty':'')+'\">'+(v===''?'—':escH(v))+'</div>';});return r?('<div class=\"ipinfo-section\"><h4>'+title+'</h4><div class=\"ipinfo-grid\">'+r+'</div></div>'):'';}"
            .   "var ipT=document.querySelector('[data-ipinfo-target]');"
            .   "if(ipT){(async function(){"
            .     "try{var r=await fetch(nbase+'?api=ipinfo');var j=await r.json();"
            .       "if(j.ok===false){ipT.innerHTML='<div class=\"ipinfo-loading\">Failed: '+escH(j.error||'unknown')+'</div>';return;}"
            .       "ipT.innerHTML=ipCard('Client',j.client)+ipCard('Server',j.server)+ipCard('Outgoing',j.outgoing);"
            .       "var ex='';if(j.forwarded&&j.forwarded.length){var hops=j.forwarded.map(function(h){return h.ip;}).join(' → ');ex+='<div class=\"ipinfo-section\"><h4>X-Forwarded-For chain</h4><div class=\"ipinfo-grid\"><div class=\"k\">hops</div><div class=\"v\">'+escH(hops)+'</div></div></div>';}"
            .       "ex+=ipKv('Request',j.request);ex+=ipKv('Host',j.host);"
            .       "ipT.insertAdjacentHTML('afterend',ex);"
            .     "}catch(e){ipT.innerHTML='<div class=\"ipinfo-loading\">Network error: '+escH(e&&e.message?e.message:e)+'</div>';}"
            .   "})();}"
            .   "document.querySelectorAll('form[data-ipinfo-lookup]').forEach(function(f){"
            .     "var o=f.querySelector('.netcheck-result'),b=f.querySelector('button[type=\"submit\"]');"
            .     "f.addEventListener('submit',async function(ev){ev.preventDefault();var ip=f.querySelector('input[name=\"ip\"]').value.trim();if(!ip)return;o.hidden=false;o.className='netcheck-result busy';o.textContent='Looking up '+ip+'…';b.disabled=true;"
            .       "try{var r=await fetch(nbase+'?api=ipinfo&ip='+encodeURIComponent(ip));var j=await r.json();"
            .         "if(j.ok===false){o.className='netcheck-result err';o.textContent='ERROR  '+(j.error||'request failed');return;}"
            .         "var L=j.lookup||{},lines=[L.ip+'   '+L.family+(L.is_private?'   (private)':'')];"
            .         "if(L.reverse)lines.push('reverse:    '+L.reverse);"
            .         "lines.push('','lookup time: '+(j.duration_ms||0)+' ms');"
            .         "o.className='netcheck-result ok';o.textContent=lines.join('\\n');"
            .       "}catch(e){o.className='netcheck-result err';o.textContent='Network error: '+(e&&e.message?e.message:e);}finally{b.disabled=false;}"
            .     "});"
            .   "});"
            .   "document.querySelectorAll('form[data-netcheck]').forEach(function(f){"
            .     "var kind=f.getAttribute('data-netcheck');"
            .     "var out=f.querySelector('.netcheck-result');"
            .     "var btn=f.querySelector('button[type=\"submit\"]');"
            .     "f.addEventListener('submit',async function(ev){"
            .       "ev.preventDefault();btn.disabled=true;"
            .       "try{"
            .         "if(kind==='portcheck'){await streamPort(f,out);return;}"
            .         "var fd=new FormData(f),p=new URLSearchParams();p.set('api',kind);"
            .         "fd.forEach(function(v,k){p.set(k,v);});"
            .         "out.hidden=false;out.className='netcheck-result busy';out.textContent='Running…';"
            .         "var r=await fetch(nbase+'?'+p.toString(),{headers:{'Accept':'application/json'}});"
            .         "var j=await r.json();"
            .         "if(j.ok===false){out.className='netcheck-result err';out.textContent='ERROR  '+(j.error||'request failed')+(j.host?'\\nhost: '+j.host:'');return;}"
            .         "if(kind==='dns'){"
            .           "if(!j.records||!j.records.length){out.className='netcheck-result warn';out.textContent='No '+j.type+' records for '+j.host+'  ('+j.duration_ms+' ms)';return;}"
            .           "var L=[j.host+'  '+j.type+'  '+j.records.length+' record(s)  ('+j.duration_ms+' ms)',''];"
            .           "j.records.forEach(function(r){var k=r.type||j.type,v=r.ip||r.ipv6||r.target||r.txt||r.value||r.mname||(r.tag?r.tag+' \"'+r.value+'\"':JSON.stringify(r));L.push(k+'  '+v+(r.ttl!=null?'   ttl '+r.ttl:''));});"
            .           "out.className='netcheck-result ok';out.textContent=L.join('\\n');"
            .         "} else if(kind==='cert'){"
            .           "var d=(j.leaf&&j.leaf.days_left);"
            .           "out.className='netcheck-result cert-result '+(d!=null&&d<0?'err':(d!=null&&d<30?'warn':'ok'));"
            .           "if(window.phproxyFmtCertHtml){out.innerHTML=window.phproxyFmtCertHtml(j);if(window.phproxyBindCertCopy)window.phproxyBindCertCopy(out);}"
            .           "else{out.textContent='Cert renderer still loading — try again in a moment.';}"
            .         "}"
            .       "}catch(e){out.className='netcheck-result err';out.textContent='Network error: '+(e&&e.message?e.message:e);}finally{btn.disabled=false;}"
            .     "});"
            .   "});"
            . "})();</script>";

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

// --- TEMPLATES (formerly files/php/index.inc.php and _panel_tabs.inc.php) ---

function phproxy_render_entry_form(array $data): void {

$_show_raw_values = !empty($_COOKIE['phproxy-show-raw']);
$_panel_buckets   = phproxy_panel_buckets();
$_visible_cookies = $_panel_buckets['cookies'];
$_custom_headers  = $_panel_buckets['headers'];
$_current_ua      = isset($_COOKIE['userAgent']) ? $_COOKIE['userAgent'] : '';
$_ua_presets      = phproxy_ua_presets();

$_active_tab = isset($_GET['tab']) ? (string) $_GET['tab'] : 'options';
$_valid_tabs = ['options' => 1, 'cookies' => 1, 'headers' => 1, 'ip' => 1, 'port' => 1, 'dns' => 1, 'cert' => 1, 'cli' => 1];
if (!isset($_valid_tabs[$_active_tab])) $_active_tab = 'options';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title><?php echo htmlspecialchars($GLOBALS['_config']['site_name']); ?></title>
    <link rel="stylesheet" href="?asset=index.css&amp;v=<?php echo htmlspecialchars($GLOBALS['_version']); ?>"/>
    <script src="?asset=netcheck.js&amp;v=<?php echo htmlspecialchars($GLOBALS['_version']); ?>" defer></script>
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
phproxy_render_panel_tabs(
    return_to:     '',
    show_response: false,
    active_tab:    $_active_tab,
    form_id:       'proxy-main-form'
);
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

    // CLI tab — clipboard copy buttons (mirrors the inline-panel handler)
    document.querySelectorAll('.cli-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var t = document.getElementById(btn.dataset.target);
            if (!t) return;
            var text = t.textContent;
            var done = function () {
                var orig = btn.textContent;
                btn.textContent = 'Copied!';
                setTimeout(function () { btn.textContent = orig; }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, done);
            } else {
                var ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); done(); } catch (e) {}
                document.body.removeChild(ta);
            }
        });
    });

    // Network-check cards (Port / DNS / SSL) — fetch the API and render
    function fmtPortcheck(j) {
        var results = j.results || [];
        if (!results.length) return 'No ports scanned';
        var header = j.host + '   ' + j.count + ' port' + (j.count === 1 ? '' : 's') +
                     '   open: ' + j.open + '   closed: ' + j.closed +
                     '   (' + (j.duration_ms || 0) + ' ms)';
        // Sequential -> show one line per port, aligned
        var maxPort = 0;
        results.forEach(function (r) { if (String(r.port).length > maxPort) maxPort = String(r.port).length; });
        var lines = [header, ''];
        results.forEach(function (r) {
            var portStr = String(r.port);
            while (portStr.length < maxPort) portStr = ' ' + portStr;
            if (r.reachable) {
                lines.push(portStr + '  OPEN    ' + r.latency_ms + ' ms');
            } else {
                lines.push(portStr + '  CLOSED  ' + (r.error || 'unreachable') + '   ' + (r.latency_ms || 0) + ' ms');
            }
        });
        return lines.join('\n');
    }
    function fmtDns(j) {
        if (!j.records || !j.records.length) {
            return 'No ' + j.type + ' records for ' + j.host + '  (' + j.duration_ms + ' ms)';
        }
        var lines = [j.host + '  ' + j.type + '  ' + j.records.length + ' record(s)  (' + j.duration_ms + ' ms)', ''];
        j.records.forEach(function (r) {
            var key = r.type || j.type;
            var val = '';
            if (r.ip)          val = r.ip;
            else if (r.ipv6)   val = r.ipv6;
            else if (r.target) val = r.target + (r.pri != null ? '  (pri ' + r.pri + ')' : '');
            else if (r.txt)    val = r.txt;
            else if (r.value)  val = r.value;
            else if (r.mname)  val = r.mname + ' / ' + (r.rname || '') + ' (serial ' + (r.serial || '') + ')';
            else if (r.tag)    val = r.tag + ' "' + (r.value || '') + '"';
            else               val = JSON.stringify(r);
            lines.push(key + '  ' + val + (r.ttl != null ? '   ttl ' + r.ttl : ''));
        });
        return lines.join('\n');
    }
    // Rich cert renderer (window.phproxyFmtCertHtml + phproxyBindCertCopy)
    // is loaded from ?asset=netcheck.js and shared with the inline panel.
    // Render the port-check result panel from the current scan state.
    function renderPortRunning(out, state) {
        var lines = [
            state.host + '   ' + state.results.length + '/' + state.count + ' port' + (state.count === 1 ? '' : 's') +
            '   open: ' + state.open + '   closed: ' + state.closed +
            (state.done ? '   (' + (state.duration_ms || 0) + ' ms)' : '   …scanning'),
            '',
        ];
        state.results.forEach(function (r) {
            var ps = String(r.port);
            while (ps.length < state.maxPort) ps = ' ' + ps;
            if (r.reachable) lines.push(ps + '  OPEN    ' + r.latency_ms + ' ms');
            else             lines.push(ps + '  CLOSED  ' + (r.error || 'unreachable') + '   ' + (r.latency_ms || 0) + ' ms');
        });
        out.textContent = lines.join('\n');
    }

    // Streaming port-check via NDJSON. Updates the result pre on every line.
    async function streamPortcheck(form, out) {
        var fd = new FormData(form);
        var params = new URLSearchParams();
        params.set('api', 'portcheck');
        params.set('stream', '1');
        fd.forEach(function (v, k) { params.set(k, v); });
        out.hidden = false;
        out.className = 'netcheck-result busy';
        out.textContent = 'Connecting…';
        var resp = await fetch('?' + params.toString(), { headers: { 'Accept': 'application/x-ndjson' } });
        if (!resp.ok) {
            // Try to surface the JSON error envelope the dispatcher sends.
            var msg = 'HTTP ' + resp.status;
            try { var j = await resp.json(); if (j && j.error) msg = 'ERROR  ' + j.error; } catch (e) {}
            out.className = 'netcheck-result err';
            out.textContent = msg;
            return;
        }
        var reader  = resp.body.getReader();
        var decoder = new TextDecoder();
        var buf = '';
        var state = { host: '', count: 0, open: 0, closed: 0, results: [], maxPort: 0, done: false, duration_ms: 0 };
        while (true) {
            var chunk = await reader.read();
            if (chunk.done) break;
            buf += decoder.decode(chunk.value, { stream: true });
            var nl;
            while ((nl = buf.indexOf('\n')) >= 0) {
                var line = buf.slice(0, nl); buf = buf.slice(nl + 1);
                if (!line.trim()) continue;
                var ev;
                try { ev = JSON.parse(line); } catch (e) { continue; }
                if (ev.event === 'start') {
                    state.host  = ev.host;
                    state.count = ev.count;
                    (ev.ports || []).forEach(function (p) { var l = String(p).length; if (l > state.maxPort) state.maxPort = l; });
                    renderPortRunning(out, state);
                } else if (ev.event === 'result') {
                    state.results.push(ev);
                    if (ev.reachable) state.open++; else state.closed++;
                    renderPortRunning(out, state);
                } else if (ev.event === 'end') {
                    state.done        = true;
                    state.duration_ms = ev.duration_ms;
                    var anyOpen = state.open > 0, anyClosed = state.closed > 0;
                    out.className = 'netcheck-result ' + (anyOpen && !anyClosed ? 'ok' : (anyOpen ? 'warn' : 'err'));
                    renderPortRunning(out, state);
                }
            }
        }
    }

    function bindNetcheck(form) {
        var kind = form.getAttribute('data-netcheck');
        var out  = form.querySelector('.netcheck-result');
        var btn  = form.querySelector('button[type="submit"]');
        form.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            btn.disabled = true;
            try {
                if (kind === 'portcheck') {
                    await streamPortcheck(form, out);
                    return;
                }
                var fd = new FormData(form);
                var params = new URLSearchParams();
                params.set('api', kind);
                fd.forEach(function (v, k) { params.set(k, v); });
                out.hidden = false;
                out.className = 'netcheck-result busy';
                out.textContent = 'Running…';
                var r = await fetch('?' + params.toString(), { headers: { 'Accept': 'application/json' } });
                var j = await r.json();
                if (j.ok === false) {
                    out.className = 'netcheck-result err';
                    out.textContent = 'ERROR  ' + (j.error || 'request failed') + (j.host ? '\nhost: ' + j.host : '');
                    return;
                }
                if (kind === 'dns') {
                    out.className = 'netcheck-result ' + (j.records && j.records.length ? 'ok' : 'warn');
                    out.textContent = fmtDns(j);
                } else if (kind === 'cert') {
                    var d = j.leaf && j.leaf.days_left;
                    out.className = 'netcheck-result cert-result ' + (d != null && d < 0 ? 'err' : (d != null && d < 30 ? 'warn' : 'ok'));
                    out.innerHTML = window.phproxyFmtCertHtml(j);
                    if (window.phproxyBindCertCopy) window.phproxyBindCertCopy(out);
                } else {
                    out.className = 'netcheck-result ok';
                    out.textContent = JSON.stringify(j, null, 2);
                }
            } catch (e) {
                out.className = 'netcheck-result err';
                out.textContent = 'Network error: ' + (e && e.message ? e.message : e);
            } finally {
                btn.disabled = false;
            }
        });
    }
    document.querySelectorAll('form[data-netcheck]').forEach(bindNetcheck);

    // IP tab — render the three identity cards plus the request / host sections.
    function renderIpinfoCard(role, data) {
        if (!data || !data.ip) {
            return '<div class="ipinfo-card"><div class="ipinfo-card-title">' + role + '</div>' +
                   '<div class="ipinfo-row"><span class="k">(not available)</span></div></div>';
        }
        var fam  = data.family ? '<span class="ipinfo-family">' + data.family + '</span>' : '';
        var priv = data.is_private ? '<span class="ipinfo-private">private</span>' : '';
        var rev  = data.reverse ? '<div class="ipinfo-row"><span class="k">Reverse</span><span class="v">' + escHtml(data.reverse) + '</span></div>' : '';
        return '<div class="ipinfo-card">' +
                 '<div class="ipinfo-card-title">' + role + (priv ? '  ' + priv : '') + '</div>' +
                 '<div class="ipinfo-ip">' + escHtml(data.ip) + fam + '</div>' +
                 rev +
               '</div>';
    }
    function renderIpinfoKv(title, obj) {
        if (!obj) return '';
        var rows = '';
        Object.keys(obj).forEach(function (k) {
            var v = obj[k];
            if (v === null || v === undefined) return;
            var label = k.replace(/_/g, ' ');
            if (v === '') {
                rows += '<div class="k">' + label + '</div><div class="v empty">—</div>';
            } else {
                rows += '<div class="k">' + label + '</div><div class="v">' + String(v).replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }) + '</div>';
            }
        });
        if (!rows) return '';
        return '<div class="ipinfo-section"><h4>' + title + '</h4><div class="ipinfo-grid">' + rows + '</div></div>';
    }
    function escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; });
    }
    async function loadIpinfo(target) {
        try {
            var r = await fetch('?api=ipinfo', { headers: { 'Accept': 'application/json' } });
            var j = await r.json();
            if (j.ok === false) {
                target.innerHTML = '<div class="ipinfo-loading">Failed: ' + escHtml(j.error || 'unknown') + '</div>';
                return;
            }
            var html  = renderIpinfoCard('Client',   j.client);
            html     += renderIpinfoCard('Server',   j.server);
            html     += renderIpinfoCard('Outgoing', j.outgoing);
            target.innerHTML = html;
            var extra = '';
            if (j.forwarded && j.forwarded.length) {
                var hops = j.forwarded.map(function (h) { return h.ip; }).join(' → ');
                extra += '<div class="ipinfo-section"><h4>X-Forwarded-For chain</h4><div class="ipinfo-grid"><div class="k">hops</div><div class="v">' + escHtml(hops) + '</div></div></div>';
            }
            extra += renderIpinfoKv('Request', j.request);
            extra += renderIpinfoKv('Host',    j.host);
            target.insertAdjacentHTML('afterend', extra);
        } catch (e) {
            target.innerHTML = '<div class="ipinfo-loading">Network error: ' + escHtml(e && e.message ? e.message : e) + '</div>';
        }
    }
    var ipTarget = document.querySelector('[data-ipinfo-target]');
    if (ipTarget) loadIpinfo(ipTarget);

    // Custom IP lookup form inside the IP tab
    document.querySelectorAll('form[data-ipinfo-lookup]').forEach(function (form) {
        var out = form.querySelector('.netcheck-result');
        var btn = form.querySelector('button[type="submit"]');
        form.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            var ip = form.querySelector('input[name="ip"]').value.trim();
            if (!ip) return;
            out.hidden = false;
            out.className = 'netcheck-result busy';
            out.textContent = 'Looking up ' + ip + '…';
            btn.disabled = true;
            try {
                var r = await fetch('?api=ipinfo&ip=' + encodeURIComponent(ip));
                var j = await r.json();
                if (j.ok === false) {
                    out.className = 'netcheck-result err';
                    out.textContent = 'ERROR  ' + (j.error || 'request failed');
                    return;
                }
                var L = j.lookup || {};
                var lines = [L.ip + '   ' + L.family + (L.is_private ? '   (private)' : '')];
                if (L.reverse) lines.push('reverse:    ' + L.reverse);
                lines.push('');
                lines.push('lookup time: ' + (j.duration_ms || 0) + ' ms');
                out.className = 'netcheck-result ok';
                out.textContent = lines.join('\n');
            } catch (e) {
                out.className = 'netcheck-result err';
                out.textContent = 'Network error: ' + (e && e.message ? e.message : e);
            } finally {
                btn.disabled = false;
            }
        });
    });
})();
</script>
</body>
</html>

<?php
}

function phproxy_render_panel_tabs(
    string $return_to = '',
    bool $show_response = false,
    array $response_pairs = [],
    array $request_pairs = [],
    string $request_line = '',
    string $active_tab = 'options',
    string $form_id = '',
    bool $show_cli = false,
    string $cli_url = '',
    string $cli_method = 'GET',
    array $cli_headers = [],
    string $cli_body = '',
    string $cli_api_url = '',
    bool $show_network = false,
    array $network_entries = [],
    string $network_current_url = '',
    bool $show_netchecks = true   // IP / Port / DNS / SSL — entry page only
): void {
    // Local aliases keep the existing template body unchanged
    $_panel_return_to      = $return_to;
    $_panel_show_response  = $show_response;
    $_panel_response_pairs = $response_pairs;
    $_panel_request_pairs  = $request_pairs;
    $_panel_request_line   = $request_line;
    $_panel_active_tab     = $active_tab;
    $_panel_form_id        = $form_id;
    $_panel_show_cli       = $show_cli;
    $_panel_cli_url        = $cli_url;
    $_panel_cli_method     = $cli_method;
    $_panel_cli_headers    = $cli_headers;
    $_panel_cli_body       = $cli_body;
    $_panel_cli_api_url    = $cli_api_url;
    $_panel_show_network   = $show_network;
    $_panel_network        = $network_entries;
    $_panel_net_current    = $network_current_url;
    $_panel_show_netchecks = $show_netchecks;

$_panel_return_html = $_panel_return_to !== ''
    ? '<input type="hidden" name="return_to" value="' . htmlspecialchars($_panel_return_to, ENT_QUOTES) . '"/>'
    : '';
$_form_attr = $_panel_form_id !== '' ? ' form="' . htmlspecialchars($_panel_form_id) . '"' : '';

$_cfg          = $GLOBALS['_config'];
$_flags_local  = $GLOBALS['_flags'];
$_frozen       = $GLOBALS['_frozen_flags'];
$_labels_local = $GLOBALS['_labels'];
$_v_cookies    = $GLOBALS['_visible_cookies'] ?? [];
$_c_headers    = $GLOBALS['_custom_headers']  ?? [];
$_show_raw     = !empty($GLOBALS['_show_raw_values']);
$_current_ua_local = isset($_COOKIE['userAgent']) ? $_COOKIE['userAgent'] : '';
$_ua_presets_local = $GLOBALS['_ua_presets'] ?? [];
?>
<div class="tabs-wrap">
    <input type="radio" name="tab" id="tab-options"<?php echo $_panel_active_tab === 'options' ? ' checked' : ''; ?>/>
    <input type="radio" name="tab" id="tab-cookies"<?php echo $_panel_active_tab === 'cookies' ? ' checked' : ''; ?>/>
    <input type="radio" name="tab" id="tab-headers"<?php echo $_panel_active_tab === 'headers' ? ' checked' : ''; ?>/>
    <?php if ($_panel_show_netchecks): ?>
    <input type="radio" name="tab" id="tab-ip"<?php echo $_panel_active_tab === 'ip' ? ' checked' : ''; ?>/>
    <input type="radio" name="tab" id="tab-port"<?php echo $_panel_active_tab === 'port' ? ' checked' : ''; ?>/>
    <input type="radio" name="tab" id="tab-dns"<?php echo $_panel_active_tab === 'dns' ? ' checked' : ''; ?>/>
    <input type="radio" name="tab" id="tab-cert"<?php echo $_panel_active_tab === 'cert' ? ' checked' : ''; ?>/>
    <?php endif; ?>
    <?php if ($_panel_show_response): ?>
    <input type="radio" name="tab" id="tab-response"<?php echo $_panel_active_tab === 'response' ? ' checked' : ''; ?>/>
    <?php endif; ?>
    <input type="radio" name="tab" id="tab-cli"<?php echo $_panel_active_tab === 'cli' ? ' checked' : ''; ?>/>
    <?php if ($_panel_show_network): ?>
    <input type="radio" name="tab" id="tab-network"<?php echo $_panel_active_tab === 'network' ? ' checked' : ''; ?>/>
    <?php endif; ?>

    <nav class="tabs">
        <label for="tab-options">Options</label>
        <label for="tab-cookies">Cookies <span class="tab-count"><?php echo count($_v_cookies); ?></span></label>
        <label for="tab-headers">Headers <span class="tab-count"><?php echo count($_c_headers); ?></span></label>
        <?php if ($_panel_show_netchecks): ?>
        <label for="tab-ip">IP</label>
        <label for="tab-port">Port</label>
        <label for="tab-dns">DNS</label>
        <label for="tab-cert">SSL</label>
        <?php endif; ?>
        <?php if ($_panel_show_response): ?>
        <label for="tab-response">Trace <span class="tab-count"><?php echo count($_panel_request_pairs) + count($_panel_response_pairs); ?></span></label>
        <?php endif; ?>
        <label for="tab-cli">CLI</label>
        <?php if ($_panel_show_network): ?>
        <label for="tab-network">Network <span class="tab-count"><?php echo count($_panel_network); ?></span></label>
        <?php endif; ?>
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
        if (!$_frozen[$_f]) { $_visible = true; break; }
    }
    if (!$_visible) continue;
?>
        <fieldset class="option-group">
            <legend><?php echo htmlspecialchars($_group_name); ?></legend>
            <ul class="prx-opt-menu">
<?php foreach ($_group_flags as $_f): ?>
<?php if (!$_frozen[$_f]): ?>
                <li class="option"><label><input<?php echo $_form_attr; ?> type="checkbox" name="<?php echo $_cfg['flags_var_name']; ?>[<?php echo $_f; ?>]"<?php echo $_flags_local[$_f] ? ' checked' : ''; ?>/><span><?php echo $_labels_local[$_f][0]; ?></span></label></li>
<?php endif; ?>
<?php endforeach; ?>
            </ul>
        </fieldset>
<?php endforeach; ?>
<?php
$_url_encoding = 'none';
if (!empty($_flags_local['encrypt_url']))   $_url_encoding = 'encrypted';
elseif ($_flags_local['rotate13'])          $_url_encoding = 'rot13';
elseif ($_flags_local['base64_encode'])     $_url_encoding = 'base64';
$_seed_ttl  = phproxy_seed_ttl();
$_seed_bits = phproxy_seed_bits();
$_seed_present = !empty($_COOKIE['phproxy-seed']);
?>
        <fieldset class="option-group">
            <legend>Address bar</legend>
            <div class="radio-row">
                <span class="radio-row-label">URL encoding</span>
                <label><input<?php echo $_form_attr; ?> type="radio" name="<?php echo $_cfg['flags_var_name']; ?>[__url_enc]" value="none"<?php echo $_url_encoding === 'none' ? ' checked' : ''; ?>/> None</label>
                <label><input<?php echo $_form_attr; ?> type="radio" name="<?php echo $_cfg['flags_var_name']; ?>[__url_enc]" value="rot13"<?php echo $_url_encoding === 'rot13' ? ' checked' : ''; ?>/> ROT13</label>
                <label><input<?php echo $_form_attr; ?> type="radio" name="<?php echo $_cfg['flags_var_name']; ?>[__url_enc]" value="base64"<?php echo $_url_encoding === 'base64' ? ' checked' : ''; ?>/> Base64</label>
                <label><input<?php echo $_form_attr; ?> type="radio" name="<?php echo $_cfg['flags_var_name']; ?>[__url_enc]" value="encrypted"<?php echo $_url_encoding === 'encrypted' ? ' checked' : ''; ?>/> Encrypted <span class="hint">(rotating key)</span></label>
            </div>
            <div class="seed-config">
                <p class="tab-help">Encrypted mode wraps each URL with a fresh AES-CTR ciphertext keyed off a random session seed. Once the seed rotates, any URL recorded earlier (logs, history, referers) becomes useless.</p>
                <div class="seed-row">
                    <form class="seed-mini" method="post" action="?action=set-seed-ttl">
                        <?php echo $_panel_return_html; ?>
                        <label class="seed-mini-label">Seed lifetime <span class="hint">(seconds)</span></label>
                        <input type="number" name="seedTtl" min="60" max="604800" step="60" value="<?php echo (int) $_seed_ttl; ?>"/>
                        <button class="button-cancel" type="submit">Save</button>
                    </form>
                    <form class="seed-mini" method="post" action="?action=set-seed-bits">
                        <?php echo $_panel_return_html; ?>
                        <label class="seed-mini-label">Key length</label>
                        <select name="seedBits">
                            <option value="128"<?php echo $_seed_bits === 128 ? ' selected' : ''; ?>>AES-128</option>
                            <option value="192"<?php echo $_seed_bits === 192 ? ' selected' : ''; ?>>AES-192</option>
                            <option value="256"<?php echo $_seed_bits === 256 ? ' selected' : ''; ?>>AES-256</option>
                        </select>
                        <button class="button-cancel" type="submit">Save</button>
                    </form>
                    <form class="seed-mini" method="post" action="?action=rotate-seed">
                        <?php echo $_panel_return_html; ?>
                        <label class="seed-mini-label">Seed <span class="hint"><?php echo $_seed_present ? 'live' : 'not set'; ?></span></label>
                        <button class="button-ghost" type="submit" title="Generate a new random seed now">Rotate now</button>
                    </form>
                </div>
            </div>
        </fieldset>
    </section>

    <section class="tab-panel" data-tab="cookies">
        <div class="tab-toolbar">
            <p class="tab-help">Cookies held for proxied sites. Click a row to edit any attribute.</p>
            <form class="toolbar-toggle" method="post" action="?action=toggle-raw">
                <?php echo $_panel_return_html; ?>
                <button type="submit" class="button-cancel" title="Switch between URL-decoded and raw wire-form values"><?php echo $_show_raw ? 'Showing: raw' : 'Showing: decoded'; ?></button>
            </form>
        </div>
<?php if (empty($_v_cookies)): ?>
        <ul class="kv-list"><li class="empty">No cookies yet</li></ul>
<?php else: ?>
        <ul class="kv-list">
<?php
foreach ($_v_cookies as $_wire => $_c):
    $_disp_value = $_show_raw ? $_c['raw_value'] : $_c['value'];
    $_disp_name  = $_show_raw ? $_c['raw_name']  : $_c['display_name'];
?>
            <li class="kv-row">
                <details class="kv-card">
                    <summary>
                        <span class="kv-display">
                            <?php if ($_c['is_proxy']): ?><span class="kv-host"><?php echo htmlspecialchars($_c['host']); ?></span><span class="kv-sep">·</span><?php endif; ?>
                            <span class="kv-name"><?php echo htmlspecialchars($_disp_name); ?></span><span class="kv-sep">=</span><span class="kv-val"><?php echo htmlspecialchars(mb_strimwidth($_disp_value, 0, 72, '…')); ?></span>
                        </span>
                        <span class="kv-chevron" aria-hidden="true">▾</span>
                    </summary>
                    <form class="kv-edit kv-edit-cookie" method="post" action="?action=edit-cookie">
                        <?php echo $_panel_return_html; ?>
                        <input type="hidden" name="name" value="<?php echo htmlspecialchars($_wire); ?>"/>
                        <label class="kv-edit-field"><span>Name</span><input type="text" name="cookieName" value="<?php echo htmlspecialchars($_c['display_name']); ?>" autocomplete="off"/></label>
                        <label class="kv-edit-field kv-edit-wide"><span>Value</span><input type="text" name="cookieValue" value="<?php echo htmlspecialchars($_c['value']); ?>" autocomplete="off"/></label>
                        <label class="kv-edit-field"><span>Host</span><input type="text" name="cookieDomain" value="<?php echo $_c['is_proxy'] ? htmlspecialchars($_c['domain']) : ''; ?>" placeholder=".example.com" autocomplete="off"/></label>
                        <label class="kv-edit-field"><span>Path</span><input type="text" name="cookiePath" value="<?php echo htmlspecialchars($_c['path'] ?: '/'); ?>" placeholder="/" autocomplete="off"/></label>
                        <label class="kv-edit-field"><span>Expires <span class="hint">(days, 0 = session)</span></span><input type="number" name="cookieExpires" value="30" min="0" max="3650"/></label>
                        <label class="kv-edit-field"><span>SameSite</span>
                            <select name="cookieSameSite">
                                <option value="">(default)</option>
                                <option value="Lax">Lax</option>
                                <option value="Strict">Strict</option>
                                <option value="None">None</option>
                            </select>
                        </label>
                        <div class="kv-edit-checks">
                            <label class="kv-edit-check"><input type="checkbox" name="cookieSecure"<?php echo $_c['secure'] ? ' checked' : ''; ?>/> Secure</label>
                            <label class="kv-edit-check"><input type="checkbox" name="cookieHttpOnly"/> HttpOnly</label>
                        </div>
                        <div class="kv-edit-actions">
                            <button class="button-submit" type="submit">Save</button>
                        </div>
                    </form>
                </details>
                <form class="kv-delete" method="post" action="?action=delete-cookie">
                    <?php echo $_panel_return_html; ?>
                    <input type="hidden" name="name" value="<?php echo htmlspecialchars($_wire); ?>"/>
                    <button class="button-icon" type="submit" title="Delete cookie" aria-label="Delete <?php echo htmlspecialchars($_c['display_name']); ?>">&times;</button>
                </form>
            </li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
        <details class="kv-card kv-card-add">
            <summary><span class="kv-display"><span class="kv-name">+ Add a cookie</span></span><span class="kv-chevron" aria-hidden="true">▾</span></summary>
            <form class="kv-edit kv-edit-cookie" method="post" action="?action=add-cookie">
                <?php echo $_panel_return_html; ?>
                <label class="kv-edit-field"><span>Name</span><input type="text" name="cookieName" placeholder="session_id" autocomplete="off" required/></label>
                <label class="kv-edit-field kv-edit-wide"><span>Value</span><input type="text" name="cookieValue" placeholder="abc123" autocomplete="off"/></label>
                <label class="kv-edit-field"><span>Host <span class="hint">(empty = proxy domain)</span></span><input type="text" name="cookieDomain" placeholder=".example.com" autocomplete="off"/></label>
                <label class="kv-edit-field"><span>Path</span><input type="text" name="cookiePath" value="/" placeholder="/" autocomplete="off"/></label>
                <label class="kv-edit-field"><span>Expires <span class="hint">(days, 0 = session)</span></span><input type="number" name="cookieExpires" value="30" min="0" max="3650"/></label>
                <label class="kv-edit-field"><span>SameSite</span>
                    <select name="cookieSameSite">
                        <option value="">(default)</option>
                        <option value="Lax" selected>Lax</option>
                        <option value="Strict">Strict</option>
                        <option value="None">None</option>
                    </select>
                </label>
                <div class="kv-edit-checks">
                    <label class="kv-edit-check"><input type="checkbox" name="cookieSecure"/> Secure</label>
                    <label class="kv-edit-check"><input type="checkbox" name="cookieHttpOnly"/> HttpOnly</label>
                </div>
                <div class="kv-edit-actions">
                    <button class="button-submit" type="submit">Add cookie</button>
                </div>
            </form>
        </details>
        <form class="tab-actions" method="post" action="?action=clear-cookies">
            <?php echo $_panel_return_html; ?>
            <button class="button-ghost" type="submit">Clear all cookies</button>
        </form>
    </section>

    <section class="tab-panel" data-tab="headers">
        <p class="section-label">User-Agent</p>
        <p class="tab-help">Sent on every proxied request. <code>.</code> means use my real browser's User-Agent, <code>-</code> means send none.</p>
        <form class="ua-form" method="post" action="?action=set-ua">
            <?php echo $_panel_return_html; ?>
            <div class="ua-picker">
                <select id="ua-preset" aria-label="User-Agent preset">
<?php foreach ($_ua_presets_local as $_val => $_lbl): ?>
                    <option value="<?php echo htmlspecialchars($_val); ?>"<?php echo $_current_ua_local === $_val ? ' selected' : ''; ?>><?php echo htmlspecialchars($_lbl); ?></option>
<?php endforeach; ?>
                    <option value="__custom__"<?php echo (!array_key_exists($_current_ua_local, $_ua_presets_local) && $_current_ua_local !== '') ? ' selected' : ''; ?>>Custom…</option>
                </select>
                <input type="text" id="ua-input" name="userAgent" value="<?php echo htmlspecialchars($_current_ua_local); ?>" placeholder="Custom User-Agent string" autocomplete="off"/>
            </div>
            <div class="tab-actions" style="margin-top:12px">
                <button class="button-submit" type="submit">Save User-Agent</button>
            </div>
        </form>

        <hr class="divider"/>

        <p class="section-label">Custom headers</p>
        <p class="tab-help">Extra HTTP headers sent on every outbound request. Header names: ASCII letters, digits, hyphens.</p>
<?php if (empty($_c_headers)): ?>
        <ul class="kv-list"><li class="empty">No custom headers</li></ul>
<?php else: ?>
        <ul class="kv-list">
<?php foreach ($_c_headers as $_h_name => $_h_value): ?>
            <li class="kv-row">
                <details class="kv-card">
                    <summary>
                        <span class="kv-display">
                            <span class="kv-name"><?php echo htmlspecialchars($_h_name); ?></span><span class="kv-sep">:</span><span class="kv-val"><?php echo htmlspecialchars(mb_strimwidth($_h_value, 0, 72, '…')); ?></span>
                        </span>
                        <span class="kv-chevron" aria-hidden="true">▾</span>
                    </summary>
                    <form class="kv-edit" method="post" action="?action=edit-header">
                        <?php echo $_panel_return_html; ?>
                        <input type="hidden" name="oldName" value="<?php echo htmlspecialchars($_h_name); ?>"/>
                        <label class="kv-edit-field"><span>Name</span><input type="text" name="headerName" value="<?php echo htmlspecialchars($_h_name); ?>" pattern="[A-Za-z0-9-]+" autocomplete="off"/></label>
                        <label class="kv-edit-field"><span>Value</span><input type="text" name="headerValue" value="<?php echo htmlspecialchars($_h_value); ?>" autocomplete="off"/></label>
                        <div class="kv-edit-actions">
                            <button class="button-submit" type="submit">Save</button>
                        </div>
                    </form>
                </details>
                <form class="kv-delete" method="post" action="?action=delete-header">
                    <?php echo $_panel_return_html; ?>
                    <input type="hidden" name="name" value="<?php echo htmlspecialchars($_h_name); ?>"/>
                    <button class="button-icon" type="submit" title="Delete header" aria-label="Delete header <?php echo htmlspecialchars($_h_name); ?>">&times;</button>
                </form>
            </li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
        <p class="section-label">Add a header</p>
        <form class="kv-add" method="post" action="?action=add-header">
            <?php echo $_panel_return_html; ?>
            <input type="text" name="headerAddName" placeholder="Accept-Language" pattern="[A-Za-z0-9-]+" autocomplete="off"/>
            <input type="text" name="headerAddValue" placeholder="en-GB,en;q=0.9" autocomplete="off"/>
            <button type="submit">Add</button>
        </form>
    </section>

    <?php if ($_panel_show_netchecks): ?>
    <section class="tab-panel" data-tab="ip">
        <p class="tab-help">Network identity for the current request. <strong>Client</strong> is who you appear as to this server; <strong>Outgoing</strong> is what the wider internet sees when the server reaches out. All data is collected locally via PHP built-ins — no external lookups, no API keys.</p>
        <div class="ipinfo-wrap" data-ipinfo-target>
            <div class="ipinfo-loading">Looking up IPs…</div>
        </div>
        <hr class="divider"/>
        <p class="section-label">Look up any IP</p>
        <form class="netcheck netcheck-solo" data-ipinfo-lookup autocomplete="off">
            <div class="netcheck-fields">
                <input type="text" name="ip" placeholder="1.1.1.1  or  2606:4700:4700::1111" required spellcheck="false" autocapitalize="off"/>
                <button class="button-submit" type="submit">Look up</button>
            </div>
            <pre class="netcheck-result" hidden></pre>
        </form>
    </section>

    <section class="tab-panel" data-tab="port">
        <p class="tab-help">Test TCP reachability and measure connection latency. A single port (<code>443</code>), an inclusive range (<code>80-443</code>) or a comma list (<code>22,80,443,8000-8010</code>) — up to 128 ports per scan. Loopback / RFC1918 hosts are blocked.</p>
        <form class="netcheck netcheck-solo" data-netcheck="portcheck" autocomplete="off">
            <div class="netcheck-fields">
                <input type="text" name="host" placeholder="host.example.com" required spellcheck="false" autocapitalize="off"/>
                <input type="text" name="port" placeholder="443  or  80-443  or  22,80,443" pattern="[0-9,\-\s]+" required spellcheck="false" autocapitalize="off"/>
            </div>
            <button class="button-submit" type="submit">Check port</button>
            <pre class="netcheck-result" hidden></pre>
        </form>
    </section>

    <section class="tab-panel" data-tab="dns">
        <p class="tab-help">Resolve DNS records via the server's resolver. A / AAAA / MX / TXT / NS / SOA / CAA / CNAME / SRV / ANY supported.</p>
        <form class="netcheck netcheck-solo" data-netcheck="dns" autocomplete="off">
            <div class="netcheck-fields">
                <input type="text" name="host" placeholder="example.com" required spellcheck="false" autocapitalize="off"/>
                <select name="type">
                    <option value="A">A</option>
                    <option value="AAAA">AAAA</option>
                    <option value="MX">MX</option>
                    <option value="TXT">TXT</option>
                    <option value="NS">NS</option>
                    <option value="SOA">SOA</option>
                    <option value="CAA">CAA</option>
                    <option value="CNAME">CNAME</option>
                    <option value="SRV">SRV</option>
                    <option value="ANY">ANY</option>
                </select>
            </div>
            <button class="button-submit" type="submit">Lookup</button>
            <pre class="netcheck-result" hidden></pre>
        </form>
    </section>

    <section class="tab-panel" data-tab="cert">
        <p class="tab-help">Open an SSL/TLS handshake and inspect everything available: TLS versions the server accepts, the cipher negotiated per version, full certificate chain with every parsed extension, fingerprints, and the raw PEM blocks. All data comes from PHP's OpenSSL functions — no <code>openssl s_client</code> shell-out, no library.</p>
        <form class="netcheck netcheck-solo" data-netcheck="cert" autocomplete="off">
            <div class="netcheck-fields">
                <input type="text" name="host" placeholder="example.com" required spellcheck="false" autocapitalize="off"/>
                <input type="number" name="port" placeholder="443" min="1" max="65535" value="443" required/>
            </div>
            <button class="button-submit" type="submit">Inspect cert</button>
            <div class="netcheck-result cert-result" hidden></div>
        </form>
    </section>
    <?php endif; ?>

    <?php if ($_panel_show_response): ?>
    <section class="tab-panel" data-tab="response">
        <p class="section-label">Request to upstream</p>
        <p class="tab-help">The HTTP request the proxy sent on your behalf for this URL.</p>
<?php if ($_panel_request_line !== ''): ?>
        <ul class="kv-list">
            <li class="kv-row kv-row-readonly">
                <div class="kv-card">
                    <div class="kv-card-readonly kv-card-status"><?php echo htmlspecialchars($_panel_request_line); ?></div>
                </div>
            </li>
<?php foreach ($_panel_request_pairs as $_rh): list($_rh_name, $_rh_value) = $_rh; ?>
            <li class="kv-row kv-row-readonly">
                <div class="kv-card">
                    <div class="kv-card-readonly">
                        <span class="kv-name"><?php echo htmlspecialchars($_rh_name); ?></span><span class="kv-sep">:</span><span class="kv-val"><?php echo htmlspecialchars($_rh_value); ?></span>
                    </div>
                </div>
            </li>
<?php endforeach; ?>
        </ul>
<?php else: ?>
        <ul class="kv-list"><li class="empty">No request captured</li></ul>
<?php endif; ?>

        <hr class="divider"/>
        <p class="section-label">Response from upstream</p>
        <p class="tab-help">Headers the upstream server sent back.</p>
<?php if (empty($_panel_response_pairs)): ?>
        <ul class="kv-list"><li class="empty">No response headers captured</li></ul>
<?php else: ?>
        <ul class="kv-list">
<?php foreach ($_panel_response_pairs as $_rh): list($_rh_name, $_rh_value) = $_rh; ?>
            <li class="kv-row kv-row-readonly">
                <div class="kv-card">
                    <div class="kv-card-readonly<?php echo $_rh_value === '' ? ' kv-card-status' : ''; ?>">
<?php if ($_rh_value === ''): ?>
                        <?php echo htmlspecialchars($_rh_name); ?>
<?php else: ?>
                        <span class="kv-name"><?php echo htmlspecialchars($_rh_name); ?></span><span class="kv-sep">:</span><span class="kv-val"><?php echo htmlspecialchars($_rh_value); ?></span>
<?php endif; ?>
                    </div>
                </div>
            </li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
    </section>
    <?php endif; ?>

    <?php
        $_cli_has_url = $_panel_cli_url !== '';
        // Base URL for the check examples — the proxy's own URL, without
        // the ?api=fetch suffix. Falls back to $_script_url for the entry page.
        $_cli_base = $_panel_cli_api_url !== ''
            ? (string) preg_replace('/\?.*$/', '', $_panel_cli_api_url)
            : (string) ($GLOBALS['_script_url'] ?? '');
        // When there's no URL context (entry page) we still want to show how
        // to drive ?api=fetch — fall back to a clear placeholder URL.
        $_cli_url_eff     = $_cli_has_url ? $_panel_cli_url     : 'https://example.com/';
        $_cli_method_eff  = $_panel_cli_method !== '' ? $_panel_cli_method : 'GET';
        $_cli_headers_eff = $_cli_has_url ? $_panel_cli_headers : [['User-Agent', 'PHProxy/1.0']];
        $_cli_body_eff    = $_panel_cli_body;
        $_cli_api_url_eff = $_panel_cli_api_url !== '' ? $_panel_cli_api_url : ($_cli_base . '?api=fetch');
        $_cli_snippets = [
            'curl'   => [
                'label'  => 'curl',
                'direct' => phproxy_cli_curl_direct($_cli_url_eff, $_cli_method_eff, $_cli_headers_eff, $_cli_body_eff),
                'proxy'  => phproxy_cli_curl_proxy($_cli_api_url_eff, $_cli_url_eff, $_cli_method_eff, $_cli_headers_eff, [], $_cli_body_eff),
                'checks' => phproxy_cli_checks_curl($_cli_base),
            ],
            'php'    => [
                'label'  => 'PHP',
                'direct' => phproxy_cli_php_direct($_cli_url_eff, $_cli_method_eff, $_cli_headers_eff, $_cli_body_eff),
                'proxy'  => phproxy_cli_php_proxy($_cli_api_url_eff, $_cli_url_eff, $_cli_method_eff, $_cli_headers_eff, [], $_cli_body_eff),
                'checks' => phproxy_cli_checks_php($_cli_base),
            ],
            'python' => [
                'label'  => 'Python',
                'direct' => phproxy_cli_python_direct($_cli_url_eff, $_cli_method_eff, $_cli_headers_eff, $_cli_body_eff),
                'proxy'  => phproxy_cli_python_proxy($_cli_api_url_eff, $_cli_url_eff, $_cli_method_eff, $_cli_headers_eff, [], $_cli_body_eff),
                'checks' => phproxy_cli_checks_python($_cli_base),
            ],
            'js'     => [
                'label'  => 'JavaScript',
                'direct' => phproxy_cli_js_direct($_cli_url_eff, $_cli_method_eff, $_cli_headers_eff, $_cli_body_eff),
                'proxy'  => phproxy_cli_js_proxy($_cli_api_url_eff, $_cli_url_eff, $_cli_method_eff, $_cli_headers_eff, [], $_cli_body_eff),
                'checks' => phproxy_cli_checks_js($_cli_base),
            ],
            'go'     => [
                'label'  => 'Go',
                'direct' => phproxy_cli_go_direct($_cli_url_eff, $_cli_method_eff, $_cli_headers_eff, $_cli_body_eff),
                'proxy'  => phproxy_cli_go_proxy($_cli_api_url_eff, $_cli_url_eff, $_cli_method_eff, $_cli_headers_eff, [], $_cli_body_eff),
                'checks' => phproxy_cli_checks_go($_cli_base),
            ],
        ];
        $_cli_first = true;
    ?>
    <section class="tab-panel" data-tab="cli">
        <p class="tab-help">
            <?php if ($_cli_has_url): ?>
            Reproduce this request from the command line. <strong>Direct</strong> hits the upstream target without involving PHProxy; <strong>Via PHProxy</strong> POSTs to the JSON API. <strong>Port / DNS / SSL</strong> below show how to drive the network-check endpoints from each language.
            <?php else: ?>
            How to drive every endpoint from the command line. <strong>Direct</strong> and <strong>Via PHProxy</strong> use a placeholder URL — open a proxied page and these snippets get pre-filled with that specific URL, method, headers and body. <strong>Port / DNS / SSL</strong> below drive the network-check endpoints.
            <?php endif; ?>
        </p>
        <div class="cli-wrap">
<?php foreach ($_cli_snippets as $_cli_key => $_cli_meta): ?>
            <input type="radio" name="cli-tab" id="cli-tab-<?php echo $_cli_key; ?>"<?php echo $_cli_first ? ' checked' : ''; $_cli_first = false; ?>/>
<?php endforeach; ?>
            <nav class="cli-tabs">
<?php foreach ($_cli_snippets as $_cli_key => $_cli_meta): ?>
                <label for="cli-tab-<?php echo $_cli_key; ?>"><?php echo htmlspecialchars($_cli_meta['label']); ?></label>
<?php endforeach; ?>
            </nav>
<?php foreach ($_cli_snippets as $_cli_key => $_cli_meta): ?>
            <section class="cli-panel" data-cli="<?php echo $_cli_key; ?>">
                <div class="cli-variant">
                    <div class="cli-toolbar">
                        <span class="cli-variant-label">Direct to upstream<?php echo $_cli_has_url ? '' : ' (example)'; ?></span>
                        <button type="button" class="button-cancel cli-copy" data-target="cli-snippet-<?php echo $_cli_key; ?>-direct">Copy</button>
                    </div>
                    <pre id="cli-snippet-<?php echo $_cli_key; ?>-direct" class="cli-snippet"><?php echo htmlspecialchars($_cli_meta['direct']); ?></pre>
                </div>
                <div class="cli-variant">
                    <div class="cli-toolbar">
                        <span class="cli-variant-label">Via PHProxy <code>?api=fetch</code><?php echo $_cli_has_url ? '' : ' (example)'; ?></span>
                        <button type="button" class="button-cancel cli-copy" data-target="cli-snippet-<?php echo $_cli_key; ?>-proxy">Copy</button>
                    </div>
                    <pre id="cli-snippet-<?php echo $_cli_key; ?>-proxy" class="cli-snippet"><?php echo htmlspecialchars($_cli_meta['proxy']); ?></pre>
                </div>
                <div class="cli-variant">
                    <div class="cli-toolbar">
                        <span class="cli-variant-label">Port / DNS / SSL checks</span>
                        <button type="button" class="button-cancel cli-copy" data-target="cli-snippet-<?php echo $_cli_key; ?>-checks">Copy</button>
                    </div>
                    <pre id="cli-snippet-<?php echo $_cli_key; ?>-checks" class="cli-snippet"><?php echo htmlspecialchars($_cli_meta['checks']); ?></pre>
                </div>
            </section>
<?php endforeach; ?>
        </div>
    </section>

    <?php if ($_panel_show_network): ?>
    <section class="tab-panel" data-tab="network">
        <div class="tab-toolbar">
            <p class="tab-help">Every URL the proxy has fetched on your behalf, newest first. The current page is highlighted. <?php echo count($_panel_network); ?>/50 entries.</p>
            <form class="toolbar-toggle" method="post" action="?action=clear-network">
                <?php echo $_panel_return_html; ?>
                <button type="submit" class="button-ghost" title="Clear the Network log">Clear</button>
            </form>
        </div>
<?php if (empty($_panel_network)): ?>
        <ul class="kv-list"><li class="empty">No requests logged yet</li></ul>
<?php else: ?>
        <table class="net-table">
            <thead>
                <tr>
                    <th>URL</th>
                    <th>Method</th>
                    <th>Status</th>
                    <th>Type</th>
                    <th class="num">Size</th>
                    <th class="num">TTFB</th>
                    <th class="num">Time</th>
                </tr>
            </thead>
            <tbody>
<?php
$_net_rev = array_reverse($_panel_network);
foreach ($_net_rev as $_ne):
    $_is_current = $_ne['url'] === $_panel_net_current;
    $_status_class = 'st-other';
    if ($_ne['status'] >= 200 && $_ne['status'] < 300) $_status_class = 'st-2xx';
    elseif ($_ne['status'] >= 300 && $_ne['status'] < 400) $_status_class = 'st-3xx';
    elseif ($_ne['status'] >= 400 && $_ne['status'] < 500) $_status_class = 'st-4xx';
    elseif ($_ne['status'] >= 500) $_status_class = 'st-5xx';
?>
                <tr<?php echo $_is_current ? ' class="current"' : ''; ?>>
                    <td class="url" title="<?php echo htmlspecialchars($_ne['url']); ?>"><?php echo htmlspecialchars(mb_strimwidth($_ne['url'], 0, 78, '…')); ?></td>
                    <td class="method"><?php echo htmlspecialchars($_ne['method']); ?></td>
                    <td class="status <?php echo $_status_class; ?>"><?php echo (int) $_ne['status']; ?></td>
                    <td class="type"><?php echo htmlspecialchars($_ne['type']); ?></td>
                    <td class="num"><?php echo phproxy_fmt_size((int) $_ne['size']); ?></td>
                    <td class="num"><?php echo (int) $_ne['ttfb_ms']; ?> ms</td>
                    <td class="num"><?php echo (int) $_ne['duration_ms']; ?> ms</td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
<?php endif; ?>
    </section>
    <?php endif; ?>
</div>
<?php
}

// --- EMBEDDED STYLESHEETS (served via ?asset=...) ---

/**
 * Append a request to the Network tab log kept in $_SESSION. Kept to the
 * last 50 entries so the cookie-backed session file doesn't grow without
 * bound. Times are in float seconds (microtime(true)); rendering converts
 * them to ms.
 */
function phproxy_net_log(string $url, string $method, int $status, string $type, int $size, float $start, ?float $ttfb_t, float $end): void
{
    if (!isset($_SESSION) || !is_array($_SESSION)) return;
    if (!isset($_SESSION['phproxy_network']) || !is_array($_SESSION['phproxy_network'])) $_SESSION['phproxy_network'] = [];
    $ttfb_ms     = $ttfb_t !== null ? (int) (($ttfb_t - $start) * 1000) : 0;
    $duration_ms = (int) (($end - $start) * 1000);
    $_SESSION['phproxy_network'][] = [
        'ts'          => $start,
        'url'         => $url,
        'method'      => $method,
        'status'      => $status,
        'type'        => $type !== '' ? $type : 'unknown',
        'size'        => $size,
        'ttfb_ms'     => $ttfb_ms,
        'duration_ms' => $duration_ms,
    ];
    if (count($_SESSION['phproxy_network']) > 50) {
        $_SESSION['phproxy_network'] = array_slice($_SESSION['phproxy_network'], -50);
    }
}

/**
 * Format a byte count for the Network table.
 */
function phproxy_fmt_size(int $bytes): string
{
    if ($bytes < 1024)     return $bytes . ' B';
    if ($bytes < 1048576)  return number_format($bytes / 1024, 1)    . ' KB';
    return                 number_format($bytes / 1048576, 1) . ' MB';
}

/**
 * CLI snippet helpers — build copy-pasteable code that replicates the
 * upstream request the proxy just made, in a handful of languages. The
 * snippets target the upstream directly (not the JSON API), so a user
 * can take the output and run it without going through PHProxy.
 *
 * Each helper takes the same shape: ($url, $method, $headers, $body) and
 * returns a single-string snippet. Quoting / escaping is per-language so
 * values containing apostrophes or backslashes survive the round-trip.
 */

function phproxy_snip_shell(string $v): string {
    // POSIX-shell single-quoted: close, escaped quote, reopen — 'don'\''t' style
    return "'" . str_replace("'", "'\\''", $v) . "'";
}
function phproxy_snip_php(string $v): string {
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $v) . "'";
}
function phproxy_snip_py(string $v): string {
    $escaped = str_replace(['\\', "'", "\n", "\r"], ['\\\\', "\\'", '\\n', '\\r'], $v);
    return "'" . $escaped . "'";
}
function phproxy_snip_js(string $v): string {
    $escaped = str_replace(['\\', "'", "\n", "\r"], ['\\\\', "\\'", '\\n', '\\r'], $v);
    return "'" . $escaped . "'";
}
function phproxy_snip_go(string $v): string {
    $escaped = str_replace(['\\', '"', "\n", "\r", "\t"], ['\\\\', '\\"', '\\n', '\\r', '\\t'], $v);
    return '"' . $escaped . '"';
}

/**
 * Strip headers that the JSON API rebuilds itself (so the "Via PHProxy"
 * snippets don't double them up). Also returns the Cookie header parsed
 * into a {name: value} dict for the api's `cookies` field.
 */
function phproxy_cli_split_for_api(array $headers): array
{
    $skip = ['host' => 1, 'connection' => 1, 'content-length' => 1, 'cookie' => 1];
    $filtered = [];
    $cookies  = [];
    foreach ($headers as $h) {
        [$name, $value] = $h;
        $lower = strtolower($name);
        if ($lower === 'cookie') {
            foreach (preg_split('/;\s*/', $value) as $pair) {
                if ($pair === '') continue;
                $eq = strpos($pair, '=');
                if ($eq === false) continue;
                $cookies[substr($pair, 0, $eq)] = substr($pair, $eq + 1);
            }
            continue;
        }
        if (isset($skip[$lower])) continue;
        $filtered[] = $h;
    }
    return ['headers' => $filtered, 'cookies' => $cookies];
}

/**
 * Build the JSON payload string the API expects. Pretty-printed so
 * snippets stay readable when shown in the panel.
 */
function phproxy_cli_api_payload(string $url, string $method, array $headers, array $cookies, string $body): string
{
    $payload = ['url' => $url];
    if ($method !== 'GET') $payload['method'] = $method;
    if (!empty($headers)) {
        $payload['headers'] = [];
        foreach ($headers as $h) {
            [$name, $value] = $h;
            $payload['headers'][$name] = $value;
        }
    }
    if (!empty($cookies)) $payload['cookies'] = $cookies;
    if ($body !== '')     $payload['body']    = $body;
    return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * curl — direct fetch to upstream.
 */
function phproxy_cli_curl_direct(string $url, string $method, array $headers, string $body = ''): string
{
    $lines = ['curl -X ' . phproxy_snip_shell($method) . ' ' . phproxy_snip_shell($url) . ' \\'];
    foreach ($headers as $h) {
        [$name, $value] = $h;
        $lines[] = '  -H ' . phproxy_snip_shell($name . ': ' . $value) . ' \\';
    }
    if ($body !== '') {
        $lines[] = '  --data-binary ' . phproxy_snip_shell($body);
    } else {
        $lines[count($lines) - 1] = rtrim($lines[count($lines) - 1], ' \\');
    }
    return implode("\n", $lines);
}

/**
 * curl — POST the JSON envelope to /index.php?api=fetch so the upstream
 * fetch happens through PHProxy. Pipes the resulting JSON to jq for the
 * body field.
 */
function phproxy_cli_curl_proxy(string $api_url, string $upstream, string $method, array $headers, array $cookies, string $body = ''): string
{
    $split   = phproxy_cli_split_for_api($headers);
    $payload = phproxy_cli_api_payload($upstream, $method, $split['headers'], $cookies, $body);
    $out  = 'curl -X POST ' . phproxy_snip_shell($api_url) . ' \\' . "\n";
    $out .= '  -H ' . phproxy_snip_shell('Content-Type: application/json') . ' \\' . "\n";
    $out .= '  -d ' . phproxy_snip_shell($payload) . " \\\n";
    $out .= "  | jq -r .body";
    return $out;
}

/**
 * PHP cURL — direct.
 */
function phproxy_cli_php_direct(string $url, string $method, array $headers, string $body = ''): string
{
    $out  = "<?php\n";
    $out .= '$ch = curl_init(' . phproxy_snip_php($url) . ");\n";
    $out .= "curl_setopt_array(\$ch, [\n";
    $out .= '    CURLOPT_CUSTOMREQUEST  => ' . phproxy_snip_php($method) . ",\n";
    $out .= "    CURLOPT_RETURNTRANSFER => true,\n";
    $out .= "    CURLOPT_FOLLOWLOCATION => true,\n";
    if (!empty($headers)) {
        $out .= "    CURLOPT_HTTPHEADER     => [\n";
        foreach ($headers as $h) {
            [$name, $value] = $h;
            $out .= '        ' . phproxy_snip_php($name . ': ' . $value) . ",\n";
        }
        $out .= "    ],\n";
    }
    if ($body !== '') $out .= '    CURLOPT_POSTFIELDS     => ' . phproxy_snip_php($body) . ",\n";
    $out .= "]);\n";
    $out .= "\$response = curl_exec(\$ch);\n";
    $out .= "\$status   = curl_getinfo(\$ch, CURLINFO_HTTP_CODE);\n";
    $out .= "curl_close(\$ch);\n\n";
    $out .= "echo \$status, \"\\n\", \$response;";
    return $out;
}

/**
 * PHP cURL — via PHProxy JSON API.
 */
function phproxy_cli_php_proxy(string $api_url, string $upstream, string $method, array $headers, array $cookies, string $body = ''): string
{
    $split   = phproxy_cli_split_for_api($headers);
    $payload = [];
    $payload['url']     = $upstream;
    if ($method !== 'GET') $payload['method'] = $method;
    if (!empty($split['headers'])) {
        $payload['headers'] = [];
        foreach ($split['headers'] as $h) {
            [$name, $value] = $h;
            $payload['headers'][$name] = $value;
        }
    }
    if (!empty($split['cookies'])) $payload['cookies'] = $split['cookies'];
    if ($body !== '') $payload['body'] = $body;

    $out  = "<?php\n";
    $out .= "\$payload = " . var_export($payload, true) . ";\n\n";
    $out .= '$ch = curl_init(' . phproxy_snip_php($api_url) . ");\n";
    $out .= "curl_setopt_array(\$ch, [\n";
    $out .= "    CURLOPT_POST           => true,\n";
    $out .= "    CURLOPT_POSTFIELDS     => json_encode(\$payload),\n";
    $out .= "    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],\n";
    $out .= "    CURLOPT_RETURNTRANSFER => true,\n";
    $out .= "]);\n";
    $out .= "\$envelope = json_decode(curl_exec(\$ch), true);\n";
    $out .= "curl_close(\$ch);\n\n";
    $out .= "echo \$envelope['status'], \"\\n\", \$envelope['body'];";
    return $out;
}

/**
 * Python — direct.
 */
function phproxy_cli_python_direct(string $url, string $method, array $headers, string $body = ''): string
{
    $out  = "import requests\n\n";
    $out .= 'response = requests.request(' . phproxy_snip_py($method) . ', ' . phproxy_snip_py($url) . ",\n";
    if (!empty($headers)) {
        $out .= "    headers={\n";
        foreach ($headers as $h) {
            [$name, $value] = $h;
            $out .= '        ' . phproxy_snip_py($name) . ': ' . phproxy_snip_py($value) . ",\n";
        }
        $out .= "    },\n";
    }
    if ($body !== '') $out .= '    data=' . phproxy_snip_py($body) . ",\n";
    $out .= "    allow_redirects=True,\n";
    $out .= ")\n\n";
    $out .= "print(response.status_code)\n";
    $out .= "print(response.text)";
    return $out;
}

/**
 * Python — via PHProxy JSON API.
 */
function phproxy_cli_python_proxy(string $api_url, string $upstream, string $method, array $headers, array $cookies, string $body = ''): string
{
    $split = phproxy_cli_split_for_api($headers);
    $out  = "import requests\n\n";
    $out .= "payload = {\n";
    $out .= '    "url":    ' . phproxy_snip_py($upstream) . ",\n";
    if ($method !== 'GET') {
        $out .= '    "method": ' . phproxy_snip_py($method) . ",\n";
    }
    if (!empty($split['headers'])) {
        $out .= "    \"headers\": {\n";
        foreach ($split['headers'] as $h) {
            [$name, $value] = $h;
            $out .= '        ' . phproxy_snip_py($name) . ': ' . phproxy_snip_py($value) . ",\n";
        }
        $out .= "    },\n";
    }
    if (!empty($split['cookies'])) {
        $out .= "    \"cookies\": {\n";
        foreach ($split['cookies'] as $cn => $cv) {
            $out .= '        ' . phproxy_snip_py($cn) . ': ' . phproxy_snip_py($cv) . ",\n";
        }
        $out .= "    },\n";
    }
    if ($body !== '') $out .= '    "body":   ' . phproxy_snip_py($body) . ",\n";
    $out .= "}\n\n";
    $out .= 'envelope = requests.post(' . phproxy_snip_py($api_url) . ", json=payload).json()\n";
    $out .= "print(envelope['status'])\n";
    $out .= "print(envelope['body'])";
    return $out;
}

/**
 * JavaScript — direct.
 */
function phproxy_cli_js_direct(string $url, string $method, array $headers, string $body = ''): string
{
    $out = 'const response = await fetch(' . phproxy_snip_js($url) . ", {\n";
    $out .= '    method: ' . phproxy_snip_js($method) . ",\n";
    if (!empty($headers)) {
        $out .= "    headers: {\n";
        foreach ($headers as $h) {
            [$name, $value] = $h;
            $out .= '        ' . phproxy_snip_js($name) . ': ' . phproxy_snip_js($value) . ",\n";
        }
        $out .= "    },\n";
    }
    if ($body !== '') $out .= '    body: ' . phproxy_snip_js($body) . ",\n";
    $out .= "});\n";
    $out .= "console.log(response.status, await response.text());";
    return $out;
}

/**
 * JavaScript — via PHProxy JSON API.
 */
function phproxy_cli_js_proxy(string $api_url, string $upstream, string $method, array $headers, array $cookies, string $body = ''): string
{
    $split = phproxy_cli_split_for_api($headers);
    $out  = "const payload = {\n";
    $out .= '    url: ' . phproxy_snip_js($upstream) . ",\n";
    if ($method !== 'GET') $out .= '    method: ' . phproxy_snip_js($method) . ",\n";
    if (!empty($split['headers'])) {
        $out .= "    headers: {\n";
        foreach ($split['headers'] as $h) {
            [$name, $value] = $h;
            $out .= '        ' . phproxy_snip_js($name) . ': ' . phproxy_snip_js($value) . ",\n";
        }
        $out .= "    },\n";
    }
    if (!empty($split['cookies'])) {
        $out .= "    cookies: {\n";
        foreach ($split['cookies'] as $cn => $cv) {
            $out .= '        ' . phproxy_snip_js($cn) . ': ' . phproxy_snip_js($cv) . ",\n";
        }
        $out .= "    },\n";
    }
    if ($body !== '') $out .= '    body: ' . phproxy_snip_js($body) . ",\n";
    $out .= "};\n\n";
    $out .= 'const r = await fetch(' . phproxy_snip_js($api_url) . ", {\n";
    $out .= "    method: 'POST',\n";
    $out .= "    headers: { 'Content-Type': 'application/json' },\n";
    $out .= "    body: JSON.stringify(payload),\n";
    $out .= "});\n";
    $out .= "const envelope = await r.json();\n";
    $out .= "console.log(envelope.status, envelope.body);";
    return $out;
}

/**
 * Go — direct.
 */
function phproxy_cli_go_direct(string $url, string $method, array $headers, string $body = ''): string
{
    $out  = "package main\n\n";
    $out .= "import (\n";
    $out .= "    \"fmt\"\n    \"io\"\n    \"net/http\"\n";
    if ($body !== '') $out .= "    \"strings\"\n";
    $out .= ")\n\n";
    $out .= "func main() {\n";
    if ($body !== '') {
        $out .= '    req, _ := http.NewRequest(' . phproxy_snip_go($method) . ', ' . phproxy_snip_go($url) . ', strings.NewReader(' . phproxy_snip_go($body) . "))\n";
    } else {
        $out .= '    req, _ := http.NewRequest(' . phproxy_snip_go($method) . ', ' . phproxy_snip_go($url) . ", nil)\n";
    }
    foreach ($headers as $h) {
        [$name, $value] = $h;
        $out .= '    req.Header.Set(' . phproxy_snip_go($name) . ', ' . phproxy_snip_go($value) . ")\n";
    }
    $out .= "\n";
    $out .= "    resp, err := (&http.Client{}).Do(req)\n";
    $out .= "    if err != nil { panic(err) }\n";
    $out .= "    defer resp.Body.Close()\n";
    $out .= "    body, _ := io.ReadAll(resp.Body)\n";
    $out .= "    fmt.Println(resp.StatusCode, string(body))\n";
    $out .= "}";
    return $out;
}

/**
 * Go — via PHProxy JSON API.
 */
function phproxy_cli_go_proxy(string $api_url, string $upstream, string $method, array $headers, array $cookies, string $body = ''): string
{
    $split = phproxy_cli_split_for_api($headers);
    $hdr_lines = '';
    foreach ($split['headers'] as $h) {
        [$name, $value] = $h;
        $hdr_lines .= '            ' . phproxy_snip_go($name) . ': ' . phproxy_snip_go($value) . ",\n";
    }
    $cookie_lines = '';
    foreach ($split['cookies'] as $cn => $cv) {
        $cookie_lines .= '            ' . phproxy_snip_go($cn) . ': ' . phproxy_snip_go($cv) . ",\n";
    }
    $body_field = $body !== '' ? '        "body":    ' . phproxy_snip_go($body) . ",\n" : '';
    $method_field = $method !== 'GET' ? '        "method":  ' . phproxy_snip_go($method) . ",\n" : '';

    $out  = "package main\n\n";
    $out .= "import (\n";
    $out .= "    \"bytes\"\n    \"encoding/json\"\n    \"fmt\"\n    \"net/http\"\n";
    $out .= ")\n\n";
    $out .= "func main() {\n";
    $out .= "    payload := map[string]any{\n";
    $out .= '        "url":     ' . phproxy_snip_go($upstream) . ",\n";
    $out .= $method_field;
    if ($hdr_lines !== '') {
        $out .= "        \"headers\": map[string]string{\n";
        $out .= $hdr_lines;
        $out .= "        },\n";
    }
    if ($cookie_lines !== '') {
        $out .= "        \"cookies\": map[string]string{\n";
        $out .= $cookie_lines;
        $out .= "        },\n";
    }
    $out .= $body_field;
    $out .= "    }\n";
    $out .= "    j, _ := json.Marshal(payload)\n";
    $out .= '    resp, err := http.Post(' . phproxy_snip_go($api_url) . ', "application/json", bytes.NewReader(j))' . "\n";
    $out .= "    if err != nil { panic(err) }\n";
    $out .= "    defer resp.Body.Close()\n\n";
    $out .= "    var envelope struct{ Status int `json:\"status\"`; Body string `json:\"body\"` }\n";
    $out .= "    json.NewDecoder(resp.Body).Decode(&envelope)\n";
    $out .= "    fmt.Println(envelope.Status, envelope.Body)\n";
    $out .= "}";
    return $out;
}

/**
 * CLI snippet helpers for the network-check endpoints. Each returns a
 * single multi-block string with examples for portcheck / dns / cert.
 * $base must be the proxy's scheme+host+path (e.g. https://proxy/index.php).
 */
function phproxy_cli_checks_curl(string $base): string
{
    $u_port_one   = $base . '?api=portcheck&host=example.com&port=443';
    $u_port_range = $base . '?api=portcheck&host=example.com&port=80-443';
    $u_port_list  = $base . '?api=portcheck&host=example.com&port=22,80,443,8000-8010';
    $u_dns        = $base . '?api=dns&host=example.com&type=A';
    $u_cert       = $base . '?api=cert&host=example.com&port=443';
    $u_port_stream = $base . '?api=portcheck&host=example.com&port=22-30&stream=1';
    $u_ip_self     = $base . '?api=ipinfo';
    $u_ip_lookup   = $base . '?api=ipinfo&ip=1.1.1.1';
    return "# IP info — client + server + outgoing IP + reverse DNS + request headers\n"
         . 'curl ' . phproxy_snip_shell($u_ip_self) . "\n\n"
         . "# IP info — reverse DNS for any IP (IPv4 or IPv6)\n"
         . 'curl ' . phproxy_snip_shell($u_ip_lookup) . "\n\n"
         . "# Port check — single port\n"
         . 'curl ' . phproxy_snip_shell($u_port_one) . "\n\n"
         . "# Port check — inclusive range\n"
         . 'curl ' . phproxy_snip_shell($u_port_range) . "\n\n"
         . "# Port check — comma-list, mixed with ranges (max 128 ports)\n"
         . 'curl ' . phproxy_snip_shell($u_port_list) . "\n\n"
         . "# Port check — streaming (NDJSON, one line per port as it completes)\n"
         . 'curl -N ' . phproxy_snip_shell($u_port_stream) . "\n\n"
         . "# DNS lookup — A / AAAA / MX / TXT / NS / SOA / CAA / CNAME / SRV / ANY\n"
         . 'curl ' . phproxy_snip_shell($u_dns) . "\n\n"
         . "# SSL handshake + cert chain inspection\n"
         . 'curl ' . phproxy_snip_shell($u_cert);
}

function phproxy_cli_checks_php(string $base): string
{
    $out  = "<?php\n";
    $out .= '$base = ' . phproxy_snip_php($base) . ";\n\n";
    $out .= "// IP info — me, the server, the server's outgoing IP + reverse DNS\n";
    $out .= "\$ipinfo = json_decode(file_get_contents(\$base . '?api=ipinfo'), true);\n";
    $out .= "printf(\"client:   %s   %s\\n\",   \$ipinfo['client']['ip'],   \$ipinfo['client']['reverse']   ?? '');\n";
    $out .= "printf(\"outgoing: %s   %s\\n\", \$ipinfo['outgoing']['ip'], \$ipinfo['outgoing']['reverse'] ?? '');\n\n";
    $out .= "// Port check — accepts: 443  |  80-443  |  22,80,443,8000-8010  (max 128)\n";
    $out .= "\$port = json_decode(file_get_contents(\$base . '?api=portcheck&host=example.com&port=80-443'), true);\n";
    $out .= "foreach (\$port['results'] as \$r) {\n";
    $out .= "    printf(\"%5d  %s  %d ms\\n\", \$r['port'], \$r['reachable'] ? 'OPEN  ' : 'CLOSED', \$r['latency_ms']);\n";
    $out .= "}\n\n";
    $out .= "// DNS lookup\n";
    $out .= "\$dns = json_decode(file_get_contents(\$base . '?api=dns&host=example.com&type=A'), true);\n";
    $out .= "print_r(\$dns);\n\n";
    $out .= "// SSL cert inspect\n";
    $out .= "\$cert = json_decode(file_get_contents(\$base . '?api=cert&host=example.com&port=443'), true);\n";
    $out .= "print_r(\$cert);";
    return $out;
}

function phproxy_cli_checks_python(string $base): string
{
    $out  = "import requests\n\n";
    $out .= 'base = ' . phproxy_snip_php($base) . "\n\n";
    $out .= "# IP info — me, the server, the server's outgoing IP + reverse DNS\n";
    $out .= "ip = requests.get(base, params={'api': 'ipinfo'}).json()\n";
    $out .= "print(f\"client:   {ip['client']['ip']}   {ip['client'].get('reverse','')}\")\n";
    $out .= "print(f\"outgoing: {ip['outgoing']['ip']}   {ip['outgoing'].get('reverse','')}\")\n\n";
    $out .= "# Port check — accepts: 443  |  80-443  |  22,80,443,8000-8010  (max 128)\n";
    $out .= "scan = requests.get(base, params={'api': 'portcheck', 'host': 'example.com', 'port': '80-443'}).json()\n";
    $out .= "for r in scan['results']:\n";
    $out .= "    print(f\"{r['port']:>5}  {'OPEN  ' if r['reachable'] else 'CLOSED'}  {r['latency_ms']} ms\")\n\n";
    $out .= "# DNS lookup\n";
    $out .= "print(requests.get(base, params={'api': 'dns', 'host': 'example.com', 'type': 'A'}).json())\n\n";
    $out .= "# SSL cert inspect\n";
    $out .= "print(requests.get(base, params={'api': 'cert', 'host': 'example.com', 'port': 443}).json())";
    return $out;
}

function phproxy_cli_checks_js(string $base): string
{
    $b = phproxy_snip_php($base);
    return "const base = $b;\n\n"
         . "// IP info — me, the server, the server's outgoing IP + reverse DNS\n"
         . "const ip = await fetch(base + '?api=ipinfo').then(r => r.json());\n"
         . "console.log(`client:   \${ip.client.ip}   \${ip.client.reverse ?? ''}`);\n"
         . "console.log(`outgoing: \${ip.outgoing.ip}   \${ip.outgoing.reverse ?? ''}`);\n\n"
         . "// Port check — accepts: 443  |  80-443  |  22,80,443,8000-8010  (max 128)\n"
         . "const scan = await fetch(base + '?api=portcheck&host=example.com&port=80-443').then(r => r.json());\n"
         . "for (const r of scan.results) {\n"
         . "    console.log(`\${String(r.port).padStart(5)}  \${r.reachable ? 'OPEN  ' : 'CLOSED'}  \${r.latency_ms} ms`);\n"
         . "}\n\n"
         . "// DNS lookup\n"
         . "const dns = await fetch(base + '?api=dns&host=example.com&type=A').then(r => r.json());\n"
         . "console.log(dns);\n\n"
         . "// SSL cert inspect\n"
         . "const cert = await fetch(base + '?api=cert&host=example.com&port=443').then(r => r.json());\n"
         . "console.log(cert);";
}

function phproxy_cli_checks_go(string $base): string
{
    $b = phproxy_snip_go($base);
    $out  = "package main\n\n";
    $out .= "import (\n";
    $out .= "    \"encoding/json\"\n";
    $out .= "    \"fmt\"\n";
    $out .= "    \"io\"\n";
    $out .= "    \"net/http\"\n";
    $out .= ")\n\n";
    $out .= "func get(url string) map[string]any {\n";
    $out .= "    r, _ := http.Get(url)\n";
    $out .= "    defer r.Body.Close()\n";
    $out .= "    b, _ := io.ReadAll(r.Body)\n";
    $out .= "    var m map[string]any\n";
    $out .= "    json.Unmarshal(b, &m)\n";
    $out .= "    return m\n";
    $out .= "}\n\n";
    $out .= "func main() {\n";
    $out .= '    base := ' . $b . "\n\n";
    $out .= "    // IP info — me, the server, the server's outgoing IP + reverse DNS\n";
    $out .= "    fmt.Println(get(base + \"?api=ipinfo\"))\n\n";
    $out .= "    // Port check — accepts: 443  |  80-443  |  22,80,443,8000-8010  (max 128)\n";
    $out .= "    scan := get(base + \"?api=portcheck&host=example.com&port=80-443\")\n";
    $out .= "    for _, r := range scan[\"results\"].([]any) {\n";
    $out .= "        rr := r.(map[string]any)\n";
    $out .= "        status := \"CLOSED\"\n";
    $out .= "        if rr[\"reachable\"].(bool) { status = \"OPEN  \" }\n";
    $out .= "        fmt.Printf(\"%5.0f  %s  %.0f ms\\n\", rr[\"port\"], status, rr[\"latency_ms\"])\n";
    $out .= "    }\n\n";
    $out .= "    fmt.Println(get(base + \"?api=dns&host=example.com&type=A\"))\n";
    $out .= "    fmt.Println(get(base + \"?api=cert&host=example.com&port=443\"))\n";
    $out .= "}";
    return $out;
}

/**
 * JSON API: fetch a URL on behalf of the caller with the given headers,
 * cookies and (optional) body. Pure stream_socket_client; no curl needed.
 * Returns a structured array ready for json_encode().
 */
function phproxy_api_fetch(array $req): array
{
    $url           = (string) ($req['url'] ?? '');
    $method        = strtoupper((string) ($req['method'] ?? 'GET'));
    $headers_in    = is_array($req['headers'] ?? null) ? $req['headers'] : [];
    $cookies_in    = is_array($req['cookies'] ?? null) ? $req['cookies'] : [];
    $body          = (string) ($req['body'] ?? '');
    $timeout       = max(1, min(120, (int) ($req['timeout'] ?? 30)));
    $follow        = !empty($req['follow_redirects']);
    $max_redirects = max(0, min(10, (int) ($req['max_redirects'] ?? 5)));
    $verify_ssl    = !isset($req['verify_ssl']) || $req['verify_ssl'] !== false;

    $started   = microtime(true);
    $redirects = [];

    while (true) {
        $parts = @parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return ['ok' => false, 'error' => 'Invalid URL', 'url' => $url];
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['ok' => false, 'error' => 'Only http/https schemes are allowed', 'url' => $url];
        }
        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        // Open socket
        $proto = $scheme === 'https' ? 'ssl' : 'tcp';
        $ctx_opts = [];
        if ($scheme === 'https' && !$verify_ssl) {
            $ctx_opts['ssl'] = ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true];
        }
        $ctx  = stream_context_create($ctx_opts);
        $sock = @stream_socket_client("$proto://$host:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($sock === false) {
            return ['ok' => false, 'error' => $errstr !== '' ? $errstr : 'Connection failed', 'errno' => (int) $errno, 'url' => $url];
        }
        stream_set_timeout($sock, $timeout);

        // Build request line + headers, sanitising every name/value to prevent
        // CRLF injection. Caller-supplied Host / Connection / Content-Length
        // are ignored — we set those ourselves.
        $req_lines = [];
        $default_port = $scheme === 'https' ? 443 : 80;
        $req_lines[] = "$method $path HTTP/1.0";
        $req_lines[] = "Host: $host" . ($port != $default_port ? ":$port" : '');
        $req_lines[] = 'Connection: close';

        $seen_lower = ['host' => 1, 'connection' => 1, 'content-length' => 1];
        $headers_lower = array_change_key_case($headers_in, CASE_LOWER);
        foreach ($headers_in as $hname => $hvalue) {
            if (!is_string($hname) || !preg_match('/^[A-Za-z0-9-]+$/', $hname)) continue;
            $hlower = strtolower($hname);
            if (isset($seen_lower[$hlower])) continue;
            $hvalue = (string) $hvalue;
            if (strpbrk($hvalue, "\r\n") !== false) continue;
            $req_lines[]            = "$hname: $hvalue";
            $seen_lower[$hlower]    = 1;
        }
        if (!isset($headers_lower['user-agent'])) {
            $req_lines[] = 'User-Agent: PHProxy-API/1.2.0';
        }
        if (!isset($headers_lower['accept'])) {
            $req_lines[] = 'Accept: */*';
        }

        // Cookies
        if (!empty($cookies_in)) {
            $cookie_parts = [];
            foreach ($cookies_in as $cname => $cvalue) {
                if (!is_string($cname) || strpbrk($cname, ";,= \t\r\n") !== false) continue;
                $cvalue = (string) $cvalue;
                if (strpbrk($cvalue, ";\r\n") !== false) continue;
                $cookie_parts[] = "$cname=$cvalue";
            }
            if ($cookie_parts) {
                $req_lines[] = 'Cookie: ' . implode('; ', $cookie_parts);
            }
        }

        // Body
        if ($body !== '' && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $req_lines[] = 'Content-Length: ' . strlen($body);
            if (!isset($headers_lower['content-type'])) {
                $req_lines[] = 'Content-Type: application/octet-stream';
            }
        }

        $request = implode("\r\n", $req_lines) . "\r\n\r\n" . $body;
        fwrite($sock, $request);

        // Read response — headers first (until \r\n\r\n), then body
        $head_raw = '';
        while (!feof($sock)) {
            $chunk = fread($sock, 8192);
            if ($chunk === false || $chunk === '') break;
            $head_raw .= $chunk;
            if (($sep = strpos($head_raw, "\r\n\r\n")) !== false) break;
        }
        $info = stream_get_meta_data($sock);
        if (!empty($info['timed_out'])) {
            fclose($sock);
            return ['ok' => false, 'error' => 'Connection timed out', 'url' => $url];
        }
        $sep      = strpos($head_raw, "\r\n\r\n");
        if ($sep === false) {
            fclose($sock);
            return ['ok' => false, 'error' => 'Malformed response (no header terminator)', 'url' => $url];
        }
        $head     = substr($head_raw, 0, $sep);
        $body_raw = substr($head_raw, $sep + 4);
        while (!feof($sock)) {
            $chunk = fread($sock, 8192);
            if ($chunk === false || $chunk === '') break;
            $body_raw .= $chunk;
        }
        fclose($sock);

        // Parse status + headers
        $lines = explode("\r\n", $head);
        $status_line = array_shift($lines);
        if (!preg_match('#^HTTP/(?:\d\.\d|\d) (\d+)(?: (.*))?$#', $status_line, $sm)) {
            return ['ok' => false, 'error' => 'Invalid status line: ' . $status_line, 'url' => $url];
        }
        $status      = (int) $sm[1];
        $status_text = $sm[2] ?? '';

        $resp_headers = [];
        $set_cookies  = [];
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) continue;
            $hn = trim(substr($line, 0, $colon));
            $hv = trim(substr($line, $colon + 1));
            if (strcasecmp($hn, 'set-cookie') === 0) {
                $cparts = preg_split('/;\s*/', $hv);
                $first  = (string) array_shift($cparts);
                $eq     = strpos($first, '=');
                $cn     = $eq === false ? $first : substr($first, 0, $eq);
                $cv     = $eq === false ? ''     : substr($first, $eq + 1);
                $ck     = ['name' => $cn, 'value' => $cv, 'domain' => '', 'path' => '', 'expires' => '', 'max_age' => null, 'secure' => false, 'httponly' => false, 'samesite' => ''];
                foreach ($cparts as $cp) {
                    if (strcasecmp($cp, 'Secure') === 0)   { $ck['secure']   = true; continue; }
                    if (strcasecmp($cp, 'HttpOnly') === 0) { $ck['httponly'] = true; continue; }
                    $eq2 = strpos($cp, '=');
                    if ($eq2 === false) continue;
                    $k = strtolower(trim(substr($cp, 0, $eq2)));
                    $v = trim(substr($cp, $eq2 + 1));
                    if ($k === 'domain')   $ck['domain']   = $v;
                    if ($k === 'path')     $ck['path']     = $v;
                    if ($k === 'expires')  $ck['expires']  = $v;
                    if ($k === 'samesite') $ck['samesite'] = $v;
                    if ($k === 'max-age')  $ck['max_age']  = (int) $v;
                }
                $set_cookies[] = $ck;
                continue;
            }
            if (isset($resp_headers[$hn])) {
                $resp_headers[$hn] .= ', ' . $hv;
            } else {
                $resp_headers[$hn] = $hv;
            }
        }

        // Redirects
        $resp_headers_lower = array_change_key_case($resp_headers, CASE_LOWER);
        if ($follow && in_array($status, [301, 302, 303, 307, 308], true) && !empty($resp_headers_lower['location'])) {
            if (count($redirects) >= $max_redirects) {
                return ['ok' => false, 'error' => 'Too many redirects', 'url' => $url, 'redirects' => $redirects];
            }
            $redirects[] = ['from' => $url, 'status' => $status, 'to' => $resp_headers_lower['location']];
            $loc = $resp_headers_lower['location'];
            if (preg_match('#^[a-z]+://#i', $loc)) {
                $url = $loc;
            } elseif ($loc !== '' && $loc[0] === '/') {
                $url = "$scheme://$host" . ($port != $default_port ? ":$port" : '') . $loc;
            } else {
                $dir = preg_replace('#/[^/]*$#', '/', ($parts['path'] ?? '/'));
                $url = "$scheme://$host" . ($port != $default_port ? ":$port" : '') . $dir . $loc;
            }
            if ($status === 303 || (in_array($status, [301, 302], true) && in_array($method, ['POST', 'PUT', 'PATCH'], true))) {
                $method = 'GET';
                $body   = '';
            }
            continue;
        }

        // Decode chunked / gzip / deflate so the JSON body is the actual payload
        if (!empty($resp_headers_lower['transfer-encoding']) && stripos($resp_headers_lower['transfer-encoding'], 'chunked') !== false) {
            $body_raw = phproxy_api_decode_chunked($body_raw);
        }
        if (!empty($resp_headers_lower['content-encoding'])) {
            $enc = strtolower($resp_headers_lower['content-encoding']);
            if ($enc === 'gzip' && function_exists('gzdecode')) {
                $d = @gzdecode($body_raw);
                if ($d !== false) $body_raw = $d;
            } elseif ($enc === 'deflate' && function_exists('gzinflate')) {
                $d = @gzinflate($body_raw);
                if ($d !== false) $body_raw = $d;
            }
        }

        return [
            'ok'                   => true,
            'url'                  => $url,
            'status'               => $status,
            'status_text'          => $status_text,
            'headers'              => $resp_headers,
            'set_cookies'          => $set_cookies,
            'body_raw'             => $body_raw,
            'redirects'            => $redirects,
            'duration_ms'          => (int) ((microtime(true) - $started) * 1000),
        ];
    }
}

/**
 * Decode an HTTP/1.1 chunked transfer-encoded body.
 */
function phproxy_api_decode_chunked(string $body): string
{
    $out = '';
    $pos = 0;
    $len = strlen($body);
    while ($pos < $len) {
        $eol = strpos($body, "\r\n", $pos);
        if ($eol === false) break;
        $size_line = substr($body, $pos, $eol - $pos);
        $size_hex  = strpos($size_line, ';') !== false ? substr($size_line, 0, strpos($size_line, ';')) : $size_line;
        $size      = (int) hexdec(trim($size_hex));
        if ($size <= 0) break;
        $pos        = $eol + 2;
        $out       .= substr($body, $pos, $size);
        $pos       += $size + 2;  // chunk + trailing CRLF
    }
    return $out;
}

/**
 * Shared JS asset — defines window.phproxyFmtCertHtml(json) so both the
 * entry-form script and the inline-panel script can render the rich
 * cert detail HTML without duplicating ~100 lines of logic.
 */
function phproxy_netcheck_js(): string {
    return <<<'PHPROXY_NETCHECK_JS'
(function () {
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c];
        });
    }
    function kvGrid(rows) {
        var html = '';
        rows.forEach(function (pair) {
            var k = pair[0], v = pair[1];
            if (v == null || v === '') return;
            html += '<div class="cert-kv-k">' + esc(k) + '</div><div class="cert-kv-v">' + esc(v) + '</div>';
        });
        return html ? '<div class="cert-kv">' + html + '</div>' : '';
    }
    function dnGrid(parts) {
        if (!parts || typeof parts !== 'object') return '';
        var html = '';
        Object.keys(parts).forEach(function (k) {
            var v = parts[k];
            if (Array.isArray(v)) v = v.join(' + ');
            if (v === '' || v == null) return;
            html += '<div class="cert-kv-k">' + esc(k) + '</div><div class="cert-kv-v">' + esc(String(v)) + '</div>';
        });
        return html ? '<div class="cert-kv">' + html + '</div>' : '';
    }
    function fmtKey(c) {
        if (!c.key_type) return '';
        return c.key_type + ' ' + c.key_bits + (c.key_curve ? ' (' + c.key_curve + ')' : '');
    }
    function fmtStatus(days) {
        if (days == null) return '';
        if (days < 0)  return '<span class="cert-status err">EXPIRED ' + Math.abs(days) + 'd ago</span>';
        if (days < 30) return '<span class="cert-status warn">expires in ' + days + 'd</span>';
        return '<span class="cert-status ok">expires in ' + days + 'd</span>';
    }
    function copyBtn(target) {
        return '<button type="button" class="button-cancel cli-copy" data-target="' + target + '">Copy</button>';
    }
    function renderCertCard(c, i, isLeaf) {
        var id   = 'cert-' + Math.random().toString(36).slice(2, 8) + '-' + i;
        var role = isLeaf
            ? 'leaf'
            : (c.subject === c.issuer_dn || c.subject_cn === c.issuer_cn ? 'root' : 'intermediate');
        var title = '<span class="cert-role">' + role + '</span> ' +
                    '<strong>' + esc(c.subject_cn || c.subject || '?') + '</strong>' +
                    ' <span class="cert-arrow">←</span> ' + esc(c.issuer_cn || '?') +
                    '   ' + fmtStatus(c.days_left);

        var coreRows = [
            ['Version',             'X.509 v' + (c.version || '?')],
            ['Serial (hex)',        c.serial_hex],
            ['Serial (decimal)',    c.serial_dec],
            ['Valid from',          c.valid_from],
            ['Valid to',            c.valid_to],
            ['Signature algorithm', c.sig_algorithm + (c.sig_algorithm_long && c.sig_algorithm_long !== c.sig_algorithm ? ' (' + c.sig_algorithm_long + ')' : '')],
            ['Public key',          fmtKey(c)],
            ['SAN',                 (c.san || []).join(', ')],
        ];

        var extHtml = '';
        if (c.extensions && Object.keys(c.extensions).length) {
            var pairs = '';
            Object.keys(c.extensions).forEach(function (k) {
                pairs += '<div class="cert-ext-k">' + esc(k) + '</div><div class="cert-ext-v"><pre>' + esc(String(c.extensions[k])) + '</pre></div>';
            });
            extHtml = '<h5>Extensions (' + Object.keys(c.extensions).length + ')</h5><div class="cert-ext">' + pairs + '</div>';
        }

        var fp = c.fingerprints || {};
        var fpRows = '';
        ['sha256', 'sha1', 'md5'].forEach(function (k) {
            if (fp[k]) fpRows += '<div class="cert-kv-k">' + k.toUpperCase() + '</div><div class="cert-kv-v cert-fp">' + esc(fp[k]) + '</div>';
        });
        var fpBlock = fpRows ? '<h5>Fingerprints</h5><div class="cert-kv">' + fpRows + '</div>' : '';

        var pubId = id + '-pubkey';
        var pemId = id + '-pem';
        var pubBlock = c.public_key_pem
            ? '<details class="cert-raw"><summary>Public key (PEM) ' + copyBtn(pubId) + '</summary><pre id="' + pubId + '" class="cert-pem">' + esc(c.public_key_pem) + '</pre></details>'
            : '';
        var pemBlock = c.pem
            ? '<details class="cert-raw"><summary>Certificate (PEM) ' + copyBtn(pemId) + '</summary><pre id="' + pemId + '" class="cert-pem">' + esc(c.pem) + '</pre></details>'
            : '';

        return '<details class="cert-card"' + (isLeaf ? ' open' : '') + '>' +
                 '<summary><span class="cert-idx">' + i + '</span> ' + title + '</summary>' +
                 '<div class="cert-body">' +
                   '<h5>Subject</h5>' + dnGrid(c.subject_parts) +
                   '<h5>Issuer</h5>'  + dnGrid(c.issuer_parts) +
                   '<h5>Details</h5>' + kvGrid(coreRows) +
                   extHtml +
                   fpBlock +
                   pubBlock + pemBlock +
                 '</div>' +
               '</details>';
    }
    window.phproxyFmtCertHtml = function (j) {
        var N = j.negotiated || {};
        var probe = '';
        if (j.tls_versions && j.tls_versions.length) {
            probe = '<table class="tls-probes">';
            j.tls_versions.forEach(function (v) {
                if (v.supported) {
                    probe += '<tr class="ok"><td class="ver">' + esc(v.version) + '</td><td>✓</td>' +
                             '<td>' + esc(v.cipher_name || '') + '</td>' +
                             '<td>' + (v.cipher_bits ? v.cipher_bits + '-bit' : '') + '</td>' +
                             '<td>' + (v.latency_ms || 0) + ' ms</td></tr>';
                } else {
                    probe += '<tr class="err"><td class="ver">' + esc(v.version) + '</td><td>✗</td>' +
                             '<td colspan="3">' + esc(v.error || 'unsupported') + '</td></tr>';
                }
            });
            probe += '</table>';
        }
        var top =
            '<div class="cert-top">' +
              '<div class="cert-top-line"><strong>' + esc(j.host) + ':' + j.port + '</strong>' +
                ' <span class="cert-time">' + (j.duration_ms || 0) + ' ms</span></div>' +
              '<div class="cert-top-neg">negotiated&nbsp;&nbsp;' +
                '<strong>' + esc(N.protocol || '?') + '</strong>&nbsp;&nbsp;' +
                esc(N.cipher_name || '?') +
                (N.cipher_bits ? '&nbsp;&nbsp;(' + N.cipher_bits + '-bit)' : '') +
              '</div>' +
              probe +
            '</div>';
        var chain = '<h4 class="cert-chain-title">Certificate chain (' + (j.chain_length || (j.chain || []).length) + ')</h4>';
        (j.chain || [j.leaf]).forEach(function (c, i) { chain += renderCertCard(c, i, i === 0); });
        return top + chain;
    };

    // Reusable copy-button wiring for any container that has just been
    // populated with .cli-copy buttons (e.g. cert PEM blocks).
    window.phproxyBindCertCopy = function (container) {
        container.querySelectorAll('.cli-copy').forEach(function (btn) {
            if (btn.dataset.bound) return;
            btn.dataset.bound = '1';
            btn.addEventListener('click', function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                var t = document.getElementById(btn.dataset.target);
                if (!t) return;
                var done = function () { var o = btn.textContent; btn.textContent = 'Copied!'; setTimeout(function () { btn.textContent = o; }, 1500); };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(t.textContent).then(done, done);
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = t.textContent;
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); done(); } catch (e) {}
                    document.body.removeChild(ta);
                }
            });
        });
    };
})();
PHPROXY_NETCHECK_JS;
}

function phproxy_index_css(): string {
    return <<<'PHPROXY_INDEX_CSS'
:root {
    --bg: #f4f5f7;
    --surface: #ffffff;
    --surface-2: #f8fafc;
    --border: #e2e6ec;
    --border-strong: #cbd5e1;
    --text: #0f172a;
    --text-muted: #64748b;
    --accent: #2563eb;
    --accent-hover: #1d4ed8;
    --accent-soft: #dbeafe;
    --accent-ring: rgba(37, 99, 235, .18);
    --danger: #ef4444;
    --danger-soft: #fee2e2;
    --warning: #f59e0b;
    --warning-soft: #fef3c7;
    --success: #10b981;
    --success-soft: #d1fae5;
    --radius: 10px;
    --radius-sm: 6px;
    --shadow: 0 10px 15px -3px rgba(15, 23, 42, .06), 0 4px 6px -4px rgba(15, 23, 42, .04);
    --font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    --font-mono: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Monaco, Consolas, monospace;
    color-scheme: light;
}

@media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
        --bg: #0b1220;
        --surface: #131c2e;
        --surface-2: #1a2438;
        --border: #2a374e;
        --border-strong: #3b4a66;
        --text: #f1f5f9;
        --text-muted: #94a3b8;
        --accent: #60a5fa;
        --accent-hover: #93c5fd;
        --accent-soft: #1e3a8a;
        --accent-ring: rgba(96, 165, 250, .25);
        --danger: #f87171;
        --danger-soft: #7f1d1d;
        --warning: #fbbf24;
        --warning-soft: #78350f;
        --success: #34d399;
        --success-soft: #064e3b;
        --shadow: 0 10px 15px -3px rgba(0, 0, 0, .4), 0 4px 6px -4px rgba(0, 0, 0, .25);
        color-scheme: dark;
    }
}

:root[data-theme="dark"] {
    --bg: #0b1220;
    --surface: #131c2e;
    --surface-2: #1a2438;
    --border: #2a374e;
    --border-strong: #3b4a66;
    --text: #f1f5f9;
    --text-muted: #94a3b8;
    --accent: #60a5fa;
    --accent-hover: #93c5fd;
    --accent-soft: #1e3a8a;
    --accent-ring: rgba(96, 165, 250, .25);
    --danger: #f87171;
    --danger-soft: #7f1d1d;
    --warning: #fbbf24;
    --warning-soft: #78350f;
    --success: #34d399;
    --success-soft: #064e3b;
    --shadow: 0 10px 15px -3px rgba(0, 0, 0, .4), 0 4px 6px -4px rgba(0, 0, 0, .25);
    color-scheme: dark;
}

* { box-sizing: border-box; }

html, body { margin: 0; padding: 0; }

body {
    background: var(--bg);
    color: var(--text);
    font: 400 16px/1.5 var(--font);
    -webkit-font-smoothing: antialiased;
}

.page {
    width: 100%;
    max-width: 880px;
    margin: 32px auto;
    padding: 0 20px;
}

.appbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
}

.appbar h1 {
    margin: 0;
    color: var(--text);
    font-size: 22px;
    font-weight: 700;
    letter-spacing: -.01em;
}

.appbar h1 .dot { color: var(--accent); }

.theme-toggle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    padding: 0;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--text-muted);
    cursor: pointer;
    transition: background-color .15s, color .15s, border-color .15s;
}

.theme-toggle:hover,
.theme-toggle:focus-visible {
    background: var(--surface-2);
    color: var(--text);
    border-color: var(--border-strong);
    outline: none;
}

.theme-toggle svg { width: 18px; height: 18px; display: block; }
.theme-toggle .icon-moon { display: none; }
.theme-toggle .icon-sun { display: block; }
:root[data-theme="dark"] .theme-toggle .icon-moon { display: block; }
:root[data-theme="dark"] .theme-toggle .icon-sun { display: none; }
@media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) .theme-toggle .icon-moon { display: block; }
    :root:not([data-theme="light"]) .theme-toggle .icon-sun { display: none; }
}

.card {
    background-color: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    padding: 20px;
    margin-bottom: 16px;
}

.form-title-row { margin-bottom: 16px; }

.form-title-row h1,
.form-title-row h2 {
    margin: 0;
    padding-bottom: 8px;
    color: var(--text);
    font-size: 18px;
    font-weight: 600;
    letter-spacing: -.01em;
    border-bottom: 2px solid var(--accent);
}

.auth-header { border-bottom-color: var(--warning) !important; }
.main-auth-box { border-color: var(--danger); }

.form-row { margin-bottom: 14px; }
.form-row:last-child { margin-bottom: 0; }

.form-row label > span {
    display: block;
    margin-bottom: 6px;
    color: var(--text-muted);
    font-size: 13px;
    font-weight: 500;
}

.url-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.url-row .url-input {
    flex: 1 1 320px;
    min-width: 0;
    font-size: 16px;
    padding: 12px 16px;
}

form input,
form textarea,
form select {
    width: 100%;
    padding: 10px 14px;
    color: var(--text);
    background-color: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font: inherit;
    transition: border-color .15s, box-shadow .15s;
}

form input:focus,
form textarea:focus,
form select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-ring);
}

form input[type=checkbox],
form input[type=radio] {
    width: auto;
    margin-right: 8px;
    accent-color: var(--accent);
}

form input[type=number] { max-width: 120px; }
form textarea { min-height: 80px; resize: vertical; }

.button-submit,
.button-cancel,
.button-ghost {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 12px 22px;
    font: 600 14px var(--font);
    text-decoration: none;
    border: 0;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: background-color .15s, color .15s, border-color .15s;
    white-space: nowrap;
}

.button-submit {
    background-color: var(--accent);
    color: #fff;
}
.button-submit:hover, .button-submit:focus { background-color: var(--accent-hover); }

.button-cancel {
    background: transparent;
    color: var(--text-muted);
    border: 1px solid var(--border);
    padding: 10px 16px;
}
.button-cancel:hover, .button-cancel:focus {
    color: var(--text);
    border-color: var(--border-strong);
}

.button-ghost {
    background: transparent;
    color: var(--danger);
    border: 1px solid var(--border);
    padding: 10px 16px;
}
.button-ghost:hover, .button-ghost:focus {
    color: #fff;
    background-color: var(--danger);
    border-color: var(--danger);
}

.button-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    color: var(--text-muted);
    background: transparent;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    font: 600 16px/1 var(--font);
    transition: color .15s, background-color .15s, border-color .15s;
}
.button-icon:hover, .button-icon:focus {
    color: #fff;
    background-color: var(--danger);
    border-color: var(--danger);
    outline: none;
}

p.explanation,
p.error,
p.info {
    margin: 16px 0 0;
    padding: 12px 16px;
    border-radius: var(--radius-sm);
    font-size: 13px;
    line-height: 1.5;
}
p.explanation { background: var(--warning-soft); color: var(--text); border-left: 3px solid var(--warning); }
p.error { background: var(--danger-soft); color: var(--text); border-left: 3px solid var(--danger); }
p.info { background: var(--success-soft); color: var(--text); border-left: 3px solid var(--success); }

.tabs-wrap { margin-top: 16px; }

.tabs-wrap > input[type="radio"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 16px;
}

.tabs label {
    padding: 8px 14px;
    color: var(--text-muted);
    font-size: 14px;
    font-weight: 500;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    cursor: pointer;
    transition: color .15s, border-color .15s;
}

.tabs label:hover { color: var(--text); }

#tab-options:checked  ~ .tabs label[for="tab-options"],
#tab-cookies:checked  ~ .tabs label[for="tab-cookies"],
#tab-headers:checked  ~ .tabs label[for="tab-headers"],
#tab-ip:checked       ~ .tabs label[for="tab-ip"],
#tab-port:checked     ~ .tabs label[for="tab-port"],
#tab-dns:checked      ~ .tabs label[for="tab-dns"],
#tab-cert:checked     ~ .tabs label[for="tab-cert"],
#tab-cli:checked      ~ .tabs label[for="tab-cli"],
#tab-response:checked ~ .tabs label[for="tab-response"] {
    color: var(--accent);
    border-bottom-color: var(--accent);
}

.tab-panel { display: none; }

#tab-options:checked  ~ .tab-panel[data-tab="options"],
#tab-cookies:checked  ~ .tab-panel[data-tab="cookies"],
#tab-headers:checked  ~ .tab-panel[data-tab="headers"],
#tab-ip:checked       ~ .tab-panel[data-tab="ip"],
#tab-port:checked     ~ .tab-panel[data-tab="port"],
#tab-dns:checked      ~ .tab-panel[data-tab="dns"],
#tab-cert:checked     ~ .tab-panel[data-tab="cert"],
#tab-cli:checked      ~ .tab-panel[data-tab="cli"],
#tab-response:checked ~ .tab-panel[data-tab="response"] {
    display: block;
}

.option-group {
    margin: 0 0 14px;
    padding: 8px 14px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface-2);
}

.option-group:last-child { margin-bottom: 0; }

.option-group legend {
    padding: 0 6px;
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .06em;
    text-transform: uppercase;
}

.prx-opt-menu {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px 16px;
}

.option label {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 5px 0;
    color: var(--text);
    font-size: 14px;
    cursor: pointer;
}

.option label input { margin: 0; }
.option label span { color: inherit; }

.radio-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 18px;
    padding: 4px 0;
}

.radio-row-label {
    color: var(--text-muted);
    font-size: 14px;
    margin-right: 4px;
}

.radio-row label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--text);
    font-size: 14px;
    cursor: pointer;
}

.radio-row label input { margin: 0; }
.radio-row .hint { color: var(--text-muted); font-size: 11px; margin-left: 2px; }

.seed-config {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid var(--border);
}

.seed-config .tab-help { margin-bottom: 10px; }

.seed-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 10px;
}

.seed-mini {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 10px 12px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    margin: 0;
}

.seed-mini-label {
    color: var(--text-muted);
    font-size: 12px;
    font-weight: 500;
}

.seed-mini input,
.seed-mini select {
    padding: 6px 10px;
    font: 13px var(--font-mono);
    width: 100%;
    max-width: none;
}

.seed-mini button {
    padding: 8px 12px;
    font: 600 13px var(--font);
    align-self: flex-start;
}

.seed-mini .hint { color: var(--text-muted); font-size: 11px; font-weight: 400; }

.kv-list {
    list-style: none;
    margin: 0 0 12px;
    padding: 0;
    max-height: 380px;
    overflow-y: auto;
}

.kv-list .kv-row {
    display: flex;
    gap: 6px;
    align-items: stretch;
    margin-bottom: 6px;
}

.kv-list .kv-card {
    flex: 1 1 auto;
    min-width: 0;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    overflow: hidden;
}

.kv-list .kv-card > summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 8px 12px;
    cursor: pointer;
    list-style: none;
    font: 13px var(--font-mono);
    color: var(--text);
}

.kv-list .kv-card > summary::-webkit-details-marker { display: none; }
.kv-list .kv-card > summary::marker { content: ''; }
.kv-list .kv-card > summary:hover { background: rgba(0,0,0,.03); }
:root[data-theme="dark"] .kv-list .kv-card > summary:hover { background: rgba(255,255,255,.04); }

.kv-list .kv-display {
    flex: 1 1 auto;
    min-width: 0;
    word-break: break-all;
}

.kv-list .kv-name    { font-weight: 600; }
.kv-list .kv-host    { color: var(--accent); }
.kv-list .kv-val     { color: var(--text-muted); }
.kv-list .kv-sep     { color: var(--text-muted); margin: 0 6px; }
.kv-list .kv-chevron {
    color: var(--text-muted);
    font-size: 11px;
    flex-shrink: 0;
    transition: transform .15s;
}
.kv-list .kv-card[open] .kv-chevron { transform: rotate(180deg); }

.kv-list .kv-edit {
    padding: 10px 12px 12px;
    border-top: 1px solid var(--border);
    background: var(--surface);
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}

.kv-edit-cookie {
    grid-template-columns: 1fr 1fr 1fr;
}

.kv-edit-wide { grid-column: span 2; }

.kv-edit-checks {
    grid-column: 1 / -1;
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    padding: 4px 0;
}

.kv-card.kv-card-add {
    background: var(--surface);
    border-style: dashed;
    margin-bottom: 10px;
}

.kv-card.kv-card-add > summary { color: var(--text-muted); font: 13px var(--font); }
.kv-card.kv-card-add > summary .kv-name { font-weight: 500; }
.kv-card.kv-card-add[open] > summary { color: var(--text); }

.tab-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 12px;
}

.tab-toolbar .tab-help { flex: 1 1 auto; margin: 0; }

.toolbar-toggle {
    margin: 0;
    flex-shrink: 0;
}

.toolbar-toggle button {
    padding: 6px 12px;
    font-size: 12px;
}

@media (max-width: 600px) {
    .kv-edit-cookie { grid-template-columns: 1fr; }
    .kv-edit-wide { grid-column: span 1; }
}

.kv-list .kv-edit-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font: 13px var(--font);
}

.kv-list .kv-edit-field > span {
    color: var(--text-muted);
    font-size: 12px;
}

.kv-list .kv-edit-field input {
    padding: 6px 10px;
    font: 13px var(--font-mono);
}

.kv-list .kv-edit-check {
    grid-column: 1 / -1;
    display: flex;
    align-items: center;
    gap: 6px;
    font: 13px var(--font);
    color: var(--text);
}

.kv-list .kv-edit-actions {
    grid-column: 1 / -1;
    display: flex;
    gap: 8px;
    justify-content: flex-end;
}

.kv-list .kv-edit-actions .button-submit { padding: 8px 14px; font-size: 13px; }

.kv-list .kv-delete {
    display: flex;
    align-items: center;
}

.kv-list .empty {
    background: transparent;
    border: 1px dashed var(--border);
    border-radius: var(--radius-sm);
    color: var(--text-muted);
    font: 13px var(--font);
    text-align: center;
    padding: 12px;
    margin-bottom: 6px;
}

.tabs label .tab-count {
    display: inline-block;
    margin-left: 4px;
    padding: 1px 7px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 999px;
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 500;
    vertical-align: middle;
}

#tab-options:checked  ~ .tabs label[for="tab-options"]  .tab-count,
#tab-cookies:checked  ~ .tabs label[for="tab-cookies"]  .tab-count,
#tab-headers:checked  ~ .tabs label[for="tab-headers"]  .tab-count,
#tab-response:checked ~ .tabs label[for="tab-response"] .tab-count {
    color: var(--accent);
    border-color: var(--accent);
}

.kv-card-readonly {
    padding: 8px 12px;
    font: 13px var(--font-mono);
    color: var(--text);
    word-break: break-all;
}

.kv-row-readonly .kv-card { background: var(--surface-2); }
.kv-row-readonly .kv-card-readonly .kv-name { font-weight: 600; color: var(--accent); }
.kv-row-readonly .kv-card-readonly .kv-sep  { color: var(--text-muted); margin: 0 6px; }
.kv-row-readonly .kv-card-readonly .kv-val  { color: var(--text); }

.kv-add {
    display: grid;
    grid-template-columns: 1fr 1.4fr auto;
    gap: 8px;
    align-items: stretch;
    margin-bottom: 14px;
}

.kv-add input { padding: 9px 12px; font: inherit; }

.kv-add button {
    padding: 9px 18px;
    background: var(--accent);
    color: #fff;
    border: 0;
    border-radius: var(--radius-sm);
    font: 600 14px var(--font);
    cursor: pointer;
    transition: background-color .15s;
}
.kv-add button:hover, .kv-add button:focus { background: var(--accent-hover); }

.tab-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.tab-help {
    margin: 0 0 12px;
    color: var(--text-muted);
    font-size: 13px;
    line-height: 1.5;
}

.tab-help code {
    padding: 1px 5px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 4px;
    font: 12px var(--font-mono);
}

.divider {
    margin: 18px 0;
    border: 0;
    border-top: 1px solid var(--border);
}

.section-label {
    margin: 0 0 8px;
    color: var(--text);
    font-size: 13px;
    font-weight: 600;
}

.ua-picker {
    display: grid;
    grid-template-columns: 1fr;
    gap: 8px;
}

.ua-picker select { padding: 9px 12px; }

.netcheck {
    display: flex;
    flex-direction: column;
}

.netcheck-solo .netcheck-fields { grid-template-columns: 1fr 140px; }

.netcheck-fields {
    display: grid;
    grid-template-columns: 1fr 140px;
    gap: 8px;
    margin-bottom: 10px;
}

.netcheck[data-netcheck="dns"] .netcheck-fields {
    grid-template-columns: 1fr 140px;
}

.netcheck-fields input,
.netcheck-fields select {
    padding: 10px 12px;
    font-size: 14px;
}

.netcheck .button-submit {
    padding: 10px 14px;
    font-size: 14px;
    align-self: flex-start;
}

.netcheck-result {
    margin: 10px 0 0 0;
    padding: 10px 12px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--text);
    font-family: var(--font-mono);
    font-size: 12px;
    line-height: 1.45;
    max-height: 240px;
    overflow: auto;
    white-space: pre-wrap;
    word-break: break-word;
}

.netcheck-result.ok    { border-color: var(--success); background: var(--success-soft); }
.netcheck-result.err   { border-color: var(--danger);  background: var(--danger-soft);  }
.netcheck-result.warn  { border-color: var(--warning); background: var(--warning-soft); }
.netcheck-result.busy  { color: var(--text-muted); font-style: italic; }

/* Cert-result variant uses a <div> instead of <pre> so it can hold a rich
   structured layout with collapsible cert cards. */
.netcheck-result.cert-result {
    font-family: var(--font);
    font-size: 13px;
    white-space: normal;
    padding: 0;
    background: transparent;
    border: none;
    max-height: 600px;
}
.cert-top {
    padding: 12px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface-2);
    margin-bottom: 12px;
}
.cert-top-line {
    font-family: var(--font-mono);
    font-size: 14px;
    margin-bottom: 4px;
}
.cert-top-line .cert-time { color: var(--text-muted); font-size: 12px; }
.cert-top-neg {
    font-family: var(--font-mono);
    font-size: 13px;
    margin-bottom: 8px;
    color: var(--text);
}
.tls-probes {
    width: 100%;
    border-collapse: collapse;
    font-family: var(--font-mono);
    font-size: 12.5px;
}
.tls-probes td {
    padding: 3px 8px;
    border-top: 1px solid var(--border);
}
.tls-probes tr:first-child td { border-top: none; }
.tls-probes .ver { font-weight: 600; width: 70px; }
.tls-probes .ok td { color: var(--text); }
.tls-probes .err td { color: var(--text-muted); }
.tls-probes .ok td:nth-child(2) { color: var(--success); font-weight: 700; }
.tls-probes .err td:nth-child(2) { color: var(--danger);  font-weight: 700; }

.cert-chain-title {
    margin: 16px 0 8px;
    font: 600 13px var(--font);
    color: var(--text-muted);
    letter-spacing: .04em;
    text-transform: uppercase;
}
.cert-card {
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    margin-bottom: 8px;
    background: var(--surface);
    overflow: hidden;
}
.cert-card > summary {
    padding: 10px 14px;
    list-style: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--text);
}
.cert-card > summary::-webkit-details-marker { display: none; }
.cert-card > summary::before {
    content: '▸';
    color: var(--text-muted);
    transition: transform .15s;
    display: inline-block;
}
.cert-card[open] > summary::before { transform: rotate(90deg); }
.cert-card[open] > summary { border-bottom: 1px solid var(--border); background: var(--surface-2); }
.cert-idx {
    display: inline-block;
    min-width: 22px;
    padding: 1px 6px;
    background: var(--accent-soft);
    color: var(--accent);
    border-radius: 4px;
    font: 600 11px var(--font-mono);
    text-align: center;
}
.cert-role {
    display: inline-block;
    padding: 1px 6px;
    background: var(--surface-2);
    color: var(--text-muted);
    border: 1px solid var(--border);
    border-radius: 999px;
    font: 600 10px var(--font);
    letter-spacing: .04em;
    text-transform: uppercase;
}
.cert-arrow { color: var(--text-muted); margin: 0 4px; }
.cert-status { font: 600 11px var(--font); margin-left: auto; padding: 1px 8px; border-radius: 999px; }
.cert-status.ok   { background: var(--success-soft); color: var(--success); }
.cert-status.warn { background: var(--warning-soft); color: var(--warning); }
.cert-status.err  { background: var(--danger-soft);  color: var(--danger);  }
.cert-body { padding: 12px 14px; }
.cert-body h5 {
    margin: 12px 0 6px;
    font: 600 11px var(--font);
    color: var(--text-muted);
    letter-spacing: .06em;
    text-transform: uppercase;
}
.cert-body h5:first-child { margin-top: 0; }
.cert-kv {
    display: grid;
    grid-template-columns: 160px 1fr;
    gap: 3px 12px;
    font-size: 12.5px;
}
.cert-kv-k { color: var(--text-muted); font-family: var(--font-mono); }
.cert-kv-v { color: var(--text); font-family: var(--font-mono); word-break: break-all; }
.cert-fp { font-size: 11.5px; letter-spacing: 0; }
.cert-ext {
    display: grid;
    grid-template-columns: 200px 1fr;
    gap: 4px 12px;
    font-size: 12.5px;
}
.cert-ext-k {
    color: var(--text-muted);
    font-family: var(--font-mono);
    font-size: 12px;
    padding-top: 4px;
}
.cert-ext-v pre {
    margin: 0;
    padding: 6px 10px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 4px;
    font: 11.5px/1.5 var(--font-mono);
    color: var(--text);
    white-space: pre-wrap;
    word-break: break-all;
    overflow-x: auto;
}
.cert-raw {
    margin-top: 10px;
    border: 1px solid var(--border);
    border-radius: 4px;
    background: var(--surface-2);
}
.cert-raw > summary {
    padding: 8px 12px;
    cursor: pointer;
    font: 600 11px var(--font);
    color: var(--text-muted);
    letter-spacing: .04em;
    text-transform: uppercase;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.cert-raw > summary::-webkit-details-marker { display: none; }
.cert-raw .button-cancel { padding: 4px 10px; font-size: 11px; margin-left: 8px; }
.cert-pem {
    margin: 0;
    padding: 10px 12px;
    border-top: 1px solid var(--border);
    background: var(--surface);
    font: 11.5px/1.5 var(--font-mono);
    color: var(--text);
    white-space: pre;
    overflow-x: auto;
    max-height: 320px;
}
@media (max-width: 600px) {
    .cert-kv  { grid-template-columns: 1fr; }
    .cert-kv-k { font-weight: 600; }
    .cert-ext { grid-template-columns: 1fr; }
}

/* IP tab — identity cards (client / server / outgoing) + request/host info */
.ipinfo-wrap {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}
.ipinfo-loading {
    grid-column: 1 / -1;
    padding: 24px;
    text-align: center;
    color: var(--text-muted);
    font-style: italic;
    border: 1px dashed var(--border);
    border-radius: var(--radius-sm);
}
.ipinfo-card {
    padding: 12px 14px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
.ipinfo-card-title {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--text-muted);
    margin-bottom: 2px;
}
.ipinfo-ip {
    font-family: var(--font-mono);
    font-size: 16px;
    font-weight: 600;
    color: var(--text);
    word-break: break-all;
}
.ipinfo-ip .ipinfo-family {
    display: inline-block;
    margin-left: 6px;
    padding: 1px 6px;
    border-radius: 999px;
    background: var(--accent-soft);
    color: var(--accent);
    font: 600 10px var(--font);
    letter-spacing: .04em;
    vertical-align: middle;
}
.ipinfo-row {
    display: flex;
    gap: 8px;
    font-size: 13px;
    min-width: 0;
}
.ipinfo-row .k {
    flex: 0 0 78px;
    color: var(--text-muted);
}
.ipinfo-row .v {
    flex: 1 1 auto;
    color: var(--text);
    min-width: 0;
    word-break: break-all;
    font-family: var(--font-mono);
    font-size: 12.5px;
}
.ipinfo-asn .ipinfo-asn-num {
    font-weight: 600;
}
.ipinfo-private {
    display: inline-block;
    padding: 1px 6px;
    border-radius: 999px;
    background: var(--warning-soft);
    color: var(--warning);
    font: 600 10px var(--font);
    letter-spacing: .04em;
}
.ipinfo-flag {
    display: inline-block;
    padding: 1px 6px;
    border-radius: 4px;
    background: var(--surface);
    border: 1px solid var(--border);
    font: 600 11px var(--font-mono);
    color: var(--text);
}
.ipinfo-section {
    margin-top: 14px;
    padding: 12px 14px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
}
.ipinfo-section h4 {
    margin: 0 0 8px 0;
    font: 600 11px var(--font);
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--text-muted);
}
.ipinfo-grid {
    display: grid;
    grid-template-columns: 160px 1fr;
    gap: 4px 12px;
    font-size: 13px;
}
.ipinfo-grid .k { color: var(--text-muted); }
.ipinfo-grid .v {
    color: var(--text);
    word-break: break-all;
    font-family: var(--font-mono);
    font-size: 12.5px;
}
.ipinfo-grid .v.empty { color: var(--text-muted); font-style: italic; font-family: var(--font); font-size: 13px; }

/* Wide grid layout for the three identity cards spans the full ip tab. */
@media (max-width: 720px) {
    .ipinfo-wrap { grid-template-columns: 1fr; }
    .ipinfo-grid { grid-template-columns: 120px 1fr; }
}

/* CLI tab — base styles (entry page). The panel-scoped equivalents live
   under #phproxy-panel so proxied-page injection doesn't leak rules to
   the host document. */
.cli-wrap > input[type="radio"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.cli-tabs {
    display: flex;
    gap: 4px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 12px;
    flex-wrap: wrap;
}
.cli-tabs label {
    padding: 6px 12px;
    color: var(--text-muted);
    font-size: 13px;
    font-weight: 500;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    cursor: pointer;
    transition: color .15s, border-color .15s;
}
.cli-tabs label:hover { color: var(--text); }
#cli-tab-curl:checked   ~ .cli-tabs label[for="cli-tab-curl"],
#cli-tab-php:checked    ~ .cli-tabs label[for="cli-tab-php"],
#cli-tab-python:checked ~ .cli-tabs label[for="cli-tab-python"],
#cli-tab-js:checked     ~ .cli-tabs label[for="cli-tab-js"],
#cli-tab-go:checked     ~ .cli-tabs label[for="cli-tab-go"] {
    color: var(--accent);
    border-bottom-color: var(--accent);
}
.cli-panel { display: none; }
#cli-tab-curl:checked   ~ .cli-panel[data-cli="curl"],
#cli-tab-php:checked    ~ .cli-panel[data-cli="php"],
#cli-tab-python:checked ~ .cli-panel[data-cli="python"],
#cli-tab-js:checked     ~ .cli-panel[data-cli="js"],
#cli-tab-go:checked     ~ .cli-panel[data-cli="go"] {
    display: block;
}
.cli-variant            { margin-bottom: 16px; }
.cli-variant:last-child { margin-bottom: 0; }
.cli-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 6px;
}
.cli-variant-label {
    color: var(--text-muted);
    font: 600 11px var(--font);
    letter-spacing: .06em;
    text-transform: uppercase;
}
.cli-variant-label code {
    background: var(--surface-2);
    padding: 1px 5px;
    border-radius: 4px;
    font: 11px var(--font-mono);
    text-transform: none;
    letter-spacing: 0;
    color: var(--text);
}
.cli-toolbar .button-cancel { padding: 6px 12px; font-size: 12px; }
.cli-snippet {
    margin: 0;
    padding: 14px 16px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font: 12.5px/1.55 var(--font-mono);
    color: var(--text);
    overflow-x: auto;
    white-space: pre;
    -webkit-text-size-adjust: 100%;
}

.footer {
    margin: 24px 0;
    text-align: center;
    font-size: 12px;
    color: var(--text-muted);
}

.footer a {
    color: var(--text-muted);
    text-decoration: none;
}

.footer a:hover { color: var(--accent); }

@media (max-width: 880px) {
    .netcheck-row { grid-template-columns: 1fr; }
}

@media (max-width: 600px) {
    .page { margin: 16px auto; padding: 0 12px; }
    .card { padding: 16px; }
    .prx-opt-menu { grid-template-columns: 1fr; }
    .kv-add { grid-template-columns: 1fr; }
}
PHPROXY_INDEX_CSS;
}

function phproxy_panel_css(): string {
    return <<<'PHPROXY_PANEL_CSS'
/* PHProxy inline panel stylesheet — scoped to #phproxy-panel.
 *
 * Every selector below begins with #phproxy-panel so loading this file on a
 * proxied page can't touch the host page's elements. The trade-off vs.
 * loading the entry-form's index.css here is that we have to duplicate the
 * rules, but it keeps the panel's styling fully isolated.
 *
 * CSS variables live on #phproxy-panel itself (not :root) for the same
 * reason. Theme follows [data-theme] on <html> and prefers-color-scheme,
 * matching the entry form's behavior.
 */

/* Full-viewport overlay below the sticky bar. The panel covers the entire
 * window from the bar down; the host page's content is hidden behind it
 * until the user closes the panel. Show/hide is driven by the sibling
 * #phproxy-panel-toggle checkbox via ~. */
#phproxy-panel {
    /* Reset every inheritable property so the host page can't bleed in.
     * `all: initial` forces this element to its initial styles regardless
     * of what the proxied page's CSS has set on its ancestors. We then
     * re-establish exactly the styling we want below. */
    all: initial;
    display: none;
    position: fixed;
    inset: 54px 0 0 0;
    overflow-y: auto;
    z-index: 2147483646;
    box-sizing: border-box;
    padding: 24px 0 40px;
    border-top: 1px solid var(--border);
    border-radius: 0;

    --bg: #f4f5f7;
    --surface: #ffffff;
    --surface-2: #f8fafc;
    --border: #e2e6ec;
    --border-strong: #cbd5e1;
    --text: #0f172a;
    --text-muted: #64748b;
    --accent: #2563eb;
    --accent-hover: #1d4ed8;
    --accent-ring: rgba(37, 99, 235, .18);
    --danger: #ef4444;
    --danger-soft: #fee2e2;
    --warning: #f59e0b;
    --warning-soft: #fef3c7;
    --success: #10b981;
    --success-soft: #d1fae5;
    --radius: 10px;
    --radius-sm: 6px;
    --shadow: 0 18px 32px -8px rgba(15, 23, 42, .18);
    --font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    --font-mono: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Monaco, Consolas, monospace;
    color-scheme: light;
    color: var(--text);
    background: var(--bg);
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    font: 400 14px/1.5 var(--font);
    -webkit-font-smoothing: antialiased;
}

#phproxy-panel-toggle:checked ~ #phproxy-panel { display: block; }

/* Dark variant. The mini-bar script sets data-theme=dark on #phproxy-panel
 * (and on #phproxy-bar) when the user picked dark in the entry form or when
 * the OS prefers dark and the user hasn't explicitly picked light. We
 * deliberately do NOT set data-theme on <html> on a proxied page — that
 * would touch the host site's own [data-theme] selectors. */
#phproxy-panel[data-theme="dark"] {
    --bg: #131c2e;
    --surface: #1a2438;
    --surface-2: #0f172a;
    --border: #2a374e;
    --border-strong: #3b4a66;
    --text: #f1f5f9;
    --text-muted: #94a3b8;
    --accent: #60a5fa;
    --accent-hover: #93c5fd;
    --accent-ring: rgba(96, 165, 250, .25);
    --danger: #f87171;
    --danger-soft: #7f1d1d;
    --warning: #fbbf24;
    --warning-soft: #78350f;
    --success: #34d399;
    --success-soft: #064e3b;
    --shadow: 0 18px 32px -8px rgba(0, 0, 0, .55);
    color-scheme: dark;
}

/* Inner wrapper centers content on wide screens so the forms don't
 * stretch edge-to-edge. */
#phproxy-panel > .panel-inner {
    max-width: 960px;
    margin: 0 auto;
    padding: 0 24px;
}

/* Panel chrome */
#phproxy-panel .panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border);
}
#phproxy-panel .panel-head h2 {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
    color: var(--text);
    letter-spacing: -.01em;
}
#phproxy-panel .panel-head .close {
    padding: 4px 10px;
    font-size: 13px;
    cursor: pointer;
    background: transparent;
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--text-muted);
    text-decoration: none;
}
#phproxy-panel .panel-head .close:hover { color: var(--text); }
#phproxy-panel .panel-refresh {
    margin-top: 16px;
    padding-top: 14px;
    border-top: 1px solid var(--border);
    display: flex;
    gap: 8px;
    justify-content: space-between;
    flex-wrap: wrap;
}
#phproxy-panel .panel-refresh .button-submit { padding: 10px 18px; font-weight: 600; }
#phproxy-panel .panel-refresh small {
    color: var(--text-muted);
    font-size: 12px;
    align-self: center;
}

#phproxy-panel,
#phproxy-panel *,
#phproxy-panel *::before,
#phproxy-panel *::after { box-sizing: border-box; }

#phproxy-panel a              { color: var(--accent); text-decoration: none; }
#phproxy-panel a:hover         { text-decoration: underline; }
#phproxy-panel h1,
#phproxy-panel h2,
#phproxy-panel h3,
#phproxy-panel p,
#phproxy-panel ul,
#phproxy-panel ol,
#phproxy-panel fieldset,
#phproxy-panel legend,
#phproxy-panel hr             { all: revert; margin: 0; padding: 0; color: inherit; font: inherit; }
#phproxy-panel ul,
#phproxy-panel ol             { list-style: none; }
#phproxy-panel fieldset       { border: 0; }

#phproxy-panel .card {
    background-color: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    padding: 20px;
    margin-bottom: 16px;
}

#phproxy-panel .form-title-row { margin-bottom: 16px; }
#phproxy-panel .form-title-row h1,
#phproxy-panel .form-title-row h2 {
    margin: 0;
    padding-bottom: 8px;
    color: var(--text);
    font-size: 18px;
    font-weight: 600;
    letter-spacing: -.01em;
    border-bottom: 2px solid var(--accent);
}
#phproxy-panel .auth-header     { border-bottom-color: var(--warning) !important; }
#phproxy-panel .main-auth-box   { border-color: var(--danger); }

#phproxy-panel .form-row              { margin-bottom: 14px; }
#phproxy-panel .form-row:last-child   { margin-bottom: 0; }
#phproxy-panel .form-row label > span {
    display: block;
    margin-bottom: 6px;
    color: var(--text-muted);
    font-size: 13px;
    font-weight: 500;
}

#phproxy-panel form input,
#phproxy-panel form textarea,
#phproxy-panel form select {
    width: 100%;
    padding: 10px 14px;
    color: var(--text);
    background-color: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font: 14px var(--font);
    transition: border-color .15s, box-shadow .15s;
}
#phproxy-panel form input:focus,
#phproxy-panel form textarea:focus,
#phproxy-panel form select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-ring);
}
#phproxy-panel form input[type=checkbox],
#phproxy-panel form input[type=radio] {
    width: auto;
    margin-right: 8px;
    accent-color: var(--accent);
}
#phproxy-panel form input[type=number] { max-width: 120px; }
#phproxy-panel form textarea           { min-height: 80px; resize: vertical; }

#phproxy-panel .button-submit,
#phproxy-panel .button-cancel,
#phproxy-panel .button-ghost {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 10px 18px;
    font: 600 14px var(--font);
    text-decoration: none;
    border: 0;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: background-color .15s, color .15s, border-color .15s;
    white-space: nowrap;
}
#phproxy-panel .button-submit                  { background-color: var(--accent); color: #fff; }
#phproxy-panel .button-submit:hover,
#phproxy-panel .button-submit:focus            { background-color: var(--accent-hover); }
#phproxy-panel .button-cancel                  { background: transparent; color: var(--text-muted); border: 1px solid var(--border); padding: 8px 14px; }
#phproxy-panel .button-cancel:hover,
#phproxy-panel .button-cancel:focus            { color: var(--text); border-color: var(--border-strong); }
#phproxy-panel .button-ghost                   { background: transparent; color: var(--danger); border: 1px solid var(--border); padding: 8px 14px; }
#phproxy-panel .button-ghost:hover,
#phproxy-panel .button-ghost:focus             { color: #fff; background-color: var(--danger); border-color: var(--danger); }

#phproxy-panel .button-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    color: var(--text-muted);
    background: transparent;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    font: 600 16px/1 var(--font);
    transition: color .15s, background-color .15s, border-color .15s;
}
#phproxy-panel .button-icon:hover,
#phproxy-panel .button-icon:focus {
    color: #fff;
    background-color: var(--danger);
    border-color: var(--danger);
    outline: none;
}

#phproxy-panel p.explanation,
#phproxy-panel p.error,
#phproxy-panel p.info {
    margin: 16px 0 0;
    padding: 12px 16px;
    border-radius: var(--radius-sm);
    font-size: 13px;
    line-height: 1.5;
}
#phproxy-panel p.explanation { background: var(--warning-soft); color: var(--text); border-left: 3px solid var(--warning); }
#phproxy-panel p.error       { background: var(--danger-soft);  color: var(--text); border-left: 3px solid var(--danger); }
#phproxy-panel p.info        { background: var(--success-soft); color: var(--text); border-left: 3px solid var(--success); }

#phproxy-panel .tabs-wrap                            { margin-top: 0; }
#phproxy-panel .tabs-wrap > input[type="radio"]      { position: absolute; opacity: 0; pointer-events: none; }
#phproxy-panel .tabs {
    display: flex;
    gap: 4px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 16px;
    flex-wrap: wrap;
}
#phproxy-panel .tabs label {
    padding: 8px 14px;
    color: var(--text-muted);
    font-size: 14px;
    font-weight: 500;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    cursor: pointer;
    transition: color .15s, border-color .15s;
}
#phproxy-panel .tabs label:hover  { color: var(--text); }

#phproxy-panel #tab-options:checked  ~ .tabs label[for="tab-options"],
#phproxy-panel #tab-cookies:checked  ~ .tabs label[for="tab-cookies"],
#phproxy-panel #tab-headers:checked  ~ .tabs label[for="tab-headers"],
#phproxy-panel #tab-ip:checked       ~ .tabs label[for="tab-ip"],
#phproxy-panel #tab-port:checked     ~ .tabs label[for="tab-port"],
#phproxy-panel #tab-dns:checked      ~ .tabs label[for="tab-dns"],
#phproxy-panel #tab-cert:checked     ~ .tabs label[for="tab-cert"],
#phproxy-panel #tab-response:checked ~ .tabs label[for="tab-response"],
#phproxy-panel #tab-cli:checked      ~ .tabs label[for="tab-cli"],
#phproxy-panel #tab-network:checked  ~ .tabs label[for="tab-network"] {
    color: var(--accent);
    border-bottom-color: var(--accent);
}

#phproxy-panel .tab-panel { display: none; }
#phproxy-panel #tab-options:checked  ~ .tab-panel[data-tab="options"],
#phproxy-panel #tab-cookies:checked  ~ .tab-panel[data-tab="cookies"],
#phproxy-panel #tab-headers:checked  ~ .tab-panel[data-tab="headers"],
#phproxy-panel #tab-ip:checked       ~ .tab-panel[data-tab="ip"],
#phproxy-panel #tab-port:checked     ~ .tab-panel[data-tab="port"],
#phproxy-panel #tab-dns:checked      ~ .tab-panel[data-tab="dns"],
#phproxy-panel #tab-cert:checked     ~ .tab-panel[data-tab="cert"],
#phproxy-panel #tab-response:checked ~ .tab-panel[data-tab="response"],
#phproxy-panel #tab-cli:checked      ~ .tab-panel[data-tab="cli"],
#phproxy-panel #tab-network:checked  ~ .tab-panel[data-tab="network"] {
    display: block;
}

/* Network tab — dev-tools-style request log */
#phproxy-panel .net-table {
    width: 100%;
    border-collapse: collapse;
    font: 12.5px var(--font-mono);
    color: var(--text);
}
#phproxy-panel .net-table th,
#phproxy-panel .net-table td {
    padding: 6px 10px;
    border-bottom: 1px solid var(--border);
    text-align: left;
    vertical-align: top;
    white-space: nowrap;
}
#phproxy-panel .net-table thead th {
    position: sticky;
    top: 0;
    background: var(--surface-2);
    color: var(--text-muted);
    font: 600 11px var(--font);
    letter-spacing: .04em;
    text-transform: uppercase;
    border-bottom: 1px solid var(--border-strong);
}
#phproxy-panel .net-table td.num,
#phproxy-panel .net-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
#phproxy-panel .net-table td.url {
    max-width: 0;
    width: 50%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--text);
}
#phproxy-panel .net-table td.method { color: var(--accent); font-weight: 600; }
#phproxy-panel .net-table td.type   { color: var(--text-muted); }
#phproxy-panel .net-table td.status { font-weight: 600; }
#phproxy-panel .net-table td.status.st-2xx { color: var(--success); }
#phproxy-panel .net-table td.status.st-3xx { color: var(--warning); }
#phproxy-panel .net-table td.status.st-4xx { color: var(--danger); }
#phproxy-panel .net-table td.status.st-5xx { color: var(--danger); }
#phproxy-panel .net-table tr.current { background: var(--accent-soft); }
#phproxy-panel .net-table tr.current td { font-weight: 600; }
#phproxy-panel .net-table tr:hover td { background: rgba(0,0,0,.02); }
#phproxy-panel[data-theme="dark"] .net-table tr:hover td { background: rgba(255,255,255,.03); }

/* IP tab — identity cards + sections */
#phproxy-panel .ipinfo-wrap {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}
#phproxy-panel .ipinfo-loading {
    grid-column: 1 / -1;
    padding: 24px;
    text-align: center;
    color: var(--text-muted);
    font-style: italic;
    border: 1px dashed var(--border);
    border-radius: var(--radius-sm);
}
#phproxy-panel .ipinfo-card {
    padding: 12px 14px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
#phproxy-panel .ipinfo-card-title {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--text-muted);
    margin-bottom: 2px;
}
#phproxy-panel .ipinfo-ip {
    font-family: var(--font-mono);
    font-size: 16px;
    font-weight: 600;
    color: var(--text);
    word-break: break-all;
}
#phproxy-panel .ipinfo-ip .ipinfo-family {
    display: inline-block;
    margin-left: 6px;
    padding: 1px 6px;
    border-radius: 999px;
    background: var(--accent-soft);
    color: var(--accent);
    font: 600 10px var(--font);
    letter-spacing: .04em;
    vertical-align: middle;
}
#phproxy-panel .ipinfo-row {
    display: flex;
    gap: 8px;
    font-size: 13px;
    min-width: 0;
}
#phproxy-panel .ipinfo-row .k { flex: 0 0 78px; color: var(--text-muted); }
#phproxy-panel .ipinfo-row .v {
    flex: 1 1 auto;
    color: var(--text);
    min-width: 0;
    word-break: break-all;
    font-family: var(--font-mono);
    font-size: 12.5px;
}
#phproxy-panel .ipinfo-asn .ipinfo-asn-num { font-weight: 600; }
#phproxy-panel .ipinfo-private {
    display: inline-block;
    padding: 1px 6px;
    border-radius: 999px;
    background: var(--warning-soft);
    color: var(--warning);
    font: 600 10px var(--font);
    letter-spacing: .04em;
}
#phproxy-panel .ipinfo-flag {
    display: inline-block;
    padding: 1px 6px;
    border-radius: 4px;
    background: var(--surface);
    border: 1px solid var(--border);
    font: 600 11px var(--font-mono);
    color: var(--text);
}
#phproxy-panel .ipinfo-section {
    margin-top: 14px;
    padding: 12px 14px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
}
#phproxy-panel .ipinfo-section h4 {
    margin: 0 0 8px 0;
    font: 600 11px var(--font);
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--text-muted);
}
#phproxy-panel .ipinfo-grid {
    display: grid;
    grid-template-columns: 160px 1fr;
    gap: 4px 12px;
    font-size: 13px;
}
#phproxy-panel .ipinfo-grid .k { color: var(--text-muted); }
#phproxy-panel .ipinfo-grid .v {
    color: var(--text);
    word-break: break-all;
    font-family: var(--font-mono);
    font-size: 12.5px;
}
#phproxy-panel .ipinfo-grid .v.empty { color: var(--text-muted); font-style: italic; font-family: var(--font); font-size: 13px; }
@media (max-width: 720px) {
    #phproxy-panel .ipinfo-wrap { grid-template-columns: 1fr; }
    #phproxy-panel .ipinfo-grid { grid-template-columns: 120px 1fr; }
}

/* Cert-result variant — collapsible cert cards (panel-scoped mirror) */
#phproxy-panel .netcheck-result.cert-result {
    font-family: var(--font);
    font-size: 13px;
    white-space: normal;
    padding: 0;
    background: transparent;
    border: none;
    max-height: 600px;
}
#phproxy-panel .cert-top {
    padding: 12px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface-2);
    margin-bottom: 12px;
}
#phproxy-panel .cert-top-line { font-family: var(--font-mono); font-size: 14px; margin-bottom: 4px; }
#phproxy-panel .cert-top-line .cert-time { color: var(--text-muted); font-size: 12px; }
#phproxy-panel .cert-top-neg { font-family: var(--font-mono); font-size: 13px; margin-bottom: 8px; color: var(--text); }
#phproxy-panel .tls-probes { width: 100%; border-collapse: collapse; font-family: var(--font-mono); font-size: 12.5px; }
#phproxy-panel .tls-probes td { padding: 3px 8px; border-top: 1px solid var(--border); }
#phproxy-panel .tls-probes tr:first-child td { border-top: none; }
#phproxy-panel .tls-probes .ver { font-weight: 600; width: 70px; }
#phproxy-panel .tls-probes .ok td  { color: var(--text); }
#phproxy-panel .tls-probes .err td { color: var(--text-muted); }
#phproxy-panel .tls-probes .ok td:nth-child(2)  { color: var(--success); font-weight: 700; }
#phproxy-panel .tls-probes .err td:nth-child(2) { color: var(--danger);  font-weight: 700; }
#phproxy-panel .cert-chain-title { margin: 16px 0 8px; font: 600 13px var(--font); color: var(--text-muted); letter-spacing: .04em; text-transform: uppercase; }
#phproxy-panel .cert-card { border: 1px solid var(--border); border-radius: var(--radius-sm); margin-bottom: 8px; background: var(--surface); overflow: hidden; }
#phproxy-panel .cert-card > summary { padding: 10px 14px; list-style: none; cursor: pointer; display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text); }
#phproxy-panel .cert-card > summary::-webkit-details-marker { display: none; }
#phproxy-panel .cert-card > summary::before { content: '▸'; color: var(--text-muted); transition: transform .15s; display: inline-block; }
#phproxy-panel .cert-card[open] > summary::before { transform: rotate(90deg); }
#phproxy-panel .cert-card[open] > summary { border-bottom: 1px solid var(--border); background: var(--surface-2); }
#phproxy-panel .cert-idx { display: inline-block; min-width: 22px; padding: 1px 6px; background: var(--accent-soft); color: var(--accent); border-radius: 4px; font: 600 11px var(--font-mono); text-align: center; }
#phproxy-panel .cert-role { display: inline-block; padding: 1px 6px; background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border); border-radius: 999px; font: 600 10px var(--font); letter-spacing: .04em; text-transform: uppercase; }
#phproxy-panel .cert-arrow { color: var(--text-muted); margin: 0 4px; }
#phproxy-panel .cert-status { font: 600 11px var(--font); margin-left: auto; padding: 1px 8px; border-radius: 999px; }
#phproxy-panel .cert-status.ok   { background: var(--success-soft); color: var(--success); }
#phproxy-panel .cert-status.warn { background: var(--warning-soft); color: var(--warning); }
#phproxy-panel .cert-status.err  { background: var(--danger-soft);  color: var(--danger);  }
#phproxy-panel .cert-body { padding: 12px 14px; }
#phproxy-panel .cert-body h5 { margin: 12px 0 6px; font: 600 11px var(--font); color: var(--text-muted); letter-spacing: .06em; text-transform: uppercase; }
#phproxy-panel .cert-body h5:first-child { margin-top: 0; }
#phproxy-panel .cert-kv { display: grid; grid-template-columns: 160px 1fr; gap: 3px 12px; font-size: 12.5px; }
#phproxy-panel .cert-kv-k { color: var(--text-muted); font-family: var(--font-mono); }
#phproxy-panel .cert-kv-v { color: var(--text); font-family: var(--font-mono); word-break: break-all; }
#phproxy-panel .cert-fp { font-size: 11.5px; letter-spacing: 0; }
#phproxy-panel .cert-ext { display: grid; grid-template-columns: 200px 1fr; gap: 4px 12px; font-size: 12.5px; }
#phproxy-panel .cert-ext-k { color: var(--text-muted); font-family: var(--font-mono); font-size: 12px; padding-top: 4px; }
#phproxy-panel .cert-ext-v pre { margin: 0; padding: 6px 10px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 4px; font: 11.5px/1.5 var(--font-mono); color: var(--text); white-space: pre-wrap; word-break: break-all; overflow-x: auto; }
#phproxy-panel .cert-raw { margin-top: 10px; border: 1px solid var(--border); border-radius: 4px; background: var(--surface-2); }
#phproxy-panel .cert-raw > summary { padding: 8px 12px; cursor: pointer; font: 600 11px var(--font); color: var(--text-muted); letter-spacing: .04em; text-transform: uppercase; display: flex; align-items: center; justify-content: space-between; }
#phproxy-panel .cert-raw > summary::-webkit-details-marker { display: none; }
#phproxy-panel .cert-raw .button-cancel { padding: 4px 10px; font-size: 11px; margin-left: 8px; }
#phproxy-panel .cert-pem { margin: 0; padding: 10px 12px; border-top: 1px solid var(--border); background: var(--surface); font: 11.5px/1.5 var(--font-mono); color: var(--text); white-space: pre; overflow-x: auto; max-height: 320px; }
@media (max-width: 600px) {
    #phproxy-panel .cert-kv  { grid-template-columns: 1fr; }
    #phproxy-panel .cert-kv-k { font-weight: 600; }
    #phproxy-panel .cert-ext { grid-template-columns: 1fr; }
}

/* CLI tab — sub-tabs for curl / PHP / Python / JS / Go */
#phproxy-panel .cli-wrap > input[type="radio"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
#phproxy-panel .cli-tabs {
    display: flex;
    gap: 4px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 12px;
    flex-wrap: wrap;
}
#phproxy-panel .cli-tabs label {
    padding: 6px 12px;
    color: var(--text-muted);
    font-size: 13px;
    font-weight: 500;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    cursor: pointer;
    transition: color .15s, border-color .15s;
}
#phproxy-panel .cli-tabs label:hover { color: var(--text); }
#phproxy-panel #cli-tab-curl:checked   ~ .cli-tabs label[for="cli-tab-curl"],
#phproxy-panel #cli-tab-php:checked    ~ .cli-tabs label[for="cli-tab-php"],
#phproxy-panel #cli-tab-python:checked ~ .cli-tabs label[for="cli-tab-python"],
#phproxy-panel #cli-tab-js:checked     ~ .cli-tabs label[for="cli-tab-js"],
#phproxy-panel #cli-tab-go:checked     ~ .cli-tabs label[for="cli-tab-go"] {
    color: var(--accent);
    border-bottom-color: var(--accent);
}
#phproxy-panel .cli-panel { display: none; }
#phproxy-panel #cli-tab-curl:checked   ~ .cli-panel[data-cli="curl"],
#phproxy-panel #cli-tab-php:checked    ~ .cli-panel[data-cli="php"],
#phproxy-panel #cli-tab-python:checked ~ .cli-panel[data-cli="python"],
#phproxy-panel #cli-tab-js:checked     ~ .cli-panel[data-cli="js"],
#phproxy-panel #cli-tab-go:checked     ~ .cli-panel[data-cli="go"] {
    display: block;
}
#phproxy-panel .cli-variant         { margin-bottom: 16px; }
#phproxy-panel .cli-variant:last-child { margin-bottom: 0; }
#phproxy-panel .cli-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 6px;
}
#phproxy-panel .cli-variant-label {
    color: var(--text-muted);
    font: 600 11px var(--font);
    letter-spacing: .06em;
    text-transform: uppercase;
}
#phproxy-panel .cli-variant-label code {
    background: var(--surface-2);
    padding: 1px 5px;
    border-radius: 4px;
    font: 11px var(--font-mono);
    text-transform: none;
    letter-spacing: 0;
    color: var(--text);
}
#phproxy-panel .cli-toolbar .button-cancel { padding: 6px 12px; font-size: 12px; }
#phproxy-panel .cli-snippet {
    margin: 0;
    padding: 14px 16px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font: 12.5px/1.55 var(--font-mono);
    color: var(--text);
    overflow-x: auto;
    white-space: pre;
    -webkit-text-size-adjust: 100%;
}

#phproxy-panel .option-group {
    margin: 0 0 14px;
    padding: 8px 14px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface-2);
}
#phproxy-panel .option-group:last-child { margin-bottom: 0; }
#phproxy-panel .option-group legend {
    padding: 0 6px;
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .06em;
    text-transform: uppercase;
}

#phproxy-panel .prx-opt-menu {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px 16px;
}
#phproxy-panel .option label {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 5px 0;
    color: var(--text);
    font-size: 14px;
    cursor: pointer;
}
#phproxy-panel .option label input { margin: 0; }
#phproxy-panel .option label span  { color: inherit; }

#phproxy-panel .radio-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 18px;
    padding: 4px 0;
}
#phproxy-panel .radio-row-label { color: var(--text-muted); font-size: 14px; margin-right: 4px; }
#phproxy-panel .radio-row label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--text);
    font-size: 14px;
    cursor: pointer;
}
#phproxy-panel .radio-row label input { margin: 0; }
#phproxy-panel .radio-row .hint       { color: var(--text-muted); font-size: 11px; margin-left: 2px; }

#phproxy-panel .seed-config {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid var(--border);
}
#phproxy-panel .seed-config .tab-help { margin-bottom: 10px; }
#phproxy-panel .seed-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 10px;
}
#phproxy-panel .seed-mini {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 10px 12px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    margin: 0;
}
#phproxy-panel .seed-mini-label { color: var(--text-muted); font-size: 12px; font-weight: 500; }
#phproxy-panel .seed-mini input,
#phproxy-panel .seed-mini select {
    padding: 6px 10px;
    font: 13px var(--font-mono);
    width: 100%;
    max-width: none;
}
#phproxy-panel .seed-mini button { padding: 8px 12px; font: 600 13px var(--font); align-self: flex-start; }
#phproxy-panel .seed-mini .hint   { color: var(--text-muted); font-size: 11px; font-weight: 400; }

#phproxy-panel .kv-list {
    list-style: none;
    margin: 0 0 12px;
    padding: 0;
    max-height: 380px;
    overflow-y: auto;
}
#phproxy-panel .kv-list .kv-row {
    display: flex;
    gap: 6px;
    align-items: stretch;
    margin-bottom: 6px;
}
#phproxy-panel .kv-list .kv-card {
    flex: 1 1 auto;
    min-width: 0;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    overflow: hidden;
}
#phproxy-panel .kv-list .kv-card > summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 8px 12px;
    cursor: pointer;
    list-style: none;
    font: 13px var(--font-mono);
    color: var(--text);
}
#phproxy-panel .kv-list .kv-card > summary::-webkit-details-marker { display: none; }
#phproxy-panel .kv-list .kv-card > summary::marker                  { content: ''; }
#phproxy-panel .kv-list .kv-card > summary:hover                    { background: rgba(0,0,0,.03); }
#phproxy-panel[data-theme="dark"] .kv-list .kv-card > summary:hover { background: rgba(255,255,255,.04); }

#phproxy-panel .kv-list .kv-display { flex: 1 1 auto; min-width: 0; word-break: break-all; }
#phproxy-panel .kv-list .kv-name    { font-weight: 600; }
#phproxy-panel .kv-list .kv-host    { color: var(--accent); }
#phproxy-panel .kv-list .kv-val     { color: var(--text-muted); }
#phproxy-panel .kv-list .kv-sep     { color: var(--text-muted); margin: 0 6px; }
#phproxy-panel .kv-list .kv-chevron {
    color: var(--text-muted);
    font-size: 11px;
    flex-shrink: 0;
    transition: transform .15s;
}
#phproxy-panel .kv-list .kv-card[open] .kv-chevron { transform: rotate(180deg); }

#phproxy-panel .kv-list .kv-edit {
    padding: 10px 12px 12px;
    border-top: 1px solid var(--border);
    background: var(--surface);
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}
#phproxy-panel .kv-edit-cookie { grid-template-columns: 1fr 1fr 1fr; }
#phproxy-panel .kv-edit-wide   { grid-column: span 2; }
#phproxy-panel .kv-edit-checks {
    grid-column: 1 / -1;
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    padding: 4px 0;
}

#phproxy-panel .kv-card.kv-card-add {
    background: var(--surface);
    border-style: dashed;
    margin-bottom: 10px;
}
#phproxy-panel .kv-card.kv-card-add > summary       { color: var(--text-muted); font: 13px var(--font); }
#phproxy-panel .kv-card.kv-card-add > summary .kv-name { font-weight: 500; }
#phproxy-panel .kv-card.kv-card-add[open] > summary { color: var(--text); }

#phproxy-panel .kv-list .kv-edit-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font: 13px var(--font);
}
#phproxy-panel .kv-list .kv-edit-field > span { color: var(--text-muted); font-size: 12px; }
#phproxy-panel .kv-list .kv-edit-field input  { padding: 6px 10px; font: 13px var(--font-mono); }

#phproxy-panel .kv-list .kv-edit-check {
    grid-column: 1 / -1;
    display: flex;
    align-items: center;
    gap: 6px;
    font: 13px var(--font);
    color: var(--text);
}
#phproxy-panel .kv-list .kv-edit-actions {
    grid-column: 1 / -1;
    display: flex;
    gap: 8px;
    justify-content: flex-end;
}
#phproxy-panel .kv-list .kv-edit-actions .button-submit { padding: 8px 14px; font-size: 13px; }

#phproxy-panel .kv-list .kv-delete { display: flex; align-items: center; }

#phproxy-panel .kv-list .empty {
    background: transparent;
    border: 1px dashed var(--border);
    border-radius: var(--radius-sm);
    color: var(--text-muted);
    font: 13px var(--font);
    text-align: center;
    padding: 12px;
    margin-bottom: 6px;
}

#phproxy-panel .tabs label .tab-count {
    display: inline-block;
    margin-left: 4px;
    padding: 1px 7px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 999px;
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 500;
    vertical-align: middle;
}
#phproxy-panel #tab-options:checked  ~ .tabs label[for="tab-options"]  .tab-count,
#phproxy-panel #tab-cookies:checked  ~ .tabs label[for="tab-cookies"]  .tab-count,
#phproxy-panel #tab-headers:checked  ~ .tabs label[for="tab-headers"]  .tab-count,
#phproxy-panel #tab-response:checked ~ .tabs label[for="tab-response"] .tab-count {
    color: var(--accent);
    border-color: var(--accent);
}

#phproxy-panel .kv-add {
    display: grid;
    grid-template-columns: 1fr 1.4fr auto;
    gap: 8px;
    align-items: stretch;
    margin-bottom: 14px;
}
#phproxy-panel .kv-add input  { padding: 9px 12px; font: 14px var(--font); }
#phproxy-panel .kv-add button {
    padding: 9px 18px;
    background: var(--accent);
    color: #fff;
    border: 0;
    border-radius: var(--radius-sm);
    font: 600 14px var(--font);
    cursor: pointer;
    transition: background-color .15s;
}
#phproxy-panel .kv-add button:hover,
#phproxy-panel .kv-add button:focus { background: var(--accent-hover); }

#phproxy-panel .tab-actions { display: flex; gap: 8px; flex-wrap: wrap; }

#phproxy-panel .tab-help {
    margin: 0 0 12px;
    color: var(--text-muted);
    font-size: 13px;
    line-height: 1.5;
}
#phproxy-panel .tab-help code {
    padding: 1px 5px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 4px;
    font: 12px var(--font-mono);
}

#phproxy-panel .divider {
    margin: 18px 0;
    border: 0;
    border-top: 1px solid var(--border);
}

#phproxy-panel .section-label {
    margin: 0 0 8px;
    color: var(--text);
    font-size: 13px;
    font-weight: 600;
}

#phproxy-panel .ua-picker {
    display: grid;
    grid-template-columns: 1fr;
    gap: 8px;
}
#phproxy-panel .ua-picker select { padding: 9px 12px; }

#phproxy-panel .tab-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 12px;
}
#phproxy-panel .tab-toolbar .tab-help     { flex: 1 1 auto; margin: 0; }
#phproxy-panel .toolbar-toggle            { margin: 0; flex-shrink: 0; }
#phproxy-panel .toolbar-toggle button     { padding: 6px 12px; font-size: 12px; }

/* Trace tab — read-only rows for request + response headers */
#phproxy-panel .kv-card-readonly {
    padding: 8px 12px;
    font: 13px var(--font-mono);
    color: var(--text);
    word-break: break-all;
}
#phproxy-panel .kv-row-readonly .kv-card                     { background: var(--surface-2); }
#phproxy-panel .kv-row-readonly .kv-card-readonly .kv-name   { font-weight: 600; color: var(--accent); }
#phproxy-panel .kv-row-readonly .kv-card-readonly .kv-sep    { color: var(--text-muted); margin: 0 6px; }
#phproxy-panel .kv-row-readonly .kv-card-readonly .kv-val    { color: var(--text); }
#phproxy-panel .kv-row-readonly .kv-card-readonly.kv-card-status {
    color: var(--accent);
    font-weight: 600;
    background: var(--accent-soft, var(--surface-2));
}

@media (max-width: 600px) {
    #phproxy-panel .prx-opt-menu   { grid-template-columns: 1fr; }
    #phproxy-panel .kv-add         { grid-template-columns: 1fr; }
    #phproxy-panel .kv-edit-cookie { grid-template-columns: 1fr; }
    #phproxy-panel .kv-edit-wide   { grid-column: span 1; }
}
PHPROXY_PANEL_CSS;
}
