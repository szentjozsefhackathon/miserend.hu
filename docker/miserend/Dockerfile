FROM node:18 AS legacy-assets

WORKDIR /legacy

COPY webapp/package*.json ./
RUN npm ci

FROM node:22 AS calendar-build

ARG NG_CONFIG=production

WORKDIR /calendar

COPY calendar/package*.json ./
RUN npm ci

COPY calendar/ .
RUN npx ng build --configuration=${NG_CONFIG} && node scripts/generate-mass-definitions.js

FROM php:8.4-apache

WORKDIR /miserend

RUN apt-get update && apt-get install -y \
        git \
        unzip \
        python3 \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-install mysqli pdo pdo_mysql zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Copy repo root
COPY . .

COPY --from=legacy-assets /legacy/node_modules \
    /miserend/webapp/node_modules

COPY --from=calendar-build /calendar/dist/mcal/browser \
    /miserend/calendar/dist/mcal/browser

# Run Angular → PHP deploy script
#
# #751: a fordított naptárcsomag már NINCS verziókövetve (a tartalomhash-elt
# fájlnév minden branchen más lett, és emiatt minden nyitott PR ütközött rajta).
# Élesben ez semmit nem változtat: a csomagot eddig is ez a lépés állította elő.
# Fejlesztésben viszont a `../webapp:/miserend/webapp` bind mount eltakarja az
# image tartalmát a hoszt (friss klón után üres) könyvtárával — ezért félreteszünk
# egy érintetlen példányt, amit az entrypoint vissza tud másolni.
RUN python3 docker/miserend/calendar_deploy.py \
    /miserend/calendar/dist/mcal/browser /miserend/webapp \
 && rm -rf calendar/dist \
 && mkdir -p /opt/mcal-dist/js /opt/mcal-dist/css \
 && cp -a /miserend/webapp/js/mcal /opt/mcal-dist/js/ \
 && cp -a /miserend/webapp/css/styles.css /opt/mcal-dist/css/ \
 && if [ -d /miserend/webapp/fonts ]; then cp -a /miserend/webapp/fonts /opt/mcal-dist/; fi

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
# #171: a konténeren belüli cron-kopogtató. Nem a cron démon: az roothoz kötött, a
# konténer viszont www-data-ként fut. Alapból ki van kapcsolva, l. CRON_ENABLED.
COPY docker/miserend/cron-loop.sh /cron-loop.sh
RUN chmod +x /entrypoint.sh /cron-loop.sh
ENTRYPOINT ["/entrypoint.sh"]
