<?php
if(parse_url($_url)['host'] == 'electronjs.org') {
    $custom = array(
        '/(<[^<\/>]+)( data-src="" )([^<>]+>)/' => '$1 $3'
    );

    foreach($custom as $key=>$val) {
        $_response_body = preg_replace($key, $val, $_response_body);
    }
}

if(preg_match('/http[s]?:\/\/(ipv[46]|www).google\.[a-z]+(\.)?[a-z]+\/+sorry\/+index/', $_url)) {
	$_response_body = 'We\'re sorry but Google Search is temporarily unavailable in our service due to high demand. We recommend you to use Microsoft Bing Search instead.';
}

$custom = array(
    '/<([^<>]+)?link[^<>]+rel([ ]+)?=([ ]+)?"preconnect"[^<>]+href([ ]+)?=([ ]+)?"[a-zA-Z:\/\.?=0-9_%]+"([^<>]+)?>/' => '',
    '/<([^<>]+)?link[^<>]+rel([ ]+)?=([ ]+)?"dns-prefetch"[^<>]+href([ ]+)?=([ ]+)?"[a-zA-Z:\/\.?=0-9_%]+"([^<>]+)?>/' => ''
);

foreach($custom as $key=>$val) {
    $_response_body = preg_replace($key, $val, $_response_body);
}
