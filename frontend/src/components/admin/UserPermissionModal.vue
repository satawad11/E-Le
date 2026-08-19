<template>
  <div
    v-if="show"
    role="dialog"
    aria-modal="true"
    aria-labelledby="perm-modal-title"
    @keydown.escape="close"
    tabindex="-1"
    class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 focus:outline-none"
  >
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden border border-slate-100 animate-fade-in">
      
      <!-- Modal Header -->
      <div class="px-6 py-5 bg-gradient-to-r from-indigo-700 via-indigo-800 to-slate-900 text-white flex items-center justify-between">
        <div class="flex items-center space-x-3">
          <div class="p-2.5 bg-white/10 rounded-xl backdrop-blur-md border border-white/20">
            <svg class="w-6 h-6 text-indigo-200" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
            </svg>
          </div>
          <div>
            <h3 id="perm-modal-title" class="text-lg font-bold">กำหนดสิทธิ์รายบุคคล (Individual Permissions)</h3>
            <p class="text-xs text-indigo-200" v-if="userData">
              จัดการสิทธิ์สำหรับ {{ userData.name }} ({{ userData.email }})
            </p>
          </div>
        </div>
        <button @click="close" class="text-indigo-200 hover:text-white hover:bg-white/10 p-2 rounded-lg transition-colors focus-visible:ring-2 focus-visible:ring-white focus:outline-none" aria-label="ปิดหน้าต่างกำหนดสิทธิ์">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <!-- Modal Body -->
      <div class="p-6 overflow-y-auto space-y-6 flex-1 bg-slate-50/50">
        
        <!-- Loading State -->
        <div v-if="loading" class="py-12 flex flex-col items-center justify-center text-slate-400">
          <div class="w-10 h-10 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin"></div>
          <span class="mt-3 text-sm font-medium text-slate-600">กำลังโหลดข้อมูลสิทธิ์...</span>
        </div>

        <template v-else>
          <!-- User Summary Card -->
          <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex flex-wrap items-center justify-between gap-4">
            <div>
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">บทบาทหลัก (Roles)</span>
              <div class="flex flex-wrap gap-1.5 mt-1">
                <span 
                  v-for="role in userData?.roles || []" 
                  :key="role" 
                  class="px-2.5 py-1 text-xs font-semibold rounded-full bg-indigo-50 text-indigo-700 border border-indigo-200"
                >
                  {{ role }}
                </span>
              </div>
            </div>
            <div class="text-right">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">สิทธิ์ทั้งหมดคงเหลือ</span>
              <div class="text-sm font-bold text-slate-800 mt-1">
                {{ effectivePermissions.length }} สิทธิ์
                <span class="text-xs font-normal text-slate-500">({{ directPermissions.length }} สิทธิ์เฉพาะบุคคล)</span>
              </div>
            </div>
          </div>

          <!-- Alert Note -->
          <div class="p-3.5 bg-amber-50 rounded-xl border border-amber-200/80 flex items-start space-x-3 text-amber-800 text-xs">
            <svg class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <div>
              <strong class="font-bold">คำแนะนำ:</strong>
              สิทธิ์ที่เป็นแถบสีเทาพร้อมตรา <span class="bg-slate-200 text-slate-700 px-1.5 py-0.5 rounded text-[10px]">สิทธิ์จากบทบาท</span> คือสิทธิ์ที่ได้จาก Role หลักอัตโนมัติ สิทธิ์ที่ติ๊กถูกได้ด้านล่างจะเป็น <strong>สิทธิ์พิเศษเฉพาะบุคคล (Direct Permission)</strong>
            </div>
          </div>

          <!-- Modules List -->
          <div class="space-y-4">
            <div 
              v-for="(module, moduleId) in modules" 
              :key="moduleId"
              class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden"
            >
              <!-- Module Header -->
              <div class="px-5 py-3.5 bg-slate-100/70 border-b border-slate-200 flex items-center justify-between">
                <div class="flex items-center space-x-3">
                  <span class="p-1.5 bg-white rounded-lg border border-slate-200 shadow-xs text-indigo-600 font-bold text-sm">
                    {{ moduleId.toUpperCase() }}
                  </span>
                  <div>
                    <h4 class="text-sm font-bold text-slate-800">{{ module.name }}</h4>
                    <p class="text-xs text-slate-500">{{ module.description }}</p>
                  </div>
                </div>
              </div>

              <!-- Module Permissions Items -->
              <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                <div 
                  v-for="(desc, permKey) in module.permissions" 
                  :key="permKey"
                  :class="[
                    'p-3 rounded-lg border transition-all flex items-start space-x-3 cursor-pointer',
                    isRolePermission(permKey) 
                      ? 'bg-slate-50 border-slate-200 opacity-80 cursor-not-allowed' 
                      : directPermissions.includes(permKey)
                        ? 'bg-indigo-50/60 border-indigo-300 ring-1 ring-indigo-200'
                        : 'bg-white border-slate-200 hover:border-slate-300'
                  ]"
                  @click="togglePermission(permKey)"
                >
                  <!-- Checkbox -->
                  <div class="mt-0.5 flex items-center">
                    <input 
                      type="checkbox"
                      :checked="effectivePermissions.includes(permKey)"
                      :disabled="isRolePermission(permKey)"
                      class="w-4 h-4 text-indigo-600 rounded border-slate-300 focus:ring-indigo-500 cursor-pointer disabled:cursor-not-allowed"
                    />
                  </div>

                  <!-- Text & Badges -->
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-1">
                      <span class="text-xs font-semibold text-slate-800 truncate">{{ desc }}</span>
                      
                      <span 
                        v-if="isRolePermission(permKey)"
                        class="px-1.5 py-0.5 text-[10px] font-medium bg-slate-200 text-slate-600 rounded flex-shrink-0"
                      >
                        จากบทบาท
                      </span>
                      <span 
                        v-else-if="directPermissions.includes(permKey)"
                        class="px-1.5 py-0.5 text-[10px] font-medium bg-emerald-100 text-emerald-700 rounded flex-shrink-0"
                      >
                        เฉพาะบุคคล
                      </span>
                    </div>
                    <code class="text-[10px] text-slate-400 font-mono block mt-0.5">{{ permKey }}</code>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </template>
      </div>

      <!-- Modal Footer -->
      <div class="px-6 py-4 bg-slate-50 border-t border-slate-200">
        <!-- Toast inline -->
        <transition
          enter-active-class="transition-all duration-300"
          enter-from-class="opacity-0 -translate-y-1"
          enter-to-class="opacity-100 translate-y-0"
          leave-active-class="transition-all duration-200"
          leave-from-class="opacity-100"
          leave-to-class="opacity-0"
        >
          <div
            v-if="toastMsg"
            class="mb-3 px-4 py-2.5 rounded-xl flex items-center gap-2.5 text-sm font-medium"
            :class="toastType === 'success'
              ? 'bg-emerald-50 border border-emerald-200 text-emerald-800'
              : 'bg-red-50 border border-red-200 text-red-800'"
          >
            <!-- icon success -->
            <svg v-if="toastType === 'success'" class="w-5 h-5 text-emerald-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <!-- icon error -->
            <svg v-else class="w-5 h-5 text-red-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            {{ toastMsg }}
          </div>
        </transition>

        <div class="flex items-center justify-end space-x-3">
          <button
            @click="close"
            class="px-4 py-2 text-xs font-semibold text-slate-600 hover:text-slate-800 hover:bg-slate-200/60 rounded-xl transition-colors"
          >
            ยกเลิก
          </button>
          <button
            @click="savePermissions"
            :disabled="loading || saving"
            class="px-5 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-md shadow-indigo-200 transition-all flex items-center space-x-2 disabled:opacity-50"
          >
            <svg v-if="saving" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            <span>{{ saving ? 'กำลังบันทึก...' : 'บันทึกการตั้งค่าสิทธิ์' }}</span>
          </button>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup>
import { ref, watch, computed } from 'vue';
import api from '../../axios';
import { useAlertStore } from '../../stores/alert';
import { useAuthStore } from '../../stores/auth';

const props = defineProps({
  show: Boolean,
  userId: [Number, String],
});

const emit = defineEmits(['close', 'saved']);

const alertStore = useAlertStore();
const authStore = useAuthStore();

const loading = ref(false);
const saving = ref(false);
const modules = ref({});
const userData = ref(null);
const rolePermissions = ref([]);
const directPermissions = ref([]);
const toastMsg = ref('');
const toastType = ref('success'); // 'success' | 'error'

function showToast(msg, type = 'success', duration = 3500) {
  toastMsg.value = msg;
  toastType.value = type;
  setTimeout(() => { toastMsg.value = ''; }, duration);
}

const effectivePermissions = computed(() => {
  return Array.from(new Set([...rolePermissions.value, ...directPermissions.value]));
});

function isRolePermission(permKey) {
  return rolePermissions.value.includes(permKey);
}

function togglePermission(permKey) {
  if (isRolePermission(permKey)) return; // ไม่สามารถเปลี่ยนสิทธิ์จาก Role ได้

  const index = directPermissions.value.indexOf(permKey);
  if (index > -1) {
    directPermissions.value.splice(index, 1);
  } else {
    directPermissions.value.push(permKey);
  }
}

async function loadData() {
  if (!props.userId) return;

  loading.value = true;
  try {
    // 1. Fetch System Modules Configuration
    const modRes = await api.get('/admin/permissions/modules');
    if (modRes.data && modRes.data.success) {
      modules.value = modRes.data.modules;
    }

    // 2. Fetch User Permissions Data
    const userRes = await api.get(`/admin/users/${props.userId}/permissions`);
    if (userRes.data && userRes.data.success) {
      userData.value = userRes.data.user;
      rolePermissions.value = userRes.data.role_permissions || [];
      directPermissions.value = userRes.data.direct_permissions || [];
    }
  } catch (err) {
    console.error('Failed to load user permissions', err);
    alertStore.showError('ไม่สามารถโหลดข้อมูลสิทธิ์ของผู้ใช้ได้');
  } finally {
    loading.value = false;
  }
}

async function savePermissions() {
  saving.value = true;
  toastMsg.value = '';
  try {
    const res = await api.put(`/admin/users/${props.userId}/permissions`, {
      direct_permissions: directPermissions.value
    });

    if (res.data && res.data.success) {
      showToast(`บันทึกสิทธิ์เฉพาะบุคคลสำเร็จแล้ว (${directPermissions.value.length} สิทธิ์)`, 'success');
      if (alertStore.showSuccess) alertStore.showSuccess('บันทึกสิทธิ์เฉพาะบุคคลเรียบร้อยแล้ว');
      else if (alertStore.success) alertStore.success('บันทึกสิทธิ์เฉพาะบุคคลเรียบร้อยแล้ว');
      
      // หากเป็นผู้ใช้ปัจจุบันที่กำลังล็อกอินอยู่ ให้รีเฟรช permission ใน store ทันทีเพื่ออัปเดตเมนู
      if (props.userId == authStore.user?.id) {
        await authStore.fetchUser({ force: true });
      }

      emit('saved');
      // ดีเลย์ปิด modal เพื่อให้ผู้ใช้เห็น toast ก่อน
      setTimeout(() => close(), 1400);
    } else {
      showToast('เกิดข้อผิดพลาดในการบันทึกสิทธิ์', 'error');
    }
  } catch (err) {
    console.error('Save permissions failed', err);
    let msg = 'ไม่สามารถบันทึกสิทธิ์ได้ กรุณาลองใหม่';
    if (err?.response?.data?.message) {
      msg = err.response.data.message;
    } else if (err?.response?.data?.error) {
      msg = err.response.data.error;
    } else if (err?.message) {
      msg = err.message;
    }
    showToast(`${msg}`, 'error', 5000);
    if (alertStore.showError) alertStore.showError(msg);
    else if (alertStore.error) alertStore.error(msg);
  } finally {
    saving.value = false;
  }
}

function close() {
  emit('close');
}

watch(() => props.show, (newVal) => {
  if (newVal) {
    loadData();
  }
});
</script>

<style scoped>
@keyframes fadeIn {
  from { opacity: 0; transform: scale(0.97); }
  to { opacity: 1; transform: scale(1); }
}
.animate-fade-in {
  animation: fadeIn 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
</style>
