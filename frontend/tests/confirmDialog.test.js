import { describe, it, expect, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const ConfirmDialog = (await import('../src/components/ConfirmDialog.vue')).default
const { useConfirm } = await import('../src/composables/useConfirm')
const { useConfirmStore } = await import('../src/stores/confirm')

/**
 * กล่องยืนยันกลางของระบบ
 *
 * หน้าจอ 15 หน้าเคยถามยืนยันด้วย `window.confirm()` / `window.prompt()` ของเบราว์เซอร์
 * ซึ่งปุ่มเป็นภาษาของเบราว์เซอร์ ไม่ใช่ภาษาไทย (ขัด PROJECT_RULES ข้อ 13)
 * จัดรูปแบบข้อความไม่ได้ และบังคับกรอกไม่ได้
 *
 * เทสต์นี้ตรึงสัญญาที่ผู้เรียกทุกหน้าพึ่งพา: promise คืน true/false สำหรับกล่องยืนยัน
 * คืนข้อความหรือ null สำหรับกล่องที่ขอข้อความ และห้ามส่งค่าว่างเมื่อบังคับกรอก
 *
 * `AppModal` ใช้ `<teleport to="body">` เนื้อหากล่องจึงอยู่นอก wrapper — ต้องค้นจาก
 * `document.body` ตรง ๆ ไม่ใช่ `wrapper.find()`
 */
const mountDialog = () => {
  setActivePinia(createPinia())

  return mount(ConfirmDialog)
}

const clickButton = async (text) => {
  const button = Array.from(document.body.querySelectorAll('button'))
    .find((b) => b.textContent.trim() === text)

  if (!button) {
    throw new Error(`ไม่พบปุ่ม "${text}" — ปุ่มที่มีคือ ${Array.from(document.body.querySelectorAll('button')).map((b) => b.textContent.trim()).join(' | ')}`)
  }

  button.click()
  await flushPromises()
}

const typeInto = async (selector, value) => {
  const field = document.body.querySelector(selector)
  field.value = value
  field.dispatchEvent(new Event('input'))
  await flushPromises()
}

describe('ConfirmDialog — กล่องยืนยันกลาง', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
  })

  it('คืน true เมื่อผู้ใช้กดยืนยัน และแสดงข้อความปุ่มภาษาไทยที่กำหนดเอง', async () => {
    mountDialog()
    const { confirmAction } = useConfirm()

    const answer = confirmAction({ title: 'ลบรายการ', message: 'ยืนยันการลบหรือไม่', confirmText: 'ลบเลย' })
    await flushPromises()

    expect(document.body.textContent).toContain('ยืนยันการลบหรือไม่')

    await clickButton('ลบเลย')

    expect(await answer).toBe(true)
  })

  it('คืน false เมื่อผู้ใช้กดยกเลิก', async () => {
    mountDialog()
    const { confirmAction } = useConfirm()

    const answer = confirmAction({ message: 'ยืนยันหรือไม่' })
    await flushPromises()
    await clickButton('ยกเลิก')

    expect(await answer).toBe(false)
  })

  it('กล่องที่ขอข้อความคืนข้อความที่กรอก', async () => {
    mountDialog()
    const { promptText } = useConfirm()

    const answer = promptText({
      title: 'ไม่ผ่านการตรวจ',
      prompt: { label: 'เหตุผลที่ไม่ผ่าน', required: true, multiline: true },
      confirmText: 'บันทึก',
    })
    await flushPromises()

    await typeInto('textarea', '  หลักฐานไม่ชัดเจน  ')
    await clickButton('บันทึก')

    // ตัดช่องว่างหัวท้ายให้ เพราะค่านี้ถูกส่งต่อเข้า API ทันที
    expect(await answer).toBe('หลักฐานไม่ชัดเจน')
  })

  it('บังคับกรอกได้จริง — สิ่งที่ window.prompt ทำไม่ได้', async () => {
    mountDialog()
    const store = useConfirmStore()
    const { promptText } = useConfirm()

    promptText({ prompt: { label: 'เหตุผล', required: true }, confirmText: 'บันทึก' })
    await flushPromises()

    await typeInto('input', '   ')
    await clickButton('บันทึก')

    // กล่องยังเปิดอยู่ และยังไม่มีการส่งค่าออกไป
    expect(store.request).not.toBeNull()
    expect(document.body.textContent).toContain('กรุณากรอกข้อมูลก่อนกดยืนยัน')
  })

  it('กล่องที่ขอข้อความคืน null เมื่อกดยกเลิก ไม่ใช่ค่าว่าง', async () => {
    mountDialog()
    const { promptText } = useConfirm()

    const answer = promptText({ prompt: { label: 'เหตุผล' } })
    await flushPromises()
    await clickButton('ยกเลิก')

    expect(await answer).toBeNull()
  })

  it('เปิดกล่องใหม่ทับกล่องเดิม ต้องไม่ทิ้ง promise เดิมค้างไว้', async () => {
    mountDialog()
    const { confirmAction } = useConfirm()

    const first = confirmAction({ message: 'คำถามแรก' })
    await flushPromises()

    confirmAction({ message: 'คำถามที่สอง' })
    await flushPromises()

    expect(await first).toBe(false)
  })
})
