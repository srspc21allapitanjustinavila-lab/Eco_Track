FROM dunglas/frankenphp:php8.4

RUN install-php-extensions mysqli

COPY . /app

WORKDIR /app