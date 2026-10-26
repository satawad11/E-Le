import { watchEffect } from 'vue'
import { usePermissionStore } from '../stores/permissions'

/**
 * v-can — ซ่อนปุ่ม/แท็บตามสิทธิ์จาก /api/me/permissions (R-06)
 *
 *   v-can="'personnel.profile.edit'"
 *   v-can="['personnel.report.view', 'personnel.import.manage']"      อย่างใดอย่างหนึ่ง
 *   v-can="{ any: ['personnel.profile.reveal'], scope: 'subtree' }"     scope ขั้นต่ำ
 *   v-can:others="'personnel.profile.reveal'"                          ต้องมีกับคนอื่น ไม่ใช่เฉพาะของตนเอง
 *
 * ซ่อนด้วย display:none และติดตามการเปลี่ยนแปลงของสิทธิ์ (โหลดเสร็จทีหลังก็แสดงถูก)
 * การซ่อนไม่ใช่การป้องกัน — server ตรวจซ้ำทุก endpoint
 */
function allowed(store, binding) {
  const value = binding.value
  if (!value) return true
  const spec = typeof value === 'string' ? { any: [value] } : Array.isArray(value) ? { any: value } : value
  const names = spec.any || []
  if (binding.arg === 'others') return names.some((n) => store.canBeyondOwn(n))
  return store.canAny(names, spec.scope || null)
}

export const canDirective = {
  mounted(el, binding) {
    const store = usePermissionStore()
    const original = el.style.display
    el.__vCanStop = watchEffect(() => {
      el.style.display = allowed(store, el.__vCanBinding || binding) ? original : 'none'
    })
    el.__vCanBinding = binding
  },
  updated(el, binding) {
    el.__vCanBinding = binding
    const store = usePermissionStore()
    el.style.display = allowed(store, binding) ? '' : 'none'
  },
  unmounted(el) {
    el.__vCanStop?.()
  },
}

export default canDirective
