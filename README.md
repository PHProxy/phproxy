# PHProxy

[![License: GPL v3](https://img.shields.io/badge/License-GPL%20v3-blue.svg)](LICENSE.md)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D%208.1-777bb3.svg)](https://www.php.net/)
[![Single file](https://img.shields.io/badge/single--file-index.php-success.svg)](index.php)

A small, fast, self-contained web proxy in **one PHP file**. Drop `index.php`
into any PHP 8.1+ web root and it just works — no Composer, no dependencies,
no `vendor/`, no build step. Rename it to `proxy.php` if you like; the script
self-references.

Originally developed on [SourceForge](http://www.sourceforge.net/projects/poxy/)
2002–2007, then abandoned. Revived and modernised here for PHP 8.5 with a
new HTML5 interface, encrypted-URL mode, full cookie/header management, and
an inline settings panel injected into proxied pages.

## Highlights

- **Single file.** `index.php` is the whole product. No `files/` directory,
  no includes, no autoloader.
- **Encrypted address bar by default.** Each URL is AES-CTR encrypted with a
  rotating session seed (128/192/256-bit, configurable). Logs / browser
  history / referers become useless once the seed rotates.
- **Inline settings panel** on every proxied page — a full-viewport overlay
  with **Options**, **Cookies**, **Headers**, and a **Trace** tab showing the
  exact HTTP request and response headers for the current URL.
- **Full cookie management** — host, path, expiry, Secure, HttpOnly, SameSite,
  all per-cookie. Add, edit, delete, switch between decoded / raw value
  display.
- **Custom outbound headers** — any `Name: Value` you want forwarded to the
  upstream. Plus a User-Agent picker with modern presets.
- **Privacy options** — strip tracking params (utm_*, fbclid, gclid, …),
  block third-party resources, block iframes / web fonts / media, send
  `DNT: 1` and `Sec-GPC: 1`, strip `<title>` / `<meta>`.
- **HTML5 UI** with light & dark themes (manual toggle + `prefers-color-scheme`).
- **Three URL forms** — `?_proxurl=…`, `index.php/<url>`, or bare-path
  `phproxy.example/https://target/` with `mod_rewrite`.
- **PHP 8.5 clean** — zero deprecations under `E_ALL`. CI lints on 8.1 + 8.5.

## What it is

PHProxy is a web HTTP/HTTPS proxy you visit in a browser. You type a URL,
the server fetches it on your behalf, rewrites links in the response so
they route back through the proxy, and serves the result. Conceptually
similar to [CGIProxy](http://www.jmarshall.com/tools/cgiproxy/) or the
discontinued Glype, only smaller and more modern.

It runs anywhere PHP runs. The server needs to be able to reach the target
sites — your access to PHProxy doesn't change.

## What it isn't

PHProxy is a **regex-based HTML rewriter**. It does not run a headless
browser, evaluate JavaScript, solve CAPTCHAs, or negotiate token-bound TLS.

Modern JS-heavy sites that gate access behind Cloudflare challenges or
require live client-side script execution (Google search, GitHub UI, most
social networks, modern SaaS apps) will not work through PHProxy — that's
a fundamental limitation of the architecture, not a bug. Use a real
headless-browser-based proxy for those.

## Install

### Docker (recommended)

```sh
git clone https://github.com/PHProxy/phproxy.git
cd phproxy
docker compose up -d
```

Open <http://localhost:8080/> — done.

The image is based on the official `php:8.5-apache` and needs no extra
extensions. The shipped `docker-compose.yml` bind-mounts the source for
development; for production, remove the `volumes:` block so the container
runs the baked-in copy of `index.php`.

### Bash (any PHP 8.1+ host)

```sh
cd /var/www/html
curl -O https://raw.githubusercontent.com/PHProxy/phproxy/master/index.php
curl -O https://raw.githubusercontent.com/PHProxy/phproxy/master/.htaccess
```

That's it — `index.php` is the entire script. The `.htaccess` enables the
bare-path URL form (`phproxy.example/https://target/`); it's optional and
the script works without it.

Visit `http://your-host/` (or wherever you dropped it).

### Standalone shared hosting

If you don't have shell access, download
[`index.php`](https://raw.githubusercontent.com/PHProxy/phproxy/master/index.php)
and (optionally) [`.htaccess`](https://raw.githubusercontent.com/PHProxy/phproxy/master/.htaccess)
and upload both via FTP / your host's file manager to the web root.

### Rename to `proxy.php` (or anything else)

`index.php` self-references via `$_SERVER['PHP_SELF']`. Just rename the file
— forms, redirects and asset URLs all stay correct.

If you keep the `.htaccess`, edit it to point the rewrite rules at the new
filename.

```sh
mv index.php proxy.php
sed -i 's|index\.php|proxy.php|g' .htaccess
```

## Requirements

- PHP **≥ 8.1**, tested on **8.5**.
- `fsockopen()` not disabled. Most shared hosts allow it.
- `openssl` extension for HTTPS targets and for the encrypted-URL mode (both
  are on by default in mainstream PHP builds).
- `zlib` extension for output compression — optional.
- `file_uploads = On` if you want POSTed file uploads to flow through.
- Apache `mod_rewrite` + `AllowOverride All` for the bare-path URL form —
  optional. The Docker image is preconfigured.

## URL forms

PHProxy accepts the target URL in three shapes:

| Form | Example | Needs `mod_rewrite`? |
| --- | --- | --- |
| Query (default) | `https://proxy.example/?_proxurl=<encoded>` | no |
| Path via `index.php` | `https://proxy.example/index.php/https://target/` | no |
| Bare path | `https://proxy.example/https://target/` | yes |

The first two work everywhere. The bare-path form is the prettiest but
needs Apache `mod_rewrite` and the shipped `.htaccess` to be honoured
(`AllowOverride All` in your vhost config).

## Anonymity

PHProxy is anonymous-by-default. The outbound request headers are built
from a known-safe whitelist (method, path, `Host`, `User-Agent`, `Accept`,
optional `Referer`, `Cookie`, `Authorization`, plus POST-body headers).
The proxy never forwards `X-Forwarded-For`, `X-Real-IP`, `Via`, or
`Forwarded` to the upstream — targets see this server's IP only.

For extra cover, the **Encrypted** URL-encoding mode (on by default) wraps
each URL in an AES-CTR ciphertext keyed off a random per-session seed
stored in a 1-hour cookie. Once the seed rotates, any URL that ended up
in an access log, browser history, or `Referer` header becomes unusable
— the ciphertext is mathematically meaningless without the now-gone key.

Both the seed lifetime and the key length (AES-128 / 192 / 256) are
configurable from the Options → Address bar tab, along with a **Rotate
now** button for immediate re-keying.

If you also want to hide the client `User-Agent`, set it to `-` in the
Headers tab — no `User-Agent` header gets forwarded.

## Configuration

All options are toggleable per-session via the inline panel (gear icon in
the top bar of every proxied page). They persist in cookies. Defaults are
sane; you typically don't need to touch them.

For server-side defaults, the top of `index.php` has a `$_config` /
`$_flags` / `$_frozen_flags` block — edit and you're done. Freezing a flag
in `$_frozen_flags` removes it from the UI entirely.

## Bugs and limitations

PHProxy inherits a few historical quirks from PHP's request handling, most
notably the legacy dot→underscore conversion on incoming variable names
(a `register_globals`-era footgun). Cookie names are restored to dotted
form internally; URL query parameters are URL-encoded into a single carrier
variable so this rarely matters in practice.

Things that simply will not work, ever, by design:

- **JavaScript-heavy sites.** PHProxy doesn't run JS. Sites that build
  their UI client-side (everything from Gmail to most React/Vue apps) will
  partially work at best.
- **Cloudflare-gated / CAPTCHA-protected sites.** No browser fingerprint,
  no challenge-solving.
- **Token-bound TLS** (browser-only TLS extensions some sites require).
- **FTP / SFTP / WebDAV.** HTTP/HTTPS only.
- **WebSockets, WebRTC, Server-Sent Events.** Synchronous fetch only.
- **Flash, Java applets, plug-ins.** Content fetched from inside these
  is invisible to the URL rewriter.

## Support

- File issues: <https://github.com/PHProxy/phproxy/issues>
- Pull requests: <https://github.com/PHProxy/phproxy/pulls>

## License

GNU GPL v3 — see [`LICENSE.md`](LICENSE.md).

Original authorship: A.A. (whitefyre), 2002–2007. Revival and modernisation
(2025–2026): see the commit history.
