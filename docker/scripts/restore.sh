#!/usr/bin/env bash
# ==============================================================================
# E-La Platform — สคริปต์ทดสอบและกู้คืนข้อมูล (Restore Verification Script)
# ==============================================================================
# การทำงาน:
# 1. หากไม่ระบุพารามิเตอร์: รันการซ้อมกู้คืนอัตโนมัติ (backup:verify-restore)
# 2. หากระบุพาธไฟล์ .zip หรือ .sql: ดำเนินการกู้คืนลงฐานข้อมูล
# ==============================================================================

set -euo pipefail

TARGET_FILE="${1:-}"

echo "========================================================"
echo "  ระบบบริหารงานสถานศึกษา E-La — สคริปต์กู้คืนข้อมูล"
echo "  วันเวลา: $(date +'%Y-%m-%d %H:%M:%S')"
echo "========================================================"

if ! docker ps --format '{{.Names}}' | grep -q "^eleave_backend$"; then
    echo "[ข้อผิดพลาด] ไม่พบคอนเทนเนอร์ eleave_backend กำลังทำงาน"
    exit 1
fi

if [ -z "${TARGET_FILE}" ]; then
    echo "[โหมดซ้อมกู้คืน] ทดสอบความสมบูรณ์ของไฟล์สำรองล่าสุดลงฐานชั่วคราว..."
    docker exec eleave_backend php artisan backup:verify-restore
    exit 0
fi

echo "[คำเตือน] คุณกำลังจะกู้คืนข้อมูลจริงจากไฟล์: ${TARGET_FILE}"
echo "การดำเนินการนี้อาจเขียนทับข้อมูลปัจจุบัน กรุณาตรวจสอบให้แน่ใจ"
read -p "พิมพ์ 'CONFIRM' เพื่อดำเนินการต่อ: " CONFIRMATION

if [ "${CONFIRMATION}" != "CONFIRM" ]; then
    echo "ยกเลิกการกู้คืนข้อมูล"
    exit 0
fi

if [[ "${TARGET_FILE}" == *.sql.gz ]]; then
    echo "กำลังนำเข้าข้อมูลจาก .sql.gz ตรงเข้า MySQL..."
    gunzip -c "${TARGET_FILE}" | docker exec -i eleave_db mysql -uroot -p"${MYSQL_ROOT_PASSWORD:-root}" eleave
    echo "  -> กู้คืนฐานข้อมูลเรียบร้อย"
elif [[ "${TARGET_FILE}" == *.sql ]]; then
    echo "กำลังนำเข้าข้อมูลจาก .sql ตรงเข้า MySQL..."
    docker exec -i eleave_db mysql -uroot -p"${MYSQL_ROOT_PASSWORD:-root}" eleave < "${TARGET_FILE}"
    echo "  -> กู้คืนฐานข้อมูลเรียบร้อย"
else
    echo "[ข้อผิดพลาด] รูปแบบไฟล์ไม่รองรับ (รองรับ .sql หรือ .sql.gz)"
    exit 1
fi

echo "กำลังล้างแคชและอัปเดตสถานะระบบ..."
docker exec eleave_backend php artisan optimize:clear
echo "========================================================"
echo "  การกู้คืนข้อมูลเสร็จสิ้นเรียบร้อย"
echo "========================================================"
