import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '../src/stores/auth'

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

vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn() }) }))

const DocumentCenterView = (await import('../src/views/admin/DocumentCenterView.vue')).default

/**
 * DC-04 / DC-05 และข้อเสนอ UI ของ DOCUMENT-CENTER-REVIEW-2026-09-19
 */
const templates = [
  { id: 1, name: 'ใบลาป่วย', code: 'leave_sick', module: 'leave', document_type: 'FORM', render_mode: 'html', bound_entities: 1 },
  { id: 2, name: 'ใบลาพักผ่อน (PDF)', code: 'leave_pdf', module: 'LEAVE', document_type: 'FORM', render_mode: 'pdf_overlay', bound_entities: 1 },
  { id: 3, name: 'หนังสือรับรองการทำงาน', code: 'hr_cert', module: 'hr', document_type: 'FORM', render_mode: 'html', bound_entities: 0 },
]

const generatedPage = (page, total) => ({
  data: Array.from({ length: page === 1 ? Math.min(30, total) : total - 30 }, (_, i) => ({
    id: (page - 1) * 30 + i + 1,
    source_type: 'Leave',
    source_id: String(i + 1),
    source_label: `ใบลา ลป.${i + 1}`,
    revision: 1,
    status: 'FINALIZED',
    generated_at: '2026-03-10T10:00:00Z',
  })),
  total,
  current_page: page,
  last_page: Math.ceil(total / 30),
})

const responses = (overrides = {}) => (url, config) => {
  const table = {
    '/admin/document-engine/overview': { engine_enabled: true, enabled_leave_types: [], templates: 3, pdf_templates: 1, active_versions: 3, versions: 3, field_definitions: 0, data_sources: 0, generated_files: 31, finalized_files: 31 },
    '/admin/document-engine/templates': templates,
    '/admin/document-engine/fields': [],
    '/admin/document-engine/data-sources': [],
    '/admin/document-engine/bindings': { rules: [], entities: {}, unbound_leave_types: [] },
    '/admin/document-engine/calibrations': [],
    '/admin/document-engine/leave-type-forms': { rows: [] },
    ...overrides,
  }

  if (url === '/admin/document-engine/generated') {
    return Promise.resolve({ data: generatedPage(config?.params?.page ?? 1, 31) })
  }

  const value = table[url]
  return value instanceof Error ? Promise.reject(value) : Promise.resolve({ data: value })
}

const mountAs = async (permissions, overrides = {}) => {
  setActivePinia(createPinia())
  useAuthStore().user = { id: 9, role_names: [], permission_names: permissions }
  get.mockImplementation(responses(overrides))

  const wrapper = mount(DocumentCenterView, { global: { stubs: { 'router-link': true } } })
  await flushPromises()

  return wrapper
}

const openTab = async (wrapper, label) => {
  await wrapper.findAll('button').find(b => b.text() === label).trigger('click')
  await flushPromises()
}

describe('DocumentCenterView', () => {
  beforeEach(() => {
    get.mockReset()
    post.mockReset()
  })

  it('เปิดเอกสารลำดับที่ 31 ได้ผ่านการแบ่งหน้า (DC-04)', async () => {
    const wrapper = await mountAs(['document.template.view', 'document.final.download'])
    await openTab(wrapper, 'เอกสารที่สร้างแล้ว')

    expect(wrapper.text()).toContain('ใบลา ลป.30')
    expect(wrapper.text()).toContain('แสดง 1-30 จาก 31 รายการ')

    await wrapper.find('nav[aria-label="การแบ่งหน้า"]').findAll('button').find(b => b.text() === '2').trigger('click')
    await flushPromises()

    const lastCall = get.mock.calls.filter(([url]) => url === '/admin/document-engine/generated').at(-1)
    expect(lastCall[1].params.page).toBe(2)
    expect(wrapper.text()).toContain('แสดง 31-31 จาก 31 รายการ')
  })

  it('ส่งตัวกรองไปที่เซิร์ฟเวอร์ และเริ่มที่หน้า 1 ใหม่ (DC-04)', async () => {
    const wrapper = await mountAs(['document.template.view'])
    await openTab(wrapper, 'เอกสารที่สร้างแล้ว')

    await wrapper.find('select').setValue('SUPERSEDED')
    await wrapper.find('input[type="search"]').setValue('ลป.12')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    const lastCall = get.mock.calls.filter(([url]) => url === '/admin/document-engine/generated').at(-1)
    expect(lastCall[1].params).toEqual({ page: 1, status: 'SUPERSEDED', q: 'ลป.12' })
  })

  it('หมวดแบบฟอร์มการลานับเฉพาะแม่แบบของโมดูลลา ไม่ว่าตัวพิมพ์ใด (DC-05)', async () => {
    const wrapper = await mountAs(['document.template.view'])

    await wrapper.findAll('button').find(b => b.text() === 'แบบฟอร์มการลา').trigger('click')
    expect(wrapper.text()).toContain('ใบลาป่วย')
    expect(wrapper.text()).toContain('ใบลาพักผ่อน (PDF)')
    expect(wrapper.text()).not.toContain('หนังสือรับรองการทำงาน')

    await wrapper.findAll('button').find(b => b.text() === 'แบบฟอร์มทั่วไป / อื่น ๆ').trigger('click')
    expect(wrapper.text()).toContain('หนังสือรับรองการทำงาน')
    expect(wrapper.text()).not.toContain('ใบลาป่วย')
  })

  it('ผู้ดูอย่างเดียวไม่เห็นปุ่มแก้ไข ลบ สร้าง และดาวน์โหลด', async () => {
    const wrapper = await mountAs(['document.template.view'])

    expect(wrapper.text()).not.toContain('+ สร้างแม่แบบใหม่')
    expect(wrapper.text()).not.toContain('แก้เนื้อหา')
    expect(wrapper.findAll('button').some(b => b.text() === 'ลบ')).toBe(false)

    await openTab(wrapper, 'เอกสารที่สร้างแล้ว')
    expect(wrapper.findAll('button').some(b => b.text() === 'ดาวน์โหลด')).toBe(false)
  })

  it('ผู้จัดการแม่แบบเห็นปุ่มจัดการ', async () => {
    const wrapper = await mountAs(['document.template.manage', 'document.final.download'])

    expect(wrapper.text()).toContain('+ สร้างแม่แบบใหม่')
    expect(wrapper.text()).toContain('แก้เนื้อหา')

    await openTab(wrapper, 'เอกสารที่สร้างแล้ว')
    expect(wrapper.findAll('button').some(b => b.text() === 'ดาวน์โหลด')).toBe(true)
  })

  it('ส่วนที่โหลดไม่สำเร็จไม่ทำให้ส่วนอื่นหายไป', async () => {
    const wrapper = await mountAs(['document.template.view'], {
      '/admin/document-engine/fields': new Error('500'),
    })

    expect(wrapper.find('[role="alert"]').text()).toContain('สารบัญตัวแปร')
    expect(wrapper.text()).toContain('ใบลาป่วย')
  })
})
