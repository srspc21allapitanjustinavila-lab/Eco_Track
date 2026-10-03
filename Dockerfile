FROM php:8.2-apache

# I-install ang MySQL extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# I-enable ang Apache Rewrite module
RUN a2enmod rewrite

# Kopyahin ang lahat ng files mo papunta sa default folder
COPY . /var/www/html/

# Palitan ang port configuration sa mismong runtime, pagkatapos ay patakbuhin ang Apache
CMD sed -i "s/80/$PORT/g" /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf && docker-php-entrypoint apache2-foreground