#!/usr/bin/env bash
# Register local_privacient with Moodle and clear the caches that hide changes.
#
# This used to copy the plugin into a git submodule: the source of truth lived
# in IOMAD/plugins/ and ./iomad was an upstream checkout, so deploying meant
# duplicating files in and then hiding them from the submodule's git. None of
# that applies now. The plugin lives in-tree at public/local/privacient and is
# bind-mounted straight into the container by docker-compose, so what is on
# disk is already what the container runs.
#
# What is still needed is Moodle-side, and is easy to forget:
#
#   upgrade.php    installs the plugin's tables, capabilities and external
#                  function records. It only does anything when version.php
#                  has a HIGHER version than the installed one, so bump it
#                  when you change db/ — otherwise this silently no-ops.
#   purge_caches   Moodle caches the class map and string files. A brand-new
#                  class (or lang string) is invisible until this runs, which
#                  looks exactly like a missing file.
#
# It does NOT add new web-service functions to the token's service — declaring
# one in db/services.php is not the same as exposing it. Run
# scripts/privacient-ws-setup.php for that.
set -euo pipefail

export PATH="$HOME/.docker/bin:$PATH"
container="${LMS_CONTAINER:-lms}"

if ! docker ps --format '{{.Names}}' | grep -qx "$container"; then
    echo "Container '$container' is not running. Start it with: docker compose up -d" >&2
    echo "(override the name with LMS_CONTAINER=... if yours differs)" >&2
    exit 1
fi

echo "==> admin/cli/upgrade.php"
docker exec "$container" php /var/www/html/admin/cli/upgrade.php --non-interactive

echo "==> admin/cli/purge_caches.php"
docker exec "$container" php /var/www/html/admin/cli/purge_caches.php

echo
echo "Done. If you added a web-service function, also run:"
echo "  docker cp scripts/privacient-ws-setup.php $container:/tmp/"
echo "  docker exec $container php /tmp/privacient-ws-setup.php"
