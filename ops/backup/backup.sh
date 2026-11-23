#!/bin/sh
# One encrypted archive per run: /backups/esign-YYYYmmdd-HHMMSS.tar.age
#
# Encrypted to an age PUBLIC key: this server can write backups but cannot read them. The matching
# private key is kept offline (docs/runbooks/backup-restore.md).
#
# Inputs (env): PGHOST PGUSER PGPASSWORD PGDATABASE BACKUP_AGE_RECIPIENT
#               BACKUP_KEEP_DAYS (default 14)  BACKUP_RCLONE_REMOTE (optional, e.g. "gdrive:esign")
# Mounts:       /source/storage (Laravel storage/app, read-only)  /source/signer (signer /data)
#               /backups (output)
set -eu

stamp=$(date +%Y%m%d-%H%M%S)
work=$(mktemp -d /tmp/esign-backup.XXXXXX)
trap 'rm -rf "$work"' EXIT
out="/backups/esign-${stamp}.tar.age"

echo "backup ${stamp}: start"

# 1. Database: custom format, restorable with pg_restore.
pg_dump --format=custom --no-owner --file="$work/database.dump"

# 2. Laravel storage (PDFs are already AES-256-GCM encrypted at rest; logo).
tar -C /source/storage -cf "$work/storage.tar" .

# 3. Signing Service: consistent SQLite copy (safe while running) + SoftHSM token files.
mkdir -p "$work/signer"
if [ -f /source/signer/signer.sqlite3 ]; then
  sqlite3 -readonly /source/signer/signer.sqlite3 ".backup '$work/signer/signer.sqlite3'"
fi
if [ -d /source/signer/softhsm ]; then
  tar -C /source/signer -cf "$work/signer/softhsm.tar" softhsm
fi

# 4. Manifest with checksums, so a restore can prove nothing was altered or truncated.
( cd "$work" && find . -type f ! -name MANIFEST.sha256 -exec sha256sum {} + > MANIFEST.sha256 )

# 5. Encrypt to the offline public key; write atomically.
tar -C "$work" -cf - . | age --encrypt --recipient "$BACKUP_AGE_RECIPIENT" --output "$out.partial"
mv "$out.partial" "$out"
echo "backup ${stamp}: wrote $out ($(du -h "$out" | cut -f1))"

# 6. Optional off-site copy (ADR-0009). Configure rclone via RCLONE_CONFIG_* env variables.
offsite_failed=0
if [ -n "${BACKUP_RCLONE_REMOTE:-}" ]; then
  if rclone copy "$out" "$BACKUP_RCLONE_REMOTE"; then
    echo "backup ${stamp}: copied to $BACKUP_RCLONE_REMOTE"
  else
    echo "backup ${stamp}: ERROR off-site copy to $BACKUP_RCLONE_REMOTE failed (local copy kept)" >&2
    offsite_failed=1
  fi
fi

# 7. Retention (local copies only).
find /backups -name 'esign-*.tar.age' -mtime +"${BACKUP_KEEP_DAYS:-14}" -print -delete
echo "backup ${stamp}: done"
exit "$offsite_failed"
