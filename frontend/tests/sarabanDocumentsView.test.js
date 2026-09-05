import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()
const post = vi.fn()
const push = vi.fn()

vi.mock('../src/axios', () => ({
  default: {
    get: (...args) => get(...args),
    post: (...args) => post(...args),
  },
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push }),
  useRoute: () => ({ params: {} }),
}))

const DocumentsView = (await import('../src/views/saraban/DocumentsView.vue')).default

/**
 * INT-05 — หน้าจอสารบรรณต้องใช้ระบบ UI เดิม ไม่สร้างชุดใหม่
 *
 * `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md` §2 บังคับให้ SARABAN ใช้
 * App Shell / Theme / Component ของระบบเดิม เทสต์นี้จึงตรึงสองเรื่อง:
 *
 *   1. หน้าจอเรียก endpoint ของสารบรรณจริง และแสดงข้อมูลที่ได้กลับมา
 *   2. ใช้ layout convention เดิม (`max-w-7xl`, การ์ด `rounded-2xl`,
 *      ตารางครอบ `overflow-x-auto`) ตามที่ `UI-COMPATIBILITY-MATRIX.md` กำหนด
 *
 * ส่วนสถานะว่าง/ผิดพลาด/กำลังโหลด ใช้ `EmptyState`/`ErrorState`/`LoadingState`
 * ของเดิม จึงไม่ต้องทดสอบพฤติกรรมภายในซ้ำที่นี่
 */
const documentsPage = {
  data: [
    {
      id: 7,
      subject: 'ขอเชิญประชุมคณะกรรมการสถานศึกษา',
      direction: 'internal',
      urgency: 'urgent',
      status: 'draft',
      document_type: { id: 2, name: 'หนังสือภายใน' },
    },
  ],
  last_page: 1,
}

describe('DocumentsView — ทะเบียนหนังสือ', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    get.mockReset()
    post.mockReset()
    push.mockReset()
    get.mockResolvedValue({ data: documentsPage })
  })

  const mountView = async () => {
    const wrapper = mount(DocumentsView, {
      global: { directives: { can: {} } },
    })
    await flushPromises()

    return wrapper
  }

  it('โหลดรายการจาก endpoint ของสารบรรณ', async () => {
    await mountView()

    expect(get).toHaveBeenCalledWith('/saraban/documents', expect.anything())
  })

  it('แสดงเรื่อง ประเภท ทิศทาง และสถานะเป็นภาษาไทย', async () => {
    const wrapper = await mountView()
    const text = wrapper.text()

    expect(text).toContain('ขอเชิญประชุมคณะกรรมการสถานศึกษา')
    expect(text).toContain('หนังสือภายใน')
    expect(text).toContain('ด่วน')
    expect(text).toContain('ฉบับร่าง')
  })

  it('ใช้ layout convention เดิมของหน้าจัดการในระบบ', async () => {
    const wrapper = await mountView()
    const html = wrapper.html()

    expect(html).toContain('max-w-7xl')
    expect(html).toContain('rounded-2xl')
    // ตารางต้องเลื่อนแนวนอนได้เสมอตาม UI-COMPATIBILITY-MATRIX หัวข้อ 6
    expect(html).toContain('overflow-x-auto')
  })

  it('เปิดหน้ารายละเอียดผ่าน route ที่ตั้งชื่อไว้ ไม่ใช่ path ที่เขียนตายตัว', async () => {
    const wrapper = await mountView()

    const openButton = wrapper.findAll('button').find((button) => button.text() === 'เปิดดู')
    await openButton.trigger('click')

    expect(push).toHaveBeenCalledWith({ name: 'saraban-document-detail', params: { id: 7 } })
  })

  it('แสดงสถานะว่างด้วย EmptyState เดิมเมื่อยังไม่มีหนังสือ', async () => {
    get.mockResolvedValue({ data: { data: [], last_page: 1 } })

    const wrapper = await mountView()

    expect(wrapper.text()).toContain('ยังไม่มีหนังสือในทะเบียน')
  })
})

/**
 * INT-05 — หน้าจอสารบรรณต้องไม่พก palette ของตัวเองเข้ามา (SoT §2)
 *
 * ตรวจจาก **ซอร์สของหน้าจอสารบรรณเอง** ไม่ใช่จาก HTML ที่เรนเดอร์ออกมา
 * เพราะ HTML ที่เรนเดอร์รวมสีของ component กลาง (`AppButton`, `EmptyState`)
 * ซึ่งเป็นของระบบเดิมที่งานนี้ไม่ได้เป็นเจ้าของและไม่ควรไปแก้
 *
 * สีที่ยอมให้ใช้คือสีหลักของระบบและเฉดตอน hover ที่ประกาศไว้ใน `style.css`
 * (`--primary: #800000`, `--primary-dark: #600000`) เท่านั้น
 */
describe('หน้าจอสารบรรณ — ชุดสี', () => {
  const ALLOWED = ['#800000', '#600000']

  it('ใช้เฉพาะสีหลักของระบบ ไม่ประกาศชุดสีใหม่', async () => {
    const { readFileSync, readdirSync } = await import('node:fs')
    const { join } = await import('node:path')

    const directory = join(process.cwd(), 'src/views/saraban')
    const offenders = []

    for (const file of readdirSync(directory)) {
      const colours = readFileSync(join(directory, file), 'utf8').match(/#[0-9a-fA-F]{6}/g) || []

      for (const colour of colours) {
        if (!ALLOWED.includes(colour.toLowerCase())) offenders.push(`${file}: ${colour}`)
      }
    }

    expect(offenders).toEqual([])
  })
})
