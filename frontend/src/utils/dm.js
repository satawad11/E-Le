/**
 * ตัวช่วยแสดงผลของหน้าจอบุคลากร (R-06) — การแสดงผลเท่านั้น ไม่มีการตัดสินสิทธิ์หรือปิดบังข้อมูลที่นี่
 */
const DATE = new Intl.DateTimeFormat('th-TH', { day: 'numeric', month: 'short', year: 'numeric' })
const DATE_LONG = new Intl.DateTimeFormat('th-TH', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
const TIME = new Intl.DateTimeFormat('th-TH', { hour: '2-digit', minute: '2-digit' })

export const thaiDate = (v) => (v ? DATE.format(new Date(v)) : '')
export const thaiDateLong = (v) => (v ? DATE_LONG.format(new Date(v)) : '')
export const thaiDateTime = (v) => (v ? `${DATE.format(new Date(v))} ${TIME.format(new Date(v))}` : '')

export const KIND_LABELS = { primary: 'ตำแหน่งหลัก', management: 'ตำแหน่งบริหาร', acting: 'รักษาการ', duty: 'หน้าที่มอบหมาย', grant: 'ชุดสิทธิ์ที่ได้รับมอบ' }
export const STATUS_LABELS = { pending: ['รอเริ่มงาน', 'info'], active: ['ปฏิบัติงาน', 'ok'], study_leave: ['ลาศึกษาต่อ', 'warn'], seconded: ['ช่วยราชการ', 'info'], suspended: ['พักราชการ', 'bad'], separated: ['พ้นสภาพ', 'mute'] }
export const CLASS_LABELS = { public: ['สาธารณะ', 'mute'], internal: ['ภายใน', 'info'], confidential: ['ลับ', 'warn'], sensitive: ['อ่อนไหว (PDPA)', 'bad'] }
export const ENTITY_LABELS = { personnel: 'ข้อมูลทั่วไป', personnel_profile: 'ข้อมูลส่วนตัว', personnel_education: 'วุฒิการศึกษา', personnel_training: 'ประวัติการอบรม', personnel_license: 'ใบอนุญาตประกอบวิชาชีพ' }
export const REQUEST_STATUS = { pending: ['รอตรวจ', 'warn'], approved: ['อนุมัติแล้ว', 'ok'], returned: ['ส่งกลับ', 'info'], rejected: ['ไม่อนุมัติ', 'bad'] }

/** ชื่อฟิลด์ภาษาไทย (ชื่อฟิลด์มาจาก API · ที่นี่แปลเป็นข้อความเท่านั้น) */
export const FIELD_LABELS = {
  personnel_code: 'รหัสบุคลากร', title: 'คำนำหน้า', first_name: 'ชื่อ', last_name: 'นามสกุล', first_name_en: 'ชื่อ (อังกฤษ)', last_name_en: 'นามสกุล (อังกฤษ)',
  personnel_type_id: 'ประเภทบุคลากร', academic_standing: 'วิทยฐานะ', status: 'สถานะ', started_at: 'วันที่เริ่มปฏิบัติงาน', separated_at: 'วันที่พ้นสภาพ',
  separation_reason: 'เหตุพ้นสภาพ', photo_path: 'รูปถ่าย',
  email_work: 'อีเมลวิทยาลัย', phone_mobile: 'โทรศัพท์มือถือ', birth_date: 'วันเกิด', emergency_contact_name: 'ผู้ติดต่อฉุกเฉิน',
  emergency_contact_phone: 'โทรศัพท์ผู้ติดต่อฉุกเฉิน', emergency_contact_relation: 'ความเกี่ยวข้อง', national_id: 'เลขประจำตัวประชาชน',
  address_registered: 'ที่อยู่ตามทะเบียนบ้าน', address_current: 'ที่อยู่ปัจจุบัน', bank_name: 'ธนาคารรับเงินเดือน', bank_account: 'เลขที่บัญชีรับเงินเดือน', health_notes: 'ข้อมูลสุขภาพ',
  level: 'ระดับการศึกษา', degree: 'ชื่อปริญญา', major: 'สาขาวิชา', institution: 'สถาบัน', graduated_year: 'ปีที่สำเร็จ (พ.ศ.)',
  course: 'หลักสูตร', organizer: 'ผู้จัด', hours: 'ชั่วโมง', start_date: 'วันเริ่ม', end_date: 'วันสิ้นสุด',
  license_type: 'ประเภทใบอนุญาต', license_number: 'เลขที่ใบอนุญาต', issued_at: 'วันออก', expires_at: 'วันหมดอายุ',
}
export const fieldLabel = (f) => FIELD_LABELS[f] || f

export const levelOf = (assignment) => (assignment.kind === 'primary' ? 5 : assignment.level)

/** ข้อความจาก error ของ API ที่แสดงผู้ใช้ได้ (ไม่แสดงข้อความทางเทคนิค) */
export function apiMessage(e, fallback = 'ดำเนินการไม่สำเร็จ กรุณาลองใหม่') {
  const data = e?.response?.data
  if (data?.errors) {
    const first = Object.values(data.errors)[0]
    if (Array.isArray(first) && first[0]) return first[0]
  }
  const status = e?.response?.status
  if (status === 404) return 'ไม่พบข้อมูล หรือข้อมูลนี้อยู่นอกขอบเขตสิทธิ์ของคุณ'
  if (status === 403) return data?.message && !/exception|sql/i.test(data.message) ? data.message : 'คุณไม่มีสิทธิ์ทำรายการนี้'
  if (status === 429) return 'ทำรายการถี่เกินไป กรุณารอสักครู่'
  if (status >= 500 || !status) return fallback
  return data?.message || fallback
}
