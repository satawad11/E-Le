import api from '../axios';

/**
 * ดาวน์โหลดไฟล์อัปโหลดผ่าน endpoint ที่ตรวจสอบสิทธิ์
 *
 * เดิมหน้าเว็บชี้ <img src> ไปที่ /storage/... ตรง ๆ ซึ่งเปิดให้ทุกคนบนอินเทอร์เน็ต
 * เข้าถึงลายเซ็นและภาพใบหน้าบุคลากรได้ (SEC-001) — ตอนนี้ไฟล์อยู่บน private disk
 * และต้องเรียกผ่าน GET /api/files/{path} พร้อม Bearer token เท่านั้น
 */

/** แปลงค่าที่เก็บใน DB ("/storage/signatures/x.png") เป็น path เทียบกับ endpoint */
function toRelative(stored) {
  if (!stored) return '';
  return String(stored).replace(/^\/?storage\//, '').replace(/^\//, '');
}

/** โหลดไฟล์เป็น object URL — อย่าลืมเรียก revokeObjectUrl เมื่อเลิกใช้ */
export async function fetchObjectUrl(stored) {
  const relative = toRelative(stored);
  if (!relative) return '';

  const res = await api.get(`/files/${relative}`, { responseType: 'blob' });
  return URL.createObjectURL(res.data);
}

export function revokeObjectUrl(url) {
  if (url && url.startsWith('blob:')) URL.revokeObjectURL(url);
}

/** เปิดไฟล์แนบในแท็บใหม่ (ใช้แทน <a href> ที่ชี้ /storage ตรง ๆ) */
export async function openSecureFile(stored) {
  const url = await fetchObjectUrl(stored);
  if (!url) return;

  window.open(url, '_blank', 'noopener');
  // ปล่อยให้เบราว์เซอร์อ่านเสร็จก่อนค่อยคืนหน่วยความจำ
  setTimeout(() => revokeObjectUrl(url), 60_000);
}
