#!/usr/bin/env bash
#
# Feriwala — development environment for WSL Ubuntu (decision D5, amended twice).
#
#   bash scripts/setup-dev-environment.sh
#
# You will be prompted for your sudo password once.
#
# Installs PostgreSQL 18 and Redis 8 from Ubuntu apt, creates the application
# role and database, and creates the `testing` schema the test suite runs in.
#
# Tests use a schema rather than a separate database so the application role
# needs no CREATEDB privilege — see phpunit.xml and requirements/04-decisions.md.
#
# Safe to re-run: every step checks before it acts.

set -euo pipefail

DB_NAME="feriwala"
DB_USER="feriwala"
DB_SCHEMA="testing"

# Override when running this on a fresh machine:
#   DB_PASS='your-password' bash scripts/setup-dev-environment.sh
DB_PASS="${DB_PASS:-secret}"

info() { printf '\n\033[1;34m==>\033[0m %s\n' "$1"; }
ok() { printf '    \033[0;32m✓\033[0m %s\n' "$1"; }

# ------------------------------------------------------------------- Packages

if ! command -v psql > /dev/null 2>&1; then
    info "Installing PostgreSQL"
    sudo apt-get update -qq
    sudo apt-get install -y postgresql postgresql-client
else
    ok "PostgreSQL already installed ($(psql --version))"
fi

if ! command -v redis-cli > /dev/null 2>&1; then
    info "Installing Redis"
    sudo apt-get install -y redis-server
else
    ok "Redis already installed ($(redis-cli --version))"
fi

# ------------------------------------------------------------------- Services

info "Enabling and starting services"
sudo systemctl enable --now postgresql redis-server
ok "postgresql and redis-server running"

# ------------------------------------------------------- Role and database

info "Creating the '$DB_USER' role and database"

sudo -u postgres psql -v ON_ERROR_STOP=1 <<SQL
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '$DB_USER') THEN
        CREATE ROLE $DB_USER LOGIN PASSWORD '$DB_PASS';
    END IF;
END
\$\$;
SQL
ok "role '$DB_USER'"

if sudo -u postgres psql -lqt | cut -d '|' -f 1 | grep -qw "$DB_NAME"; then
    ok "database '$DB_NAME' already exists"
else
    sudo -u postgres createdb -O "$DB_USER" "$DB_NAME"
    ok "database '$DB_NAME' created"
fi

# The role owns the database, so it can create its own schemas without
# CREATEDB or superuser rights.
PGPASSWORD="$DB_PASS" psql -h 127.0.0.1 -U "$DB_USER" -d "$DB_NAME" -qtAc \
    "CREATE SCHEMA IF NOT EXISTS $DB_SCHEMA AUTHORIZATION $DB_USER;" > /dev/null
ok "schema '$DB_SCHEMA' (used by the test suite)"

# ---------------------------------------------------------------- Verification

info "Verifying"
ok "PostgreSQL $(PGPASSWORD="$DB_PASS" psql -h 127.0.0.1 -U "$DB_USER" -d "$DB_NAME" -tAc 'SHOW server_version;')"
ok "Redis $(redis-cli INFO server | grep redis_version | tr -d '\r' | cut -d: -f2)"

cat <<DONE

Done.

  PostgreSQL  127.0.0.1:5432   user $DB_USER
              database $DB_NAME, test schema '$DB_SCHEMA'
  Redis       127.0.0.1:6379   databases: 0 default, 1 cache, 2 queue, 3 locks

Set DB_PASSWORD in .env to match, then:

  php artisan migrate
  php artisan db:seed --class=RolesAndPermissionsSeeder

Optional — a database GUI. pgAdmin 4 can be installed in WSL, but a Windows
client (DBeaver, TablePlus) pointed at 127.0.0.1:5432 is usually simpler.

DONE
