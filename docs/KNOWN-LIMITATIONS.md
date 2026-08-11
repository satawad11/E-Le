# KNOWN LIMITATIONS / OPEN ITEMS
- รายละเอียดกฎหมาย/ระเบียบสิทธิการลาและเกณฑ์เลื่อนเงินเดือนต้องนำเข้าผ่าน Policy ที่ได้รับการตรวจสอบจากหน่วยงานก่อน production
- Connector เครื่องสแกนจริงต้องปรับตาม API/DB/file format ของผู้ผลิตที่ใช้งาน
- E-signature ที่มีผลตามกฎหมายอาจต้องเชื่อม provider/PKI เพิ่มเติม
- Multi-tenant แยกฐานข้อมูลไม่อยู่ใน initial scope; ออกแบบ organization-ready ก่อน
