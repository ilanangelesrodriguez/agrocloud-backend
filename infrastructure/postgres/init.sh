#!/bin/sh
set -eu
# Runs only when PostgreSQL initializes an empty volume.
for service in identity agricultural notifications ai; do
  case "$service" in
    identity) password="$IDENTITY_DB_PASSWORD" ;;
    agricultural) password="$AGRICULTURAL_DB_PASSWORD" ;;
    notifications) password="$NOTIFICATIONS_DB_PASSWORD" ;;
    ai) password="$AI_DB_PASSWORD" ;;
  esac
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
    --set=role="${service}_app" --set=db="${service}_db" --set=password="$password" <<'SQL'
CREATE USER :"role" WITH PASSWORD :'password';
CREATE DATABASE :"db" OWNER :"role";
REVOKE ALL ON DATABASE :"db" FROM PUBLIC;
SQL
done
