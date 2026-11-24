# Nightly encrypted backup (ADR-0009): PostgreSQL dump, Laravel storage (encrypted PDFs, logo),
# Signing Service data (SQLite store + SoftHSM token files).
#
# rclone is deliberately NOT installed: the Alpine package carried critical CVEs (one on the KEV
# list) while off-site copy was not configured yet. Add a current, pinned rclone when an off-site
# target is chosen (docs/runbooks/backup-restore.md).
FROM postgres:17-alpine
RUN apk upgrade --no-cache \
 && apk add --no-cache age sqlite tar tzdata
COPY backup.sh /usr/local/bin/backup.sh
COPY entrypoint.sh /usr/local/bin/backup-entrypoint.sh
RUN chmod 0755 /usr/local/bin/backup.sh /usr/local/bin/backup-entrypoint.sh
ENTRYPOINT ["backup-entrypoint.sh"]
