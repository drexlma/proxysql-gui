FROM php:8.3-apache

RUN docker-php-ext-install mysqli \
 && printf 'expose_php=Off\ndisplay_errors=Off\nlog_errors=On\nsession.use_strict_mode=1\n' \
      > /usr/local/etc/php/conf.d/proxysql-gui.ini \
 && printf 'ServerName localhost\n<DirectoryMatch "^/var/www/html/(lib|pages|bin)">\n    Require all denied\n</DirectoryMatch>\n<Files "config*.php">\n    Require all denied\n</Files>\n<Directory /var/www/html>\n    Options -Indexes\n</Directory>\n' \
      > /etc/apache2/conf-enabled/proxysql-gui.conf

COPY src/ /var/www/html/
