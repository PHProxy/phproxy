FROM php:8.5.12-apache@sha256:4d1f268dd3b670bd003861c5385cb2f8ba7196dbc88ee84dcae47270c116f242

LABEL org.opencontainers.image.title="PHProxy" \
      org.opencontainers.image.description="A self-contained PHP web proxy" \
      org.opencontainers.image.source="https://github.com/PHProxy/phproxy" \
      org.opencontainers.image.licenses="GPL-3.0-only"

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf '%s\n' \
        'expose_php=Off' \
        'display_errors=Off' \
        'log_errors=On' \
        'error_reporting=E_ALL' \
        'memory_limit=128M' \
        'max_execution_time=30' \
        'max_input_time=30' \
        'default_socket_timeout=15' \
        'post_max_size=8M' \
        'upload_max_filesize=8M' \
        'session.use_strict_mode=1' \
        'session.use_only_cookies=1' \
        'session.cookie_httponly=1' \
        'session.cookie_samesite=Lax' \
        'session.save_path=/tmp' \
        > "$PHP_INI_DIR/conf.d/phproxy.ini" \
    && chown root:root /var/www/html \
    && chmod 0555 /var/www/html

COPY --chown=0:0 --chmod=0444 index.php /var/www/html/index.php
COPY --chown=0:0 --chmod=0444 LICENSE.md /usr/share/licenses/phproxy/LICENSE.md

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl --fail --silent --max-time 3 --output /dev/null 'http://127.0.0.1/?asset=index.css' || exit 1

EXPOSE 80
