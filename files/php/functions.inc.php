<?php
function show_report($data)
{
    require_once "./files/php/index.inc.php";
    exit(0);
}

function add_cookie($name, $value, $expires = 0)
{
    return rawurlencode(rawurlencode($name)) . '=' . rawurlencode(rawurlencode($value)) . (empty($expires) ? '' : '; expires=' . gmdate('D, d-M-Y H:i:s \G\M\T', $expires)) . '; path=/; domain=.' . $GLOBALS['_http_host'];
}

function set_post_vars($array, $parent_key = null)
{
    $temp = array();

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

function set_post_files($array, $parent_key = null)
{
    $temp = array();

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

function url_parse($url, &$container)
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
        $path         = array();
        $temp['path'] = explode('/', $temp['path']);

        foreach ($temp['path'] as $dir) {
            if ($dir === '..') {
                array_pop($path);
            } else if ($dir !== '.') {
                for ($dir = rawurldecode($dir), $new_dir = '', $i = 0, $count_i = strlen($dir); $i < $count_i; $new_dir .= strspn($dir{$i}, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789$-_.+!*\'(),?:@&;=') ? $dir{$i} : rawurlencode($dir{$i}), ++$i);
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

function complete_url($url, $proxify = true)
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

    return $proxify ? "{$GLOBALS['_script_url']}?{$GLOBALS['_config']['url_var_name']}=" . encode_url($url) . $fragment : $url;
}

function proxify_inline_css($css)
{
    preg_match_all('#url\s*\(\s*(.+?(?=\)[f;,}!\s*]))\)#i', $css, $matches, PREG_SET_ORDER);

    for ($i = 0, $count = count($matches); $i < $count; ++$i) {
        $css = str_replace($matches[$i][0], 'url(' . proxify_css_url($matches[$i][1]) . ')', $css);
    }

    return $css;
}

function proxify_css($css)
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

function proxify_css_url($url)
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

function encode_url($url)
{
    global $_flags;

    if ($_flags['rotate13']) {
        $url = str_rot13($url);
    } elseif ($_flags['base64_encode']) {
        $url = base64_encode($url);
    }

    return rawurlencode($url);
}

function decode_url($url)
{
    global $_flags;
    $url = rawurldecode($url);

    if ($_flags['rotate13']) {
        $url = str_rot13($url);
    } elseif ($_flags['base64_encode']) {
        $url = base64_decode($url);
    }

    return str_replace(array('&amp;', '&#38;'), '&', $url);
}
