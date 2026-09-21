#!/bin/bash
# Moodle CLI cron, once a minute.
#
# Company deletes, certificate issuance, and other adhoc work only happen
# when this runs. The web container does not invoke it.
set -eu

php /var/www/html/docker/wait-db.php

if [ ! -f /var/www/html/config.php ]; then
  cp /var/www/html/docker/config.docker.php /var/www/html/config.php
fi

while true; do
  start=$(date +%s)
  php /var/www/html/admin/cli/cron.php || true
  elapsed=$(( $(date +%s) - start ))
  if [ "$elapsed" -lt 60 ]; then
    sleep $((60 - elapsed))
  fi
done
