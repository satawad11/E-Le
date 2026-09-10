import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()
const post = vi.fn()

vi.mock('../src/axios', () => ({
  default: {
    get: (...args) => get(...args),
    post: (...args) => post(...args),
    put: vi.fn(),
    delete: vi.fn(),
  },
}))

vi.mock('vue-router', () => ({
  onBeforeRouteLeave: vi.fn(),
  useRouter: () => ({ push: vi.fn() }),
  useRoute: () => ({ query: {}, params: {} }),
}))

vi.mock('../src/stores/alert', () => ({
  useAlertStore: () => ({ success: vi.fn(), error: vi.fn() }),
}))

const savedSteps = [
  { id: 1, step_order: 1, name: 'ตรวจสอบโดยหัวหน้าบุคลากร', approver_type: 'ROLE', role_id: 2 },
]

/*
 * คำตอบของ `/admin/workflow-steps/readiness` — กรณีที่เกิดจริงบนข้อมูลชุดพัฒนา:
 * หัวหน้าบุคลากรเป็นผู้อนุมัติเพียงคนเดียวของขั้นแรก ใบลาของเขาเองจึงค้างตลอดไป
 */
const readinessWithProblem = {
  chain: 'leave',
  checked_users: 6,
  blocked_users: 1,
  steps: [{
    step_order: 1,
    name: 'ตรวจสอบโดยหัวหน้าบุคลากร',
    approver_type: 'ROLE',
    config_problem: null,
    no_approver_count: 0,
    self_only_count: 1,
    no_approver_sample: [],
    self_only_sample: [{ id: 4, name: 'นายสมปอง หัวหน้าบุคลากร' }],
  }],
  blocked: [],
  data_gaps: {
    without_org_unit: { count: 0, sample: [] },
    without_personnel_type: { count: 1, sample: [{ id: 6, name: 'นายสมเกียรติ เจ้าหน้าที่สารบัญ' }] },
    units_without_manager: { count: 0, sample: [], not_applicable: true },
  },
}

const mountView = async (readiness = readinessWithProblem) => {
  get.mockImplementation((url) => {
    if (url === '/admin/roles') return Promise.resolve({ data: [{ id: 2, name: 'HR Head' }] })
    if (url === '/users/list') return Promise.resolve({ data: [] })

    return Promise.resolve({ data: { steps: savedSteps, fingerprint: 'abc' }, headers: {} })
  })
  post.mockImplementation((url) => {
    if (url === '/admin/workflow-steps/readiness') return Promise.resolve({ data: readiness })

    return Promise.resolve({ data: {} })
  })

  const { default: View } = await import('../src/views/admin/WorkflowManagementView.vue')
  const wrapper = mount(View)
  await flushPromises()

  return wrapper
}

describe('ความพร้อมของข้อมูลบนหน้าตั้งค่าสายอนุมัติ', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    get.mockReset()
    post.mockReset()
  })

  it('ตรวจให้เองทันทีที่เปิดหน้า โดยไม่ต้องกดอะไร', async () => {
    await mountView()

    const called = post.mock.calls.filter(([url]) => url === '/admin/workflow-steps/readiness')
    expect(called).toHaveLength(1)
    // ต้องส่งสายที่เห็นบนหน้าจอไปด้วย ไม่ใช่ให้เซิร์ฟเวอร์เดาเอง
    expect(called[0][1].steps).toHaveLength(1)
    expect(called[0][1].chain).toBe('leave')
  })

  it('บอกว่ามีผู้ยื่นกี่คนที่จะติด และติดเพราะอะไร', async () => {
    const wrapper = await mountView()

    expect(wrapper.text()).toContain('ผู้ยื่น 1 จาก 6 คนจะติดอยู่ที่สายนี้')
    expect(wrapper.text()).toContain('ผู้อนุมัติที่หาได้คือผู้ยื่นเอง')
    expect(wrapper.text()).toContain('นายสมปอง หัวหน้าบุคลากร')
    expect(wrapper.text()).toContain('ยังไม่ระบุประเภทบุคลากร 1 คน')
  })

  it('ไม่ทักเรื่องหัวหน้าหน่วยงานเมื่อสายไม่ได้ใช้ผังองค์กร', async () => {
    const wrapper = await mountView()

    expect(wrapper.text()).not.toContain('หน่วยงานที่ยังไม่มีหัวหน้า')
  })

  it('บอกว่าพร้อมใช้งานเมื่อไม่มีใครติด', async () => {
    const wrapper = await mountView({
      ...readinessWithProblem,
      blocked_users: 0,
      steps: [{ ...readinessWithProblem.steps[0], self_only_count: 0, self_only_sample: [] }],
      data_gaps: {
        without_org_unit: { count: 0, sample: [] },
        without_personnel_type: { count: 0, sample: [] },
        units_without_manager: { count: 0, sample: [], not_applicable: true },
      },
    })

    expect(wrapper.text()).toContain('ตรวจผู้ใช้ 6 คน ไม่พบผู้ที่จะติดในสายนี้')
  })
})
