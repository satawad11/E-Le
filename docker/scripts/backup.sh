#!/usr/bin/env bash
# ==============================================================================
# E-La Platform — สคริปต์สำรองข้อมูลอัตโนมัติ (Automated Backup Script)
# ==============================================================================
# การทำงาน:
# 1. สั่งรันคำสั่งสำรองข้อมูลผ่าน Laravel Backup ในคอนเทนเนอร์ backend
# 2. จัดเก็บไฟล์สำรอง (ฐานข้อมูล + ไฟล์เอกสารส่วนบุคคล private_uploads)
# 3. ลบไฟล์สำรองเก่าที่เกินกำหนด (Retention: ค่าเริ่มต้น 30 วัน)
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
BACKUP_DIR="${PROJECT_ROOT}/backend/storage/app/backup"
RETENTION_DAYS="${RETENTION_DAYS:-30}"
TIMESTAMP="$(date +'%Y%m%d_%H%M%S')"

echo "========================================================"
echo "  ระบบบริหารงานสถานศึกษา E-La — เริ่มการสำรองข้อมูล"
echo "  วันเวลา: $(date +'%Y-%m-%d %H:%M:%S')"
echo "========================================================"

# ตรวจสอบว่าคอนเทนเนอร์ backend ทำงานอยู่หรือไม่
if ! docker ps --format '{{.Names}}' | grep -q "^eleave_backend$"; then
    echo "[ข้อผิดพลาด] ไม่พบคอนเทนเนอร์ eleave_backend กำลังทำงาน"
    echo "กรุณาเริ่มระบบด้วย: docker compose up -d"
    exit 1
fi

echo "[1/3] กำลังสร้างไฟล์สำรอง (ฐานข้อมูล + เอกสารสารบรรณ/ลายเซ็น)..."
if docker exec eleave_backend php artisan backup:run --only-to-disk=backup; then
    echo "  -> สำรองข้อมูลผ่านระบบสำเร็จ"
else
    echo "[คำเตือน] สำรองผ่านระบบไม่สำเร็จ กำลังสลับไปสำรองฐานข้อมูลตรงผ่าน mysqldump..."
    mkdir -p "${BACKUP_DIR}"
    FALLBACK_SQL="${BACKUP_DIR}/eleave_db_manual_${TIMESTAMP}.sql.gz"
    docker exec eleave_db mysqldump -uroot -p"${MYSQL_ROOT_PASSWORD:-root}" eleave | gzip > "${FALLBACK_SQL}"
    echo "  -> สำรองฐานข้อมูลฉุกเฉินเรียบร้อย: ${FALLBACK_SQL}"
fi

echo "[2/3] ตรวจสอบไฟล์สำรองล่าสุด..."
if docker exec eleave_backend test -f artisan; then
    docker exec eleave_backend php artisan backup:list || true
fi

echo "[3/3] ตรวจสอบและลบไฟล์สำรองเก่าที่เกิน ${RETENTION_DAYS} วัน..."
if [ -d "${BACKUP_DIR}" ]; then
    find "${BACKUP_DIR}" -type f -name "*.zip" -mtime +"${RETENTION_DAYS}" -print -delete 2>/dev/null || true
    find "${BACKUP_DIR}" -type f -name "*.sql.gz" -mtime +"${RETENTION_DAYS}" -print -delete 2>/dev/null || true
    echo "  -> ทำความสะอาดไฟล์สำรองเก่าเรียบร้อย"
fi

echo "========================================================"
echo "  การสำรองข้อมูลเสร็จสมบูรณ์เรียบร้อย"
echo "========================================================"
exit 0
