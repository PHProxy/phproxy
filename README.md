## PHProxy
[![AUR](https://img.shields.io/badge/style-GPL--3.0-blue.svg?style=flat&label=License)](https://github.com/azetrix/ShortLink/blob/master/LICENSE)

PHProxy is a web HTTP proxy written in PHP. It is designed to bypass proxy restrictions through a web interface very similar to the popular [CGIProxy](http://www.jmarshall.com/tools/cgiproxy/). The only thing that PHProxy needs is a web server with PHP installed (see Requirements below). Be aware though, that the sever has to be able to access those resources to deliver them to you.

Originaly developed in [SourceForge](http://www.sourceforge.net/projects/poxy/) during 2002-2007 and then abandoned. This project needs to live and it's development is continued here.

## Support

 * Create an issue: https://github.com/PHProxy/PHProxy/issues/new

## License

This source code is released under the GPL.
A copy of the license is provided in this package in the filename `LICENSE.md`.

## Requirements

 * PHP version > 5
 * `safe_mode` turned off or at least having the `fsockopen()` function not disabled
 * OpenSSL for support for secure connections (https)
 * Zlib for output compression
 * `file_uploads` turned On for HTTP file uploads.

## Installation

Copy the files of the repository in your public web server folder or to a
directory of your liking (prefrebly in its own directory).

```
cd /var/www/html/
git clone https://github.com/PHProxy/phproxy.git
```

## How it Works

You simply supply a URL to the form and click Browse. The script then 
accesses that URL, and if it has any HTML contents, it modifies 
any URLs so that they point back to the script. Of course, there is more
to it than this, but if you would like to know more in
detail, view the source code.

## Bugs and Limitations

PHP is restrictive by nature, and as such, some problems arise that 
would have not if this project were otherwise coded in another programming
language. The first example of this is the dots in incoming variable names 
from POST and GET methods. In a normal programming language, this wouldn't be
a problem as these variables could be accessed normally as they are 
supplied, with dots included. In PHP, however, dots in GET, POST, and
COOKIE variable names are magically transformed into underscores
because of `register_globals`. Things like Yahoo! Mail which has dots
in variable names will not work. There's no easy way around this, but
luckily, I have provided the solutions right here:

  1. I've already taken care of cookies by manually transforming
     the underscores manually into dots when needed.
  2. For GET variables, this shouldn't be a huge problem since the URLs
     are URL-encoded into the url_var_name. The only time this should be
     an issue is when a GET form uses dots in input names, and this could
     be recitified by using $_SERVER['QUERY_STRING'], and parsing that
     variable. But this, luckily, doesn't happen too often.
  3. As for POST data, one solution is to use $HTTP_RAW_POST_DATA. But then,
     this variable might not be available in certain PHP configurations,
     and it would need further parsing, and it still doesn't account 
     for uploaded FILES. This is extremely impractical and ugly.

The best thing you could do if you have enough control over your Web server
and can compile custom builds of PHP is to delete a single line in a PHP source
code file called "php_variables.c" located in the "main" directory.
The function in question is called "php_register_variable_ex". I've only checked
this with PHP v4.4.4 and the exact line to delete is 117th line which basically
consists of this:

			case '.':

Now just compile and install PHP and everything should be fine. Just make
sure that you have register_globals off or something might get messed up.

Another problem facing many Web proxies is support for JavaScript.
The best thing you could do right now is to have the JavaScript
disabled on your browsing options as most sites degrade gracefully,
such as Gmail.

A third limitation for Web proxies is content accessed from within proxied
Flash and Java applications and such. Since the proxy script doesn't have access
to the source code of these applications, the links which they may decide
to stream or access will not be proxified. There's no easy solution for this
right now.

PHProxy also doesn't support FTP. This may or may not be introduced 
in future releases, but there are no current plans for FTP support.
