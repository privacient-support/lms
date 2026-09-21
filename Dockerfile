FROM php:8.2-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        libicu-dev \
        libxml2-dev \
        libonig-dev \
        ghostscript \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        intl \
        mysqli \
        opcache \
        zip \
        soap \
        exif \
        bcmath \
    && docker-php-ext-enable opcache \
    && a2enmod rewrite expires headers \
    && echo 'ServerName localhost' >> /etc/apache2/apache2.conf \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php-moodle.ini /usr/local/etc/php/conf.d/moodle.ini
COPY docker/apache-moodle.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
    && chmod +x /var/www/html/docker/entrypoint.sh

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["apache2-foreground"]
