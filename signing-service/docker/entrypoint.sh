#!/bin/sh
# Initialise the SoftHSM2 token on first start, then run the given command.
set -eu

: "${SIGNER_HSM_PIN:?SIGNER_HSM_PIN is required}"
: "${SIGNER_HSM_SO_PIN:?SIGNER_HSM_SO_PIN is required}"
LABEL="${SIGNER_HSM_TOKEN_LABEL:-tte}"

mkdir -p /data/softhsm/tokens
if [ ! -f "$SOFTHSM2_CONF" ]; then
  printf 'directories.tokendir = /data/softhsm/tokens\nobjectstore.backend = file\nlog.level = ERROR\n' \
    > "$SOFTHSM2_CONF"
fi

if ! softhsm2-util --show-slots | grep -Eq "Label:[[:space:]]+${LABEL}[[:space:]]*$"; then
  echo "Initialising SoftHSM token '${LABEL}'"
  softhsm2-util --init-token --free --label "$LABEL" \
    --pin "$SIGNER_HSM_PIN" --so-pin "$SIGNER_HSM_SO_PIN" > /dev/null
fi

exec "$@"
