import { fetchObjectUrl, revokeObjectUrl } from '../utils/secureFile';

/**
 * แสดงรูปที่เก็บอยู่บน private disk โดยดึงผ่าน endpoint ที่ตรวจสอบสิทธิ์
 *
 * ใช้แทน :src="getImageUrl(path)" ที่ชี้ไป /storage/... ตรง ๆ
 *
 *   <img v-secure-src="user.profile.signature_path" />
 *
 * <img src> แนบ Authorization header ไม่ได้ จึงต้องดึงเป็น blob แล้วค่อยใส่ object URL
 */
async function load(el, stored) {
  // คืนหน่วยความจำของรูปก่อนหน้าเสมอ
  revokeObjectUrl(el.dataset.secureObjectUrl);
  delete el.dataset.secureObjectUrl;

  if (!stored) {
    el.removeAttribute('src');
    return;
  }

  // กัน race เมื่อค่าเปลี่ยนเร็ว ๆ — ใช้เฉพาะผลของการโหลดครั้งล่าสุด
  const token = String(Date.now() + Math.random());
  el.dataset.secureLoadToken = token;

  try {
    const url = await fetchObjectUrl(stored);
    if (el.dataset.secureLoadToken !== token) {
      revokeObjectUrl(url);
      return;
    }
    el.src = url;
    el.dataset.secureObjectUrl = url;
  } catch {
    // ไฟล์หาย/ไม่มีสิทธิ์ — ปล่อยว่างไว้ ให้ fallback ของหน้าจอทำงาน
    el.removeAttribute('src');
  }
}

export const secureSrcDirective = {
  mounted(el, binding) {
    load(el, binding.value);
  },
  updated(el, binding) {
    if (binding.value !== binding.oldValue) load(el, binding.value);
  },
  unmounted(el) {
    revokeObjectUrl(el.dataset.secureObjectUrl);
  },
};

export default secureSrcDirective;
