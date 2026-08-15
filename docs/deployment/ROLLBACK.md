# Rollback Procedure

ในกรณีที่พบปัญหาร้ายแรงระดับ BLOCKER/CRITICAL หลังการ Deploy ขึ้น Production และไม่สามารถใช้ Hotfix ได้ทันที ให้ดำเนินการ Rollback ไปยังเวอร์ชันล่าสุดที่ทำงานได้ตามขั้นตอนดังนี้

## ขั้นตอนการ Rollback

### 1. เข้าสู่ Maintenance Mode
หยุดการสร้างข้อมูลใหม่จากผู้ใช้
```bash
docker exec eleave_backend php artisan down --secret="ela-rollback-bypass"
```

### 2. ย้อนกลับ Source Code
ใช้ Git เพื่อย้อนโค้ดกลับไปที่ Release/Commit ก่อนหน้า
```bash
git fetch --tags
git checkout tags/<PREVIOUS_VERSION_TAG> 
# เช่น git checkout tags/v0.9.5
```

### 3. Rebuild Containers
ปรับ Container ให้ตรงกับ Source Code ย้อนหลัง
```bash
docker-compose build
docker-compose up -d
```

### 4. Database Rollback
**คำเตือน:** การ Rollback ฐานข้อมูลขึ้นอยู่กับความเสียหาย
- **กรณี Migration มีปัญหาเล็กน้อย (Additive เท่านั้น):**
  รัน Migration Rollback `step=1` (ถ้ามีโค้ดรองรับ)
  ```bash
  docker exec eleave_backend php artisan migrate:rollback --step=1 --force
  ```
- **กรณีข้อมูลเสียหายรุนแรง (Data Corruption):**
  ทำการ Restore จากไฟล์ Backup ที่ถูกทำไว้ในขั้นตอน `Pre-Deploy`
  ```bash
  cat /backups/db/pre_deploy_<timestamp>.sql | docker exec -i eleave_db mysql -u root --password="${MYSQL_ROOT_PASSWORD}" eleave
  ```

### 5. ล้าง Cache และ Restart Queue
```bash
docker exec eleave_backend php artisan optimize:clear
docker exec eleave_backend php artisan queue:restart
docker-compose restart worker scheduler
```

### 6. ตรวจสอบการ Rollback และเปิดระบบ
1. ตรวจสอบว่าระบบกลับมาใช้งานได้ (Smoke Tests)
2. ปลด Maintenance Mode
```bash
docker exec eleave_backend php artisan up
```
3. บันทึกสาเหตุและสรุป Post-Mortem Report เพื่อหาสาเหตุการล้มเหลวต่อไป
