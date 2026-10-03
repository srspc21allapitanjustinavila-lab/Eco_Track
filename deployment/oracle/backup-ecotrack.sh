#!/usr/bin/env bash
set -euo pipefail

backup_dir=/var/backups/ecotrack
timestamp=$(date +%Y-%m-%d_%H-%M-%S)
mkdir -p "$backup_dir"
umask 077

mysqldump --defaults-extra-file=/root/.ecotrack-backup.cnf --single-transaction --routines --events --triggers ecotrack_db \
  | gzip > "$backup_dir/ecotrack_${timestamp}.sql.gz"
tar -C /var/www/ecotrack -czf "$backup_dir/uploads_${timestamp}.tar.gz" uploads

find "$backup_dir" -type f -mtime +14 -delete
