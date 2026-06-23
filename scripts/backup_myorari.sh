#!/usr/bin/env bash
# Backup cifrato per MyOrari: database + file applicativi, con retention di 30 giorni.
set -Eeuo pipefail
umask 077

APP_DIR="/var/www/html/App_iperal-1"
RCLONE_REMOTE="myorari-crypt:"
RETENTION_DAYS=30
WORK_ROOT="${HOME}/.local/share/myorari-backups"
LOCK_FILE="${WORK_ROOT}/backup.lock"
FINAL_ARCHIVE=""

log() {
    printf '%s %s\n' "$(date '+%F %T')" "$*"
}

fail() {
    log "ERRORE: $*"
    exit 1
}

command -v php >/dev/null || fail 'PHP non disponibile.'
command -v rclone >/dev/null || fail 'rclone non disponibile.'
command -v tar >/dev/null || fail 'tar non disponibile.'
command -v sha256sum >/dev/null || fail 'sha256sum non disponibile.'
command -v sudo >/dev/null || fail 'sudo non disponibile.'

[[ -d "$APP_DIR" ]] || fail "Cartella applicazione non trovata: ${APP_DIR}"
mkdir -p "$WORK_ROOT"

exec 9>"$LOCK_FILE"
flock -n 9 || fail 'Un backup è già in corso.'

DB_NAME="$(php -r '
    $env = require $argv[1];
    $name = (string) ($env["APP_DB_NAME"] ?? "");
    if (!preg_match("/^[A-Za-z0-9_]+$/", $name)) {
        exit(1);
    }
    echo $name;
' "${APP_DIR}/app_local_env.php")" || fail 'Nome database non leggibile da app_local_env.php.'
[[ -n "$DB_NAME" ]] || fail 'Nome database non configurato.'

# Verifica subito sia il permesso di dump locale sia il remote rclone cifrato.
sudo -n true || fail 'sudo senza password non disponibile per creare il dump MySQL.'
rclone lsd "$RCLONE_REMOTE" >/dev/null

STAMP="$(date '+%Y-%m-%d_%H-%M-%S')"
WORK_DIR="$(mktemp -d "${WORK_ROOT}/run-${STAMP}-XXXXXX")"
cleanup() {
    rm -rf "$WORK_DIR"
    [[ -z "$FINAL_ARCHIVE" ]] || rm -f "$FINAL_ARCHIVE"
}
trap cleanup EXIT

DB_DUMP="${WORK_DIR}/database-${STAMP}.sql.gz"
FILES_ARCHIVE="${WORK_DIR}/files-${STAMP}.tar.gz"
CHECKSUMS="${WORK_DIR}/SHA256SUMS.txt"
FINAL_ARCHIVE="${WORK_ROOT}/myorari-backup-${STAMP}.tar.gz"

log "Avvio backup ${STAMP}."

sudo -n mysqldump \
    --single-transaction \
    --routines \
    --events \
    --no-tablespaces \
    --databases "$DB_NAME" | gzip -9 > "$DB_DUMP"

tar \
    --exclude='./storage/sessions' \
    --exclude='./.git' \
    -C "$APP_DIR" \
    -czf "$FILES_ARCHIVE" .

(
    cd "$WORK_DIR"
    sha256sum "$(basename "$DB_DUMP")" "$(basename "$FILES_ARCHIVE")" > "$(basename "$CHECKSUMS")"
    tar -czf "$FINAL_ARCHIVE" \
        "$(basename "$DB_DUMP")" \
        "$(basename "$FILES_ARCHIVE")" \
        "$(basename "$CHECKSUMS")"
)

rclone copyto "$FINAL_ARCHIVE" "${RCLONE_REMOTE}$(basename "$FINAL_ARCHIVE")"
rclone delete --min-age "${RETENTION_DAYS}d" "$RCLONE_REMOTE"
rclone rmdirs "$RCLONE_REMOTE" >/dev/null 2>&1 || true

SIZE="$(du -h "$FINAL_ARCHIVE" | awk '{print $1}')"
rm -f "$FINAL_ARCHIVE"
FINAL_ARCHIVE=""
log "Backup completato e cifrato su Google Drive (${SIZE}); retention ${RETENTION_DAYS} giorni."
