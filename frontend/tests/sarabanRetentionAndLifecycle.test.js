import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import RetentionPanel from '../src/components/saraban/RetentionPanel.vue'
import { useAuthStore } from '../src/stores/auth'

vi.mock('../src/axios', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
  },
}))

describe('Saraban Retention & Lifecycle Components', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    const pinia = createPinia()
    setActivePinia(pinia)
    const auth = useAuthStore()
    auth.user = { permissions: ['saraban.policy.manage', 'saraban.config.manage'] }
  })

  it('renders RetentionPanel with vocational presets and updates years on preset click', async () => {
    const wrapper = mount(RetentionPanel, {
      props: { documentId: 101 },
      global: {
        directives: {
          can: () => {},
        },
        stubs: {
          AppButton: { template: '<button @click="$emit(\'click\')"><slot /></button>' },
          AppInput: {
            props: ['modelValue', 'label', 'type'],
            emits: ['update:modelValue'],
            template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
          },
          AppBadge: { template: '<span><slot /></span>' },
          ConfirmOperation: true,
          RouterLink: true,
        },
      },
    })

    // Simulate state loaded with active document retention
    wrapper.vm.state = {
      on_hold: false,
      schedule: {
        retain_years: 5,
        retain_until: '2031-09-16',
        status: 'scheduled',
      },
      holds: [],
    }
    await wrapper.vm.$nextTick()

    expect(wrapper.text()).toContain('อายุการเก็บรักษาและการอายัดหนังสือ')
    expect(wrapper.text()).toContain('ไม่มีการอายัดที่มีผล')
    expect(wrapper.text()).toContain('๕ ปี (การเงิน/พัสดุ/ธุรการ)')

    // Click 10-year preset button
    const presetBtn = wrapper.findAll('button').find(b => b.text().includes('๑๐ ปี'))
    expect(presetBtn).toBeDefined()
    await presetBtn.trigger('click')

    expect(wrapper.vm.years).toBe(10)
  })

  it('handles legal hold reason presets correctly', async () => {
    const wrapper = mount(RetentionPanel, {
      props: { documentId: 102 },
      global: {
        directives: {
          can: () => {},
        },
        stubs: {
          AppButton: { template: '<button @click="$emit(\'click\')"><slot /></button>' },
          AppInput: {
            props: ['modelValue', 'label', 'type'],
            emits: ['update:modelValue'],
            template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
          },
          AppBadge: { template: '<span><slot /></span>' },
          ConfirmOperation: true,
          RouterLink: true,
        },
      },
    })

    wrapper.vm.state = {
      on_hold: true,
      schedule: null,
      holds: [
        {
          id: 1,
          reference: 'สตง. ๐๑/๒๕๖๙',
          reason: 'การตรวจสอบของ สตง./ป.ป.ช.',
          active: true,
          placed_at: '2026-09-16',
        },
      ],
    }
    await wrapper.vm.$nextTick()

    expect(wrapper.text()).toContain('หนังสือฉบับนี้ถูกอายัด')
    expect(wrapper.text()).toContain('สตง. ๐๑/๒๕๖๙')

    // Click reason preset
    const reasonBtn = wrapper.findAll('button').find(b => b.text().includes('การตรวจสอบของ สตง./ป.ป.ช.'))
    expect(reasonBtn).toBeDefined()
    await reasonBtn.trigger('click')

    expect(wrapper.vm.reason).toBe('การตรวจสอบของ สตง./ป.ป.ช.')
  })
})
