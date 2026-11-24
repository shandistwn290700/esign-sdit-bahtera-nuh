#!/bin/sh
# Runs after 50-laravel-automations (migrations). Only the `web` container (AUTORUN_ENABLED=true)
# creates the first admin from TTE_ADMIN_EMAIL / TTE_ADMIN_PASSWORD; it is a no-op once an admin
# exists. A failure is reported but never stops the container.
if [ "${AUTORUN_ENABLED:-false}" = "true" ] && [ -n "${TTE_ADMIN_EMAIL:-}" ]; then
  php /var/www/html/artisan tte:bootstrap-admin --no-interaction \
    || echo "tte: admin bootstrap failed, see the message above"
fi
