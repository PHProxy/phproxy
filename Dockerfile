FROM php:8.5-apache

RUN a2enmod rewrite

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
