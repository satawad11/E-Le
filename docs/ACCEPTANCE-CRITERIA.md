# GLOBAL ACCEPTANCE CRITERIA
- ผู้ใช้ที่ไม่มีสิทธิ์เปิด URL/API โดยตรงแล้วต้องถูกปฏิเสธ
- เปลี่ยน role/menu ไม่ทำให้ข้าม ABAC scope
- policy/workflow/template ใหม่ไม่เปลี่ยนผลย้อนหลังของรายการที่ pin version แล้ว
- รายงานลาสรุปจำนวนครั้งและจำนวนวันได้ถูกต้อง
- ตารางเวลารายบุคคล override ค่า default อย่าง deterministic
- vendor event ซ้ำไม่สร้าง attendance ซ้ำ
- external notification ล่มไม่ทำให้คำขอหลัก rollback
- UI ที่ส่งมอบไม่มีข้อความผู้ใช้หลักเป็นภาษาอังกฤษ
