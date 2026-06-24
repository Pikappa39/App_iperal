#!/usr/bin/env bash
set -euo pipefail

# Verifica le risposte pubbliche fondamentali dopo un deploy senza modificare dati.
project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
base_url="${1:-${APP_HEALTHCHECK_URL:-https://myorari.it}}"
base_url="${base_url%/}"

if [[ ! "$base_url" =~ ^https?:// ]]; then
    echo "Errore: indica un URL completo, ad esempio https://myorari.it" >&2
    exit 2
fi

version="$(sed -n "s/.*define('APP_VERSION', '\([^']*\)'.*/\1/p" "$project_root/app_config.php" | head -n 1)"

check_endpoint() {
    local label="$1"
    local path="$2"
    local expected_content_type="$3"
    local result
    local status
    local content_type

    if ! result="$(curl --silent --show-error --location --connect-timeout 5 --max-time 20 \
        --output /dev/null --write-out '%{http_code}|%{content_type}' "$base_url$path")"; then
        echo "FAIL $label: richiesta non riuscita ($base_url$path)" >&2
        return 1
    fi

    status="${result%%|*}"
    content_type="${result#*|}"
    if [[ "$status" != "200" || "$content_type" != "$expected_content_type"* ]]; then
        echo "FAIL $label: HTTP $status, Content-Type '${content_type:-assente}' ($base_url$path)" >&2
        return 1
    fi

    echo "OK   $label: HTTP $status, $content_type"
}

css_path="/sfera.css"
if [[ -n "$version" ]]; then
    css_path+="?v=$version"
fi

check_endpoint "home" "/" "text/html"
check_endpoint "login" "/login_reg.php" "text/html"
check_endpoint "stylesheet" "$css_path" "text/css"
check_endpoint "manifest" "/manifest.php" "application/manifest+json"
check_endpoint "service worker" "/service-worker.php" "application/javascript"

echo "Verifica post-deploy completata con successo."
