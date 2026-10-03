FROM php:8.4-cli

RUN docker-php-ext-install pdo_mysql

WORKDIR /app

COPY . /app

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app"]