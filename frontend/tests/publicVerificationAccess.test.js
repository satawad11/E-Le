import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { PUBLIC_PATH_PREFIXES, isPublicPath } from '../src/axios.js'

const SRC = join(dirname(fileURLToPath(import.meta.url)), '..', 'src')

/**
 * หน้าตรวจสอบเอกสารสาธารณะต้องเปิดได้จริงโดยไม่ต้องเข้าสู่ระบบ (SoT §12)
 *
 * **ที่มา — ข้อบกพร่องจริงสองข้อที่พบตอนทำ UI QA เมื่อ 2026-09-05**
 *
 *   1. app shell เรียก `/api/settings` ตอนบูตทุกครั้ง ได้ 401 แล้ว interceptor
 *      พาไปหน้าเข้าสู่ระบบ — **หน้าตรวจสอบทั้งของใบลาและของหนังสือราชการจึง
 *      เปิดไม่ได้เลยสำหรับผู้ที่ถือกระดาษอยู่ในมือ** ซึ่งเป็นคนกลุ่มเดียวที่หน้านั้นมีไว้ให้
 *
 *   2. หน้าตรวจสอบไล่เงื่อนไขของตัวเองแล้วตกลงมาที่ "เอกสารถูกต้องตามระบบ"
 *      เป็นค่าสุดท้าย — เมื่อ server เริ่มตรวจแฮชไฟล์และการเพิกถอน หน้าจอจึงยัง
 *      ขึ้นว่าถูกต้องทั้งที่ `is_valid` เป็น false
 *
 * ทั้งสองข้อทำให้กลไกตรวจสอบที่ฝั่ง server ทำงานถูกต้อง **ไปไม่ถึงผู้ใช้เลย**
 */
describe('การเข้าถึงหน้าตรวจสอบสาธารณะ', () => {
  it('ไม่พาไปหน้าเข้าสู่ระบบเมื่ออยู่บนหน้าตรวจสอบเอกสาร', () => {
    expect(isPublicPath('/verify-saraban/abc123')).toBe(true)
    expect(isPublicPath('/verify-leave/abc123')).toBe(true)
    expect(isPublicPath('/login')).toBe(true)
  })

  it('ยังพาไปหน้าเข้าสู่ระบบสำหรับหน้าที่ต้องยืนยันตัวตน', () => {
    expect(isPublicPath('/saraban/documents')).toBe(false)
    expect(isPublicPath('/')).toBe(false)
    expect(isPublicPath(undefined)).toBe(false)
  })

  /**
   * รายชื่อใน `axios.js` ต้องตรงกับ route ที่ประกาศ `meta.public` ใน router
   *
   * สองที่นี้เดินหนีกันได้ง่ายมาก และผลของการเดินหนีคือหน้าสาธารณะที่เปิดไม่ได้
   * โดยไม่มีอะไรฟ้อง — ซึ่งเกิดขึ้นมาแล้วจริง
   */
  it('ครอบคลุมทุก route ที่ประกาศว่าเป็นสาธารณะใน router', () => {
    const router = readFileSync(
      join(SRC, 'router/index.js'),
      'utf8',
    )

    const publicPaths = [...router.matchAll(/path:\s*'([^']+)'[\s\S]{0,400}?public:\s*true/g)]
      .map((match) => match[1])

    expect(publicPaths.length).toBeGreaterThan(0)

    for (const path of publicPaths) {
      // ตัดพารามิเตอร์ออก แล้วเทียบว่ามี prefix ใดครอบคลุมเส้นทางนี้
      const concrete = path.replace(/:[^/]+/g, 'x')

      expect(
        PUBLIC_PATH_PREFIXES.some((prefix) => concrete.startsWith(prefix)),
        `route สาธารณะ ${path} ไม่มี prefix ครอบคลุมใน PUBLIC_PATH_PREFIXES — หน้านี้จะถูกพาไปหน้าเข้าสู่ระบบ`,
      ).toBe(true)
    }
  })
})

/**
 * หน้าตรวจสอบต้องเชื่อผลที่ server ตัดสิน ไม่ไล่เงื่อนไขเอง
 *
 * ตรวจที่ตัวซอร์สโดยตรง เพราะสิ่งที่ต้องกันคือ **รูปแบบการเขียน** ที่ทำให้หน้าจอ
 * ตกยุคทุกครั้งที่ server ตรวจเพิ่ม ไม่ใช่ผลลัพธ์ของกรณีใดกรณีหนึ่ง
 */
describe('หน้าตรวจสอบหนังสือราชการ', () => {
  const view = readFileSync(
    join(SRC, 'views/saraban/VerifyDocumentView.vue'),
    'utf8',
  )

  it('ตัดสินข้อความจาก verification_status ที่ server ส่งมา', () => {
    expect(view).toContain('verification_status')
  })

  it('ถือว่าสถานะที่ไม่รู้จักคือใช้อ้างอิงไม่ได้ ไม่ใช่ผ่าน', () => {
    expect(view).toContain('UNUSABLE')
    expect(view).toMatch(/STATUS_TEXT\[[^\]]+\]\s*\|\|\s*UNUSABLE/)
  })

  it('รู้จักสถานะที่ทำให้ใช้อ้างอิงไม่ได้ทุกตัวที่ server ส่งได้', () => {
    for (const status of ['DOCUMENT_MODIFIED_AFTER_SIGNING', 'REVOKED', 'REVOCATION_PENDING']) {
      expect(view).toContain(status)
    }
  })

  /** ห้ามบอกคนทั่วไปว่าผิดพลาดที่จุดใด — เป็นข้อมูลภายใน */
  it('ไม่เปิดเผยรายละเอียดภายในให้ผู้ตรวจสอบทั่วไป', () => {
    for (const internal of ['AUTHORITY_INVALID', 'SIGNER_MISMATCH', 'CERTIFICATE_', 'checks']) {
      expect(view).not.toContain(internal)
    }
  })
})
