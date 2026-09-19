#!/usr/bin/env bash
#
# Creates the application and test databases on the local SQL Server container.
# Run as a one-shot service (mssql-init) after the mssql service is healthy.
set -euo pipefail

SQLCMD=(/opt/mssql-tools18/bin/sqlcmd -C -S mssql -U sa -P "${MSSQL_SA_PASSWORD}" -b)

for i in $(seq 1 30); do
    if "${SQLCMD[@]}" -Q "SELECT 1" >/dev/null 2>&1; then
        break
    fi
    echo "waiting for SQL Server to accept connections ($i/30)..."
    sleep 2
done

for db in "${DB_DATABASE}" "${DB_DATABASE}_testing"; do
    "${SQLCMD[@]}" -Q "IF DB_ID('${db}') IS NULL CREATE DATABASE [${db}];"
    echo "ensured database: ${db}"
done
