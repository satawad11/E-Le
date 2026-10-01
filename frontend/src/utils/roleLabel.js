/*
 * ชื่อบทบาท สิทธิ์ และกลุ่มสิทธิ์ ที่แสดงต่อผู้ใช้ — ภาษาไทย 100%
 *
 * บทบาทในฐานข้อมูลมีทั้งชื่อไทยและชื่ออังกฤษปนกันตามยุคที่ถูกสร้าง (`Super Admin`,
 * `SuperAdmin`, `ผู้ดูแลระบบสูงสุด` ล้วนหมายถึงบทบาทเดียวกัน) หน้าจอจึงต้องแปลง
 * ให้เหลือชื่อไทยชุดเดียวเสมอ
 *
 * แยกออกมาเป็น util เพราะทั้งตารางรายชื่อและฟอร์มเพิ่ม/แก้ไขบุคลากรต้องใช้ร่วมกัน
 * — เดิมอยู่ในไฟล์เดียวกัน พอแยกฟอร์มออกเป็นคอมโพเนนต์ก็หลุดหายไปหนึ่งฝั่ง
 *
 * ADMIN-UX — เพิ่ม permissionLabel, groupLabel, groupPermissions, roleDescription
 * เพื่อให้หน้าจอจัดการบทบาทและสิทธิ์แสดงเป็นภาษาไทยทั้งหมด
 * ข้อมูลสำเนาจาก PermissionCatalogSeeder::MODULE_PERMISSIONS
 */
const LABELS = {
  'ผู้ดูแลระบบสูงสุด': 'ผู้ดูแลระบบสูงสุด',
  'Super Admin': 'ผู้ดูแลระบบสูงสุด',
  SuperAdmin: 'ผู้ดูแลระบบสูงสุด',
  'ผู้อำนวยการ': 'ผู้อำนวยการ',
  Director: 'ผู้อำนวยการ',
  'รองผู้อำนวยการ': 'รองผู้อำนวยการ',
  'Deputy Director': 'รองผู้อำนวยการ',
  DeputyDirector: 'รองผู้อำนวยการ',
  // ชื่อย่อที่เคยใช้เฉพาะในหน้าจัดการลำดับการอนุมัติ — ย้ายมารวมที่ชุดกลางชุดเดียว
  Deputy: 'รองผู้อำนวยการ',
  'หัวหน้าแผนกวิชา / หัวหน้าฝ่าย': 'หัวหน้าแผนกวิชา / หัวหน้าฝ่าย',
  HeadOfDepartment: 'หัวหน้าแผนกวิชา / หัวหน้าฝ่าย',
  'เจ้าหน้าที่บุคลากร (HR)': 'เจ้าหน้าที่บุคลากร (HR)',
  HROfficer: 'เจ้าหน้าที่บุคลากร (HR)',
  'HR Head': 'เจ้าหน้าที่บุคลากร (HR)',
  HRHead: 'เจ้าหน้าที่บุคลากร (HR)',
  'เจ้าหน้าที่สารบรรณกลาง': 'เจ้าหน้าที่สารบรรณกลาง',
  SarabanOfficer: 'เจ้าหน้าที่สารบรรณกลาง',
  'Document Officer': 'เจ้าหน้าที่สารบรรณกลาง',
  'บุคลากร / ครู / เจ้าหน้าที่': 'บุคลากร / ครู / เจ้าหน้าที่',
  Employee: 'บุคลากร / ครู / เจ้าหน้าที่',
}

export const roleLabel = (name) => LABELS[name] || name || ''

/**
 * บทบาทมาตรฐานของระบบ — ห้ามลบจากหน้าจอ
 */
export const SYSTEM_ROLES = [
  'SuperAdmin', 'Director', 'Deputy Director', 'DeputyDirector',
  'HeadOfDepartment', 'HROfficer', 'HR Head',
  'SarabanOfficer', 'Document Officer', 'Employee',
]

/**
 * คำอธิบายหน้าที่ของบทบาทมาตรฐาน — ใช้เมื่อ backend ไม่ได้ส่ง description มา
 */
const ROLE_DESCRIPTIONS = {
  SuperAdmin: 'สิทธิ์เต็มรูปแบบ ดูแลระบบทั้งหมด จัดการผู้ใช้ บทบาท สิทธิ์ และตั้งค่าระบบ',
  Director: 'ผู้อำนวยการสถานศึกษา อนุมัติการลาขั้นสุดท้าย ลงนามหนังสือราชการ',
  'Deputy Director': 'รองผู้อำนวยการ ปฏิบัติราชการแทนในขอบเขตที่ได้รับมอบหมาย',
  DeputyDirector: 'รองผู้อำนวยการ ปฏิบัติราชการแทนในขอบเขตที่ได้รับมอบหมาย',
  HeadOfDepartment: 'หัวหน้าแผนกวิชา/หัวหน้าฝ่าย อนุมัติการลาระดับแผนก ดูแลบุคลากรในสังกัด',
  HROfficer: 'เจ้าหน้าที่บุคลากร ตรวจสอบหลักฐานการลา จัดการทะเบียนบุคลากร ออกรายงาน',
  'HR Head': 'เจ้าหน้าที่บุคลากร ตรวจสอบหลักฐานการลา จัดการทะเบียนบุคลากร ออกรายงาน',
  SarabanOfficer: 'เจ้าหน้าที่สารบรรณกลาง ลงทะเบียนรับ-ส่งหนังสือ ออกเลข กระจายเอกสาร',
  'Document Officer': 'เจ้าหน้าที่สารบรรณกลาง ลงทะเบียนรับ-ส่งหนังสือ ออกเลข กระจายเอกสาร',
  Employee: 'บุคลากรทั่วไป ครู เจ้าหน้าที่ ยื่นใบลา ลงเวลา ดูข้อมูลของตนเอง',
}

export const roleDescription = (name) => ROLE_DESCRIPTIONS[name] || ''

/**
 * ชื่อกลุ่มสิทธิ์ภาษาไทย
 */
export const PERMISSION_GROUP_LABELS = {
  leave: 'ระบบการลา',
  attendance: 'ระบบลงเวลา',
  overtime: 'งานล่วงเวลา (OT)',
  document: 'เอกสารและรายงาน',
  saraban: 'งานสารบรรณ',
  system: 'ระบบกลาง',
  admin: 'ผู้ดูแลระบบ',
}

/**
 * คำอธิบายสิทธิ์ภาษาไทย — สำเนาจาก PermissionCatalogSeeder::MODULE_PERMISSIONS
 */
const PERMISSION_LABELS = {
  'leave.view': 'ดูข้อมูลการลา', 'leave.create': 'ยื่นใบลา', 'leave.cancel': 'ขอยกเลิกใบลา',
  'leave.approve': 'อนุมัติใบลา', 'leave.export': 'ส่งออกข้อมูลการลา',
  'leave.hr_verify': 'ตรวจสอบใบลาโดยงานบุคลากร',
  'leave.approval.dept': 'อนุมัติการลาระดับแผนกวิชา/ฝ่าย',
  'leave.approval.final': 'อนุมัติการลาขั้นสุดท้าย',
  'leave.approval.hr': 'ตรวจและกำกับการลาในฐานะงานบุคลากร',
  'leave.management.manage': 'จัดการสิทธิ์วันลาและซิงค์ข้อมูลวันลา',
  'leave.report.view': 'ดูรายงานและสถิติการลา',
  'leave.policy.manage': 'จัดการนโยบายสิทธิการลา',
  'leave.calendar.manage': 'จัดการปฏิทินวันทำการ',
  'leave.evidence.verify': 'ตรวจสอบหลักฐานประกอบการลา',
  'leave.audit.view': 'ดูประวัติการดำเนินการของใบลา',
  'attendance.checkin': 'บันทึกเวลาเข้า-ออกงาน', 'attendance.view_self': 'ดูประวัติการลงเวลาของตนเอง',
  'attendance.view_all': 'ดูรายงานการลงเวลาของบุคลากรทั้งหมด',
  'attendance.export': 'ส่งออกรายงานเวลาเข้า-ออกงาน',
  'attendance.ot': 'บันทึกเวลาทำงานล่วงเวลา', 'attendance.ot_summary': 'ดูสรุป OT ทั้งองค์กร',
  'overtime.operation.execute': 'ออกคำสั่ง OT และลงเวลา',
  'overtime.management.review': 'อนุมัติคำสั่ง OT มอบหมายผู้ปฏิบัติงาน รับรองเวลา',
  'document.template.view': 'ดูแม่แบบเอกสาร', 'document.template.manage': 'จัดการแม่แบบเอกสาร',
  'document.report.view': 'ดูรายงานศูนย์จัดการเอกสาร', 'document.report.manage': 'จัดการนิยามรายงาน',
  'document.generate': 'ออกเอกสารจากระบบ', 'document.final.view': 'ดูเอกสารฉบับสมบูรณ์',
  'document.final.download': 'ดาวน์โหลดเอกสารฉบับสมบูรณ์', 'document.audit.view': 'ดูประวัติการออกเอกสาร',
  'saraban.document.view': 'ดูหนังสือและทะเบียนเอกสาร', 'saraban.document.create': 'สร้างหนังสือ',
  'saraban.document.update': 'แก้ไขหนังสือที่ยังไม่ออกเลข', 'saraban.document.submit': 'เสนอหนังสือเข้าสู่กระบวนการ',
  'saraban.incoming.register': 'ลงทะเบียนรับหนังสือ', 'saraban.incoming.stamp': 'ประทับตรารับหนังสือ',
  'saraban.number.reserve': 'จองเลขหนังสือ', 'saraban.number.allocate': 'ออกเลขหนังสือ',
  'saraban.number.release': 'คืนเลขหนังสือที่จองไว้', 'saraban.number.cancel': 'ยกเลิกเลขหนังสือ',
  'saraban.workflow.review': 'ตรวจและดำเนินการหนังสือตามสายงาน',
  'saraban.workflow.approve': 'พิจารณาและเห็นชอบหนังสือก่อนเสนอลงนาม',
  'saraban.workflow.forward': 'ส่งหนังสือต่อไปยังขั้นถัดไป', 'saraban.workflow.return': 'ส่งหนังสือกลับไปแก้ไข',
  'saraban.workflow.simulate': 'จำลองเส้นทางเดินหนังสือ',
  'saraban.workflow.manage': 'จัดการเวอร์ชันสายงานเดินหนังสือ',
  'saraban.endorse.create': 'เกษียนหนังสือ', 'saraban.signature.sign': 'ลงนามในหนังสือ',
  'saraban.signature.revoke': 'ยื่นคำขอเพิกถอนลายเซ็น',
  'saraban.signature.revoke_approve': 'อนุมัติ/ปฏิเสธคำขอเพิกถอน',
  'saraban.distribution.create': 'จัดทำรายการกระจายหนังสือ',
  'saraban.distribution.send': 'ส่งหนังสือถึงผู้รับ', 'saraban.distribution.track': 'ติดตามการรับทราบ',
  'saraban.inbox.view': 'เปิดกล่องหนังสือเวียนถึงตนเอง',
  'saraban.confidential.view': 'เปิดหนังสือชั้นลับ', 'saraban.secret.view': 'เปิดหนังสือชั้นลับมาก',
  'saraban.top_secret.view': 'เปิดหนังสือชั้นลับที่สุด',
  'saraban.report.view': 'ดูรายงานงานสารบรรณ', 'saraban.report.export': 'ส่งออกรายงานงานสารบรรณ',
  'saraban.audit.view': 'ดูประวัติการดำเนินการของหนังสือ',
  'saraban.policy.manage': 'จัดการนโยบายงานสารบรรณ', 'saraban.authority.view': 'ดูตารางอำนาจหน้าที่',
  'saraban.config.manage': 'ตั้งค่าทะเบียนหนังสือ',
  'system.exception.view': 'ดูรายการข้อผิดพลาด', 'system.exception.manage': 'มอบหมายและปิดรายการข้อผิดพลาด',
  'system.operations.view': 'ดูศูนย์ปฏิบัติการระบบ', 'system.operations.retry': 'สั่งประมวลผลซ้ำ',
  'system.feature_flags.manage': 'เปิด-ปิดความสามารถของระบบ',
  'system.manage': 'สิทธิ์ผู้ดูแลระบบสูงสุด', 'system.user.view': 'ดูรายการผู้ใช้งาน',
  'system.user.manage': 'เพิ่ม/แก้ไข/ระงับผู้ใช้งาน', 'system.role.manage': 'จัดการบทบาทและสิทธิ์',
  'system.menu.manage': 'ตั้งค่าสิทธิ์เมนู', 'system.setting.manage': 'ตั้งค่าระบบและข้อมูลสถานศึกษา',
  'admin.users.manage_permissions': 'กำหนดสิทธิ์รายบุคคล',
  view_users: 'ดูรายชื่อผู้ใช้งาน', edit_users: 'แก้ไขผู้ใช้งาน', delete_users: 'ลบผู้ใช้งาน',
  manage_settings: 'ตั้งค่าระบบ', manage_departments: 'จัดการหน่วยงาน',
  manage_leave_types: 'จัดการประเภทการลา', manage_workflow_steps: 'จัดการลำดับการอนุมัติ',
  approve_leave: 'อนุมัติใบลา', view_all_attendances: 'ดูการลงเวลาทั้งหมด', export_reports: 'ส่งออกรายงาน',
}

export const permissionLabel = (code) => PERMISSION_LABELS[code] || code || ''

export const groupLabel = (prefix) => {
  const lower = (prefix || '').toLowerCase()
  return PERMISSION_GROUP_LABELS[lower] || prefix || 'ทั่วไป'
}

/**
 * จัดกลุ่มรายการสิทธิ์ตามโมดูล พร้อมชื่อกลุ่มภาษาไทย
 * ใช้ร่วมกันทั้ง RolesManagementView และ UserFormModal
 */
export const groupPermissions = (permissions) => {
  const groups = {}
  ;(permissions || []).forEach((p) => {
    let prefix = 'general'
    if (p.name.includes('.')) {
      prefix = p.name.split('.')[0]
    } else if (p.name.includes('_')) {
      prefix = p.name.split('_')[0]
    }
    const label = groupLabel(prefix)
    if (!groups[label]) groups[label] = []
    groups[label].push(p)
  })
  return groups
}
