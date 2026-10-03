FROM php:8.2-apache

# I-disable ang ibang MPM para maiwasan ang "More than one MPM loaded" error
RUN a2dismod mpm_event mpm_worker || true
RUN a2enmod mpm_prefork || true

# I-enable ang Apache Rewrite module
RUN a2enmod rewrite

# I-install ang MySQL extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Kopyahin ang project files mo
COPY . /var/www/html/

# I-configure ang Apache gamit ang literal na ${PORT} para sa runtime binding
RUN sed -i 's/Listen 80/Listen \${PORT}/' /etc/apache2/ports.conf
RUN sed -i 's/<VirtualHost \*:80>/<VirtualHost \*:\${PORT}>/' /etc/apache2/sites-available/000-default.conf