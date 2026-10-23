#!/bin/bash
# Runs once, on first start of an empty data volume.
# One schema + one user per service; each user can only touch its own schema.
set -euo pipefail

create_schema() {
  local db="$1" user="$2" pass="$3"
  mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${user}'@'%' IDENTIFIED BY '${pass}';
GRANT ALL PRIVILEGES ON \`${db}\`.* TO '${user}'@'%';
SQL
}

create_schema identity_db    identity_user    "${IDENTITY_DB_PASSWORD}"
create_schema inventory_db   inventory_user   "${INVENTORY_DB_PASSWORD}"
create_schema reservation_db reservation_user "${RESERVATION_DB_PASSWORD}"
create_schema integration_db integration_user "${INTEGRATION_DB_PASSWORD}"

mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" -e "FLUSH PRIVILEGES;"
