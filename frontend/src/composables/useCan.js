import { usePermissionStore } from '../stores/permissions'

/**
 * useCan — ถามสิทธิ์ในสคริปต์ของหน้า (R-06)
 *
 *   const { can, canAny, canBeyondOwn } = useCan()
 *   can('personnel.profile.edit')            มีอย่างน้อยหนึ่ง scope
 *   can('personnel.profile.view', 'subtree') มี scope ≥ subtree
 *   canBeyondOwn('personnel.profile.reveal') มีกับคนอื่น ไม่ใช่เฉพาะของตนเอง
 *
 * ใช้แสดง/ซ่อนเท่านั้น — server ตรวจซ้ำทุกครั้ง · สิทธิ์ระดับรายการ (เช่น ปุ่มต่อแถว) ใช้ค่า `abilities`/`can` ที่ API คืนมา
 */
export function useCan() {
  const store = usePermissionStore()

  return {
    can: (name, minScope = null) => store.can(name, minScope),
    canAny: (names, minScope = null) => store.canAny(names, minScope),
    canBeyondOwn: (name) => store.canBeyondOwn(name),
    scopesOf: (name) => store.scopesOf(name),
    ready: () => store.load(),
  }
}

export default useCan
