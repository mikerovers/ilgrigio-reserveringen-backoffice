#!/bin/sh
set -e

echo "Running post-deployment setup..."

# Install JavaScript vendor assets
php bin/console importmap:install

# Publish bundle assets (EasyAdmin CSS/JS); public/bundles is gitignored and composer runs with --no-scripts
php bin/console assets:install public --env=prod

# Compile assets for production
php bin/console asset-map:compile --env=prod

# Clear and warm cache for production
php bin/console cache:clear --env=prod --no-warmup || true
php bin/console cache:warmup --env=prod || true

# Run database migrations
php bin/console doctrine:migrations:migrate --no-interaction --env=prod || true

# Set permissions on compiled assets
chmod -R 755 public/assets 2>/dev/null || true

# Cache commands above run as root and recreate var/ owned by root; hand it back to PHP-FPM (www-data)
chown -R www-data:www-data /app/var

echo "Setup complete. Starting services..."

# Start supervisord
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
