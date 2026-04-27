#!/bin/sh
set -eu

OPENEMR_DIR="/var/www/localhost/htdocs/openemr"
BASE_ENTRYPOINT="/usr/local/bin/openemr-start-base.sh"

fix_permissions() {
  chown -R apache:apache "${OPENEMR_DIR}" >/dev/null 2>&1 || true
  chmod -R u+rwX,go+rX "${OPENEMR_DIR}" >/dev/null 2>&1 || true
}

fix_permissions
(
  count=0
  while [ "$count" -lt 72 ]; do
    sleep 5
    fix_permissions
    count=$((count + 1))
  done
) &

if [ -x "${BASE_ENTRYPOINT}" ]; then
  exec "${BASE_ENTRYPOINT}" "$@"
fi

cd "${OPENEMR_DIR}"
exec ./openemr.sh "$@"
