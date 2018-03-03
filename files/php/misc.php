<?php
if(preg_match('/http[s]?:\/\/(ipv[46]|www).google\.[a-z]+(\.)?[a-z]+\/+sorry\/+index/', $_url)) {
	$_response_body = 'We\'re sorry but Google Search is temporarily unavailable in our service due to high demand. We recommend you to use Microsoft Bing Search instead.';
}
