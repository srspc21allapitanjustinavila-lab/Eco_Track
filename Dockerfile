FROM php:8.2-apache

# I-enable ang Apache Rewrite module (para kung may .htaccess ka)
RUN a2enmod rewrite

# I-install ang MySQL extensions na kailangan ng database mo
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Kopyahin ang lahat ng files mo papunta sa default folder ng Apache
COPY . /var/www/html/

# I-configure ang Apache para makinig sa dynamic PORT na ibinibigay ng Railway
RUN sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf
RUN sed -i 's/<VirtualHost \*:80>/<VirtualHost \*:${PORT}>/' /etc/apache2/sites-available/000-default.conf