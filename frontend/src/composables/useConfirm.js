import { useConfirmStore } from '../stores/confirm'

/**
 * กล่องยืนยันมาตรฐานของระบบ — ใช้แทน `window.confirm()` / `window.prompt()`
 *
 * เขียนแทนกันได้ตรง ๆ เพียงเติม `await`:
 *
 *   ก่อน  if (!confirm('ลบรายการนี้?')) return
 *   หลัง  if (!await confirmAction({ message: 'ลบรายการนี้?' })) return
 *
 * ปุ่มและข้อความเป็นภาษาไทยทั้งหมด และหน้าตาเดียวกับกล่องของโมดูลสารบรรณ
 */
export function useConfirm() {
  const store = useConfirmStore()

  /**
   * ถามยืนยัน — คืน true เมื่อผู้ใช้กดยืนยัน
   *
   * @param {{title?: string, message: string, detail?: string,
   *          confirmText?: string, cancelText?: string, tone?: 'default'|'danger'}} options
   * @returns {Promise<boolean>}
   */
  const confirmAction = (options) => store.ask({ ...options, prompt: null })

  /**
   * ถามยืนยันการกระทำที่ย้อนกลับไม่ได้ — ปุ่มยืนยันเป็นสีแดง
   *
   * @returns {Promise<boolean>}
   */
  const confirmDanger = (options) => store.ask({ ...options, prompt: null, tone: 'danger' })

  /**
   * ขอข้อความจากผู้ใช้ — คืนข้อความที่กรอก หรือ null เมื่อกดยกเลิก
   *
   * ใช้แทน `window.prompt()` ซึ่งไม่มีทั้ง label, ข้อความอธิบาย และการบังคับกรอก
   *
   * @param {{title?: string, message?: string, prompt: {label: string, placeholder?: string,
   *          required?: boolean, multiline?: boolean}, defaultValue?: string}} options
   * @returns {Promise<string|null>}
   */
  const promptText = (options) => store.ask(options)

  return { confirmAction, confirmDanger, promptText }
}
