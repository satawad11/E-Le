<template>
  <div class="max-w-4xl mx-auto space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-800">ตารางอำนาจหน้าที่</h1>
      <p class="text-sm text-gray-500 mt-1">
        แสดงอำนาจที่ระบบใช้ตัดสินจริง รวมการรักษาการและการมอบอำนาจที่มีผลอยู่
      </p>
    </div>

    <LoadingState v-if="loading" type="skeleton-table" :rows="6" :columns="3" />
    <ErrorState v-else-if="error" :message="error" @retry="load" />

    <template v-else>
      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5">
        <dl class="grid sm:grid-cols-3 gap-4 text-sm">
          <div>
            <dt class="text-gray-500">ผู้ใช้</dt>
            <dd class="font-medium text-gray-900">{{ subject?.name || '—' }}</dd>
          </div>
          <div>
            <dt class="text-gray-500">หน่วยงานที่รับผิดชอบ</dt>
            <dd class="font-medium text-gray-900">
              {{ matrix.managed_org_units?.length || 0 }} หน่วยงาน
            </dd>
          </div>
          <div>
            <dt class="text-gray-500">ปฏิบัติราชการแทน</dt>
            <dd class="font-medium text-gray-900">
              {{ matrix.acting_for?.length ? `${matrix.acting_for.length} คน` : 'ไม่มี' }}
            </dd>
          </div>
        </dl>
      </section>

      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <header class="px-5 py-4 border-b border-gray-100">
          <h2 class="font-semibold text-gray-800">ความสามารถ</h2>
          <p class="text-sm text-gray-500 mt-0.5">
            "ทั้งองค์กร" หมายถึงไม่จำกัดหน่วยงาน
          </p>
        </header>

        <div class="overflow-x-auto">
          <table class="w-full text-sm min-w-[640px]">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
              <tr>
                <th class="text-left px-5 py-3 font-semibold">ความสามารถ</th>
                <th class="text-left px-5 py-3 font-semibold">สถานะ</th>
                <th class="text-left px-5 py-3 font-semibold">ขอบเขต</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-for="row in abilityRows" :key="row.ability" class="hover:bg-gray-50/60">
                <td class="px-5 py-2.5 font-mono text-xs text-gray-700">{{ row.ability }} <AppButton v-can="'saraban.authority.view'" variant="ghost" @click="findHolders(row.ability)">ดูผู้มีอำนาจ</AppButton></td>
                <td class="px-5 py-2.5">
                  <AppBadge :variant="row.granted ? 'success' : 'gray'">
                    {{ row.granted ? 'มีอำนาจ' : 'ไม่มี' }}
                  </AppBadge>
                </td>
                <td class="px-5 py-2.5 text-gray-600">{{ row.scope }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
    <AppModal v-if="holders !== null" :model-value="true" title="ผู้มีอำนาจตามความสามารถที่เลือก" @update:model-value="holders = null"><p v-if="!holders.length">ไม่พบผู้มีอำนาจ</p><ul><li v-for="h in holders" :key="h.user_id" class="py-2">{{ h.name }} · {{ h.org_unit || 'ไม่ระบุหน่วยงาน' }}</li></ul></AppModal>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../../axios'
import AppButton from '../../components/ui/AppButton.vue'
import AppModal from '../../components/ui/AppModal.vue'
import { getErrorMessage } from '../../utils/errorHandler'
import AppBadge from '../../components/ui/AppBadge.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import LoadingState from '../../components/ui/LoadingState.vue'

/**
 * ตารางอำนาจหน้าที่ (SoT §6 · INT-16)
 *
 * แสดง **สิ่งที่ระบบใช้ตัดสินจริง** ไม่ใช่การคำนวณของหน้าจอเอง — ถ้าหน้าจอ
 * คำนวณเอง มันจะบอกความจริงคนละชุดกับที่ระบบใช้ ซึ่งอันตรายกว่าการไม่มีหน้าจอเลย
 */
const matrix = ref({})
const subject = ref(null)
const loading = ref(true)
const error = ref('')
const holders = ref(null)
async function findHolders(ability) { try { holders.value = (await api.get('/saraban/authority/holders', { params: { ability } })).data.holders } catch(e) { error.value = getErrorMessage(e, 'ค้นหาผู้มีอำนาจไม่สำเร็จ') } }

const abilityRows = computed(() =>
  Object.entries(matrix.value.abilities || {}).map(([ability, detail]) => ({
    ability,
    granted: detail.granted,
    scope: !detail.granted
      ? '—'
      : detail.org_units === null
        ? 'ทั้งองค์กร'
        : `${detail.org_units.length} หน่วยงาน`,
  })),
)

const load = async () => {
  loading.value = true
  error.value = ''

  try {
    const { data } = await api.get('/saraban/authority')
    matrix.value = data.matrix
    subject.value = data.user
  } catch (err) {
    error.value = getErrorMessage(err, 'โหลดตารางอำนาจหน้าที่ไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>
