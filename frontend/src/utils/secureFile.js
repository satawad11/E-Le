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

/**
 * เปิด/ดาวน์โหลดรายงานที่ต้องยืนยันตัวตน (SEC-009)
 *
 * เดิมหน้าเว็บใช้ window.open พร้อมแนบ Sanctum token ทาง query string
 * ทำให้ token ไปโผล่ใน access log, referrer และ browser history
 * ตอนนี้ยิงผ่าน axios ซึ่งแนบ Authorization header ให้แล้ว รับกลับมาเป็น blob
 *
 * ถ้า response มี Content-Disposition: attachment จะดาวน์โหลดพร้อมชื่อไฟล์เดิม
 * ถ้าไม่มี (เช่นหน้า preview ที่เป็น HTML) จะเปิดในแท็บใหม่
 */
export async function openAuthenticatedReport(path, params = {}) {
  const res = await api.get(path, { params, responseType: 'blob' });

  const disposition = res.headers['content-disposition'] || '';
  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition);
  const filename = match ? decodeURIComponent(match[1]) : '';

  const url = URL.createObjectURL(res.data);

  if (filename) {
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
  } else {
    window.open(url, '_blank', 'noopener');
  }

  setTimeout(() => revokeObjectUrl(url), 60_000);
}

/** แปลง error จาก openAuthenticatedReport เป็นข้อความไทยที่ผู้ใช้เข้าใจได้ */
export function reportErrorMessage(error) {
  const status = error?.response?.status;

  if (status === 401) return 'เซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่';
  if (status === 403) return 'คุณไม่มีสิทธิ์ออกรายงานนี้';

  return 'ออกรายงานไม่สำเร็จ กรุณาลองใหม่อีกครั้ง';
}
