<template>
  <div class="bg-gray-100/80 rounded-2xl p-4 sm:p-6 border border-gray-200/80 space-y-4">
    <!-- แถบควบคุมด้านบนตัวอย่างเอกสาร -->
    <div class="flex flex-wrap items-center justify-between gap-3 bg-white px-4 py-2.5 rounded-xl border border-gray-200 shadow-2xs">
      <div class="flex items-center gap-2">
        <span class="inline-block w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse" />
        <span class="text-sm font-semibold text-gray-800">ตัวอย่างเอกสารแบบเรียลไทม์ (Live Document Preview)</span>
        <span class="text-xs px-2 py-0.5 rounded-full bg-primary-50 text-primary-700 font-medium">
          {{ templateLabel }}
        </span>
      </div>

      <div class="flex items-center gap-2">
        <!-- ปุ่มสลับเลขไทย/เลขอารบิก -->
        <button
          type="button"
          class="text-xs px-2.5 py-1.5 rounded-lg border transition-colors flex items-center gap-1.5"
          :class="displayThaiDigits ? 'bg-primary-50 border-primary-300 text-primary-800 font-medium' : 'bg-gray-50 border-gray-200 text-gray-600 hover:bg-gray-100'"
          title="แปลงเลขอารบิกเป็นเลขไทยตามระเบียบงานสารบรรณ"
          @click="displayThaiDigits = !displayThaiDigits"
        >
          <span>เลขไทย (๑ ๒ ๓)</span>
          <span v-if="displayThaiDigits" class="text-primary-600">✓</span>
        </button>

        <!-- ปุ่มคัดลอกข้อความร่างทั้งหมด -->
        <button
          type="button"
          class="text-xs px-2.5 py-1.5 rounded-lg border border-gray-200 bg-gray-50 hover:bg-gray-100 text-gray-600 transition-colors flex items-center gap-1"
          @click="copyDocumentText"
        >
          <span>{{ copied ? 'คัดลอกแล้ว ✓' : 'คัดลอกข้อความ' }}</span>
        </button>
      </div>
    </div>

    <!-- แผ่นกระดาษจำลอง A4 -->
    <div
      class="bg-white mx-auto shadow-md rounded-sm border border-gray-200/90 text-gray-900 font-serif leading-relaxed transition-all"
      :style="{ minHeight: '680px', maxWidth: '720px', padding: '40px 48px' }"
    >
      <!-- ตราประทับชั้นความเร็ว / ชั้นความลับ (Stamps) -->
      <div class="flex items-center justify-between gap-2 mb-3 pb-2 border-b border-dashed border-gray-200 text-xs">
        <div class="flex items-center gap-2">
          <span
            v-if="urgency && urgency !== 'normal'"
            class="px-2 py-0.5 rounded border border-red-500 text-red-600 font-sans font-bold text-[11px] tracking-wider"
          >
            {{ urgencyLabel }}
          </span>
          <span
            v-if="confidentiality && confidentiality !== 'normal'"
            class="px-2 py-0.5 rounded border border-amber-600 text-amber-700 font-sans font-bold text-[11px] tracking-wider"
          >
            {{ confidentialityLabel }}
          </span>
        </div>
        <span class="text-[11px] text-gray-400 font-sans">แบบฟอร์มมาตรฐาน สอศ. / สำนักนายกฯ พ.ศ. ๒๕๖๔</span>
      </div>

      <!-- ======================================================== -->
      <!-- 1. รูปแบบ: บันทึกข้อความ (ภายใน) — ครุฑซ้าย ๑.๕ ซม. -->
      <!-- ======================================================== -->
      <div v-if="templateId === 'internal_memo' || templateId === 'memo'" class="space-y-4">
        <div class="flex items-start justify-between relative">
          <!-- ตราครุฑขนาด 1.5 ซม. ด้านซ้ายบน -->
          <div class="flex-shrink-0 w-12 h-14 flex items-center justify-center">
            <img :src="garudaIcon" alt="ตราครุฑ" class="w-12 h-14 object-contain" />
          </div>
          <!-- หัวเรื่อง "บันทึกข้อความ" ตรงกลาง -->
          <div class="flex-1 text-center pr-12">
            <h1 class="text-2xl sm:text-3xl font-bold tracking-widest text-gray-900 font-sans">บันทึกข้อความ</h1>
          </div>
        </div>

        <!-- รายละเอียดส่วนหัว: ส่วนราชการ, ที่, วันที่ -->
        <div class="space-y-1.5 text-[15px] pt-2 border-t border-gray-300">
          <div class="flex flex-wrap items-baseline gap-2">
            <span class="font-bold whitespace-nowrap">ส่วนราชการ</span>
            <span class="flex-1 border-b border-dotted border-gray-400 pb-0.5">{{ formatDigits(agencyText || '—') }}</span>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            <div class="flex items-baseline gap-2">
              <span class="font-bold whitespace-nowrap">ที่</span>
              <span class="flex-1 border-b border-dotted border-gray-400 pb-0.5">{{ formatDigits(documentNumberText) }}</span>
            </div>
            <div class="flex items-baseline gap-2">
              <span class="font-bold whitespace-nowrap">วันที่</span>
              <span class="flex-1 border-b border-dotted border-gray-400 pb-0.5">{{ formatDigits(dateText) }}</span>
            </div>
          </div>

          <div class="flex flex-wrap items-baseline gap-2 pt-1">
            <span class="font-bold whitespace-nowrap">เรื่อง</span>
            <span class="flex-1 font-semibold text-gray-900 border-b border-dotted border-gray-400 pb-0.5">{{ formatDigits(subjectText || '—') }}</span>
          </div>

          <div class="flex flex-wrap items-baseline gap-2 pt-1">
            <span class="font-bold whitespace-nowrap">เรียน</span>
            <span class="flex-1 text-gray-800">{{ formatDigits(salutationText || 'ผู้อำนวยการ') }}</span>
          </div>
        </div>

        <!-- เนื้อหาเอกสาร (Body Paragraphs) -->
        <div class="text-[15px] leading-relaxed pt-3 space-y-3 text-justify text-gray-800 whitespace-pre-wrap font-serif">
          <p v-if="bodyContent" class="indent-10">{{ formatDigits(bodyContent) }}</p>
          <div v-else class="text-gray-400 italic text-sm py-4 text-center">
            (พิมพ์รายละเอียดเนื้อหาหนังสือ หรือเลือกแม่แบบข้อความด้านล่าง)
          </div>
        </div>

        <!-- บล็อกลายมือชื่อ (Signatory) -->
        <div class="pt-8 flex justify-end text-center text-[15px]">
          <div class="space-y-1 w-64">
            <div class="h-10 flex items-center justify-center text-xs text-gray-400 border-b border-dashed border-gray-300">
              ( ลงชื่อ )
            </div>
            <p class="font-semibold text-gray-800 pt-1">( {{ formatDigits(signatoryName || '....................................................') }} )</p>
            <p class="text-xs text-gray-600">{{ formatDigits(signatoryTitle || 'ตำแหน่ง ....................................................') }}</p>
          </div>
        </div>
      </div>

      <!-- ======================================================== -->
      <!-- 2. รูปแบบ: หนังสือภายนอก — ครุฑกลาง ๓.๐ ซม. -->
      <!-- ======================================================== -->
      <div v-else-if="templateId === 'external_letter' || templateId === 'external'" class="space-y-4">
        <!-- ตราครุฑขนาด 3.0 ซม. กึ่งกลางหน้ากระดาษ -->
        <div class="flex justify-center mb-2">
          <img :src="garudaIcon" alt="ตราครุฑ" class="w-20 h-22 object-contain" />
        </div>

        <!-- แถวที่หนังสือ และ ที่อยู่ส่วนราชการ -->
        <div class="grid grid-cols-2 gap-4 text-[15px]">
          <div class="flex items-baseline gap-2">
            <span class="font-bold whitespace-nowrap">ที่</span>
            <span class="flex-1 border-b border-dotted border-gray-400 pb-0.5">{{ formatDigits(documentNumberText) }}</span>
          </div>
          <div class="text-right text-xs sm:text-[13px] text-gray-700 leading-snug">
            {{ formatDigits(agencyText || 'วิทยาลัยอาชีวศึกษา') }}
          </div>
        </div>

        <!-- วันที่ (กึ่งกลางเยื้องขวา) -->
        <div class="flex justify-end pr-8 text-[15px]">
          <div class="flex items-baseline gap-2">
            <span class="border-b border-dotted border-gray-400 pb-0.5">{{ formatDigits(dateText) }}</span>
          </div>
        </div>

        <!-- เรื่อง, เรียน, อ้างถึง, สิ่งที่ส่งมาด้วย -->
        <div class="space-y-2 text-[15px] pt-1">
          <div class="flex items-baseline gap-2">
            <span class="font-bold whitespace-nowrap">เรื่อง</span>
            <span class="font-semibold text-gray-900">{{ formatDigits(subjectText || '—') }}</span>
          </div>

          <div class="flex items-baseline gap-2">
            <span class="font-bold whitespace-nowrap">เรียน</span>
            <span>{{ formatDigits(salutationText || 'ผู้รับหนังสือ') }}</span>
          </div>

          <div v-if="referenceText" class="flex items-baseline gap-2 text-xs text-gray-600">
            <span class="font-bold whitespace-nowrap">อ้างถึง</span>
            <span>{{ formatDigits(referenceText) }}</span>
          </div>

          <div v-if="enclosuresText" class="flex items-baseline gap-2 text-xs text-gray-600">
            <span class="font-bold whitespace-nowrap">สิ่งที่ส่งมาด้วย</span>
            <span>{{ formatDigits(enclosuresText) }}</span>
          </div>
        </div>

        <!-- เนื้อหาหนังสือภายนอก -->
        <div class="text-[15px] leading-relaxed pt-2 space-y-3 text-justify text-gray-800 whitespace-pre-wrap font-serif">
          <p v-if="bodyContent" class="indent-10">{{ formatDigits(bodyContent) }}</p>
          <div v-else class="text-gray-400 italic text-sm py-4 text-center">
            (พิมพ์รายละเอียดเนื้อหาหนังสือ หรือเลือกแม่แบบข้อความด้านล่าง)
          </div>
        </div>

        <!-- คำลงท้าย และ บล็อกผู้ลงนาม -->
        <div class="pt-6 flex justify-end text-center text-[15px]">
          <div class="space-y-1 w-64">
            <p class="mb-4">ขอแสดงความนับถือ</p>
            <div class="h-10 flex items-center justify-center text-xs text-gray-400 border-b border-dashed border-gray-300">
              ( ลงชื่อ )
            </div>
            <p class="font-semibold text-gray-800 pt-1">( {{ formatDigits(signatoryName || '....................................................') }} )</p>
            <p class="text-xs text-gray-600">{{ formatDigits(signatoryTitle || 'ผู้อำนวยการวิทยาลัย') }}</p>
          </div>
        </div>

        <!-- ส่วนท้าย: หน่วยงานเจ้าของเรื่อง / ช่องทางติดต่อ -->
        <div class="pt-8 border-t border-gray-200 text-xs text-gray-500 space-y-0.5">
          <p>{{ formatDigits(contactText || 'งานสารบรรณ ฝ่ายบริหารทรัพยากร โทร. ๐-๒xxx-xxxx') }}</p>
        </div>
      </div>

      <!-- ======================================================== -->
      <!-- 3. รูปแบบ: คำสั่งวิทยาลัย — ครุฑกลาง ๓.๐ ซม. -->
      <!-- ======================================================== -->
      <div v-else-if="templateId === 'college_order' || templateId === 'order'" class="space-y-4">
        <!-- ตราครุฑขนาด 3.0 ซม. กึ่งกลาง -->
        <div class="flex justify-center mb-2">
          <img :src="garudaIcon" alt="ตราครุฑ" class="w-20 h-22 object-contain" />
        </div>

        <!-- ชื่อคำสั่ง และ ที่คำสั่ง -->
        <div class="text-center space-y-1">
          <h1 class="text-xl sm:text-2xl font-bold tracking-wide text-gray-900 font-sans">
            {{ formatDigits(orderTitleText || 'คำสั่งวิทยาลัย') }}
          </h1>
          <p class="text-sm font-medium text-gray-700">
            ที่ {{ formatDigits(documentNumberText || '.../๒๕๖๙') }}
          </p>
          <p class="text-base font-semibold text-gray-900 pt-1">
            เรื่อง {{ formatDigits(subjectText || '....................................................') }}
          </p>
        </div>

        <!-- เนื้อหาคำสั่ง -->
        <div class="text-[15px] leading-relaxed pt-3 space-y-3 text-justify text-gray-800 whitespace-pre-wrap font-serif">
          <p v-if="bodyContent" class="indent-10">{{ formatDigits(bodyContent) }}</p>
          <div v-else class="text-gray-400 italic text-sm py-4 text-center">
            (ระบุความเป็นมา อำนาจตามกฎหมาย รายละเอียดคำสั่ง และวันที่ให้มีผลบังคับใช้)
          </div>
        </div>

        <!-- สั่ง ณ วันที่ และ ลงนาม -->
        <div class="pt-6 space-y-4">
          <p class="text-center text-[15px] indent-8">
            {{ formatDigits(orderDateText || `สั่ง ณ วันที่ ${dateText}`) }}
          </p>

          <div class="flex justify-end text-center text-[15px]">
            <div class="space-y-1 w-64">
              <div class="h-10 flex items-center justify-center text-xs text-gray-400 border-b border-dashed border-gray-300">
                ( ลงชื่อ )
              </div>
              <p class="font-semibold text-gray-800 pt-1">( {{ formatDigits(signatoryName || '....................................................') }} )</p>
              <p class="text-xs text-gray-600">{{ formatDigits(signatoryTitle || 'ผู้อำนวยการวิทยาลัย') }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- ======================================================== -->
      <!-- 4. รูปแบบ: ประกาศวิทยาลัย — ครุฑกลาง ๓.๐ ซม. -->
      <!-- ======================================================== -->
      <div v-else class="space-y-4">
        <!-- ตราครุฑขนาด 3.0 ซม. กึ่งกลาง -->
        <div class="flex justify-center mb-2">
          <img :src="garudaIcon" alt="ตราครุฑ" class="w-20 h-22 object-contain" />
        </div>

        <!-- ชื่อประกาศ และ เรื่อง -->
        <div class="text-center space-y-1">
          <h1 class="text-xl sm:text-2xl font-bold tracking-wide text-gray-900 font-sans">
            {{ formatDigits(announcementTitleText || 'ประกาศวิทยาลัย') }}
          </h1>
          <p class="text-base font-semibold text-gray-900 pt-1">
            เรื่อง {{ formatDigits(subjectText || '....................................................') }}
          </p>
        </div>

        <!-- เนื้อหาประกาศ -->
        <div class="text-[15px] leading-relaxed pt-3 space-y-3 text-justify text-gray-800 whitespace-pre-wrap font-serif">
          <p v-if="bodyContent" class="indent-10">{{ formatDigits(bodyContent) }}</p>
          <div v-else class="text-gray-400 italic text-sm py-4 text-center">
            (ระบุรายละเอียดข้อความประกาศ)
          </div>
        </div>

        <!-- ประกาศ ณ วันที่ และ ลงนาม -->
        <div class="pt-6 space-y-4">
          <p class="text-center text-[15px] indent-8">
            {{ formatDigits(announcementDateText || `ประกาศ ณ วันที่ ${dateText}`) }}
          </p>

          <div class="flex justify-end text-center text-[15px]">
            <div class="space-y-1 w-64">
              <div class="h-10 flex items-center justify-center text-xs text-gray-400 border-b border-dashed border-gray-300">
                ( ลงชื่อ )
              </div>
              <p class="font-semibold text-gray-800 pt-1">( {{ formatDigits(signatoryName || '....................................................') }} )</p>
              <p class="text-xs text-gray-600">{{ formatDigits(signatoryTitle || 'ผู้อำนวยการวิทยาลัย') }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import garudaIcon from '../../assets/garuda.svg'
import { ThaiDateFormatter } from '../../utils/ThaiDateFormatter'

const props = defineProps({
  templateId: {
    type: String,
    default: 'internal_memo',
  },
  documentNumber: {
    type: String,
    default: '',
  },
  subject: {
    type: String,
    default: '',
  },
  summary: {
    type: String,
    default: '',
  },
  agency: {
    type: String,
    default: '',
  },
  salutation: {
    type: String,
    default: '',
  },
  reference: {
    type: String,
    default: '',
  },
  enclosures: {
    type: String,
    default: '',
  },
  contact: {
    type: String,
    default: '',
  },
  signatoryName: {
    type: String,
    default: '',
  },
  signatoryTitle: {
    type: String,
    default: '',
  },
  urgency: {
    type: String,
    default: 'normal',
  },
  confidentiality: {
    type: String,
    default: 'normal',
  },
  customDate: {
    type: String,
    default: '',
  },
})

const displayThaiDigits = ref(true)
const copied = ref(false)

const formatDigits = (text) => {
  if (!text) return ''
  return displayThaiDigits.value ? ThaiDateFormatter.toThaiDigits(text) : String(text)
}

const templateLabel = computed(() => {
  switch (props.templateId) {
    case 'internal_memo':
    case 'memo':
      return 'บันทึกข้อความ (ภายใน)'
    case 'external_letter':
    case 'external':
      return 'หนังสือภายนอก'
    case 'college_order':
    case 'order':
      return 'คำสั่งสถานศึกษา'
    case 'college_announcement':
    case 'announcement':
      return 'ประกาศสถานศึกษา'
    default:
      return 'เอกสารราชการ'
  }
})

const urgencyLabel = computed(() => {
  const map = {
    urgent: 'ด่วน',
    very_urgent: 'ด่วนมาก',
    most_urgent: 'ด่วนที่สุด',
  }
  return map[props.urgency] || ''
})

const confidentialityLabel = computed(() => {
  const map = {
    confidential: 'ลับ',
    secret: 'ลับมาก',
    top_secret: 'ลับที่สุด',
  }
  return map[props.confidentiality] || ''
})

const documentNumberText = computed(() => {
  return props.documentNumber || 'ศธ ๐๖๐๑/.....'
})

const dateText = computed(() => {
  if (props.customDate) {
    return ThaiDateFormatter.toThaiText(props.customDate)
  }
  return ThaiDateFormatter.toThaiText(new Date())
})

const subjectText = computed(() => props.subject || '')
const agencyText = computed(() => props.agency || '')
const salutationText = computed(() => props.salutation || '')
const referenceText = computed(() => props.reference || '')
const enclosuresText = computed(() => props.enclosures || '')
const contactText = computed(() => props.contact || '')
const bodyContent = computed(() => props.summary || '')

const orderTitleText = computed(() => {
  return props.agency ? `คำสั่ง${props.agency}` : 'คำสั่งวิทยาลัย'
})

const orderDateText = computed(() => {
  return `สั่ง ณ วันที่ ${dateText.value}`
})

const announcementTitleText = computed(() => {
  return props.agency ? `ประกาศ${props.agency}` : 'ประกาศวิทยาลัย'
})

const announcementDateText = computed(() => {
  return `ประกาศ ณ วันที่ ${dateText.value}`
})

const copyDocumentText = async () => {
  try {
    const textPieces = [
      `[${templateLabel.value}]`,
      `ที่: ${documentNumberText.value}`,
      `วันที่: ${dateText.value}`,
      `เรื่อง: ${subjectText.value}`,
      salutationText.value ? `เรียน: ${salutationText.value}` : '',
      `\n${bodyContent.value}\n`,
      props.signatoryName ? `(${props.signatoryName})` : '',
      props.signatoryTitle || '',
    ].filter(Boolean).join('\n')

    await navigator.clipboard.writeText(formatDigits(textPieces))
    copied.value = true
    setTimeout(() => {
      copied.value = false
    }, 2500)
  } catch {
    // Fallback if clipboard fails
  }
}
</script>
