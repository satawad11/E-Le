<template>
  <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
    <header class="px-5 py-4 border-b border-gray-100 flex flex-wrap items-center gap-3">
      <div class="flex-1 min-w-0">
        <h2 class="font-semibold text-gray-800">เรื่องนี้ดำเนินมาถึงไหน</h2>
        <p class="text-sm text-gray-500 mt-0.5">
          ฉบับที่ {{ version?.version_no ?? '—' }} · {{ stageLabel }}
        </p>
      </div>
      <AppBadge v-if="workflow?.started" variant="info">
        ขั้นปัจจุบัน: {{ workflow.current_step?.name || 'จบกระบวนการแล้ว' }}
      </AppBadge>
      <AppBadge v-else variant="gray">ยังไม่ได้เข้าสู่กระบวนการ</AppBadge>
    </header>

    <div class="p-5 grid md:grid-cols-2 gap-6">
      <!--
        ลำดับการลงนามที่ผ่านมา — ผู้ลงนามลำดับหลังต้องเห็นว่าใครลงนามมาก่อน
        และไฟล์ที่ผู้นั้นรับรองยังไม่ถูกแก้ ถ้าไฟล์เพี้ยนต้องเห็นก่อนลงนามทับ
      -->
      <div>
        <h3 class="text-sm font-semibold text-gray-700">ผู้ลงนามก่อนหน้า</h3>

        <ol v-if="evidences.length" class="mt-3 space-y-3">
          <li v-for="(item, index) in evidences" :key="item.uuid" class="flex gap-3">
            <span
              class="shrink-0 w-6 h-6 rounded-full bg-[#800000]/10 text-[#800000] text-xs grid place-items-center"
              aria-hidden="true"
            >
              {{ index + 1 }}
            </span>
            <div class="min-w-0 text-sm">
              <p class="text-gray-800 font-medium truncate">{{ item.signer_name_snapshot }}</p>
              <p class="text-gray-500">
                {{ item.position_snapshot || '—' }} · {{ formatDateTime(item.signed_at) }}
              </p>
              <!--
                ไม่แปลว่า "ลายเซ็นถูกต้อง" — บอกเพียงว่าไฟล์ที่ผู้นั้นรับรอง
                ยังตรงกับ hash ที่บันทึกไว้ (SoT §14 · ห้ามอ้างเกินที่พิสูจน์ได้)
              -->
              <AppBadge :variant="item.file_intact ? 'success' : 'danger'" class="mt-1">
                {{ item.file_intact ? 'ไฟล์ที่รับรองยังไม่ถูกแก้' : 'ไฟล์ที่รับรองไม่ตรงกับหลักฐาน' }}
              </AppBadge>
            </div>
          </li>
        </ol>

        <p v-else class="mt-3 text-sm text-gray-500">
          ยังไม่มีผู้ใดลงนาม — ท่านเป็นผู้ลงนามลำดับแรกของหนังสือฉบับนี้
        </p>
      </div>

      <!-- ความเคลื่อนไหวของเรื่อง — ล่าสุดอยู่บน -->
      <div>
        <h3 class="text-sm font-semibold text-gray-700">ความเคลื่อนไหวล่าสุด</h3>

        <ul v-if="history.length" class="mt-3 space-y-2">
          <li v-for="(entry, index) in history" :key="index" class="text-sm text-gray-600">
            <span class="text-gray-800">{{ entry.actor || 'ระบบ' }}</span>
            · {{ entry.action }} · {{ formatDateTime(entry.at) }}
            <span v-if="entry.comment" class="block text-gray-500">“{{ entry.comment }}”</span>
          </li>
        </ul>

        <p v-else class="mt-3 text-sm text-gray-500">ยังไม่มีความเคลื่อนไหวในกระบวนการ</p>

        <!--
          บอกขั้นถัดไปเท่าที่ระบบรู้ **โดยไม่มีปุ่มสั่งการ** — การเดินเรื่องทำที่
          หน้ารายละเอียด หน้านี้มีไว้ลงนามอย่างเดียว การมีสองที่ให้กดสั่งการ
          เรื่องเดียวกันคือทางที่ผู้ใช้กดผิด
        -->
        <p v-if="nextActions.length" class="mt-3 text-sm text-gray-500">
          หลังลงนามแล้ว ขั้นถัดไปที่ทำได้: {{ nextActions.join(' · ') }} (ทำที่หน้ารายละเอียดหนังสือ)
        </p>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue'
import AppBadge from '../ui/AppBadge.vue'
import { ThaiDateFormatter } from '../../utils/ThaiDateFormatter'

/**
 * บริบทของเรื่องสำหรับหน้าลงนามและหน้าเกษียน
 *
 * เหตุผลที่ต้องมี: หนังสือราชการเดินผ่านหลายมือ ผู้ลงนามลำดับหลังจำเป็นต้องรู้
 * ว่าใครลงนามมาก่อน ไฟล์ที่ผู้นั้นรับรองยังไม่ถูกแก้ และเรื่องอยู่ขั้นใด ·
 * ถ้าเห็นแต่หน้ากระดาษ ผู้ลงนามจะไม่มีทางรู้ว่าตนกำลังลงนามซ้ำ ลงนามผิดลำดับ
 * หรือลงนามบนไฟล์ที่ถูกแก้หลังผู้อื่นรับรองไปแล้ว
 */
const props = defineProps({
  workflow: { type: Object, default: null },
  evidences: { type: Array, default: () => [] },
  version: { type: Object, default: null },
})

const STAGES = {
  original: 'ต้นฉบับ',
  revised: 'ฉบับแก้ไข',
  reviewed: 'ผ่านการตรวจ',
  endorsed: 'เกษียนแล้ว',
  signed: 'ลงนามแล้ว',
  final: 'ฉบับสมบูรณ์',
}

const stageLabel = computed(() => STAGES[props.version?.stage] || props.version?.stage || '—')

// จำกัด 5 รายการล่าสุด — ประวัติทั้งหมดดูได้ที่หน้ารายละเอียด
const history = computed(() => (props.workflow?.history || []).slice(0, 5))
const nextActions = computed(() => (props.workflow?.transitions || []).map((item) => item.name))

const formatDateTime = (value) => ThaiDateFormatter.toThaiDateTime(value)
</script>
