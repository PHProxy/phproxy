<?php
if(preg_match('/http[s]?:\/\/(ipv[46]|www).google\.[a-z]+(\.)?[a-z]+\/+sorry\/+index/', $_url)) {
	$_response_body = 'We\'re sorry but Google Search is temporarily unavailable in our service due to high demand. We recommend you to use Microsoft Bing Search instead.';
}
if(preg_match('/facebook\.com[.]?$/', $_url_parts['host']) && $_content_type == 'application/xhtml+xml') {
	$_content_type = 'text/html';
	$_response_headers['content-type'] = $_content_type.'; charset=utf-8';
}
