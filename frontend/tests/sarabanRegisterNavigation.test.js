import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.hoisted(() => vi.fn())
const push = vi.hoisted(() => vi.fn())
const routeMeta = vi.hoisted(() => ({ value: {} }))

vi.mock('../src/axios', () => ({ default: { get: (...args) => get(...args), post: vi.fn() } }))
vi.mock('vue-router', () => ({
  useRouter: () => ({ push }),
  useRoute: () => ({ params: {}, meta: routeMeta.value }),
}))

const DocumentsView = (await import('../src/views/saraban/DocumentsView.vue')).default
const DistributionTrackingView = (await import('../src/views/saraban/DistributionTrackingView.vue')).default

/**
 * ทางเข้าตาม NAV §2 — ทะเบียนแยกตามทิศทาง กล่องรอลงทะเบียน และการติดตามการกระจาย
 *
 * เรื่องที่ตรึงไว้:
 *
 *   1. ทะเบียนที่แยกตามทิศทางต้อง **ถามเฉพาะทิศทางนั้น** ไม่ใช่แสดงทั้งหมด
 *      แล้วหวังให้ผู้ใช้กรองเอง
 *   2. กล่องรอลงทะเบียนกรองที่ฝั่ง server ไม่ใช่ที่หน้าจอ
 *   3. หน้าติดตามการกระจาย **ไม่มีปุ่มส่ง** — การส่งทำจากหน้าหนังสือที่มีบริบทครบ
 */
const global = {
  directives: { can: {} },
  plugins: [],
}

const mountWith = (component, meta = {}) => {
  routeMeta.value = meta

  return mount(component, { global })
}

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockReset()
  push.mockReset()
  get.mockResolvedValue({ data: { data: [], last_page: 1, meta: { total: 0 } } })
})

describe('ทะเบียนหนังสือแยกตามทิศทาง', () => {
  it('ทะเบียนหนังสือส่งถามเฉพาะหนังสือส่ง และขึ้นหัวข้อของตัวเอง', async () => {
    const wrapper = mountWith(DocumentsView, {
      saraban: { direction: 'outgoing', title: 'ทะเบียนหนังสือส่ง', description: 'หนังสือที่ออกจากหน่วยงานของท่าน' },
    })
    await flushPromises()

    const [url, config] = get.mock.calls[0]
    expect(url).toBe('/saraban/documents')
    expect(config.params.direction).toBe('outgoing')
    expect(wrapper.find('h1').text()).toBe('ทะเบียนหนังสือส่ง')
  })

  /*
   * ปุ่มล้างตัวกรองต้องไม่ล้างทิศทางที่มากับหน้า — ไม่งั้นหน้า "ทะเบียนหนังสือส่ง"
   * จะกลายเป็นทะเบียนรวมทั้งที่หัวข้อยังบอกว่าเป็นทะเบียนหนังสือส่ง
   */
  it('ล้างตัวกรองแล้วยังคงเป็นทะเบียนของทิศทางเดิม', async () => {
    const wrapper = mountWith(DocumentsView, { saraban: { direction: 'internal' } })
    await flushPromises()

    // ปุ่มล้างเงื่อนไขอยู่ในแผงค้นหาขั้นสูง ต้องเปิดแผงก่อน
    await wrapper.findAll('button').find((b) => b.text() === 'ค้นหาขั้นสูง').trigger('click')

    const reset = wrapper.findAll('button').find((b) => b.text() === 'ล้างเงื่อนไข')
    expect(reset, 'ปุ่มล้างเงื่อนไข').toBeTruthy()

    await reset.trigger('click')
    await flushPromises()

    expect(get.mock.calls.at(-1)[1].params.direction).toBe('internal')
  })

  it('กล่องรอลงทะเบียนกรองที่ฝั่ง server', async () => {
    mountWith(DocumentsView, { saraban: { pendingRegistration: true, title: 'กล่องรอลงทะเบียน' } })
    await flushPromises()

    expect(get.mock.calls[0][1].params.pending_registration).toBe(1)
  })

  it('ทะเบียนรวมไม่ส่งตัวกรองทิศทางไปเลย', async () => {
    mountWith(DocumentsView, {})
    await flushPromises()

    expect(get.mock.calls[0][1].params.direction).toBeUndefined()
    expect(get.mock.calls[0][1].params.pending_registration).toBeUndefined()
  })
})

describe('ติดตามการกระจายเอกสาร', () => {
  it('อ่านรายการจาก endpoint ของสารบรรณ และไม่มีปุ่มส่งหนังสือ', async () => {
    get.mockResolvedValue({
      data: {
        data: [{ uuid: 'a', document_id: 9, subject: 'ขอเชิญประชุม', sent_by: 'ธุรการ', sent_at: '2026-09-01T03:00:00Z', total: 4, acknowledged: 1 }],
        meta: { total: 1, current_page: 1, last_page: 1 },
      },
    })

    const wrapper = mountWith(DistributionTrackingView)
    await flushPromises()

    expect(get).toHaveBeenCalledWith('/saraban/distributions', { params: { page: 1 } })
    expect(wrapper.text()).toContain('ขอเชิญประชุม')
    // ยังไม่รับทราบ = 4 - 1 · ตัวเลขที่ต้องตามต่อคือสิ่งที่ต้องเด่น
    expect(wrapper.text()).toContain('ยังไม่รับทราบ 3 คน')
    expect(wrapper.findAll('button').some((b) => b.text().includes('ส่ง'))).toBe(false)
  })

  it('เปิดแถวแล้วไปที่หนังสือฉบับนั้น', async () => {
    get.mockResolvedValue({
      data: {
        data: [{ uuid: 'a', document_id: 9, subject: 'ขอเชิญประชุม', total: 1, acknowledged: 1 }],
        meta: { total: 1 },
      },
    })

    const wrapper = mountWith(DistributionTrackingView)
    await flushPromises()

    await wrapper.find('tbody tr').trigger('click')

    expect(push).toHaveBeenCalledWith({ name: 'saraban-document-detail', params: { id: 9 } })
  })
})
