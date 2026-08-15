# Backup and Restore Procedures

## 1. Overview
This document defines the automated backup and manual restore procedures for the E-la production Docker environment.

## 2. Backup Strategy

### 2.1 Database Backup (MySQL 8)
- **Tool:** `mysqldump` executed inside the database container.
- **Schedule:** Daily at 02:00 AM (local time).
- **Retention:** 30 days of daily backups, 12 months of monthly backups.
- **Encryption:** Compressed with `gzip` and encrypted using `gpg` before off-site transfer.
- **Command:**
  ```bash
  docker exec e-la-db /usr/bin/mysqldump -u root --password="${DB_ROOT_PASSWORD}" e_la_db \
      | gzip -9 | gpg -c --batch --passphrase="${BACKUP_PASSPHRASE}" > /backups/db/ela_db_$(date +%F).sql.gz.gpg
  ```

### 2.2 Private Files Backup (`storage/app/private_uploads`)
- **Tool:** `tar` and `rsync` (or AWS S3 Sync).
- **Schedule:** Daily at 02:30 AM.
- **Retention:** 30 days.
- **Command:**
  ```bash
  tar -czf - /path/to/ela/backend/storage/app/private_uploads \
      | gpg -c --batch --passphrase="${BACKUP_PASSPHRASE}" > /backups/files/ela_files_$(date +%F).tar.gz.gpg
  ```

### 2.3 Configuration Backup
- **Scope:** `.env` files, `docker-compose.yml`, and Nginx/TLS configurations.
- **Schedule:** On every modification (manual trigger or configuration management tool).

## 3. Restore Procedures

### 3.1 Prerequisite
- Verify the integrity of the backup file by decrypting it.
  ```bash
  gpg -d --batch --passphrase="${BACKUP_PASSPHRASE}" ela_db_2026-08-15.sql.gz.gpg > ela_db.sql.gz
  gunzip ela_db.sql.gz
  ```

### 3.2 Database Restore
1. Stop the application container to prevent data mutations: `docker-compose stop app`
2. Drop and recreate the database:
   ```bash
   docker exec -i e-la-db mysql -u root --password="${DB_ROOT_PASSWORD}" -e "DROP DATABASE e_la_db; CREATE DATABASE e_la_db;"
   ```
3. Import the SQL dump:
   ```bash
   cat ela_db.sql | docker exec -i e-la-db mysql -u root --password="${DB_ROOT_PASSWORD}" e_la_db
   ```

### 3.3 Private Files Restore
1. Decrypt and extract the tarball into the storage directory:
   ```bash
   gpg -d --batch --passphrase="${BACKUP_PASSPHRASE}" ela_files_2026-08-15.tar.gz.gpg | tar -xz -C /path/to/ela/backend/storage/app/private_uploads
   ```
2. Verify permissions:
   ```bash
   chown -R 1000:1000 /path/to/ela/backend/storage/app/private_uploads
   ```

## 4. Restore Validation
A backup is only considered successful if it can be restored. Post-restore checks:
- **Migration Status:** Run `docker exec e-la-app php artisan migrate:status` to ensure schema consistency.
- **Data Check:** Query the latest `audit_logs` record to ensure data recency matches the expected RPO.
- **File Check:** Verify a recent PDF/image from `private_uploads` is accessible and undamaged.
