# CLAUDE.md — Claude Code Project Instructions

ปฏิบัติตาม `AGENTS.md` และ `PROJECT_RULES.md` เป็นข้อบังคับ

## Work style
- สำรวจโค้ดเดิมก่อนแก้
- เปลี่ยนเท่าที่จำเป็นและรักษา architecture
- หาก requirement ขัดกัน ให้ยึดลำดับ: Security > Data integrity > Master Blueprint > Module spec > UI preference
- ใช้ migration เท่านั้นในการเปลี่ยน schema
- สร้าง automated test สำหรับ business rule และ authorization ทุกครั้ง

## Stop conditions
หยุดและรายงานก่อนทำต่อเมื่อ:
- ต้องเปลี่ยน architecture หลัก
- พบ schema conflict ที่อาจทำข้อมูลสูญหาย
- กฎราชการยังไม่มี policy/version ที่ชัดเจน
- checkpoint ไม่ผ่าน

## Completion
ห้ามประกาศว่าเสร็จ หาก Definition of Done ของ module ยังไม่ครบ
