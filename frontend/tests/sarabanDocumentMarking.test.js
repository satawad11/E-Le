import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }))
const push = vi.hoisted(() => vi.fn())

vi.mock('../src/axios', () => ({ default: api }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push }), useRoute: () => ({ params: { id: 7 } }) }))
vi.mock('../src/utils/secureFile', () => ({ fetchObjectUrl: vi.fn(async () => 'blob:document') }))
vi.mock('../src/stores/alert', () => ({ useAlertStore: () => ({ success: vi.fn(), error: vi.fn() }) }))

const PdfWorkspace = (await import('../src/components/saraban/PdfWorkspace.vue')).default
const EndorseDocumentView = (await import('../src/views/saraban/EndorseDocumentView.vue')).default
const SignDocumentView = (await import('../src/views/saraban/SignDocumentView.vue')).default

/**
 * การวางลายเซ็นและรอยเกษียนบนเอกสารจริง (SoT §14, §15 · NAV §4.7)
 *
 * เรื่องที่ตรึงไว้ที่ชั้นหน้าจอ:
 *
 *   1. **วางได้ทุกตำแหน่งบนทุกหน้า** — พิกัดมาจากจุดที่คลิกจริง ไม่ใช่ค่าตายตัว
 *      และหน้าที่บันทึกคือหน้าที่กำลังเปิดอยู่
 *   2. **พิกัดที่ส่งเป็น PDF point** — หารด้วยระดับซูมแล้ว ผลจึงไม่ขึ้นกับจอผู้วาง
 *   3. **การเกษียนไม่ใช่การลงนาม** และต้องยืนยันก่อนจึงจะส่งคำขอ
 */
const stubWorkspace = {
  props: ['modelValue', 'locked', 'source', 'placing', 'editable'],
  emits: ['update:modelValue', 'place', 'cancel-placing'],
  template: '<div class="workspace"></div>',
}

const mountView = (component) =>
  mount(component, {
    global: {
      stubs: {
        PdfWorkspace: stubWorkspace,
        AppModal: { template: '<div><slot/><slot name="footer"/></div>' },
        HandwritingCanvas: { name: 'HandwritingCanvas', emits: ['change'], template: '<div class="pad"></div>' },
      },
    },
  })

const click = async (wrapper, text) => {
  const button = wrapper.findAll('button').find((b) => b.text() === text)
  expect(button, text).toBeTruthy()
  await button.trigger('click')
  await flushPromises()
}

beforeEach(() => {
  for (const fn of Object.values(api)) fn.mockReset()
  push.mockReset()
  api.post.mockResolvedValue({ data: {} })
  api.get.mockImplementation((url) => {
    if (url.endsWith('/annotations')) {
      return Promise.resolve({
        data: {
          version: { id: 3, version_no: 2, stage: 'original', file_path: 'saraban/a.pdf' },
          annotations: [{ id: 1, page: 1, type: 'stamp', x: 10, y: 10, width: 100, height: 40, payload: {} }],
          editable: true,
        },
      })
    }
    if (url.endsWith('/workflow')) {
      return Promise.resolve({ data: { started: true, current_step: { name: 'เสนอผู้อำนวยการ' }, transitions: [], history: [] } })
    }

    return Promise.resolve({ data: { evidences: [] } })
  })
  URL.revokeObjectURL = vi.fn()
})

describe('พื้นที่ทำงานบนเอกสาร', () => {
  it('คลิกที่ใดก็ได้บนหน้าเอกสารแล้วได้พิกัดจุดนั้นเป็น PDF point', async () => {
    const wrapper = mount(PdfWorkspace, {
      props: { modelValue: [], placing: { type: 'signature', label: 'ลายเซ็น', width: 100, height: 40 } },
    })

    const stage = wrapper.find('.relative')
    stage.element.getBoundingClientRect = () => ({ left: 50, top: 20, width: 595, height: 842 })

    await stage.trigger('click', { clientX: 250, clientY: 320 })

    // จุดที่คลิกเป็นจุดกึ่งกลางของวัตถุ จึงหักครึ่งความกว้าง/สูงออก
    expect(wrapper.emitted('place')[0][0]).toEqual({ page: 1, x: 150, y: 280 })
  })

  it('ไม่วางอะไรเมื่อยังไม่ได้เลือกเครื่องมือ', async () => {
    const wrapper = mount(PdfWorkspace, { props: { modelValue: [] } })
    const stage = wrapper.find('.relative')
    stage.element.getBoundingClientRect = () => ({ left: 0, top: 0, width: 595, height: 842 })

    await stage.trigger('click', { clientX: 100, clientY: 100 })

    expect(wrapper.emitted('place')).toBeUndefined()
  })

  it('วัตถุที่บันทึกไว้แล้วแสดงเป็นบริบทและลากไม่ได้', () => {
    const wrapper = mount(PdfWorkspace, {
      props: {
        modelValue: [],
        editable: true,
        locked: [{ id: 5, page: 1, type: 'stamp', x: 10, y: 10, width: 100, height: 40, payload: { title: 'รับหนังสือ' } }],
      },
    })

    const locked = wrapper.find('[aria-label*="ที่บันทึกไว้แล้ว"]')
    expect(locked.exists()).toBe(true)
    expect(locked.classes()).toContain('pointer-events-none')
  })
})

describe('หน้าเกษียนหนังสือ', () => {
  it('วางความเห็นตรงจุดที่คลิกบนหน้าที่เปิดอยู่ แล้วส่งหลังยืนยันเท่านั้น', async () => {
    const wrapper = mountView(EndorseDocumentView)
    await flushPromises()

    await click(wrapper, 'วางเครื่องหมายถูก')
    expect(wrapper.findComponent(stubWorkspace).props('placing')).toMatchObject({ type: 'checkmark' })

    // หน้า 3 พิสูจน์ว่าไม่ได้ผูกกับหน้า 1 เสมอ
    wrapper.findComponent(stubWorkspace).vm.$emit('place', { page: 3, x: 120, y: 400 })
    await flushPromises()

    expect(api.post).not.toHaveBeenCalled()

    await click(wrapper, 'ตรวจและบันทึกเกษียน')
    await click(wrapper, 'ยืนยันบันทึกเกษียน')

    expect(api.post).toHaveBeenCalledWith('/saraban/documents/7/endorse', {
      objects: [{ page: 3, type: 'checkmark', x: 120, y: 400, width: 14, height: 14, payload: null }],
    })
  })

  it('บอกว่าการเกษียนไม่ใช่การลงนาม และแสดงบริบทของเรื่อง', async () => {
    const wrapper = mountView(EndorseDocumentView)
    await flushPromises()

    expect(wrapper.text()).toContain('ไม่ใช่การลงนามรับรอง')
    expect(wrapper.text()).toContain('เสนอผู้อำนวยการ')
  })
})

describe('หน้าลงนาม', () => {
  it('ส่งลายเซ็นตามหน้าและพิกัดที่วางไว้จริง', async () => {
    const wrapper = mountView(SignDocumentView)
    await flushPromises()

    wrapper.findComponent({ name: 'HandwritingCanvas' }).vm.$emit('change', [[{ x: 1, y: 1 }, { x: 2, y: 2 }]])
    await flushPromises()

    await click(wrapper, 'วางลายเซ็นบนเอกสาร')
    wrapper.findComponent(stubWorkspace).vm.$emit('place', { page: 2, x: 300, y: 500 })
    await flushPromises()

    await click(wrapper, 'ตรวจและลงนาม')
    await click(wrapper, 'ยืนยันลงนามในหนังสือ')

    expect(api.post).toHaveBeenCalledWith('/saraban/documents/7/sign', expect.objectContaining({
      page: 2, x: 300, y: 500, width: 150, height: 50,
    }))
  })

  it('แสดงผู้ลงนามก่อนหน้าและสถานะไฟล์ที่ผู้นั้นรับรอง', async () => {
    api.get.mockImplementation((url) => {
      if (url.endsWith('/signatures')) {
        return Promise.resolve({
          data: {
            evidences: [
              { uuid: 'a', signer_name_snapshot: 'นายทดสอบ', position_snapshot: 'ผู้อำนวยการ', signed_at: '2026-09-01T03:00:00Z', file_intact: false },
            ],
          },
        })
      }
      if (url.endsWith('/workflow')) return Promise.resolve({ data: { started: false, transitions: [], history: [] } })

      return Promise.resolve({ data: { version: null, annotations: [], editable: true } })
    })

    const wrapper = mountView(SignDocumentView)
    await flushPromises()

    expect(wrapper.text()).toContain('นายทดสอบ')
    expect(wrapper.text()).toContain('ไฟล์ที่รับรองไม่ตรงกับหลักฐาน')
  })
})
