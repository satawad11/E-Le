import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()
const put = vi.fn()
const post = vi.fn()

vi.mock('../src/axios', () => ({
  default: {
    get: (...args) => get(...args),
    put: (...args) => put(...args),
    post: (...args) => post(...args),
    delete: vi.fn(),
  },
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { templateId: '7' } }),
  onBeforeRouteLeave: vi.fn(),
}))

/*
 * pdf.js ต้องถูกแทน เพราะ jsdom ไม่มี canvas จริงและไม่มี worker
 *
 * สิ่งที่เทสต์นี้ตรวจคือ "หน้าออกแบบใช้ขนาดหน้าจากไฟล์เป็นระบบพิกัด" ไม่ใช่ตัวเรนเดอร์ PDF
 * จึงคืน viewport ของหน้าขนาดที่ไม่ใช่ A4 มาให้ แล้วดูว่าหน้าจอใช้ค่านั้นจริง
 */
vi.mock('pdfjs-dist', () => ({
  GlobalWorkerOptions: {},
  getDocument: () => ({
    promise: Promise.resolve({
      numPages: 1,
      getPage: () => Promise.resolve({
        getViewport: ({ scale }) => ({ width: 935.43 * scale, height: 595.28 * scale }),
        render: () => ({ promise: Promise.resolve(), cancel: () => {} }),
      }),
      destroy: () => {},
    }),
  }),
}))

vi.mock('pdfjs-dist/build/pdf.worker.min.mjs?url', () => ({ default: 'worker.js' }))

const DocumentDesignerView = (await import('../src/views/admin/DocumentDesignerView.vue')).default

/**
 * DESIGNER-01/02 — หน้าออกแบบแบบฟอร์มต้องยึดขนาดหน้าจริงของไฟล์ต้นฉบับ
 * และส่งคุณสมบัติของฟิลด์ที่ตัวเรนเดอร์อ่านจริงกลับไปเมื่อบันทึก
 *
 * บั๊กเดิม: หน้าจอตรึงกระดาษไว้ที่ A4 (595×842) เสมอ แบบฟอร์มแนวนอนหรือขนาดอื่น
 * จึงวางบนจอตำแหน่งหนึ่งแต่พิมพ์ออกอีกตำแหน่งหนึ่ง
 */
const template = { id: 7, code: 'leave_vacation', name: 'แบบใบขอลาพักผ่อน' }

const draftVersion = {
  id: 21,
  version: 2,
  status: 'DRAFT',
  render_mode: 'pdf_overlay',
  pdf_file_path: 'document-templates/leave_vacation.pdf',
  page_count: 1,
}

const fields = [
  { id: 1, code: 'employee.full_name', label_th: 'ชื่อ-สกุลผู้ยื่น', group_th: 'ผู้ยื่น', data_type: 'TEXT' },
  { id: 2, code: 'leave.start_date', label_th: 'วันที่เริ่มลา', group_th: 'การลา', data_type: 'DATE' },
]

const routeResponses = () => {
  get.mockImplementation((url) => {
    if (url === '/admin/document-engine/templates/7') {
      return Promise.resolve({ data: { template, versions: [draftVersion], binding_rules: [] } })
    }
    if (url === '/admin/document-engine/fields') {
      return Promise.resolve({ data: fields })
    }
    if (url === '/admin/document-engine/versions/21/mappings') {
      return Promise.resolve({
        data: {
          version: draftVersion,
          mappings: [],
          fields,
          formats: { thai_date: 'วันที่ไทยเต็ม (1 ตุลาคม 2569)' },
          field_types: ['TEXT', 'DATE', 'CHECKBOX', 'QR_CODE'],
        },
      })
    }
    if (url === '/admin/document-engine/versions/21/geometry') {
      return Promise.resolve({ data: { page_count: 1, pages: [{ page: 1, width: 935.43, height: 595.28 }] } })
    }
    if (url === '/admin/document-engine/versions/21/pdf') {
      return Promise.resolve({ data: new Blob(['%PDF-1.4'], { type: 'application/pdf' }) })
    }
    if (url === '/admin/document-engine/versions/21/preflight') {
      return Promise.resolve({ data: { errors: [], warnings: ['ฟิลด์ ก กับ ข วางทับกันในหน้า 1'] } })
    }
    return Promise.resolve({ data: {} })
  })
}

const mountDesigner = async () => {
  const wrapper = mount(DocumentDesignerView, {
    global: { stubs: { 'router-link': true } },
    attachTo: document.body,
  })

  await flushPromises()
  await flushPromises()

  return wrapper
}

describe('DocumentDesignerView — หน้าออกแบบแบบฟอร์ม', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    get.mockReset()
    put.mockReset()
    post.mockReset()
    routeResponses()
    global.URL.createObjectURL = vi.fn(() => 'blob:preview')
    global.URL.revokeObjectURL = vi.fn()
  })

  it('ใช้ขนาดหน้าจริงของแบบฟอร์ม ไม่ใช่ A4 ที่ตรึงไว้', async () => {
    const wrapper = await mountDesigner()

    expect(wrapper.text()).toContain('935×595 pt')
    expect(wrapper.text()).not.toContain('595×842 pt')
  })

  it('แสดงเฉพาะชนิดฟิลด์ที่ตัวเรนเดอร์วาดได้ (ไม่มี BARCODE)', async () => {
    const wrapper = await mountDesigner()

    await wrapper.findAll('aside button').find(button => button.text().includes('ชื่อ-สกุลผู้ยื่น')).trigger('click')
    await flushPromises()

    const types = wrapper.findAll('select').map(select => select.text()).join(' ')

    expect(types).toContain('QR_CODE')
    expect(types).not.toContain('BARCODE')
  })

  it('บันทึกคุณสมบัติของฟิลด์ที่ตัวเรนเดอร์อ่านจริงกลับไปให้เซิร์ฟเวอร์', async () => {
    put.mockResolvedValue({ data: { message: 'บันทึกตำแหน่งฟิลด์เรียบร้อยแล้ว', mappings: [] } })

    const wrapper = await mountDesigner()

    await wrapper.findAll('aside button').find(button => button.text().includes('วันที่เริ่มลา')).trigger('click')
    await flushPromises()

    await wrapper.findAll('button').find(button => button.text().includes('บันทึกตำแหน่งฟิลด์')).trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledTimes(1)

    const [url, payload] = put.mock.calls[0]

    expect(url).toBe('/admin/document-engine/versions/21/mappings')
    expect(payload.mappings).toHaveLength(1)
    expect(payload.mappings[0]).toMatchObject({
      field_code: 'leave.start_date',
      field_type: 'DATE',
      page: 1,
      align: 'left',
      overflow: 'shrink',
    })
  })

  it('แสดงผลตรวจก่อนเผยแพร่ให้เห็นก่อนกดปุ่ม', async () => {
    const wrapper = await mountDesigner()

    await wrapper.findAll('button').find(button => button.text().includes('ตรวจแบบฟอร์มนี้')).trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('ข้อควรระวัง 1 ข้อ')
    expect(wrapper.text()).toContain('วางทับกันในหน้า 1')
  })

  it('พรีวิวจากใบลาจริงต้องระบุเลขที่ใบลาก่อน', async () => {
    const wrapper = await mountDesigner()

    const modeSelect = wrapper.findAll('select').find(select => select.text().includes('พรีวิวจากใบลาจริง'))
    await modeSelect.setValue('leave')

    get.mockClear()

    await wrapper.findAll('button').find(button => button.text().includes('พรีวิว PDF')).trigger('click')
    await flushPromises()

    const previewCalls = get.mock.calls.filter(([url]) => url.includes('/preview'))

    expect(previewCalls).toHaveLength(0)
  })
})
