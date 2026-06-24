#!/usr/bin/env bash
# Verifica che l'ultimo backup cifrato su rclone esista, sia non vuoto e recente.
set -Eeuo pipefail

RCLONE_REMOTE="${MYORARI_BACKUP_REMOTE:-myorari-crypt:}"
MAX_AGE_HOURS="${MYORARI_BACKUP_MAX_AGE_HOURS:-30}"
ARCHIVE_PREFIX="myorari-backup-"

log() {
    printf '%s %s\n' "$(date '+%F %T')" "$*"
}

fail() {
    log "ERRORE: $*"
    exit 1
}

command -v rclone >/dev/null || fail 'rclone non disponibile.'
[[ "$MAX_AGE_HOURS" =~ ^[0-9]+$ ]] || fail 'MYORARI_BACKUP_MAX_AGE_HOURS deve essere un numero intero.'

latest_archive="$(rclone lsf --files-only "$RCLONE_REMOTE" \
    | awk -v prefix="$ARCHIVE_PREFIX" '$0 ~ "^" prefix "[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}\\.tar\\.gz$"' \
    | sort \
    | tail -n 1)"

[[ -n "$latest_archive" ]] || fail "Nessun archivio backup trovato su ${RCLONE_REMOTE}."

stamp="${latest_archive#${ARCHIVE_PREFIX}}"
stamp="${stamp%.tar.gz}"
backup_epoch="$(date -d "${stamp/_/ }" +%s 2>/dev/null)" || fail "Data non leggibile nel nome ${latest_archive}."
now_epoch="$(date +%s)"
age_seconds=$((now_epoch - backup_epoch))
age_hours=$((age_seconds / 3600))

if (( age_seconds < 0 )); then
    fail "L'orologio del server non e' coerente con ${latest_archive}."
fi
if (( age_seconds > MAX_AGE_HOURS * 3600 )); then
    fail "Backup troppo vecchio: ${latest_archive}, ${age_hours} ore fa (limite ${MAX_AGE_HOURS} ore)."
fi

archive_size="$(rclone ls "${RCLONE_REMOTE}${latest_archive}" | awk '{total += $1} END {print total + 0}')"
[[ "$archive_size" =~ ^[0-9]+$ ]] || fail "Dimensione non leggibile per ${latest_archive}."
(( archive_size > 0 )) || fail "L'archivio ${latest_archive} e' vuoto."

log "OK: ${latest_archive}, ${age_hours} ore fa, ${archive_size} byte su ${RCLONE_REMOTE}."
