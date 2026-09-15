<template>
  <div class="max-w-6xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">ประทับตราหนังสือ</h1>
        <p class="text-sm text-gray-500 mt-1">
          วางตราประทับรับและข้อความบนหน้าหนังสือ · ไฟล์ฉบับสมบูรณ์สร้างที่เครื่องแม่ข่าย
          ไม่ใช่ภาพจากหน้าจอ
        </p>
      </div>
      <AppButton variant="ghost" @click="backToDocument">กลับสู่หนังสือ</AppButton>
    </div>

    <LoadingState v-if="loading" type="skeleton-card" />
    <ErrorState v-else-if="loadError" :message="loadError" @retry="boot" />

    <template v-else>
      <EmptyState
        v-if="!fileUrl"
        title="หนังสือฉบับนี้ยังไม่มีไฟล์"
        description="แนบไฟล์หนังสือก่อนจึงจะประทับตราได้"
      />

      <template v-else>
        <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
          <header class="px-5 py-4 border-b border-gray-100 flex flex-wrap items-center gap-3">
            <div class="flex-1">
              <h2 class="font-semibold text-gray-800">พื้นที่ทำงาน</h2>
              <p class="text-sm text-gray-500 mt-0.5">
                ฉบับที่ {{ version?.version_no }} · {{ stageLabel(version?.stage) }}
              </p>
            </div>
            <AppBadge :variant="editable ? 'info' : 'gray'">
              {{ editable ? 'แก้ไขได้' : 'อ่านอย่างเดียว' }}
            </AppBadge>
          </header>

          <div class="p-5 space-y-4">
            <div v-if="editable" class="flex flex-wrap gap-2">
              <AppButton size="sm" variant="outline" :disabled="!receiveStamp" @click="addReceiveStamp">
                + ตราประทับรับ
              </AppButton>
              <AppButton size="sm" variant="outline" @click="addText">+ ข้อความ</AppButton>
              <p v-if="!receiveStamp" class="text-xs text-gray-400 self-center">
                หนังสือฉบับนี้ไม่มีทะเบียนรับ จึงไม่มีตราประทับรับให้วาง
              </p>
            </div>

            <PdfWorkspace v-model="annotations" :source="fileUrl" :editable="editable" />
          </div>

          <footer v-if="editable" class="px-5 py-4 border-t border-gray-100 flex flex-wrap justify-end gap-3">
            <AppButton variant="ghost" :loading="saving" @click="save">บันทึกตำแหน่ง</AppButton>
            <AppButton :loading="rendering" :disabled="!annotations.length" @click="render">
              สร้างฉบับที่ประทับตราแล้ว
            </AppButton>
          </footer>
        </section>
      </template>
    </template>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../../axios'
import { getErrorMessage } from '../../utils/errorHandler'
import { fetchObjectUrl } from '../../utils/secureFile'
import { useAlertStore } from '../../stores/alert'
import AppBadge from '../../components/ui/AppBadge.vue'
import AppButton from '../../components/ui/AppButton.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import LoadingState from '../../components/ui/LoadingState.vue'
import PdfWorkspace from '../../components/saraban/PdfWorkspace.vue'

const STAGES = {
  original: 'ต้นฉบับ',
  revised: 'ฉบับแก้ไข',
  reviewed: 'ผ่านการตรวจ',
  endorsed: 'เกษียนแล้ว',
  signed: 'ลงนามแล้ว',
  final: 'ฉบับสมบูรณ์',
}

const route = useRoute()
const router = useRouter()
const alertStore = useAlertStore()

const loading = ref(true)
const loadError = ref('')
const saving = ref(false)
const rendering = ref(false)
const version = ref(null)
const editable = ref(false)
const annotations = ref([])
const receiveStamp = ref(null)
const fileUrl = ref('')

const stageLabel = (stage) => STAGES[stage] || stage || '—'

const releaseFile = () => {
  if (fileUrl.value) {
    URL.revokeObjectURL(fileUrl.value)
    fileUrl.value = ''
  }
}

const boot = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get(`/saraban/documents/${route.params.id}/annotations`)

    version.value = data.version
    editable.value = data.editable
    receiveStamp.value = data.receive_stamp
    annotations.value = (data.annotations || []).map((item) => ({
      id: item.id,
      page: item.page,
      type: item.type,
      x: Number(item.x),
      y: Number(item.y),
      width: item.width === null ? null : Number(item.width),
      height: item.height === null ? null : Number(item.height),
      payload: item.payload,
    }))

    releaseFile()

    if (data.version?.file_path) {
      /*
       * ไฟล์ต้องเรียกผ่าน /api/files/{path} ซึ่งตรวจสิทธิ์รายไฟล์
       * — ห้ามชี้ไปที่ /storage โดยตรง (SEC-001)
       */
      fileUrl.value = await fetchObjectUrl(`/storage/${data.version.file_path}`)
    }
  } catch (err) {
    loadError.value = getErrorMessage(err, 'เปิดพื้นที่ทำงานไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

const addReceiveStamp = () => {
  if (!receiveStamp.value) return

  annotations.value = [
    ...annotations.value,
    { page: 1, type: 'stamp', x: 380, y: 40, width: 165, height: 66, payload: receiveStamp.value },
  ]
}

const addText = () => {
  annotations.value = [
    ...annotations.value,
    { page: 1, type: 'text', x: 80, y: 200, width: 220, height: 20, payload: { text: 'ข้อความ', font_size: 14 } },
  ]
}

const save = async () => {
  saving.value = true
  try {
    await api.put(`/saraban/documents/${route.params.id}/annotations`, {
      annotations: annotations.value.map(({ id: _id, ...rest }) => rest),
    })
    alertStore.success('บันทึกตำแหน่งแล้ว')
    await boot()
  } catch (err) {
    alertStore.error(getErrorMessage(err, 'บันทึกไม่สำเร็จ'))
  } finally {
    saving.value = false
  }
}

const render = async () => {
  rendering.value = true
  try {
    await api.put(`/saraban/documents/${route.params.id}/annotations`, {
      annotations: annotations.value.map(({ id: _id, ...rest }) => rest),
    })

    const { data } = await api.post(`/saraban/documents/${route.params.id}/annotations/render`)

    alertStore.success(`สร้างฉบับที่ ${data.version.version_no} เรียบร้อยแล้ว`)
    await boot()
  } catch (err) {
    alertStore.error(getErrorMessage(err, 'สร้างฉบับที่ประทับตราแล้วไม่สำเร็จ'))
  } finally {
    rendering.value = false
  }
}

const backToDocument = async () => {
  try {
    await router.push({ name: 'saraban-document-detail', params: { id: route.params.id } })
  } catch {
    await router.push(`/saraban/documents/${route.params.id}`)
  }
}

onMounted(boot)
onBeforeUnmount(() => {
  try { releaseFile() } catch {}
})
</script>
