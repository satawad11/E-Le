/**
 * statusHelper.js
 * ตัวช่วยแปลง status ของใบลาเป็นภาษาไทย + สีที่สอดคล้อง
 */

export const STATUS_MAP = {
  Pending:   { label: 'รออนุมัติ',  color: 'amber' },
  Approved:  { label: 'อนุมัติแล้ว', color: 'green' },
  Rejected:  { label: 'ไม่อนุมัติ',  color: 'red'   },
  Cancelled: { label: 'ยกเลิกแล้ว', color: 'gray'  },
}

/**
 * แปลง status เป็นข้อความภาษาไทย
 * @param {string} status
 * @returns {string}
 */
export function statusLabel(status) {
  return STATUS_MAP[status]?.label ?? status
}

/**
 * คืน Tailwind class สำหรับ badge ตาม status
 * @param {string} status
 * @returns {string}
 */
export function statusBadgeClass(status) {
  const colorMap = {
    amber: 'bg-amber-100 text-amber-700',
    green: 'bg-green-100 text-green-700',
    red:   'bg-red-100   text-red-700',
    gray:  'bg-gray-100  text-gray-600',
  }
  const color = STATUS_MAP[status]?.color ?? 'gray'
  return colorMap[color] ?? colorMap.gray
}

export function compositeStatusLabel(leave) {
  if (leave.status === 'Approved' && leave.cancellation_status === 'Pending') {
    return 'รออนุมัติยกเลิก'
  }
  return statusLabel(leave.status)
}

export function compositeStatusBadgeClass(leave) {
  if (leave.status === 'Approved' && leave.cancellation_status === 'Pending') {
    return 'bg-orange-100 text-orange-700'
  }
  return statusBadgeClass(leave.status)
}
