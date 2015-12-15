## PHProxy - Web based PHP Proxy

PHProxy is a web HTTP proxy written in php. It is designed to bypass proxy restrictions through a web interface very similar to the popular CGIProxy. The only thing that PHProxy needs is a web server with PHP installed (see Requirements below). Be aware though, that the sever has to be able to access those resources to deliver them to you.

Originaly developed in [SourceForge](http://www.sourceforge.net/projects/poxy/) during 2002-2007 and then abandoned. This project needs to live, this is why I  have copied it here and will continue to develop it.

## Support

Use the Github functionality here: https://github.com/PHProxy/PHProxy/issues/new

## License

This source code is released under the GPL.
A copy of the license in provided in this package in the file named LICENSE.md

## Documentation

http://phproxy.readthedocs.org


## Requirements

 * php > 5
 * safe_mode=off / fsockopen() allowed
 * openssl for https support
 * zlib for output compression
 * file_uploads=on for file uploads.

## Installation

Simply copy the files of the repository in your public web server folder.

```
cd /var/www/html/
git clone https://github.com/PHProxy/phproxy.git .
```

## Disclaimer & Limitations

PHP is not the most convenient, fast and secure language to write a web proxy, but this is the only open-source php based proxy and it needs to live for the masses. Just as an example in the U.S. national vulnerability database (nvd.nist.gov) to the current moment there are 70k vulnerabilities and 22k or 1/3 are related to php.

So basically use at your own risk.
