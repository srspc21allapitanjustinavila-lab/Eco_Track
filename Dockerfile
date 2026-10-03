FROM dunglas/frankenphp:php8.4

# Set the server name to listen on the dynamic port assigned by Railway
ENV SERVER_NAME=":${PORT}"

RUN install-php-extensions mysqli

COPY . /app

WORKDIR /app