FROM node:18 AS legacy-assets

WORKDIR /legacy

COPY webapp/package*.json ./
RUN npm ci

FROM node:18 AS calendar-build

WORKDIR /calendar

COPY calendar/package*.json ./
RUN npm ci

COPY calendar/ .
RUN npm run build

FROM php:8.2-apache

WORKDIR /miserend

RUN apt-get update && apt-get install -y \
        git \
        unzip \
        python3 \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-install mysqli pdo pdo_mysql \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Copy repo root
COPY . .

COPY --from=legacy-assets /legacy/node_modules \
    /miserend/webapp/node_modules

COPY --from=calendar-build /calendar/dist/mcal/browser \
    /miserend/calendar/dist/mcal/browser

# Run Angular → PHP deploy script
RUN python3 docker/miserend/calendar_deploy.py \
    /miserend/calendar/dist/mcal/browser /miserend/webapp \
 && rm -rf calendar/dist

# Permissions
RUN chown -R www-data:www-data webapp/fajlok

# Install Composer
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
 && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
 && php -r "unlink('composer-setup.php');"

# Backend PHP deps
WORKDIR /miserend/webapp
RUN composer install --no-interaction --no-progress

# PHP config
COPY docker/miserend/php.ini /usr/local/etc/php/conf.d/php.ini

# Apache vhost
COPY docker/miserend/apache/apache.conf /etc/apache2/sites-available/miserend.conf
COPY docker/miserend/apache/ports.conf /etc/apache2/ports.conf
RUN a2dissite 000-default \
 && a2ensite miserend \
 && apache2ctl configtest

 RUN mkdir -p /var/www/app/fajlok/tmp \
    && chown -R www-data:www-data /var/www/app/fajlok
    
# Entrypoint
COPY docker/miserend/entrypoint.sh /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
