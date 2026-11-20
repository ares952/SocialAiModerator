#!/bin/bash
# Runs once on the first start with an empty datadir. Creates the database used by the
# integration tests, so tests never touch the application database.
set -euo pipefail

mariadb -uroot -p"${MARIADB_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${MARIADB_DATABASE}_test\`;
GRANT ALL PRIVILEGES ON \`${MARIADB_DATABASE}_test\`.* TO '${MARIADB_USER}'@'%';
FLUSH PRIVILEGES;
SQL
