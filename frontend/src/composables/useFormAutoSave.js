import { ref, watch, onMounted, onBeforeUnmount, getCurrentInstance } from 'vue'

/**
 * useFormAutoSave — Composable สำหรับบันทึกข้อมูลแบบฟอร์มอัตโนมัติลงใน LocalStorage
 *
 * ช่วยป้องกันข้อมูลสูญหายเมื่อผู้ใช้งานเผลอปิดแท็บ หรือเน็ตหลุด
 *
 * @param {string} storageKey - คีย์เฉพาะสำหรับบันทึกข้อมูล เช่น 'ela_draft_leave_request_123'
 * @param {import('vue').Ref|object} formRef - reactive object หรือ ref ของข้อมูลฟอร์ม
 * @param {object} options - การตั้งค่าเพิ่มเติม
 * @param {number} [options.debounceMs=1500] - หน่วงเวลาบันทึก (มิลลิวินาที)
 * @param {Function} [options.onRestore] - Callback เมื่อผู้ใช้กู้คืนร่าง
 * @param {Function} [options.isFormPopulated] - ฟังก์ชันตรวจว่าฟอร์มมีข้อมูลจริงหรือไม่
 */
export function useFormAutoSave(storageKey, formRef, options = {}) {
  const debounceMs = options.debounceMs ?? 1500
  const hasDraft = ref(false)
  const savedDraftData = ref(null)
  const lastSavedAt = ref('')
  const isSaving = ref(false)
  let timer = null

  const formatThaiTime = (isoString) => {
    if (!isoString) return ''
    try {
      const d = new Date(isoString)
      return d.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
    } catch {
      return ''
    }
  }

  const checkExistingDraft = () => {
    try {
      const raw = localStorage.getItem(storageKey)
      if (raw) {
        const parsed = JSON.parse(raw)
        if (parsed && parsed.data) {
          savedDraftData.value = parsed.data
          hasDraft.value = true
          lastSavedAt.value = formatThaiTime(parsed.savedAt)
          return true
        }
      }
    } catch {
      // Ignored
    }
    hasDraft.value = false
    savedDraftData.value = null
    return false
  }

  // ตรวจสอบข้อมูลร่างเดิมทันทีตั้งแต่สร้าง composable
  checkExistingDraft()

  const saveToStorage = () => {
    try {
      isSaving.value = true
      const now = new Date().toISOString()
      const payload = {
        data: formRef.value ? JSON.parse(JSON.stringify(formRef.value)) : JSON.parse(JSON.stringify(formRef)),
        savedAt: now,
      }
      localStorage.setItem(storageKey, JSON.stringify(payload))
      lastSavedAt.value = formatThaiTime(now)
      hasDraft.value = false
    } catch {
      // LocalStorage error
    } finally {
      setTimeout(() => {
        isSaving.value = false
      }, 300)
    }
  }

  const scheduleAutoSave = () => {
    if (timer) clearTimeout(timer)
    timer = setTimeout(() => {
      const check = options.isFormPopulated
      if (check && !check(formRef.value || formRef)) {
        return
      }
      saveToStorage()
    }, debounceMs)
  }

  const restoreDraft = () => {
    if (!savedDraftData.value) return false
    try {
      const target = formRef.value || formRef
      Object.assign(target, JSON.parse(JSON.stringify(savedDraftData.value)))
      if (typeof options.onRestore === 'function') {
        options.onRestore(savedDraftData.value)
      }
      hasDraft.value = false
      return true
    } catch {
      return false
    }
  }

  const clearDraft = () => {
    try {
      if (timer) clearTimeout(timer)
      localStorage.removeItem(storageKey)
      hasDraft.value = false
      savedDraftData.value = null
      lastSavedAt.value = ''
    } catch {
      // Ignored
    }
  }

  const unwatch = watch(
    () => (formRef.value ? formRef.value : formRef),
    () => {
      scheduleAutoSave()
    },
    { deep: true, flush: 'sync' },
  )

  if (getCurrentInstance()) {
    onMounted(() => {
      checkExistingDraft()
    })

    onBeforeUnmount(() => {
      if (timer) clearTimeout(timer)
      unwatch()
    })
  }

  return {
    hasDraft,
    savedDraftData,
    lastSavedAt,
    isSaving,
    checkExistingDraft,
    restoreDraft,
    clearDraft,
    saveToStorage,
  }
}
