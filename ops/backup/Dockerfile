# Nightly encrypted backup (ADR-0009): PostgreSQL dump, Laravel storage (encrypted PDFs, logo),
# Signing Service data (SQLite store + SoftHSM token files).
FROM postgres:17-alpine
RUN apk add --no-cache age sqlite tar tzdata rclone
COPY backup.sh /usr/local/bin/backup.sh
COPY entrypoint.sh /usr/local/bin/backup-entrypoint.sh
RUN chmod 0755 /usr/local/bin/backup.sh /usr/local/bin/backup-entrypoint.sh
ENTRYPOINT ["backup-entrypoint.sh"]
