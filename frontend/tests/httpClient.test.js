import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'

/**
 * SEC-025 — เส้นแบ่งระหว่าง "หมดสิทธิ์" กับ "ล้มเหลวชั่วคราว"
 *
 * มีเพียง HTTP 401 เท่านั้นที่แปลว่าการยืนยันตัวตนหมดอายุ
 * 429 (คำขอถี่) · 5xx (ระบบขัดข้อง) · network error ต้องไม่ทำให้ผู้ใช้หลุดจากระบบ
 * ถ้าเส้นนี้เพี้ยน ผู้ใช้จะถูกเตะออกกลางคันโดยไม่มีสาเหตุที่อธิบายได้
 */

let api
let isAuthenticationExpired
let httpRetryConfig
let adapter

const respond = (config, status, data = {}, headers = {}) =>
  Promise.reject(
    Object.assign(new Error(`Request failed with status code ${status}`), {
      isAxiosError: true,
      config,
      response: { status, data, headers, statusText: '', config },
    })
  )

beforeEach(async () => {
  vi.resetModules()
  localStorage.clear()

  const module = await import('../src/axios')
  api = module.default
  isAuthenticationExpired = module.isAuthenticationExpired
  httpRetryConfig = module.httpRetryConfig
  httpRetryConfig.baseDelayMs = 1
  httpRetryConfig.maxDelayMs = 2

  adapter = vi.fn()
  api.defaults.adapter = adapter

  // jsdom ห้ามเขียน window.location.href ตรง ๆ — แทนที่ด้วยวัตถุธรรมดาเพื่อสังเกตการ redirect
  delete window.location
  window.location = { pathname: '/dashboard', href: '/dashboard' }
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('การแยกแยะความล้มเหลวของ HTTP client', () => {
  it('401 เท่านั้นที่นับว่าการยืนยันตัวตนหมดอายุ', () => {
    expect(isAuthenticationExpired({ response: { status: 401 } })).toBe(true)
    expect(isAuthenticationExpired({ response: { status: 429 } })).toBe(false)
    expect(isAuthenticationExpired({ response: { status: 500 } })).toBe(false)
    expect(isAuthenticationExpired({})).toBe(false)
  })

  it('401 ล้างโทเคนแล้วพากลับหน้าเข้าสู่ระบบ', async () => {
    localStorage.setItem('token', 'โทเคนที่หมดอายุแล้ว')
    localStorage.setItem('token_expires_at', '123')
    adapter.mockImplementation((config) => respond(config, 401))

    await expect(api.get('/leaves')).rejects.toBeTruthy()

    expect(localStorage.getItem('token')).toBeNull()
    expect(localStorage.getItem('token_expires_at')).toBeNull()
    expect(window.location.href).toBe('/login')
  })

  it('429 ไม่ล้างโทเคน ลองใหม่ตามจำนวนที่กำหนด แล้วคืนข้อความไทย', async () => {
    localStorage.setItem('token', 'โทเคนที่ยังใช้ได้')
    adapter.mockImplementation((config) => respond(config, 429, {}, { 'retry-after': '0' }))

    const error = await api.get('/leaves').catch((e) => e)

    // ครั้งแรก + ลองใหม่อีก 2 ครั้ง = 3
    expect(adapter).toHaveBeenCalledTimes(1 + httpRetryConfig.maxRetries)
    expect(localStorage.getItem('token')).toBe('โทเคนที่ยังใช้ได้')
    expect(window.location.href).not.toBe('/login')
    expect(error.thaiMessage).toBe('มีการส่งคำขอถี่เกินไป กรุณารอสักครู่แล้วลองใหม่อีกครั้ง')
  })

  it('429 ที่หายไปเองระหว่างลองใหม่ ถือว่าคำขอสำเร็จ', async () => {
    let call = 0
    adapter.mockImplementation((config) => {
      call += 1
      if (call === 1) return respond(config, 429, {}, { 'retry-after': '0' })
      return Promise.resolve({ status: 200, data: { ok: true }, headers: {}, config })
    })

    const response = await api.get('/leaves')

    expect(response.data).toEqual({ ok: true })
    expect(adapter).toHaveBeenCalledTimes(2)
  })

  it('429 ของคำขอที่เปลี่ยนข้อมูลจะไม่ถูกยิงซ้ำอัตโนมัติ', async () => {
    adapter.mockImplementation((config) => respond(config, 429, {}, { 'retry-after': '0' }))

    await expect(api.post('/leaves', {})).rejects.toBeTruthy()

    expect(adapter).toHaveBeenCalledTimes(1)
  })

  it('ข้อความอังกฤษของเฟรมเวิร์กใน 429 ถูกแทนด้วยข้อความไทยก่อนถึงหน้าจอ', async () => {
    adapter.mockImplementation((config) =>
      respond(config, 429, { message: 'Too Many Attempts.' }, { 'retry-after': '0' })
    )

    const error = await api.get('/leaves').catch((e) => e)

    expect(error.response.data.message).toBe('มีการส่งคำขอถี่เกินไป กรุณารอสักครู่แล้วลองใหม่อีกครั้ง')
  })

  it('ข้อความไทยที่ระบบตั้งใจส่งมาเองไม่ถูกแทนที่', async () => {
    adapter.mockImplementation((config) =>
      respond(config, 429, { message: 'บัญชีถูกล็อกชั่วคราว กรุณาลองใหม่ในภายหลัง' }, { 'retry-after': '0' })
    )

    const error = await api.post('/login', {}).catch((e) => e)

    expect(error.response.data.message).toBe('บัญชีถูกล็อกชั่วคราว กรุณาลองใหม่ในภายหลัง')
  })

  it('5xx ไม่ล้างโทเคนและไม่พาไปหน้าเข้าสู่ระบบ', async () => {
    localStorage.setItem('token', 'โทเคนที่ยังใช้ได้')
    adapter.mockImplementation((config) => respond(config, 503))

    const error = await api.get('/dashboard').catch((e) => e)

    expect(localStorage.getItem('token')).toBe('โทเคนที่ยังใช้ได้')
    expect(window.location.href).not.toBe('/login')
    expect(error.thaiMessage).toBe('ระบบกำลังปิดปรับปรุงชั่วคราวหรือไม่สามารถให้บริการได้ในขณะนี้')
  })

  it('network error ไม่ล้างโทเคนและคืนข้อความไทย', async () => {
    localStorage.setItem('token', 'โทเคนที่ยังใช้ได้')
    adapter.mockImplementation(() =>
      Promise.reject(Object.assign(new Error('Network Error'), { isAxiosError: true, code: 'ERR_NETWORK' }))
    )

    const error = await api.get('/dashboard').catch((e) => e)

    expect(localStorage.getItem('token')).toBe('โทเคนที่ยังใช้ได้')
    expect(window.location.href).not.toBe('/login')
    expect(error.thaiMessage).toContain('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้')
  })
})

describe('การรวมคำขอที่ซ้ำกัน', () => {
  it('คำขอที่ใช้ร่วมกันและวิ่งพร้อมกันถูกยุบเหลือใบเดียว', async () => {
    adapter.mockImplementation(
      (config) => new Promise((resolve) => setTimeout(() => resolve({ status: 200, data: { id: 1 }, headers: {}, config }), 5))
    )

    const results = await Promise.all([api.get('/user'), api.get('/user'), api.get('/user')])

    expect(adapter).toHaveBeenCalledTimes(1)
    expect(results.map((r) => r.data.id)).toEqual([1, 1, 1])
  })

  it('เมื่อคำขอก่อนหน้าจบแล้ว คำขอใหม่ยิงจริงอีกครั้ง', async () => {
    adapter.mockImplementation((config) => Promise.resolve({ status: 200, data: {}, headers: {}, config }))

    await api.get('/settings')
    await api.get('/settings')

    expect(adapter).toHaveBeenCalledTimes(2)
  })
})
