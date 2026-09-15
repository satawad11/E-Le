import { defineStore } from 'pinia'

/**
 * กล่องยืนยันกลางของระบบ
 *
 * **ที่มา:** หน้าจอ 15 หน้าถามยืนยันด้วย `window.confirm()` / `window.prompt()`
 * ของเบราว์เซอร์ ซึ่งมีปัญหาจริงสามข้อ: ปุ่มเป็นภาษาของเบราว์เซอร์ไม่ใช่ภาษาไทย
 * (ขัด PROJECT_RULES ข้อ 13), จัดรูปแบบข้อความไม่ได้เลยแม้แต่ตัวหนา และบางเบราว์เซอร์
 * บนมือถือกดปิดทั้งแท็บได้จากกล่องนั้น · ที่สำคัญคือมันไม่เหมือนกับกล่องยืนยันของ
 * โมดูลสารบรรณที่ใช้ `AppModal` ผู้ใช้จึงเจอสองหน้าตาในระบบเดียวกัน
 *
 * ใช้รูปแบบเดียวกับ `stores/alert.js` — คอมโพเนนต์เดียวที่ `App.vue` กับ store ที่ใคร
 * ก็เรียกได้ · ตัว store เก็บ `resolve` ของ promise ไว้ แล้วคืนค่าเมื่อผู้ใช้ตัดสินใจ
 * ผู้เรียกจึงเขียนได้ตรงไปตรงมาเหมือนเดิม: `if (!await confirm({...})) return`
 */
export const useConfirmStore = defineStore('confirm', {
  state: () => ({
    /** คำขอที่กำลังแสดงอยู่ — null เมื่อไม่มีกล่องเปิดอยู่ */
    request: null,
  }),

  actions: {
    /**
     * เปิดกล่องยืนยัน แล้วรอผลจากผู้ใช้
     *
     * @returns {Promise<boolean|string|null>} true/false สำหรับกล่องยืนยันธรรมดา
     *   ส่วนกล่องที่ขอข้อความจะคืนข้อความที่กรอก หรือ null เมื่อกดยกเลิก
     */
    ask(options) {
      /*
       * มีกล่องค้างอยู่แล้ว = ผู้เรียกก่อนหน้ายังรอคำตอบ · ปิดของเดิมด้วย "ยกเลิก"
       * เพื่อไม่ให้ promise เส้นนั้นค้างตลอดไป แล้วจึงเปิดกล่องใหม่
       */
      this.dismiss()

      return new Promise((resolve) => {
        this.request = {
          title: options.title || 'ยืนยันการดำเนินการ',
          message: options.message || '',
          detail: options.detail || '',
          confirmText: options.confirmText || 'ยืนยัน',
          cancelText: options.cancelText || 'ยกเลิก',
          // 'danger' = การกระทำที่ย้อนกลับไม่ได้ ปุ่มยืนยันเป็นสีแดง
          tone: options.tone === 'danger' ? 'danger' : 'default',
          // ตั้งค่าเมื่อกล่องนี้ต้องให้ผู้ใช้กรอกข้อความ (แทน window.prompt)
          prompt: options.prompt || null,
          value: options.defaultValue || '',
          resolve,
        }
      })
    },

    /** ผู้ใช้กดยืนยัน — กล่องที่ขอข้อความคืนข้อความ กล่องธรรมดาคืน true */
    confirm(value) {
      this.settle(this.request?.prompt ? value : true)
    },

    /** ผู้ใช้กดยกเลิก ปิดกล่อง หรือกด Esc */
    dismiss() {
      this.settle(this.request?.prompt ? null : false)
    },

    settle(result) {
      const pending = this.request

      if (!pending) {
        return
      }

      this.request = null
      pending.resolve(result)
    },
  },
})
