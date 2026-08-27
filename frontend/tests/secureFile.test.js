import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

const get = vi.fn()

vi.mock('../src/axios', () => ({
  default: {
    get: (...args) => get(...args),
  },
}))

const { fetchObjectUrl, openAuthenticatedReport, reportErrorMessage } = await import('../src/utils/secureFile')

/**
 * SEC-009/SEC-001 — ไฟล์อัปโหลดและรายงานต้องผ่าน endpoint ที่ยืนยันตัวตนเท่านั้น
 *
 * เทสต์นี้ตรึงพฤติกรรมสองข้อที่เคยเป็นช่องโหว่จริง:
 *   1. ห้ามชี้ไปที่ /storage/... ตรง ๆ (เดิมเปิดสาธารณะ)
 *   2. ห้ามแนบ token ทาง query string (เดิม token ไปโผล่ใน access log/referrer)
 */
describe('secureFile', () => {
  beforeEach(() => {
    get.mockReset()
    globalThis.URL.createObjectURL = vi.fn(() => 'blob:mock')
    globalThis.URL.revokeObjectURL = vi.fn()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('เรียกไฟล์ผ่าน /files/{path} ไม่ใช่ /storage โดยตรง', async () => {
    get.mockResolvedValue({ data: new Blob(['x']) })

    await fetchObjectUrl('/storage/signatures/abc.png')

    expect(get).toHaveBeenCalledTimes(1)
    const [url, options] = get.mock.calls[0]

    expect(url).toBe('/files/signatures/abc.png')
    expect(url).not.toContain('/storage/')
    expect(options.responseType).toBe('blob')
  })

  it('ไม่ยิงคำขอเลยเมื่อไม่มี path', async () => {
    expect(await fetchObjectUrl('')).toBe('')
    expect(await fetchObjectUrl(null)).toBe('')
    expect(get).not.toHaveBeenCalled()
  })

  it('ออกรายงานผ่าน axios โดยไม่มี token ใน query string', async () => {
    vi.useFakeTimers()
    get.mockResolvedValue({ data: new Blob(['csv']), headers: {} })

    const openSpy = vi.spyOn(globalThis, 'open').mockImplementation(() => null)

    await openAuthenticatedReport('/reports/export-excel', { from: '2026-01-01' })

    const [url, options] = get.mock.calls[0]

    expect(url).toBe('/reports/export-excel')
    expect(options.params).toEqual({ from: '2026-01-01' })
    expect(JSON.stringify(options.params)).not.toMatch(/token/i)
    expect(openSpy).toHaveBeenCalled()
  })

  it('ดาวน์โหลดด้วยชื่อไฟล์เดิมเมื่อเซิร์ฟเวอร์ส่ง Content-Disposition มา', async () => {
    vi.useFakeTimers()
    get.mockResolvedValue({
      data: new Blob(['pdf']),
      headers: { 'content-disposition': 'attachment; filename="รายงาน.pdf"' },
    })

    const clicked = []
    const realCreate = document.createElement.bind(document)
    vi.spyOn(document, 'createElement').mockImplementation((tag) => {
      const element = realCreate(tag)
      if (tag === 'a') {
        element.click = () => clicked.push(element.download)
      }
      return element
    })

    await openAuthenticatedReport('/reports/export-excel')

    expect(clicked).toEqual(['รายงาน.pdf'])
  })

  it('แปลงข้อผิดพลาดของรายงานเป็นข้อความไทย', () => {
    expect(reportErrorMessage({ response: { status: 401 } })).toContain('เข้าสู่ระบบใหม่')
    expect(reportErrorMessage({ response: { status: 403 } })).toContain('ไม่มีสิทธิ์')
    expect(reportErrorMessage({})).toContain('ออกรายงานไม่สำเร็จ')
  })
})
