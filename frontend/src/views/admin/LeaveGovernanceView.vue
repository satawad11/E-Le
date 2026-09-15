<template><div class="max-w-7xl mx-auto space-y-6" aria-labelledby="title"><header><h1 id="title" class="text-2xl font-bold">ตรวจหลักฐานการลา</h1><p class="text-sm text-gray-500">ตรวจหลักฐาน พิจารณายกเลิกบางช่วง และติดตามรายการเกินกำหนด</p></header><nav class="flex flex-wrap gap-2" aria-label="ตัวกรองงาน"><button v-for="tab in tabs" :key="tab.value" @click="filter=tab.value;load()" class="rounded-xl border px-4 py-2 text-sm" :class="filter===tab.value?'bg-[#800000] text-white':''">{{tab.label}}</button></nav><div v-if="loading" role="status" class="rounded-2xl border bg-white p-10 text-center">กำลังโหลด...</div><section v-else-if="filter==='pending_adjustment'" class="space-y-3"><article v-for="item in adjustments" :key="item.id" class="rounded-2xl border bg-white p-5"><div class="flex flex-wrap justify-between gap-4"><div><p class="font-bold">{{item.leave?.user?.name}} · {{item.leave?.type?.name}}</p><p class="text-sm text-gray-600">ขอยกเลิก {{date(item.cancel_from)}} – {{date(item.cancel_to)}} ({{item.days}} วัน)</p><p class="mt-2 text-sm">{{item.reason}}</p></div><div class="flex gap-2"><button class="rounded-lg bg-green-600 px-3 py-2 text-white" @click="decide(item,'Approved')">อนุมัติ</button><button class="rounded-lg bg-red-600 px-3 py-2 text-white" @click="decide(item,'Rejected')">ไม่อนุมัติ</button></div></div></article><p v-if="!adjustments.length" class="rounded-2xl border bg-white p-10 text-center text-gray-500">ไม่มีคำขอรอพิจารณา</p></section><section v-else class="space-y-3"><article v-for="leave in leaves" :key="leave.id" class="rounded-2xl border bg-white p-5"><div class="flex flex-wrap justify-between gap-3"><div><p class="text-xs text-gray-500">{{leave.document_number}}</p><h2 class="font-bold">{{leave.user?.name}} · {{leave.type?.name}}</h2><p class="text-sm text-gray-600">{{leave.user?.department?.name||'-'}} · {{date(leave.start_date)}} – {{date(leave.end_date)}}</p></div><div class="flex items-start gap-2"><span v-if="leave.pending_evidence_count" class="rounded-full bg-amber-50 px-3 py-1 text-xs text-amber-800">หลักฐานรอตรวจ {{leave.pending_evidence_count}}</span><button class="rounded-lg border px-3 py-2 text-sm" @click="openDetail(leave)">ตรวจรายการ</button></div></div></article><p v-if="!leaves.length" class="rounded-2xl border bg-white p-10 text-center text-gray-500">ไม่มีรายการในตัวกรองนี้</p></section>
<div v-if="selected" class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @click.self="selected=null"><section role="dialog" aria-modal="true" aria-labelledby="detail-title" class="max-h-[90vh] w-full max-w-3xl overflow-auto rounded-2xl bg-white p-6"><div class="flex justify-between"><h2 id="detail-title" class="text-xl font-bold">ตรวจสอบ {{selected.document_number}}</h2><button aria-label="ปิด" @click="selected=null"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.x"/></svg></button></div><div class="mt-5 space-y-3"><article v-for="e in selected.evidences" :key="e.id" class="rounded-xl border p-4"><p class="font-medium">{{e.original_name}}</p><p class="text-xs text-gray-500">ผู้ส่ง {{e.uploader?.name||'-'}}</p><div class="mt-3 flex flex-wrap gap-2"><button class="rounded-lg border px-3 py-2 text-sm" @click="download(e)">ดาวน์โหลด</button><button class="rounded-lg bg-green-600 px-3 py-2 text-sm text-white" @click="verify(e,'verified')">ผ่าน</button><button class="rounded-lg bg-red-600 px-3 py-2 text-sm text-white" @click="verify(e,'rejected')">ไม่ผ่าน</button></div></article><p v-if="!selected.evidences?.length" class="text-gray-500">ไม่มีหลักฐาน</p></div><div class="mt-6 border-t pt-4"><label class="block text-sm">เหตุผลยกเลิกเอกสาร<textarea v-model="voidReason" rows="2" class="mt-1 w-full rounded-lg border p-2" placeholder="ต้องระบุเหตุผลและตรวจสอบก่อนดำเนินการ"></textarea></label><button class="mt-2 rounded-lg border border-red-300 px-4 py-2 text-red-700 disabled:opacity-40" :disabled="!voidReason.trim()" @click="voidLeave">ยกเลิกเอกสาร (Void)</button></div></section></div></div></template>
<script setup>
/*
 * ตรวจหลักฐานการลา
 *
 * ไฟล์นี้เคยถูกบีบเหลือบรรทัดเดียวทั้งบล็อก ทำให้แก้ต่อ ทบทวนโค้ด และอ่าน diff
 * แทบไม่ได้เลย · จัดรูปแบบใหม่โดยไม่เปลี่ยนพฤติกรรม ยกเว้นกล่องยืนยันที่เปลี่ยน
 * จาก `confirm()` / `prompt()` ของเบราว์เซอร์มาเป็นกล่องมาตรฐานของระบบ
 */
import { onMounted, ref } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { useConfirm } from '../../composables/useConfirm'
import { getErrorMessage } from '../../utils/errorHandler'
import { ICONS } from '../../utils/icons'

const alerts = useAlertStore()
const { confirmAction, confirmDanger, promptText } = useConfirm()

const loading = ref(false)
const filter = ref('pending_evidence')
const leaves = ref([])
const adjustments = ref([])
const selected = ref(null)
const voidReason = ref('')

const tabs = [
  { value: 'pending_evidence', label: 'หลักฐานรอตรวจ' },
  { value: 'pending_adjustment', label: 'ยกเลิกบางช่วง' },
  { value: 'overdue', label: 'ใกล้/เกินกำหนด' },
  { value: 'all', label: 'ทั้งหมด' },
]

const load = async () => {
  loading.value = true
  try {
    if (filter.value === 'pending_adjustment') {
      const { data } = await api.get('/admin/leave-governance/adjustments')
      adjustments.value = data.data || data
    } else {
      const { data } = await api.get('/admin/leave-governance/worklist', { params: { status: filter.value } })
      leaves.value = data.data || data
    }
  } catch (e) {
    alerts.error(getErrorMessage(e, 'โหลดรายการไม่สำเร็จ'))
  } finally {
    loading.value = false
  }
}

const openDetail = (l) => {
  selected.value = l
  voidReason.value = ''
}

/*
 * เหตุผลที่ไม่ผ่านเป็นข้อมูลที่ผู้ถูกตรวจจะได้อ่าน จึงต้องบังคับกรอกจริง
 * `window.prompt()` เดิมบังคับไม่ได้ ผู้ตรวจกด OK ทั้งช่องว่างแล้วเงียบหายไปเฉย ๆ
 */
const verify = async (e, status) => {
  let note = ''

  if (status === 'rejected') {
    note = await promptText({
      title: 'ไม่ผ่านการตรวจหลักฐาน',
      message: `หลักฐาน "${e.original_name}" จะถูกบันทึกว่าไม่ผ่าน`,
      prompt: { label: 'เหตุผลที่ไม่ผ่าน', placeholder: 'ระบุให้ผู้ยื่นทราบว่าต้องแก้ไขอะไร', required: true, multiline: true },
      confirmText: 'บันทึกผลไม่ผ่าน',
    })

    if (!note) return
  }

  try {
    await api.put(`/leave-evidences/${e.id}/verify`, { status, note })
    alerts.success('บันทึกผลตรวจหลักฐานแล้ว')
    selected.value = null
    await load()
  } catch (error) {
    alerts.error(getErrorMessage(error, 'บันทึกผลตรวจหลักฐานไม่สำเร็จ'))
  }
}

const decide = async (item, status) => {
  const approving = status === 'Approved'

  const confirmed = await confirmAction({
    title: approving ? 'อนุมัติคำขอยกเลิกบางช่วง' : 'ไม่อนุมัติคำขอยกเลิกบางช่วง',
    message: approving ? 'อนุมัติคำขอนี้หรือไม่' : 'ไม่อนุมัติคำขอนี้หรือไม่',
    confirmText: approving ? 'อนุมัติ' : 'ไม่อนุมัติ',
  })

  if (!confirmed) return

  try {
    await api.put(`/leave-adjustments/${item.id}/decision`, { status })
    alerts.success('บันทึกผลพิจารณาแล้ว')
    await load()
  } catch (error) {
    alerts.error(getErrorMessage(error, 'บันทึกผลพิจารณาไม่สำเร็จ'))
  }
}

const voidLeave = async () => {
  const confirmed = await confirmDanger({
    title: 'ยกเลิกเอกสารใบลา',
    message: 'การยกเลิกเอกสารจะคืนยอดวันลาและเก็บประวัติ ต้องการดำเนินการหรือไม่',
    confirmText: 'ยกเลิกเอกสาร',
  })

  if (!confirmed) return

  try {
    await api.post(`/leaves/${selected.value.id}/void`, { reason: voidReason.value })
    alerts.success('ยกเลิกเอกสารแล้ว')
    selected.value = null
    await load()
  } catch (error) {
    alerts.error(getErrorMessage(error, 'ยกเลิกเอกสารไม่สำเร็จ'))
  }
}

const download = async (e) => {
  const r = await api.get(`/leave-evidences/${e.id}/download`, { responseType: 'blob' })
  const u = URL.createObjectURL(r.data)
  const a = document.createElement('a')
  a.href = u
  a.download = e.original_name
  a.click()
  URL.revokeObjectURL(u)
}

const date = (v) => new Intl.DateTimeFormat('th-TH', { dateStyle: 'medium' }).format(new Date(v))

onMounted(load)
</script>
