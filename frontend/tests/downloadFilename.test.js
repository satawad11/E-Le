import { describe, it, expect } from 'vitest'
import { buildFilename, filenameFromHeaders, sanitizeFilenamePart } from '../src/utils/downloadFilename'

/*
 * ชื่อไฟล์ที่หน้าจอตั้งต้องเทียบเท่ากับ `App\Support\DocumentFilename` ฝั่งเซิร์ฟเวอร์
 * ไม่งั้นผู้ใช้จะได้ชื่อไฟล์คนละแบบเมื่อ proxy ตัด `Content-Disposition` ทิ้ง
 */
describe('ชื่อไฟล์เอกสารที่ดาวน์โหลด', () => {
  it('ตัดอักขระที่ระบบไฟล์ห้ามใช้และช่องว่างออก', () => {
    expect(sanitizeFilenamePart('สม:ชาย*ใจ?ดี')).toBe('สม_ชาย_ใจ_ดี')
    expect(sanitizeFilenamePart('  ลา ป่วย  ')).toBe('ลา_ป่วย')
    expect(sanitizeFilenamePart('สธ 0412/1234')).toBe('สธ_0412_1234')
  })

  it('ประกอบชื่อไฟล์โดยข้ามส่วนที่ว่าง', () => {
    expect(buildFilename(['ใบลา', 'ลาป่วย', null, '', 12], 'pdf')).toBe('ใบลา_ลาป่วย_12.pdf')
    expect(buildFilename([null, ''], 'csv')).toBe('เอกสาร.csv')
  })

  it('อ่านชื่อไทยจาก filename* ก่อนชื่อสำรอง ASCII', () => {
    const thai = 'ใบลา_ลาป่วย_1234.pdf'
    const headers = {
      'content-disposition': `attachment; filename="1234.pdf"; filename*=UTF-8''${encodeURIComponent(thai)}`,
    }

    expect(filenameFromHeaders(headers)).toBe(thai)
  })

  it('ตกไปใช้ชื่อสำรองเมื่อไม่มี filename* หรือถอดรหัสไม่ได้', () => {
    expect(filenameFromHeaders({ 'content-disposition': 'attachment; filename="report.csv"' })).toBe('report.csv')
    expect(filenameFromHeaders({ 'content-disposition': "attachment; filename=\"a.pdf\"; filename*=UTF-8''%E0%A" }))
      .toBe('a.pdf')
    expect(filenameFromHeaders({})).toBe('')
  })
})
