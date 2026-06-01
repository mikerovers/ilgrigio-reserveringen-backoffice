#!/bin/sh
set -e

echo "Running post-deployment setup..."

# Install JavaScript vendor assets
php bin/console importmap:install

# Compile assets for production
php bin/console asset-map:compile --env=prod

# Clear and warm cache for production
php bin/console cache:clear --env=prod --no-warmup || true
php bin/console cache:warmup --env=prod || true

# Set permissions on compiled assets
chmod -R 755 public/assets 2>/dev/null || true

echo "Setup complete. Starting services..."

# Start supervisord
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
