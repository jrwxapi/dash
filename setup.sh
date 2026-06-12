#!/usr/bin/env bash
set -euo pipefail

# Personal Intelligence Dashboard — LAPP setup (Linux / Apache / PostgreSQL / PHP).
# Idempotent: safe to re-run.

DB_NAME="dashboard"
DB_USER="dashboard"
DB_PASS="dashboard_pass"   # If you change this, update includes/config.php too

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APACHE_CONF="/etc/apache2/conf-available/dash.conf"
PHP_BIN="$(command -v php)"

echo "==> Setting up dashboard in ${PROJECT_DIR}"

# Prompt for sudo once upfront — and fail loudly rather than half-installing.
sudo -v

# ── PostgreSQL ───────────────────────────────────────────────────────────────
echo "--> Creating PostgreSQL user and database..."
if [ "$(sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='${DB_USER}'")" = "1" ]; then
    echo "    (user exists — resetting password to match config)"
    sudo -u postgres psql -qc "ALTER USER ${DB_USER} WITH PASSWORD '${DB_PASS}';"
else
    sudo -u postgres psql -qc "CREATE USER ${DB_USER} WITH PASSWORD '${DB_PASS}';"
fi
if [ "$(sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='${DB_NAME}'")" = "1" ]; then
    echo "    (database already exists, skipping)"
else
    sudo -u postgres psql -qc "CREATE DATABASE ${DB_NAME} OWNER ${DB_USER};"
fi

echo "--> Applying schema..."
PGPASSWORD="${DB_PASS}" psql -h localhost -U "${DB_USER}" -d "${DB_NAME}" \
    -v ON_ERROR_STOP=1 -q -f "${PROJECT_DIR}/db/schema.sql"

# ── Apache ───────────────────────────────────────────────────────────────────
echo "--> Configuring Apache (${APACHE_CONF})..."
sudo tee "${APACHE_CONF}" >/dev/null <<EOF
# Personal dashboard — serves ${PROJECT_DIR}/public at http://localhost/dash
Alias /dash ${PROJECT_DIR}/public

<Directory ${PROJECT_DIR}/public>
    Options -Indexes
    AllowOverride None
    DirectoryIndex index.php
    # Local machine only. For kiosk/LAN access change to:  Require all granted
    Require local
</Directory>
EOF
sudo a2enconf dash >/dev/null

# Apache (www-data) needs traverse permission down to the project directory.
echo "--> Granting www-data traverse access to the project path..."
if command -v setfacl >/dev/null; then
    sudo setfacl -m u:www-data:x "${HOME}" "${HOME}/Projects"
else
    echo "    setfacl not found — using chmod o+x on \$HOME and \$HOME/Projects"
    chmod o+x "${HOME}" "${HOME}/Projects"
fi

sudo systemctl reload apache2

# ── Cron ─────────────────────────────────────────────────────────────────────
echo "--> Installing crontab entry (runs pollers every minute)..."
mkdir -p "${PROJECT_DIR}/logs"
CRON_LINE="* * * * * ${PHP_BIN} ${PROJECT_DIR}/cron/poll.php >> ${PROJECT_DIR}/logs/poll.log 2>&1"
( crontab -l 2>/dev/null | grep -vF "cron/poll.php" ; echo "${CRON_LINE}" ) | crontab -

# ── First poll ───────────────────────────────────────────────────────────────
echo "--> Running the first poll now (news feeds take ~30-60s)..."
"${PHP_BIN}" "${PROJECT_DIR}/cron/poll.php" || true

echo ""
echo "==> Done!"
echo ""
echo "  Dashboard:  http://localhost/dash/"
echo "  Admin:      http://localhost/dash/admin.php"
echo ""
echo "  Next steps:"
echo "   - Open the admin page to set your name, weather location, ESPP, and holdings."
echo "   - Optional AI cards: install Ollama (https://ollama.com), then:"
echo "       ollama pull phi3:mini && ollama pull gemma2:2b"
echo "   - Logs: tail -f ${PROJECT_DIR}/logs/poll.log"
