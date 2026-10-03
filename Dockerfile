FROM php:8.2-apache

# 1. Piliting i-disable ang conflict na MPMs para maiwasan ang error sa logs
RUN a2dismod mpm_event mpm_worker || true
RUN a2enmod mpm_prefork

# 2. I-install ang MySQL extensions para sa database mo
RUN docker-php-ext-install mysqli pdo pdo_mysql

# 3. I-enable ang Apache Rewrite module
RUN a2enmod rewrite

# 4. Kopyahin ang lahat ng EcoTrack files mo
COPY . /var/www/html/

# 5. Palitan ang port configuration eksakto sa pag-start ng container
CMD sed -i "s/80/$PORT/g" /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf && docker-php-entrypoint apache2-foreground