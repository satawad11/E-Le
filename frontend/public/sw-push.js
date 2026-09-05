/*
 * Service Worker สำหรับการแจ้งเตือนผ่านเบราว์เซอร์ (SoT §16 · INT-14)
 *
 * ทำแค่สองอย่างโดยตั้งใจ: แสดงการแจ้งเตือนที่ได้รับ และเปิดหน้าที่เกี่ยวข้อง
 * เมื่อผู้ใช้กด — **ไม่ทำ cache ใด ๆ** เพราะระบบนี้ไม่ได้ออกแบบให้ทำงาน
 * แบบออฟไลน์ การแคชหน้าเว็บของระบบราชการโดยไม่ตั้งใจทำให้ผู้ใช้เห็นข้อมูลเก่า
 * โดยไม่รู้ตัว ซึ่งอันตรายกว่าการโหลดช้า
 */
self.addEventListener('push', (event) => {
  if (!event.data) return

  let payload = {}

  try {
    payload = event.data.json()
  } catch {
    payload = { title: 'การแจ้งเตือน', body: event.data.text() }
  }

  event.waitUntil(
    self.registration.showNotification(payload.title || 'การแจ้งเตือน', {
      body: payload.body || '',
      icon: '/favicon.svg',
      badge: '/favicon.svg',
      data: { url: payload.url || '/' },
    }),
  )
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()

  const target = event.notification.data?.url || '/'

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
      // โฟกัสแท็บที่เปิดระบบอยู่แล้ว แทนการเปิดแท็บใหม่ซ้อนไปเรื่อย ๆ
      const existing = windows.find((client) => client.url.includes(self.location.origin))

      if (existing) return existing.focus()

      return self.clients.openWindow(target)
    }),
  )
})
