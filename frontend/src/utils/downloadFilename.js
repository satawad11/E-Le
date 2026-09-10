/*
 * ชื่อไฟล์ของเอกสารที่ผู้ใช้ดาวน์โหลด
 *
 * เซิร์ฟเวอร์เป็นผู้ตัดสินชื่อไฟล์เสมอ (`App\Support\DocumentFilename`) หน้าจอมีหน้าที่
 * อ่านจาก `Content-Disposition` ให้ถูกต้อง — ต้องอ่าน `filename*` ก่อน เพราะชื่อไฟล์ไทย
 * อยู่ในนั้น ส่วน `filename` ธรรมดาเป็นชื่อสำรอง ASCII ที่มีไว้ให้ตัวอ่านรุ่นเก่า
 *
 * ชื่อสำรองฝั่งหน้าจอใช้เมื่อคำตอบไม่มี header นี้เท่านั้น (เช่น proxy ตัดทิ้ง)
 */

/** อักขระที่ระบบไฟล์ห้ามใช้ รวมช่องว่างทุกชนิด — ให้ผลตรงกับตัวช่วยฝั่งเซิร์ฟเวอร์ */
const FORBIDDEN = /(?:[/\\:*?"<>|]|\s)+/g

/** ความยาวสูงสุดของชื่อไฟล์ ไม่รวมนามสกุล */
const MAX_LENGTH = 150

export const sanitizeFilenamePart = (value) =>
  String(value ?? '')
    .trim()
    .replace(FORBIDDEN, '_')
    .replace(/_+/g, '_')
    .replace(/^_|_$/g, '')

/** ประกอบชื่อไฟล์จากส่วนต่าง ๆ โดยข้ามส่วนที่ว่าง */
export const buildFilename = (parts, extension) => {
  const name = parts.map(sanitizeFilenamePart).filter(Boolean).join('_') || 'เอกสาร'

  return `${name.slice(0, MAX_LENGTH)}.${extension}`
}

/** ชื่อไฟล์ที่เซิร์ฟเวอร์ประกาศไว้ — คืนค่าว่างเมื่อไม่มี header ให้อ่าน */
export const filenameFromHeaders = (headers) => {
  const disposition = headers?.['content-disposition'] || ''
  const utf8 = /filename\*=UTF-8''([^;]+)/i.exec(disposition)
  if (utf8) {
    try {
      return decodeURIComponent(utf8[1].trim().replace(/^"|"$/g, ''))
    } catch {
      // เปอร์เซ็นต์เข้ารหัสเสียหาย — ตกไปใช้ชื่อสำรองด้านล่างแทนที่จะพังทั้งการดาวน์โหลด
    }
  }

  const plain = /filename="?([^";]+)"?/i.exec(disposition)

  return plain ? plain[1].trim() : ''
}
