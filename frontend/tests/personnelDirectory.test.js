import { describe, it, expect, vi, beforeEach } from 'vitest'

const get = vi.fn()

vi.mock('../src/axios', () => ({
  default: {
    get: (...args) => get(...args),
  },
}))

const { fetchAllPersonnel } = await import('../src/utils/personnelDirectory')

/**
 * ช่องเลือกชื่อบุคลากรต้องได้รายชื่อครบทุกคน
 *
 * `/admin/users` ถูกเปลี่ยนเป็นแบ่งหน้าเสมอ (ค่าเริ่มต้น 25 คนต่อหน้า) เพื่อไม่ให้
 * ทะเบียนประวัติทั้งสถานศึกษาถูกส่งออกทุกครั้งที่เปิดหน้าจอ ซึ่งถูกต้องสำหรับตาราง
 * ที่มีปุ่มเลื่อนหน้า แต่หน้าจอที่ใช้รายชื่อเป็น "ช่องเลือก" ไม่มีปุ่มเลื่อนหน้า
 * — บุคลากรของวิทยาลัยที่ชื่อเรียงหลังคนที่ 25 จึงเลือกไม่ได้เลย
 *
 * เทสต์นี้ตรึงพฤติกรรมของ helper ที่ไล่ดึงจนครบทุกหน้า
 */
describe('fetchAllPersonnel', () => {
  beforeEach(() => {
    get.mockReset()
  })

  it('ไล่ดึงจนครบทุกหน้า แล้วรวมเป็นรายชื่อชุดเดียว', async () => {
    get.mockImplementation((_url, config) => {
      const page = config.params.page

      return Promise.resolve({
        data: {
          data: [{ id: page, name: `บุคลากรหน้า ${page}` }],
          last_page: 3,
        },
      })
    })

    const rows = await fetchAllPersonnel()

    expect(get).toHaveBeenCalledTimes(3)
    expect(rows.map((r) => r.id)).toEqual([1, 2, 3])
  })

  it('ขอครั้งละ 100 คน และส่งพารามิเตอร์ที่ผู้เรียกกำหนดไปด้วย', async () => {
    get.mockResolvedValue({ data: { data: [], last_page: 1 } })

    await fetchAllPersonnel({ status: 'active' })

    expect(get).toHaveBeenCalledWith('/admin/users', {
      params: { status: 'active', per_page: 100, page: 1 },
    })
  })

  it('รองรับ endpoint ที่คืน array ตรง ๆ โดยไม่ขอหน้าถัดไปซ้ำ', async () => {
    get.mockResolvedValue({ data: [{ id: 9, name: 'นางสาวทดสอบ' }] })

    const rows = await fetchAllPersonnel()

    expect(get).toHaveBeenCalledTimes(1)
    expect(rows).toHaveLength(1)
  })

  it('ไม่วนไม่รู้จบเมื่อ server ส่ง last_page ผิดรูป', async () => {
    get.mockResolvedValue({ data: { data: [{ id: 1 }], last_page: 9999 } })

    const rows = await fetchAllPersonnel()

    expect(get).toHaveBeenCalledTimes(50)
    expect(rows).toHaveLength(50)
  })
})
