# กรณีทดสอบ (UAT Test Cases) - E-la v1.0

*หมายเหตุ: คอลัมน์ Actual Result, PASS/FAIL, Tester, Date เว้นว่างไว้เพื่อให้ผู้ทดสอบจริงเป็นผู้บันทึก*

## 1. Authentication & Profile
| Test ID | Role | Preconditions | Steps | Expected Result | Actual Result | PASS/FAIL | Severity | Tester | Date |
|---|---|---|---|---|---|---|---|---|---|
| AUTH-01 | บุคลากรทั่วไป | มีบัญชีที่เปิดใช้งานแล้ว | 1. เข้าสู่ระบบผ่าน Desktop<br>2. กรอก Username/Password ที่ถูกต้อง | เข้าสู่ระบบสำเร็จและแสดงหน้า Dashboard | | | CRITICAL | | |
| AUTH-02 | บุคลากรทั่วไป | เปิดหน้าเว็บผ่าน Mobile | 1. เข้าสู่ระบบผ่าน Mobile<br>2. พยายามกดเมนูต่างๆ | หน้าจอแสดงผลแบบ Responsive เมนูไม่บังเนื้อหา | | | HIGH | | |
| PROF-01 | บุคลากรทั่วไป | อยู่หน้า Profile | 1. เข้าเมนูประวัติส่วนตัว<br>2. อัปโหลดรูปลายเซ็น (PNG) | อัปโหลดสำเร็จและรูปแสดงในช่องลายเซ็น | | | HIGH | | |

## 2. Attendance & OT
| Test ID | Role | Preconditions | Steps | Expected Result | Actual Result | PASS/FAIL | Severity | Tester | Date |
|---|---|---|---|---|---|---|---|---|---|
| ATT-01 | บุคลากรทั่วไป | อยู่ในเครือข่ายที่อนุญาต | 1. กดปุ่ม Check-in เวลา 08:00 น. | ระบบบันทึกเวลา 08:00 น. สำเร็จ | | | CRITICAL | | |
| ATT-02 | เจ้าหน้าที่บุคลากร | มีข้อมูลลงเวลาผิดพลาดของพนักงาน A | 1. แก้ไขเวลาเข้างานพนักงาน A เป็น 08:20 น.<br>2. ใส่เหตุผลประกอบ | ระบบบันทึกเวลาใหม่ พร้อมสร้าง Audit trail | | | HIGH | | |
| OT-01 | หัวหน้างาน | มีคำสั่งล่วงเวลาที่ต้องอนุมัติ | 1. เข้าเมนูคำสั่งล่วงเวลา<br>2. กดอนุมัติคำสั่ง | สถานะเปลี่ยนเป็น Approved พนักงานที่เกี่ยวข้องได้รับการแจ้งเตือน | | | HIGH | | |

## 3. Leave & Approval
| Test ID | Role | Preconditions | Steps | Expected Result | Actual Result | PASS/FAIL | Severity | Tester | Date |
|---|---|---|---|---|---|---|---|---|---|
| LEV-01 | บุคลากรทั่วไป | มีโควตาวันลาพักผ่อน > 1 วัน | 1. ยื่นลาพักผ่อน 1 วันล่วงหน้า<br>2. กดส่งใบลา | ใบลาถูกสร้างสถานะ Pending และถูกส่งหาหัวหน้างาน | | | CRITICAL | | |
| LEV-02 | หัวหน้างาน | มีใบลาใน Inbox | 1. เข้าเมนู Approval Inbox<br>2. กดอนุมัติใบลาของลูกน้อง | ใบลาไปสู่ Step ถัดไป (หรือ Approved) พร้อมประทับลายเซ็น | | | CRITICAL | | |
| LEV-03 | บุคลากรทั่วไป | มีใบลารออนุมัติ (Pending) | 1. กดปุ่มยกเลิกใบลาของตนเอง | สถานะใบลาเปลี่ยนเป็น Cancelled คืนสิทธิ์วันลา | | | MEDIUM | | |

## 4. Delegation
| Test ID | Role | Preconditions | Steps | Expected Result | Actual Result | PASS/FAIL | Severity | Tester | Date |
|---|---|---|---|---|---|---|---|---|---|
| DEL-01 | ผู้อำนวยการ | มีแผนลาราชการ | 1. เข้าเมนู Delegation<br>2. เลือกมอบอำนาจให้รอง ผอ.<br>3. กำหนดวันที่มอบหมาย | รอง ผอ. จะเห็นเอกสารรออนุมัติของ ผอ. ในช่วงเวลาดังกล่าว | | | HIGH | | |

## 5. Saraban
| Test ID | Role | Preconditions | Steps | Expected Result | Actual Result | PASS/FAIL | Severity | Tester | Date |
|---|---|---|---|---|---|---|---|---|---|
| SAR-01 | เจ้าหน้าที่สารบรรณ | มีหนังสือรับเข้า | 1. ออกเลขรับหนังสือ<br>2. แนบไฟล์ PDF<br>3. ส่งต่อให้ ผอ. | ระบบออกเลข Running แทรกปี พ.ศ. และส่งแจ้งเตือน ผอ. | | | CRITICAL | | |
| SAR-02 | ผู้อำนวยการ | มีหนังสือสารบรรณส่งมาถึง | 1. กดลงนามรับทราบ<br>2. เกษียณหนังสือส่งต่อหัวหน้างาน | เอกสารประทับลายเซ็น ผอ. แบบ e-Signature พร้อมส่งต่อ | | | CRITICAL | | |

## 6. Reports & Notifications
| Test ID | Role | Preconditions | Steps | Expected Result | Actual Result | PASS/FAIL | Severity | Tester | Date |
|---|---|---|---|---|---|---|---|---|---|
| REP-01 | เจ้าหน้าที่บุคลากร | เข้าเมนูรายงาน | 1. เลือกรายงานสรุปวันลาประจำเดือน<br>2. กดส่งออกเป็น PDF/Excel | โหลดไฟล์รายงานสำเร็จ ข้อมูลครบถ้วน | | | HIGH | | |
| NOT-01 | บุคลากรทั่วไป | มีคนส่งเอกสารมาให้ | 1. สังเกต In-App Notification<br>2. ตรวจสอบ LINE OA (ถ้าผูกไว้) | ได้รับแจ้งเตือนทันที | | | MEDIUM | | |

## 7. Negative Tests (Permissions & Organization Scope)
| Test ID | Role | Preconditions | Steps | Expected Result | Actual Result | PASS/FAIL | Severity | Tester | Date |
|---|---|---|---|---|---|---|---|---|---|
| NEG-01 | บุคลากรทั่วไป | รู้ URL เมนูสารบรรณ | 1. พิมพ์ URL `/saraban/documents` ตรงๆ ใน Browser | ระบบแสดง 403 Forbidden และไม่ให้เข้าถึง | | | BLOCKER | | |
| NEG-02 | หัวหน้างาน (หน่วย A) | รู้ ID ใบลาของลูกน้องหน่วย B | 1. เรียก API GET `/api/leaves/{id_ของหน่วย_B}` โดยตรง | ระบบแสดง 403 Forbidden (ป้องกัน IDOR) | | | BLOCKER | | |
| NEG-03 | บุคลากรทั่วไป | ต้องการแก้เวลาเข้างานตัวเอง | 1. เรียก API POST `/api/attendances/correction` เพื่อแก้ข้อมูลตนเองโดยตรงไม่อนุมัติ | ระบบแสดง 403 Forbidden หรือปฏิเสธ Request | | | BLOCKER | | |
| NEG-04 | บุคลากรทั่วไป | ลาเกินโควตา | 1. สร้างคำขอลาพักผ่อนเกินสิทธิ์ที่มีอยู่ | ระบบแสดง 422 อนุมัติไม่ได้ (วันลาไม่พอ) | | | HIGH | | |
