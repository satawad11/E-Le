import { describe, it, expect } from 'vitest'
import {
  getErrorMessage,
  sanitizeErrorMessage,
  isForbidden,
  isNotFound,
  isUnauthorized,
  isNetworkError,
} from '../src/utils/errorHandler'

/**
 * QUALITY-004 — errorHandler เป็นด่านสุดท้ายก่อนข้อความจากเซิร์ฟเวอร์ถึงตาผู้ใช้
 *
 * ถ้าด่านนี้พลาด ข้อความ SQL, path ของไฟล์บนเซิร์ฟเวอร์ หรือชื่อคลาสภายใน
 * จะไปโผล่บนหน้าจอ ซึ่งเป็นทั้งการรั่วข้อมูลโครงสร้างระบบและ UI ที่ไม่เป็นภาษาไทย
 */
describe('sanitizeErrorMessage', () => {
  it('กลืนข้อความ SQL/stack trace แล้วคืนข้อความไทยกลาง ๆ แทน', () => {
    const leaks = [
      'SQLSTATE[42S02]: Base table or view not found',
      'PDOException: could not find driver',
      'Illuminate\\Database\\QueryException',
      'at /var/www/html/app/Models/Leave.php:120',
      'syntax error near FROM',
    ]

    for (const leak of leaks) {
      const safe = sanitizeErrorMessage(leak)

      expect(safe).toBe('เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่อีกครั้ง หรือติดต่อผู้ดูแลระบบ')
      expect(safe).not.toContain(leak)
    }
  })

  it('ปล่อยข้อความไทยที่ตั้งใจส่งให้ผู้ใช้ผ่านไปตามเดิม', () => {
    expect(sanitizeErrorMessage('คุณไม่มีสิทธิ์ลบใบลาของผู้อื่น'))
      .toBe('คุณไม่มีสิทธิ์ลบใบลาของผู้อื่น')
  })

  it('คืนสตริงว่างเมื่อไม่ใช่ข้อความ', () => {
    expect(sanitizeErrorMessage(null)).toBe('')
    expect(sanitizeErrorMessage(undefined)).toBe('')
    expect(sanitizeErrorMessage({ message: 'x' })).toBe('')
  })
})

describe('getErrorMessage', () => {
  it('รวมข้อความ validation ทุกช่องของ 422', () => {
    const error = {
      response: {
        status: 422,
        data: { errors: { title: ['ต้องระบุชื่อเรื่อง'], files: ['ชนิดของไฟล์แนบนี้ไม่ได้รับอนุญาต'] } },
      },
    }

    const message = getErrorMessage(error)

    expect(message).toContain('ต้องระบุชื่อเรื่อง')
    expect(message).toContain('ชนิดของไฟล์แนบนี้ไม่ได้รับอนุญาต')
  })

  it('แปลงสถานะ HTTP ที่พบบ่อยเป็นข้อความไทย', () => {
    expect(getErrorMessage({ response: { status: 401, data: {} } })).toContain('เข้าสู่ระบบใหม่')
    expect(getErrorMessage({ response: { status: 403, data: {} } })).toContain('ไม่มีสิทธิ์')
    expect(getErrorMessage({ response: { status: 429, data: {} } })).toContain('ถี่เกินไป')
    expect(getErrorMessage({ response: { status: 500, data: {} } })).toContain('ข้อผิดพลาดภายในระบบ')
  })

  it('ไม่ปล่อยข้อความดิบจากเซิร์ฟเวอร์แม้สถานะจะเป็น 500', () => {
    const message = getErrorMessage({
      response: { status: 500, data: { message: 'SQLSTATE[HY000] connection refused' } },
    })

    expect(message).not.toContain('SQLSTATE')
  })

  it('บอกผู้ใช้ว่าเชื่อมต่อไม่ได้เมื่อเป็น network error', () => {
    expect(getErrorMessage({ code: 'ERR_NETWORK' })).toContain('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้')
  })

  it('คืนข้อความสำรองเมื่อไม่มีข้อมูลอะไรเลย', () => {
    expect(getErrorMessage(null)).toBe('เกิดข้อผิดพลาดในการประมวลผล')
    expect(getErrorMessage(null, 'บันทึกไม่สำเร็จ')).toBe('บันทึกไม่สำเร็จ')
  })
})

describe('ตัวช่วยตรวจสถานะ', () => {
  it('แยกสถานะที่หน้าจอต้องปฏิบัติต่างกันได้ถูกต้อง', () => {
    expect(isUnauthorized({ response: { status: 401 } })).toBe(true)
    expect(isForbidden({ response: { status: 403 } })).toBe(true)
    expect(isNotFound({ response: { status: 404 } })).toBe(true)
    expect(isNetworkError({ code: 'ERR_NETWORK' })).toBe(true)

    expect(isForbidden({ response: { status: 404 } })).toBe(false)
    expect(isNetworkError({ response: { status: 500 } })).toBe(false)
  })
})
