/*
 * personnelDirectory.js — ดึงรายชื่อบุคลากรให้ครบทุกคนสำหรับช่องเลือกชื่อ
 *
 * `/admin/users` ถูกเปลี่ยนเป็นแบ่งหน้าเสมอ (ค่าเริ่มต้น 25 คนต่อหน้า) เพื่อไม่ให้
 * ทะเบียนประวัติทั้งสถานศึกษาถูกส่งออกไปทุกครั้งที่เปิดหน้าจอ — ถูกต้องแล้วสำหรับ
 * "ตารางรายชื่อ" ที่มีปุ่มเลื่อนหน้า
 *
 * แต่หน้าจอที่ใช้รายชื่อเป็น **ช่องเลือก** (บันทึกวันลาแทนบุคลากร, ผู้รับผิดชอบ
 * หน่วยงาน, กำหนดประเภทบุคลากร, ผู้ลงนามในหน้าตั้งค่า) ไม่มีปุ่มเลื่อนหน้า
 * จึงเห็นบุคลากรแค่ 25 คนแรกตามลำดับตัวอักษร คนที่เหลือของวิทยาลัยเลือกไม่ได้เลย
 *
 * helper นี้ไล่ดึงทีละหน้าจนครบ แล้วคืนเป็น array เดียว — ขอบเขตการมองเห็นและ
 * การบันทึก audit ยังเป็นของฝั่ง server เหมือนเดิมทุกประการ
 */
import api from '../axios'

const PAGE_SIZE = 100

/** กันวนไม่รู้จบถ้า server ส่ง last_page ผิดรูป — 100 × 50 = 5,000 คน เกินขนาดสถานศึกษาจริงมาก */
const MAX_PAGES = 50

export async function fetchAllPersonnel(params = {}) {
  const rows = []
  let page = 1
  let lastPage

  do {
    const { data } = await api.get('/admin/users', {
      params: { ...params, per_page: PAGE_SIZE, page },
    })

    // รองรับทั้งรูปแบบแบ่งหน้าและ array ตรง ๆ เผื่อ endpoint เปลี่ยนรูปในอนาคต
    const chunk = Array.isArray(data) ? data : (data?.data ?? [])
    rows.push(...chunk)

    if (Array.isArray(data)) break

    lastPage = Number(data?.last_page ?? data?.meta?.last_page ?? 1) || 1
    page += 1
  } while (page <= lastPage && page <= MAX_PAGES)

  return rows
}
