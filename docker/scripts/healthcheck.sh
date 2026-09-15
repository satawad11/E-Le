#!/usr/bin/env bash
# ==============================================================================
# E-La Platform — สคริปต์ตรวจเช็คสุขภาพระบบ (Operations Health Check)
# ==============================================================================

set -euo pipefail

echo "========================================================"
echo "  ระบบบริหารงานสถานศึกษา E-La — ตรวจสอบสถานะการทำงาน"
echo "  วันเวลา: $(date +'%Y-%m-%d %H:%M:%S')"
echo "========================================================"

FAILED=0

# 1. ตรวจสอบ Docker Containers
echo -e "\n[1/4] ตรวจสอบสถานะ Docker Containers:"
CONTAINERS=("eleave_db" "eleave_backend" "eleave_nginx" "eleave_worker" "eleave_scheduler" "eleave_clamav")

for c in "${CONTAINERS[@]}"; do
    STATUS=$(docker inspect --format='{{.State.Status}}' "$c" 2>/dev/null || echo "not_found")
    HEALTH=$(docker inspect --format='{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$c" 2>/dev/null || echo "none")
    
    if [ "$STATUS" == "running" ]; then
        if [ "$HEALTH" == "healthy" ] || [ "$HEALTH" == "none" ]; then
            echo "  [✓ ผ่าน] $c (กำลังทำงาน - $STATUS, health: $HEALTH)"
        else
            echo "  [! แจ้งเตือน] $c (กำลังทำงานแต่ health: $HEALTH)"
        fi
    else
        echo "  [✗ ล้มเหลว] $c (สถานะ: $STATUS)"
        FAILED=$((FAILED + 1))
    fi
done

# 2. ตรวจสอบ Web Gateway (Nginx / API Ping)
echo -e "\n[2/4] ตรวจสอบการตอบสนองของ Nginx API Gateway:"
if curl -s -f http://127.0.0.1:8001/api/ping >/dev/null 2>&1 || curl -s -f http://localhost:8001/api/ping >/dev/null 2>&1; then
    echo "  [✓ ผ่าน] Endpoint /api/ping ตอบสนองปกติ (HTTP 200 OK)"
else
    echo "  [✗ ล้มเหลว] ไม่สามารถเรียก /api/ping ได้"
    FAILED=$((FAILED + 1))
fi

# 3. ตรวจสอบการเชื่อมต่อฐานข้อมูล
echo -e "\n[3/4] ตรวจสอบการเชื่อมต่อฐานข้อมูล MySQL:"
if docker exec eleave_backend php artisan db:show >/dev/null 2>&1; then
    echo "  [✓ ผ่าน] Backend สามารถเชื่อมต่อฐานข้อมูลได้ปกติ"
else
    echo "  [✗ ล้มเหลว] Backend ไม่สามารถเชื่อมต่อฐานข้อมูลได้"
    FAILED=$((FAILED + 1))
fi

# 4. ตรวจสอบคิวและ Scheduler
echo -e "\n[4/4] ตรวจสอบชีวจรงานตามเวลา (Scheduler Heartbeat):"
if docker exec eleave_backend php artisan operations:check-heartbeats >/dev/null 2>&1; then
    echo "  [✓ ผ่าน] Scheduler Heartbeat ทำงานปกติ"
else
    echo "  [! แจ้งเตือน] ตรวจสอบประวัติ Heartbeat ของ Scheduler"
fi

echo -e "\n========================================================"
if [ $FAILED -eq 0 ]; then
    echo "  สรุปผล: ระบบทำงานปกติสมบูรณ์ 100% (All Systems Operational)"
else
    echo "  สรุปผล: พบข้อผิดพลาด $FAILED จุด กรุณาตรวจสอบคอนเทนเนอร์ข้างต้น"
fi
echo "========================================================"
exit $FAILED
