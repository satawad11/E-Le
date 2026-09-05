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

const PolicyCenterView = (await import('../src/views/saraban/PolicyCenterView.vue')).default

/**
 * INT-16 — ศูนย์นโยบาย (SoT §18)
 *
 * เรื่องที่ตรึงไว้ที่ชั้นหน้าจอ:
 *
 *   1. แสดง **ค่าที่ใช้บังคับอยู่จริง** ไม่ใช่ค่าเริ่มต้นในโค้ด
 *   2. บอกได้ว่านโยบายไหนมีฉบับร่างค้างอยู่
 *   3. "บันทึกร่าง" กับ "ประกาศใช้" เป็นคนละการกระทำ — บันทึกร่างต้องไม่ประกาศ
 */
const policiesPayload = {
  policies: [
    {
      key: 'distribution',
      name: 'การเวียนหนังสือและกรอบเวลารับทราบ',
      effective: { acknowledgement_due_days: 7 },
      defaults: { acknowledgement_due_days: 3 },
      published_version: 2,
      published_at: '2026-09-01T09:00:00+07:00',
      has_draft: true,
      draft_payload: { acknowledgement_due_days: 9 },
    },
  ],
}

describe('PolicyCenterView — ศูนย์นโยบาย', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    get.mockReset()
    post.mockReset()
    get.mockResolvedValue({ data: policiesPayload })
    post.mockResolvedValue({ data: {} })
  })

  const mountView = async () => {
    const wrapper = mount(PolicyCenterView, { global: { directives: { can: {} } } })
    await flushPromises()

    return wrapper
  }

  it('โหลดนโยบายจาก endpoint ของสารบรรณ', async () => {
    await mountView()

    expect(get).toHaveBeenCalledWith('/saraban/policies')
  })

  it('แสดงค่าที่ใช้บังคับอยู่จริงและเวอร์ชันที่ประกาศ', async () => {
    const wrapper = await mountView()
    const text = wrapper.text()

    expect(text).toContain('การเวียนหนังสือและกรอบเวลารับทราบ')
    expect(text).toContain('ใช้เวอร์ชันที่ 2')
    // ค่าที่ใช้จริง (7) ไม่ใช่ค่าเริ่มต้นในโค้ด (3)
    expect(text).toContain('7')
    expect(text).toContain('มีฉบับร่างรอประกาศ')
  })

  it('บันทึกร่างต้องไม่ประกาศใช้ไปด้วย', async () => {
    const wrapper = await mountView()

    await wrapper.findAll('button').find((button) => button.text() === 'แก้ไข').trigger('click')
    await flushPromises()

    const save = [...document.body.querySelectorAll('button')]
      .find((button) => button.textContent.trim() === 'บันทึกร่าง')
    save.click()
    await flushPromises()

    const calledPaths = post.mock.calls.map(([path]) => path)
    expect(calledPaths).toContain('/saraban/policies/distribution/draft')
    expect(calledPaths).not.toContain('/saraban/policies/distribution/publish')
  })

  it('ใช้ layout convention เดิมของระบบ', async () => {
    const wrapper = await mountView()

    expect(wrapper.html()).toContain('rounded-2xl')
  })
})

/** หน้าจอ INT-16 ต้องไม่พก palette ของตัวเองเข้ามา (SoT §2) */
describe('หน้าจอ INT-16 ใช้สีของระบบเดิมเท่านั้น', () => {
  it('ไม่มีรหัสสีนอกเหนือจาก #800000 และ #600000', async () => {
    const { readFileSync } = await import('node:fs')

    const sources = [
      'src/views/saraban/PolicyCenterView.vue',
      'src/views/saraban/AuthorityMatrixView.vue',
      'src/views/saraban/NumberSeriesView.vue',
      'src/views/admin/IntegrationsView.vue',
    ]

    for (const path of sources) {
      const hexColours = readFileSync(path, 'utf8').match(/#[0-9a-fA-F]{3,8}\b/g) || []

      for (const colour of hexColours) {
        expect(['#800000', '#600000']).toContain(colour.toLowerCase())
      }
    }
  })
})
