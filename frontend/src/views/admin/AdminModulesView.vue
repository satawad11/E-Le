<template>
  <div class="max-w-4xl mx-auto space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-800">โมดูลระบบ</h1>
      <p class="text-sm text-gray-500 mt-1">
        ปิดโมดูลแล้วเมนู หน้าจอ และ API ของโมดูลนั้นจะหายไปทั้งชุดทันที ข้อมูลเดิมไม่ถูกลบ
      </p>
    </div>

    <div class="p-4 bg-blue-50 border border-blue-200 rounded-xl flex items-start gap-3">
      <svg class="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.info"/>
      </svg>
      <p class="text-sm text-blue-800">
        โมดูลที่มีโมดูลอื่นพึ่งพาอยู่ เมื่อปิดแล้วโมดูลที่พึ่งพาจะถูกปิดตามไปด้วยโดยอัตโนมัติ
        เช่น ปิด "ระบบลงเวลา" แล้ว "ระบบงานล่วงเวลา" จะใช้งานไม่ได้ด้วย
      </p>
    </div>

    <div v-if="loading" class="flex justify-center py-16">
      <div class="animate-spin h-10 w-10 rounded-full border-4 border-[#800000] border-t-transparent"></div>
    </div>

    <div v-else class="space-y-3">
      <article
        v-for="module in modules"
        :key="module.code"
        class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 flex flex-wrap items-start gap-4"
      >
        <div class="min-w-0 flex-1">
          <div class="flex items-center gap-2 flex-wrap">
            <h2 class="font-bold text-gray-900">{{ module.name_th || module.name }}</h2>
            <span
              class="px-2 py-0.5 rounded-full text-xs font-semibold"
              :class="module.enabled ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'"
            >
              {{ module.enabled ? 'เปิดใช้งาน' : 'ปิดอยู่' }}
            </span>
            <span v-if="!module.can_disable" class="px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700">
              โมดูลแกน ปิดไม่ได้
            </span>
          </div>
          <p class="text-sm text-gray-500 mt-1">{{ module.description }}</p>
          <p v-if="module.dependencies?.length" class="text-xs text-gray-400 mt-1">
            ต้องใช้ร่วมกับ: {{ module.dependencies.join(', ') }}
          </p>
          <p v-if="module.disabled_dependencies?.length" class="text-xs text-red-600 mt-1">
            ใช้งานไม่ได้เพราะโมดูลที่พึ่งพาถูกปิดอยู่: {{ module.disabled_dependencies.join(', ') }}
          </p>
        </div>

        <button
          v-if="module.can_disable"
          type="button"
          role="switch"
          :aria-checked="module.enabled"
          :aria-label="`เปิดหรือปิด${module.name_th || module.name}`"
          :disabled="saving === module.config_key"
          class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors disabled:opacity-50 flex-shrink-0 mt-1"
          :class="module.enabled ? 'bg-emerald-500' : 'bg-slate-300'"
          @click="toggle(module)"
        >
          <span
            class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
            :class="module.enabled ? 'translate-x-6' : 'translate-x-1'"
          />
        </button>
      </article>
    </div>
  </div>
</template>

<script setup>
/**
 * โมดูลระบบ (CLEANUP-02)
 *
 * เดิมหน้านี้เขียนค่าตั้งค่า `enable_leave_menu` / `enable_attendance_menu` ซึ่งเคยใช้
 * ซ่อนเมนูที่เขียนตายตัวใน `App.vue` เมื่อแถบเมนูเปลี่ยนไปอ่านจากตาราง `menus`
 * (ซึ่งกรองด้วย ModuleRegistry) สวิตช์ในหน้านี้ก็ไม่มีผลกับสิ่งใดอีกเลย
 *
 * ตอนนี้หน้านี้ตั้งค่ากลไกเดียวกับที่เมนู ด่าน route และ Notification Center ใช้จริง
 */
import { onMounted, ref } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { useMenuStore } from '../../stores/menuStore'
import { ICONS } from '../../utils/icons'

const alertStore = useAlertStore()
const menuStore = useMenuStore()

const modules = ref([])
const loading = ref(true)
const saving = ref(null)

const load = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/admin/modules')
    modules.value = data.modules
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'โหลดรายการโมดูลไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

const toggle = async (module) => {
  saving.value = module.config_key
  try {
    const { data } = await api.post('/admin/modules', {
      module: module.config_key,
      enabled: !module.enabled,
    })
    modules.value = data.modules

    if (data.affected_dependants?.length) {
      alertStore.info(`${data.message} — โมดูลที่พึ่งพาถูกปิดตามด้วย: ${data.affected_dependants.join(', ')}`, 6000)
    } else {
      alertStore.success(data.message)
    }

    // แถบเมนูกรองตามโมดูลที่เปิดอยู่ จึงต้องโหลดใหม่ให้เห็นผลทันที
    await menuStore.fetchUserMenus()
  } catch (error) {
    alertStore.error(
      Object.values(error.response?.data?.errors || {})[0]?.[0]
        || error.response?.data?.message
        || 'บันทึกไม่สำเร็จ',
    )
    await load()
  } finally {
    saving.value = null
  }
}

onMounted(load)
</script>
