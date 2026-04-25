FROM php:8.2-apache

# Cài thư viện cho Imagick
RUN apt-get update && apt-get install -y \
    libmagickwand-dev \
    libmagickcore-dev \
    && pecl install imagick \
    && docker-php-ext-enable imagick

# Extension cần thiết
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Bật mod rewrite (nếu cần)
RUN a2enmod rewrite