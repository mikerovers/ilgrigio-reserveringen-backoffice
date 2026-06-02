FROM php:8.2-cli-alpine

RUN apk add --no-cache \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    icu-dev \
    supervisor \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd intl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_ENV=prod

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

COPY . .

# Provide an empty .env so Symfony runtime dotenv loading does not error in prod
# (real config comes from DigitalOcean platform env vars; .env is gitignored/dockerignored).
RUN touch .env

RUN composer dump-autoload --no-dev --optimize

# Configure supervisor to keep the messenger consumer running and restart it on exit
RUN mkdir -p /etc/supervisor/conf.d /var/log/supervisor && \
    printf '%s\n' \
    '[supervisord]' \
    'nodaemon=true' \
    'user=root' \
    'logfile=/var/log/supervisor/supervisord.log' \
    'pidfile=/var/run/supervisord.pid' \
    '' \
    '[program:messenger-consume]' \
    'command=php /app/bin/console messenger:consume async webhook_ingest --time-limit=3600 --memory-limit=128M -vv' \
    'stdout_logfile=/dev/stdout' \
    'stdout_logfile_maxbytes=0' \
    'stderr_logfile=/dev/stderr' \
    'stderr_logfile_maxbytes=0' \
    'autostart=true' \
    'autorestart=true' \
    'startsecs=0' \
    'startretries=10' \
    > /etc/supervisor/conf.d/supervisord.conf

CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
