import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ref } from 'vue'
import { useFormAutoSave } from '../src/composables/useFormAutoSave.js'

describe('useFormAutoSave Composable', () => {
  const KEY = 'test_form_draft_key'

  beforeEach(() => {
    localStorage.clear()
    vi.useFakeTimers()
  })

  it('saves form data to localStorage automatically with debounce', async () => {
    const formData = ref({ title: '', reason: '' })
    const { lastSavedAt } = useFormAutoSave(KEY, formData, { debounceMs: 100 })

    expect(localStorage.getItem(KEY)).toBeNull()

    formData.value.title = 'ขอยื่นใบลาพักผ่อน'
    formData.value.reason = 'ไปทำธุระส่วนตัว'

    // Advance time to trigger debounce
    vi.advanceTimersByTime(150)

    const savedRaw = localStorage.getItem(KEY)
    expect(savedRaw).not.toBeNull()

    const parsed = JSON.parse(savedRaw)
    expect(parsed.data.title).toBe('ขอยื่นใบลาพักผ่อน')
    expect(parsed.data.reason).toBe('ไปทำธุระส่วนตัว')
    expect(lastSavedAt.value).not.toBe('')
  })

  it('restores draft data correctly', () => {
    const existing = {
      data: { title: 'ร่างหนังสือด่วนมาก', content: 'ข้อความร่างเดิม' },
      savedAt: new Date().toISOString(),
    }
    localStorage.setItem(KEY, JSON.stringify(existing))

    const formData = ref({ title: '', content: '' })
    const { hasDraft, restoreDraft } = useFormAutoSave(KEY, formData)

    expect(hasDraft.value).toBe(true)

    const restored = restoreDraft()
    expect(restored).toBe(true)
    expect(formData.value.title).toBe('ร่างหนังสือด่วนมาก')
    expect(formData.value.content).toBe('ข้อความร่างเดิม')
    expect(hasDraft.value).toBe(false)
  })

  it('clears draft from localStorage properly', () => {
    localStorage.setItem(KEY, JSON.stringify({ data: { test: 123 }, savedAt: new Date().toISOString() }))
    const formData = ref({ test: 123 })
    const { clearDraft } = useFormAutoSave(KEY, formData)

    clearDraft()
    expect(localStorage.getItem(KEY)).toBeNull()
  })
})
