import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }))
const push = vi.hoisted(() => vi.fn())

vi.mock('../src/axios', () => ({ default: api }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push }), useRoute: () => ({ params: {}, meta: {} }) }))
vi.mock('../src/stores/alert', () => ({ useAlertStore: () => ({ success: vi.fn(), error: vi.fn() }) }))

const WorkflowDesignView = (await import('../src/views/saraban/WorkflowDesignView.vue')).default

/**
 * RISK-26 — หน้าจัดการสายการเดินหนังสือ (NAV §5.2)
 *
 * เรื่องที่ตรึงไว้ที่ชั้นหน้าจอ:
 *
 *   1. **ผู้ดูแลกำหนดได้แค่ลำดับขั้นและผู้รับผิดชอบ** — ไม่มีช่องให้ผูกเส้นทางเอง
 *      เส้นทางเป็นผลของลำดับที่ server ผูกให้
 *   2. **ผู้รับผิดชอบเลือกจากรายการ ไม่ให้พิมพ์** — พิมพ์ผิดหนึ่งตัวได้ขั้นที่
 *      ไม่มีใครทำได้
 *   3. **เผยแพร่ต้องยืนยันก่อน** และข้อความยืนยันต้องบอกว่าเรื่องที่เดินอยู่ไม่กระทบ
 */
const overview = {
  definition: { id: 1, code: 'saraban.document', name: 'สายการเดินหนังสือ' },
  versions: [
    { id: 20, version: 2, status: 'active', steps_count: 3, effective_from: '2026-09-17', effective_to: null, notes: null },
    { id: 10, version: 1, status: 'retired', steps_count: 2, effective_from: '2026-09-04', effective_to: '2026-09-17', notes: null },
  ],
  assignable_permissions: [
    { name: 'saraban.workflow.review', label: 'ตรวจและดำเนินการหนังสือตามสายงาน' },
    { name: 'saraban.workflow.approve', label: 'พิจารณาและเห็นชอบหนังสือก่อนเสนอลงนาม' },
    { name: 'saraban.signature.sign', label: 'ลงนามในหนังสือ' },
  ],
}

const activeDetail = {
  id: 20,
  version: 2,
  status: 'active',
  editable: false,
  steps: [
    { id: 1, code: 'saraban_review', name: 'งานสารบรรณตรวจสอบ', assignee_permission: 'saraban.workflow.review', is_final: false },
    { id: 2, code: 'deputy_review', name: 'รองผู้อำนวยการพิจารณา', assignee_permission: 'saraban.workflow.approve', is_final: false },
    { id: 3, code: 'signature', name: 'ผู้มีอำนาจลงนาม', assignee_permission: 'saraban.signature.sign', is_final: true },
  ],
  transitions: [],
}

const draftDetail = { ...activeDetail, id: 30, version: 3, status: 'draft', editable: true }

const global = {
  stubs: { AppModal: { template: '<div><slot/><slot name="footer"/></div>' } },
  directives: { can: {} },
}

const click = async (wrapper, text) => {
  const button = wrapper.findAll('button').find((b) => b.text() === text)
  expect(button, text).toBeTruthy()
  await button.trigger('click')
  await flushPromises()
}

const respond = ({ withDraft = false } = {}) => {
  api.get.mockImplementation((url) => {
    if (url === '/saraban/workflow/design') {
      return Promise.resolve({
        data: withDraft
          ? { ...overview, versions: [{ id: 30, version: 3, status: 'draft', steps_count: 3, effective_from: '2026-09-17' }, ...overview.versions] }
          : overview,
      })
    }
    if (url.endsWith('/30')) return Promise.resolve({ data: draftDetail })

    return Promise.resolve({ data: activeDetail })
  })
}

beforeEach(() => {
  for (const fn of Object.values(api)) fn.mockReset()
  push.mockReset()
  api.post.mockResolvedValue({ data: draftDetail })
  api.put.mockResolvedValue({ data: draftDetail })
  api.delete.mockResolvedValue({ data: {} })
  respond()
})

describe('หน้าจัดการสายการเดินหนังสือ', () => {
  it('แสดงสายที่ใช้อยู่เป็นลำดับขั้น พร้อมชี้ขั้นที่ออกเลข', async () => {
    const wrapper = mount(WorkflowDesignView, { global })
    await flushPromises()

    expect(wrapper.text()).toContain('งานสารบรรณตรวจสอบ')
    expect(wrapper.text()).toContain('รองผู้อำนวยการพิจารณา')
    expect(wrapper.text()).toContain('ลงนามและออกเลข')
  })

  it('ยังไม่มีฉบับร่างก็ยังแก้อะไรไม่ได้ นอกจากสร้างฉบับร่าง', async () => {
    const wrapper = mount(WorkflowDesignView, { global })
    await flushPromises()

    expect(wrapper.findAll('input')).toHaveLength(0)
    expect(wrapper.text()).toContain('ยังไม่มีฉบับร่าง')

    await click(wrapper, 'สร้างฉบับร่างจากสายที่ใช้อยู่')

    expect(api.post).toHaveBeenCalledWith('/saraban/workflow/design/drafts')
  })

  it('ผู้รับผิดชอบเลือกจากรายการที่ server ส่งมา ไม่มีช่องให้พิมพ์เอง', async () => {
    respond({ withDraft: true })

    const wrapper = mount(WorkflowDesignView, { global })
    await flushPromises()

    const options = wrapper.findAll('select')[0].findAll('option')

    expect(options.map((o) => o.text())).toEqual([
      'ตรวจและดำเนินการหนังสือตามสายงาน',
      'พิจารณาและเห็นชอบหนังสือก่อนเสนอลงนาม',
      'ลงนามในหนังสือ',
    ])
  })

  /*
   * เส้นทางไม่มีช่องให้กรอก — เป็นผลของลำดับขั้นเท่านั้น
   * ถ้าวันหนึ่งมีช่องกรอกโผล่มา เทสต์นี้จะล้มและบังคับให้ทบทวน
   */
  it('บอกผู้ใช้ว่าระบบผูกเส้นทางให้จากลำดับ และไม่มีช่องให้ผูกเอง', async () => {
    respond({ withDraft: true })

    const wrapper = mount(WorkflowDesignView, { global })
    await flushPromises()

    expect(wrapper.text()).toContain('ระบบผูกเส้นทางให้จากลำดับนี้เอง')
    expect(wrapper.text()).not.toContain('เส้นทาง (transition)')
  })

  it('เลื่อนลำดับขั้นแล้วบันทึกตามลำดับที่เห็นบนหน้าจอ', async () => {
    respond({ withDraft: true })

    const wrapper = mount(WorkflowDesignView, { global })
    await flushPromises()

    // เลื่อนขั้นที่สองขึ้นมาเป็นขั้นแรก — ปุ่มของขั้นแรกถูกปิดไว้อยู่แล้ว
    const moveUp = wrapper.findAll('button').filter((b) => b.text() === 'เลื่อนขึ้น')

    expect(moveUp[0].attributes('disabled')).toBeDefined()

    await moveUp[1].trigger('click')
    await flushPromises()
    await click(wrapper, 'บันทึกฉบับร่าง')

    const [url, body] = api.put.mock.calls.at(-1)

    expect(url).toBe('/saraban/workflow/design/30')
    expect(body.steps.map((step) => step.code)).toEqual([
      'deputy_review',
      'saraban_review',
      'signature',
    ])
  })

  it('เผยแพร่ต้องยืนยัน และบอกว่าเรื่องที่เดินอยู่ไม่ถูกกระทบ', async () => {
    respond({ withDraft: true })

    const wrapper = mount(WorkflowDesignView, { global })
    await flushPromises()

    await click(wrapper, 'เผยแพร่ให้มีผลใช้งาน')

    expect(api.post).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('เรื่องที่กำลังเดินอยู่ยังใช้สายเดิมจนจบ')

    await click(wrapper, 'ยืนยันเผยแพร่สายการเดินหนังสือ')

    // บันทึกสิ่งที่เห็นบนหน้าจอก่อนเสมอ แล้วจึงเผยแพร่
    expect(api.put).toHaveBeenCalled()
    expect(api.post).toHaveBeenCalledWith('/saraban/workflow/design/30/publish')
  })

  it('ทิ้งฉบับร่างต้องยืนยันก่อน', async () => {
    respond({ withDraft: true })

    const wrapper = mount(WorkflowDesignView, { global })
    await flushPromises()

    await click(wrapper, 'ทิ้งฉบับร่าง')
    expect(api.delete).not.toHaveBeenCalled()

    await click(wrapper, 'ยืนยันทิ้งฉบับร่าง')
    expect(api.delete).toHaveBeenCalledWith('/saraban/workflow/design/30')
  })
})
