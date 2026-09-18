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

const BiometricHubView = (await import('../src/views/admin/BiometricHubView.vue')).default

describe('BiometricHubView — นำเข้าข้อมูลเครื่องสแกนนิ้ว/ใบหน้า', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    get.mockReset()
    post.mockReset()
  })

  const mountView = async () => {
    const wrapper = mount(BiometricHubView, {
      global: {
        stubs: {
          'router-link': {
            template: '<a><slot /></a>',
          },
        },
      },
    })
    await flushPromises()
    return wrapper
  }

  it('เรนเดอร์หัวข้อและคำอธิบาย Biometric Hub ภาษาไทย 100%', async () => {
    const wrapper = await mountView()
    const text = wrapper.text()

    expect(text).toContain('นำเข้าข้อมูลเครื่องสแกนนิ้วและใบหน้า (Biometric Hub)')
    expect(text).toContain('อัปโหลดไฟล์บันทึกเวลาสแกน')
    expect(text).toContain('ลากไฟล์มาวางที่นี่ หรือคลิกเพื่อเลือกไฟล์')
  })

  it('สามารถเลือกไฟล์และส่งคำขอไปยัง API เพื่อนำเข้าเวลาได้สำเร็จ', async () => {
    post.mockResolvedValue({
      data: {
        success: true,
        data: {
          filename: 'attendance.dat',
          total_events: 10,
          imported: 8,
          skipped: 2,
        },
      },
    })

    const wrapper = await mountView()

    const file = new File(['999\t2026-09-16 08:00:00\t0\n'], 'attendance.dat', { type: 'text/plain' })
    const fileInput = wrapper.find('input[type="file"]')

    Object.defineProperty(fileInput.element, 'files', {
      value: [file],
      writable: true,
    })

    await fileInput.trigger('change')
    await flushPromises()

    expect(wrapper.text()).toContain('attendance.dat')

    const submitBtn = wrapper.findAll('button').find((b) => b.text().includes('เริ่มประมวลผลและนำเข้าเวลา'))
    expect(submitBtn).toBeDefined()

    await submitBtn.trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith(
      '/v1/attendance/biometric/import-file',
      expect.any(FormData),
      expect.objectContaining({
        headers: { 'Content-Type': 'multipart/form-data' },
      })
    )

    expect(wrapper.text()).toContain('ผลการนำเข้าและประมวลผลข้อมูลเวลา')
    expect(wrapper.text()).toContain('นำเข้าและคำนวณเวลาสำเร็จ')
    expect(wrapper.text()).toContain('8 รายการ')
    expect(wrapper.text()).toContain('2 รายการ')
  })
})
