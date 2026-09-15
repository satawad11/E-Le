import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('../src/axios', () => ({ default: api }))
vi.mock('../src/stores/auth', () => ({ useAuthStore: () => ({ hasPermission: () => true }) }))
vi.mock('../src/stores/alert', () => ({ useAlertStore: () => ({ addAlert: vi.fn() }) }))
import OvertimeOrdersView from '../src/views/admin/OvertimeOrdersView.vue'

describe('ช่วงเวลาอนุมัติ OT', () => {
  it.each([true, false])('ใช้เวลาที่อนุญาตไว้เมื่อมี และใช้เวลาที่ขอเมื่อยังไม่มี (%s)', async (permitted) => {
    const order = {
      uuid: 'order-1', title: 'คำสั่งทดสอบ', status: 'pending', assignments: [],
      scheduled_start: '2026-09-20T17:00:00', scheduled_end: '2026-09-20T20:00:00',
      approved_start: permitted ? '2026-09-20T18:00:00' : null,
      approved_end: permitted ? '2026-09-20T19:00:00' : null,
    }
    api.get.mockImplementation(async (url) => ({ data: url.startsWith('/overtime/orders') ? { data: [order], current_page: 1, last_page: 1 } : [] }))
    api.post.mockReset().mockResolvedValue({ data: {} })
    const wrapper = mount(OvertimeOrdersView)
    await flushPromises()
    const approve = wrapper.findAll('button').find(b => b.text() === 'อนุมัติ')
    expect(approve).toBeTruthy()
    await approve.trigger('click')
    const dates = wrapper.findAll('input[type="datetime-local"]')
    expect(dates.map(input => input.element.value)).toEqual(permitted
      ? ['2026-09-20T18:00', '2026-09-20T19:00']
      : ['2026-09-20T17:00', '2026-09-20T20:00'])
    await wrapper.findAll('button').find(b => b.text() === 'ยืนยันอนุมัติ').trigger('click')
    await flushPromises()
    expect(api.post).toHaveBeenCalledWith('/overtime/orders/order-1/approve', expect.objectContaining({
      approved_start: permitted ? '2026-09-20T18:00' : '2026-09-20T17:00',
      approved_end: permitted ? '2026-09-20T19:00' : '2026-09-20T20:00',
    }))
    wrapper.unmount()
  })
})
