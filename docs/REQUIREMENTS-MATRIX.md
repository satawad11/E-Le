# REQUIREMENTS MATRIX (REBUILD-2569)

| ID | ความต้องการ | ที่มา | เฟส |
|---|---|---|---|
| REQ-ORG-01 | ต้นไม้หน่วยงาน ระดับ L1–L4 ตามประเภทหน่วย ความลึกไม่เท่ากันได้ | Plan §3.1 | R-02, R-03 |
| REQ-ORG-02 | ตำแหน่งบริหารผูกกับประเภทหน่วย | CORE-01 | R-03 |
| REQ-ORG-03 | ย้าย/ปิดหน่วยโดยไม่ลบประวัติ | Rules 10 | R-03 |
| REQ-PER-01 | ข้อมูลบุคลากรครบหมวด (พื้นฐาน, การศึกษา, อบรม, ใบอนุญาต, เอกสาร) | CORE-02 | R-03, R-06 |
| REQ-PER-02 | assignment 4 ประเภท มีเลขคำสั่งและวันที่มีผล คนเดียวหลายตำแหน่ง | Plan §3.2 | R-03 |
| REQ-PER-03 | ชั้นความลับฟิลด์ 4 ชั้น ปิดบังตามสิทธิ์ | Rules 11 | R-03, R-06 |
| REQ-PER-04 | เปิดดูข้อมูลอ่อนไหวต้องระบุเหตุผล ถูกบันทึก เจ้าของดูได้ | Rules 11–12 | R-06 |
| REQ-PER-05 | คำขอแก้ไขข้อมูล + Maker–Checker | CORE-02 | R-06 |
| REQ-PER-06 | นำเข้าข้อมูลบุคลากรจาก Excel พร้อมรายงานข้อผิดพลาด | Plan R-03 | R-03 |
| REQ-AZ-01 | Permission จาก manifest เท่านั้น + `modules:sync` | Rules 5 | R-04 |
| REQ-AZ-02 | สิทธิ์ = (permission, scope) ต่อ assignment แล้ว union | Rules 3 | R-04 |
| REQ-AZ-03 | Deny by default, override deny ชนะ, มีวันหมดอายุ | Rules 4, 9 | R-04 |
| REQ-AZ-04 | กฎกลาง not_own, maker_checker, hr_field_guard, grant_ceiling, two_person | Rules 6 | R-04 |
| REQ-AZ-05 | ไม่มีบัญชีข้ามการตรวจ ผู้ดูแลระบบไม่อนุมัติ ไม่เห็นข้อมูลอ่อนไหว | Rules 8 | R-02, R-04 |
| REQ-AZ-06 | Permission Simulator (`explain`) | Plan §3.6 | R-04, R-07 |
| REQ-AZ-07 | หน้าตั้งค่าสิทธิ์รายโมดูล + สรุปเป็นภาษาคน + ผลกระทบก่อนบันทึก | ต้นแบบ 10 | R-07 |
| REQ-MENU-01 | เมนูจาก manifest กรองตามสิทธิ์ ชื่อตาม scope badge ตามขอบเขต | Plan §8 | R-04 |
| REQ-ACC-01 | สถานะบัญชี 5 แบบ + JML อัตโนมัติ | CORE-03 | R-05 |
| REQ-ACC-02 | MFA TOTP บังคับกลุ่มสิทธิ์สูง | CORE-03 | R-05 |
| REQ-ACC-03 | ทบทวนสิทธิ์พิเศษรายภาคเรียน ไม่ยืนยัน = ถอน | Plan §3.6 | R-05 |
| REQ-WF-01 | สายอนุมัติตามระดับหน่วย ไม่มี fallback ตามชื่อ role | Rules 7 | R-04, R-08 |
| REQ-WF-02 | ข้ามผู้ยื่นและชั้นว่าง รวมชั้นที่เป็นคนเดียวกัน บันทึกตำแหน่งที่ลงนาม | CORE-05 | R-08 |
| REQ-AUD-01 | Audit แยก data access / permission change / login แบบ append-only | Rules 12 | R-02, R-07 |
| REQ-MOD-01 | โมดูลลา ลงเวลา OT สารบรรณ แจ้งเตือน ใช้ core ใหม่ทั้งหมด | Plan R-08 | R-08 |
