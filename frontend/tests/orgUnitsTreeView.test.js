import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()

vi.mock('../src/axios', () => ({
  default: {
    get: (...args) => get(...args),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}))

const OrgUnitsManagementView = (await import('../src/views/admin/OrgUnitsManagementView.vue')).default

/**
 * หน้าโครงสร้างหน่วยงานต้องอ่านออกเมื่อผังลึกหลายชั้น
 *
 * ผังของวิทยาลัยจริงมีสามชั้น (ฝ่าย > งาน และ ฝ่ายวิชาการ > แผนกวิชา) ประมาณสามสิบหน่วย
 * API คืนรายการเรียงตาม `parent_id` แล้วตามชื่อ ซึ่งอ่านไม่ออกว่าหน่วยไหนอยู่ใต้ฝ่ายใด
 * หน้าจอจึงต้องเรียงแบบต้นไม้และย่อหน้าตามชั้น
 */
const chart = [
  { id: 1, parent_id: null, name: 'วิทยาลัยเทคนิคทดสอบ', type: 'organization', is_active: true, members: 0, is_root: true },
  { id: 2, parent_id: 1, name: 'ฝ่ายบริหารทรัพยากร', type: 'division', is_active: true, members: 0, is_root: false },
  { id: 3, parent_id: 2, name: 'งานบุคลากร', type: 'section', is_active: true, members: 4, is_root: false },
  { id: 4, parent_id: 1, name: 'ฝ่ายวิชาการ', type: 'division', is_active: true, members: 0, is_root: false },
  { id: 5, parent_id: 4, name: 'แผนกวิชาช่างยนต์', type: 'department', is_active: true, members: 9, is_root: false },
]

const mountView = async () => {
  setActivePinia(createPinia())
  get.mockResolvedValue({ data: chart })

  const wrapper = mount(OrgUnitsManagementView, {
    global: { stubs: { RouterLink: true } },
  })
  await flushPromises()

  return wrapper
}

describe('OrgUnitsManagementView — ผังแบบต้นไม้', () => {
  beforeEach(() => {
    get.mockReset()
  })

  it('เรียงหน่วยงานแบบต้นไม้ ลูกตามหลังแม่ทันที ไม่ใช่เรียงตาม parent_id', async () => {
    const wrapper = await mountView()

    const names = wrapper.vm.orderedUnits.map((unit) => unit.name)
    expect(names).toEqual([
      'วิทยาลัยเทคนิคทดสอบ',
      'ฝ่ายบริหารทรัพยากร',
      'งานบุคลากร',
      'ฝ่ายวิชาการ',
      'แผนกวิชาช่างยนต์',
    ])
  })

  it('ให้ความลึกตามชั้นจริง เพื่อใช้ย่อหน้าในตารางและในช่องเลือกหน่วยงานแม่', async () => {
    const wrapper = await mountView()

    expect(wrapper.vm.orderedUnits.map((unit) => unit.depth)).toEqual([0, 1, 2, 1, 2])
  })

  it('แสดงระดับของหน่วยงานเป็นภาษาไทย', async () => {
    const wrapper = await mountView()

    const text = wrapper.text()
    expect(text).toContain('ฝ่าย')
    expect(text).toContain('งาน')
    expect(text).toContain('แผนกวิชา')
  })

  it('ไม่ทำหน่วยงานที่หาแม่ไม่เจอหายไปจากหน้าจอ', async () => {
    setActivePinia(createPinia())
    get.mockResolvedValue({
      data: [
        ...chart,
        { id: 99, parent_id: 4242, name: 'หน่วยงานกำพร้า', type: 'section', is_active: true, members: 0, is_root: false },
      ],
    })

    const wrapper = mount(OrgUnitsManagementView, { global: { stubs: { RouterLink: true } } })
    await flushPromises()

    expect(wrapper.vm.orderedUnits.map((unit) => unit.name)).toContain('หน่วยงานกำพร้า')
  })
})
