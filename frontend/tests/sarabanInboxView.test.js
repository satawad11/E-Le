import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()
const post = vi.fn()

vi.mock('../src/axios', () => ({
  default: {
    get: (...args) => get(...args),
    post: (...args) => post(...args),
  },
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn() }),
  useRoute: () => ({ params: {} }),
}))

const InboxView = (await import('../src/views/saraban/InboxView.vue')).default

/**
 * INT-13 — กล่องหนังสือเข้า (SoT §15)
 *
 * เรื่องที่ตรึงไว้ที่ชั้นหน้าจอ:
 *
 *   1. **การเปิดอ่านต้องไม่กลายเป็นการรับทราบ** — ปุ่ม "เปิดอ่าน" ยิงแค่ GET
 *      และหน้าจอยังคงเตือนว่ายังไม่ได้รับทราบ
 *   2. **การรับทราบเป็นการกระทำแยกที่ผู้ใช้ต้องกดเอง**
 *   3. ใช้ layout และ component ของระบบเดิม ไม่มี palette ของตัวเอง
 */
const inboxPage = {
  data: [
    {
      id: 3,
      recipient_role: 'to',
      status: 'delivered',
      delivered_at: '2026-09-05T08:30:00+07:00',
      opened_at: null,
      acknowledged_at: null,
      note: 'เพื่อทราบและถือปฏิบัติ',
      sent_by: 'นางสาวสมหญิง ใจดี',
      document: {
        id: 11,
        subject: 'ขอเชิญประชุมคณะกรรมการสถานศึกษา',
        document_number: 'ศธ 04001/125',
        owner_org_unit: 'ฝ่ายอำนวยการ',
      },
    },
  ],
  meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  unacknowledged: 1,
}

const openedItem = {
  ...inboxPage.data[0],
  status: 'opened',
  opened_at: '2026-09-05T09:00:00+07:00',
}

describe('InboxView — กล่องหนังสือเข้า', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    get.mockReset()
    post.mockReset()
    get.mockImplementation((url) =>
      url === '/saraban/inbox'
        ? Promise.resolve({ data: inboxPage })
        : Promise.resolve({ data: { item: openedItem } }),
    )
  })

  const mountView = async () => {
    const wrapper = mount(InboxView, { global: { directives: { can: {} } } })
    await flushPromises()

    return wrapper
  }

  it('โหลดกล่องของผู้ใช้จาก endpoint ของสารบรรณ', async () => {
    await mountView()

    expect(get).toHaveBeenCalledWith('/saraban/inbox', expect.anything())
  })

  it('แสดงเรื่อง เลขที่ ผู้ส่ง และจำนวนที่ยังไม่รับทราบ', async () => {
    const wrapper = await mountView()
    const text = wrapper.text()

    expect(text).toContain('ขอเชิญประชุมคณะกรรมการสถานศึกษา')
    expect(text).toContain('ศธ 04001/125')
    expect(text).toContain('นางสาวสมหญิง ใจดี')
    expect(text).toContain('ได้รับแล้ว')
  })

  /** ข้อบังคับที่ชัดที่สุดของ SoT §15 */
  it('การเปิดอ่านไม่ยิงคำสั่งรับทราบ และยังเตือนว่ายังไม่ได้รับทราบ', async () => {
    const wrapper = await mountView()

    await wrapper.findAll('button').find((button) => button.text() === 'เปิดอ่าน').trigger('click')
    await flushPromises()

    expect(get).toHaveBeenCalledWith('/saraban/inbox/3')
    expect(post).not.toHaveBeenCalled()
    expect(document.body.textContent).toContain('ยังไม่ได้กดรับทราบ')
  })

  it('การรับทราบเป็นการกระทำแยกที่ต้องกดเอง', async () => {
    post.mockResolvedValue({
      data: { item: { ...openedItem, status: 'acknowledged', acknowledged_at: '2026-09-05T09:05:00+07:00' } },
    })

    const wrapper = await mountView()

    await wrapper.findAll('button').find((button) => button.text() === 'เปิดอ่าน').trigger('click')
    await flushPromises()

    const acknowledge = [...document.body.querySelectorAll('button')]
      .find((button) => button.textContent.trim() === 'รับทราบ')
    acknowledge.click()
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/saraban/inbox/3/acknowledge', expect.anything())
  })

  it('ใช้ layout convention เดิมของระบบ', async () => {
    const wrapper = await mountView()
    const html = wrapper.html()

    expect(html).toContain('rounded-2xl')
    expect(html).toContain('overflow-x-auto')
  })
})

/**
 * หน้าจอ INT-13 ต้องไม่พก palette ของตัวเองเข้ามา (SoT §2)
 *
 * ตรวจจากซอร์สของหน้าจอเอง ไม่ใช่ HTML ที่เรนเดอร์ เพราะ HTML รวมสีของ
 * component กลางซึ่งงานนี้ไม่ได้เป็นเจ้าของ
 */
describe('หน้าจอ INT-13 ใช้สีของระบบเดิมเท่านั้น', () => {
  it('ไม่มีรหัสสีนอกเหนือจาก #800000 และ #600000', async () => {
    const { readFileSync } = await import('node:fs')

    const sources = [
      'src/views/saraban/InboxView.vue',
      'src/components/saraban/DistributeDocumentModal.vue',
    ]

    for (const path of sources) {
      const hexColours = readFileSync(path, 'utf8').match(/#[0-9a-fA-F]{3,8}\b/g) || []

      for (const colour of hexColours) {
        expect(['#800000', '#600000']).toContain(colour.toLowerCase())
      }
    }
  })
})
