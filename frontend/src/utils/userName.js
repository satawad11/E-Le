/**
 * userName.js
 * ชื่อ-นามสกุลอยู่บนตาราง user_profiles ไม่ใช่ users (users มีแค่คอลัมน์ name)
 * helper นี้เลือกใช้ profile ก่อน แล้วค่อย fallback ไปที่ users.name
 */
export function userDisplayName(user) {
  if (!user) return '-'
  const profile = user.profile
  const full = [profile?.first_name, profile?.last_name].filter(Boolean).join(' ').trim()
  return full || user.name || '-'
}
