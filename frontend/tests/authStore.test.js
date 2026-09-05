import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

/**
 * SEC-025 — ผู้ใช้ต้องไม่ถูกเตะออกจากระบบเพราะความล้มเหลวชั่วคราว
 *
 * เดิม `fetchUser()` เรียก `logout()` ใน `catch` ที่ครอบทุกความล้มเหลว
 * เปิดหน้าเว็บถี่ ๆ จน `/api/user` โดน 429 หรือเจอ 5xx ครั้งเดียว ผู้ใช้ก็หลุด
 * ทั้งที่โทเคนยังไม่หมดอายุ · เทสต์ชุดนี้ตรึงเส้นแบ่งนั้นไว้
 */

const failWith = (status) =>
  Object.assign(new Error(`HTTP ${status}`), { isAxiosError: true, response: { status, data: {} } })

const networkFailure = () =>
  Object.assign(new Error('Network Error'), { isAxiosError: true, code: 'ERR_NETWORK' })

const api = { get: vi.fn(), post: vi.fn() }

vi.mock('../src/axios', () => ({
  default: api,
  isAuthenticationExpired: (error) => error?.response?.status === 401,
}))

let useAuthStore

beforeEach(async () => {
  vi.resetModules()
  vi.clearAllMocks()
  localStorage.clear()
  localStorage.setItem('token', 'โทเคนที่ยังใช้ได้')
  setActivePinia(createPinia())

  useAuthStore = (await import('../src/stores/auth')).useAuthStore
})

describe('การคงเซสชันของ auth store', () => {
  it('401 คือเซสชันหมดอายุจริง — ล้างโทเคนออก', async () => {
    api.get.mockRejectedValue(failWith(401))
    api.post.mockResolvedValue({ data: {} })
    const store = useAuthStore()

    expect(await store.fetchUser()).toBeNull()
    expect(store.token).toBeNull()
    expect(localStorage.getItem('token')).toBeNull()
  })

  it.each([
    ['429 คำขอถี่เกินไป', failWith(429)],
    ['500 ระบบขัดข้อง', failWith(500)],
    ['503 ระบบปิดปรับปรุง', failWith(503)],
    ['network error', networkFailure()],
  ])('%s ต้องไม่ทำให้ผู้ใช้หลุดจากระบบ', async (_label, failure) => {
    api.get.mockRejectedValue(failure)
    const store = useAuthStore()

    expect(await store.fetchUser()).toBeNull()
    expect(store.token).toBe('โทเคนที่ยังใช้ได้')
    expect(localStorage.getItem('token')).toBe('โทเคนที่ยังใช้ได้')
  })

  /**
   * จำลองการเปิดหน้าต่อเนื่องหลายหน้าจนเกินรูปแบบการใช้งานปกติ
   * โดยให้บางจังหวะเจอ 429 — ผู้ใช้ต้องยังอยู่ในระบบตลอด
   */
  it('เปิดหน้าต่อเนื่อง 30 ครั้งโดยเจอ 429 สลับไปมา ผู้ใช้ยังอยู่ในระบบ', async () => {
    const store = useAuthStore()
    let call = 0

    api.get.mockImplementation(() => {
      call += 1
      if (call % 3 === 0) return Promise.reject(failWith(429))
      return Promise.resolve({ data: { id: 1, name: 'ผู้ใช้ทดสอบ' } })
    })

    for (let page = 0; page < 30; page += 1) {
      await store.fetchUser({ force: true })
      expect(store.token).toBe('โทเคนที่ยังใช้ได้')
    }

    expect(localStorage.getItem('token')).toBe('โทเคนที่ยังใช้ได้')
  })

  it('คำขอ /user ที่วิ่งพร้อมกันถูกยุบเหลือใบเดียว', async () => {
    const store = useAuthStore()
    api.get.mockImplementation(() => new Promise((resolve) => setTimeout(() => resolve({ data: { id: 1 } }), 5)))

    await Promise.all([store.fetchUser(), store.fetchUser(), store.fetchUser()])

    expect(api.get).toHaveBeenCalledTimes(1)
  })
})

describe('การต่ออายุโทเคน', () => {
  it('การต่ออายุที่ล้มด้วย 429 ต้องไม่ล้างโทเคนและไม่โยนต่อ', async () => {
    const store = useAuthStore()
    store.tokenExpiresAt = Date.now() + 60 * 1000 // อยู่ในช่วงที่ต้องต่ออายุ
    api.post.mockRejectedValue(failWith(429))
    api.get.mockResolvedValue({ data: { id: 1 } })

    await store.fetchUser()

    expect(store.token).toBe('โทเคนที่ยังใช้ได้')
  })

  it('การต่ออายุที่วิ่งพร้อมกันถูกยุบเหลือใบเดียว', async () => {
    const store = useAuthStore()
    store.tokenExpiresAt = Date.now() + 60 * 1000
    api.post.mockImplementation(
      () => new Promise((resolve) => setTimeout(() => resolve({ data: { access_token: 'ใบใหม่', expires_in: 28800 } }), 5))
    )
    api.get.mockResolvedValue({ data: { id: 1 } })

    await Promise.all([store.fetchUser({ force: true }), store.fetchUser({ force: true })])

    expect(api.post).toHaveBeenCalledTimes(1)
    expect(store.token).toBe('ใบใหม่')
  })

  it('adoptToken เก็บโทเคนใบใหม่พร้อมเวลาหมดอายุ (ใช้หลังเปลี่ยนรหัสผ่าน)', () => {
    const store = useAuthStore()

    store.adoptToken('โทเคนหลังเปลี่ยนรหัสผ่าน', 28800)

    expect(store.token).toBe('โทเคนหลังเปลี่ยนรหัสผ่าน')
    expect(localStorage.getItem('token')).toBe('โทเคนหลังเปลี่ยนรหัสผ่าน')
    expect(store.tokenExpiresAt).toBeGreaterThan(Date.now())
  })
})
