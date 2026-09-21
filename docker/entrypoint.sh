#!/bin/bash
set -euo pipefail

MOODLE_DATAROOT="${MOODLE_DATAROOT:-/var/moodledata}"

echo "Waiting for database..."
php /var/www/html/docker/wait-db.php

if [ ! -f /var/www/html/config.php ]; then
  echo "Creating /var/www/html/config.php from docker template..."
  cp /var/www/html/docker/config.docker.php /var/www/html/config.php
  chown www-data:www-data /var/www/html/config.php
fi

mkdir -p "${MOODLE_DATAROOT}"
chown -R www-data:www-data "${MOODLE_DATAROOT}"
chmod 0777 "${MOODLE_DATAROOT}" 2>/dev/null || true

exec "$@"
