#!/bin/sh
# Run backup.sh daily at BACKUP_CRON (default 02:00 in TZ), or once with `backup-entrypoint.sh now`.
set -eu

: "${BACKUP_AGE_RECIPIENT:?BACKUP_AGE_RECIPIENT (age public key) is required}"

if [ "${1:-}" = "now" ]; then
  exec /usr/local/bin/backup.sh
fi

echo "${BACKUP_CRON:-0 2 * * *} /usr/local/bin/backup.sh >> /proc/1/fd/1 2>&1" > /etc/crontabs/root
echo "backup: scheduled '${BACKUP_CRON:-0 2 * * *}' (TZ=${TZ:-UTC})"
exec crond -f -l 8
