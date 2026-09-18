import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import SignDocumentView from '../src/views/saraban/SignDocumentView.vue'
import EndorseDocumentView from '../src/views/saraban/EndorseDocumentView.vue'
import { useAuthStore } from '../src/stores/auth'

vi.mock('vue-router', () => ({
  useRoute: () => ({
    params: { id: '101' },
    query: {},
  }),
  useRouter: () => ({
    push: vi.fn(),
  }),
}))

vi.mock('../src/axios', () => ({
  default: {
    get: vi.fn((url) => {
      if (url.includes('/annotations')) {
        return Promise.resolve({
          data: {
            version: { id: 1, version_no: 1, stage: 'original', file_path: 'saraban/101/doc.pdf' },
            annotations: [],
            editable: true,
            receive_stamp: { receive_no: '๑๒๓/๒๕๖๙', received_at: '2026-09-16 09:00:00', unit_name: 'งานสารบรรณ' },
          },
        })
      }
      if (url.includes('/workflow')) {
        return Promise.resolve({ data: { steps: [] } })
      }
      if (url.includes('/signatures')) {
        return Promise.resolve({ data: { evidences: [] } })
      }
      if (url.includes('/templates')) {
        return Promise.resolve({
          data: {
            endorsement_presets: [
              { label: 'ทราบ / ดำเนินการตามระเบียบ', text: 'ทราบ ดำเนินการตามระเบียบ' },
              { label: 'มอบ รอง ผอ. ฝ่ายวิชาการ', text: 'มอบ รองผู้อำนวยการฝ่ายวิชาการ ดำเนินการต่อไป' },
            ],
          },
        })
      }
      return Promise.resolve({ data: {} })
    }),
    post: vi.fn(() => Promise.resolve({ data: { success: true } })),
    put: vi.fn(() => Promise.resolve({ data: { success: true } })),
  },
}))

vi.mock('../src/utils/secureFile', () => ({
  fetchObjectUrl: vi.fn(() => Promise.resolve('blob:http://localhost/sample-blob')),
}))

describe('SARABAN Phase 5 — Digital Signatures, Endorsement & Stamping Workspace', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('SignDocumentView — แสดงปุ่มใช้ชื่อ-ตำแหน่งตนเอง และกรอกข้อความกำกับอัตโนมัติ', async () => {
    const authStore = useAuthStore()
    authStore.user = {
      name: 'นายวิชาญ สอนดี',
      position: 'รองผู้อำนวยการฝ่ายวิชาการ',
      current_assignment: {
        position: 'รองผู้อำนวยการฝ่ายวิชาการ',
        org_unit: { name: 'ฝ่ายวิชาการ' },
      },
    }

    const wrapper = mount(SignDocumentView, {
      global: {
        stubs: {
          teleport: true,
          transition: true,
          SigningContextPanel: true,
          PdfWorkspace: true,
          RenderedPreview: true,
          ConfirmOperation: true,
          HandwritingCanvas: true,
        },
      },
    })

    await flushPromises()

    expect(wrapper.text()).toContain('ลงนามในหนังสือ')
    expect(wrapper.text()).toContain('ใช้ชื่อ-ตำแหน่งตนเอง')

    // Find and click the autofill button
    const autofillBtn = wrapper.findAll('button').find((b) => b.text().includes('ใช้ชื่อ-ตำแหน่งตนเอง'))
    expect(autofillBtn).toBeDefined()
    await autofillBtn.trigger('click')
    await flushPromises()

    // noteText input should now have the signer's name and position
    const textInput = wrapper.find('input')
    expect(textInput.exists()).toBe(true)
    expect(textInput.element.value).toContain('นายวิชาญ สอนดี')
    expect(textInput.element.value).toContain('รองผู้อำนวยการฝ่ายวิชาการ')
  })

  it('EndorseDocumentView — แสดงชุดข้อความเกษียนด่วน ๔ ฝ่ายอาชีวศึกษา', async () => {
    const wrapper = mount(EndorseDocumentView, {
      global: {
        stubs: {
          teleport: true,
          transition: true,
          SigningContextPanel: true,
          PdfWorkspace: true,
          RenderedPreview: true,
          ConfirmOperation: true,
          HandwritingCanvas: true,
        },
      },
    })

    await flushPromises()

    expect(wrapper.text()).toContain('เกษียนหนังสือ')
    expect(wrapper.text()).toContain('ข้อความเกษียนด่วน (สำหรับ ผอ. / รอง ผอ. ๔ ฝ่าย)')

    // Click preset button
    const presetBtn = wrapper.findAll('button').find((b) => b.text().includes('มอบ รอง ผอ. ฝ่ายวิชาการ'))
    expect(presetBtn).toBeDefined()
    await presetBtn.trigger('click')
    await flushPromises()

    // Textarea should contain preset text
    const textarea = wrapper.find('textarea')
    expect(textarea.element.value).toBe('มอบ รองผู้อำนวยการฝ่ายวิชาการ ดำเนินการต่อไป')
  })
})