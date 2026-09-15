<template>
  <div class="max-w-7xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
      <div class="flex-1 min-w-0">
        <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl sm:truncate flex items-center gap-3">
          <svg class="w-7 h-7 flex-shrink-0 text-[#800000]" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.users"/>
          </svg>
          จัดการบทบาทและสิทธิ์ (Role & Permission Management)
        </h2>
        <p class="mt-1 text-sm text-gray-500">
          บริหารจัดการบทบาทในระบบ (Roles) และกำหนดชุดสิทธิ์การใช้งาน (Permission Matrix) สำหรับแต่ละกลุ่มผู้ใช้
        </p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button
          @click="fetchRoles"
          class="inline-flex items-center gap-2 px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors cursor-pointer"
        >
          <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.refresh"/>
          </svg>
          รีเฟรช
        </button>
        <button
          @click="openCreateModal"
          class="inline-flex items-center gap-2 px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-[#800000] hover:bg-[#600000] transition-colors cursor-pointer"
        >
          <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.plus"/>
          </svg>
          สร้างบทบาทใหม่
        </button>
      </div>
    </div>

    <!-- Alert Banner -->
    <div v-if="successMessage" class="mb-4 bg-green-50 border-l-4 border-green-400 p-4 rounded shadow-sm">
      <div class="flex">
        <div class="flex-shrink-0">
          <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.check"/>
          </svg>
        </div>
        <div class="ml-3"><p class="text-sm text-green-700 font-medium">{{ successMessage }}</p></div>
      </div>
    </div>
    <div v-if="errorMessage" class="mb-4 bg-red-50 border-l-4 border-red-400 p-4 rounded shadow-sm">
      <div class="flex">
        <div class="flex-shrink-0">
          <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.error"/>
          </svg>
        </div>
        <div class="ml-3"><p class="text-sm text-red-700 font-medium">{{ errorMessage }}</p></div>
      </div>
    </div>

    <!-- Loading State -->
    <LoadingState v-if="loading" type="spinner" message="กำลังโหลดรายการบทบาทและสิทธิ์..." />

    <!-- Empty State -->
    <div v-else-if="!roles || roles.length === 0" class="text-center py-12 bg-white rounded-xl shadow-sm border border-gray-200 p-8">
      <svg class="w-14 h-14 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="ICONS.users"/>
      </svg>
      <h3 class="text-lg font-bold text-gray-800 mb-2">ไม่พบรายการบทบาทในระบบ</h3>
      <p class="text-sm text-gray-500 mb-6 max-w-md mx-auto">
        ยังไม่มีรายการบทบาทแสดงผลในขณะนี้ กดปุ่มดึงข้อมูลอีกครั้งหรือสร้างบทบาทใหม่
      </p>
      <div class="flex justify-center gap-3">
        <button @click="fetchRoles" class="inline-flex items-center gap-2 px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium text-sm rounded-xl transition-colors">
          <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.refresh"/>
          </svg>
          โหลดข้อมูลอีกครั้ง
        </button>
        <button @click="openCreateModal" class="inline-flex items-center gap-2 px-4 py-2 bg-[#800000] hover:bg-[#600000] text-white font-medium text-sm rounded-xl transition-colors">
          <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.plus"/>
          </svg>
          สร้างบทบาทใหม่
        </button>
      </div>
    </div>

    <!-- Roles Grid -->
    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <div
        v-for="role in roles"
        :key="role.id"
        class="bg-white rounded-2xl shadow-sm border border-gray-200 hover:shadow-md transition-shadow flex flex-col justify-between overflow-hidden"
      >
        <div class="p-6">
          <!-- Role Header -->
          <div class="flex justify-between items-start mb-3">
            <div>
              <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                <svg class="w-5 h-5 flex-shrink-0 text-[#800000]" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="getRoleBadgeIcon(role.name)"/>
                </svg>
                <span>{{ roleLabel(role.name) }}</span>
              </h3>
              <p class="text-xs text-gray-500 mt-1 line-clamp-2">{{ role.description || roleDescription(role.name) || 'ไม่มีคำอธิบาย' }}</p>
            </div>
            <span class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-full bg-amber-50 text-amber-800 border border-amber-200">
              <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.user"/>
              </svg>
              {{ role.users_count || 0 }} คน
            </span>
          </div>

          <!-- Permissions Summary -->
          <div class="mt-4">
            <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider block mb-2">
              สิทธิ์ในระบบ ({{ role.permissions ? role.permissions.length : 0 }} สิทธิ์):
            </span>
            <div v-if="role.name === 'SuperAdmin'" class="flex items-center gap-1.5 p-2 bg-yellow-50 rounded-lg border border-yellow-200 text-xs text-yellow-800 font-medium">
              <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.shieldCheck"/>
              </svg>
              สิทธิ์เต็มรูปแบบ (All System Permissions)
            </div>
            <div v-else-if="role.permissions && role.permissions.length > 0" class="flex flex-wrap gap-1 max-h-36 overflow-y-auto pr-1">
              <span
                v-for="perm in role.permissions.slice(0, 12)"
                :key="perm.id"
                class="px-2 py-0.5 rounded text-[11px] bg-slate-100 text-slate-700 border border-slate-200"
              >
                {{ permissionLabel(perm.name) }}
              </span>
              <span v-if="role.permissions.length > 12" class="px-2 py-0.5 rounded text-[11px] bg-gray-200 text-gray-600 font-medium">
                + อีก {{ role.permissions.length - 12 }} สิทธิ์
              </span>
            </div>
            <div v-else class="text-xs text-gray-400 italic">ยังไม่ได้กำหนดสิทธิ์</div>
          </div>
        </div>

        <!-- Role Card Footer -->
        <div class="bg-gray-50 px-6 py-3 border-t border-gray-100 flex justify-end gap-2">
          <button
            @click="openEditModal(role)"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-gray-300 text-gray-700 hover:bg-gray-100 rounded-lg text-xs font-medium transition-colors cursor-pointer"
          >
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.pencil"/>
            </svg>
            แก้ไขสิทธิ์
          </button>
          <button
            v-if="!SYSTEM_ROLES.includes(role.name)"
            @click="confirmDeleteRole(role)"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-red-50 text-red-600 hover:bg-red-100 rounded-lg text-xs font-medium transition-colors border border-red-200 cursor-pointer"
          >
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.trash"/>
            </svg>
            ลบ
          </button>
        </div>
      </div>
    </div>

    <!-- Create/Edit Role Modal -->
    <transition name="fade">
      <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 overflow-y-auto">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl overflow-hidden my-8">
          <!-- Modal Header -->
          <div class="bg-[#800000] text-white px-6 py-4 flex justify-between items-center">
            <h3 class="font-bold text-lg">
              {{ isEditing ? `แก้ไขบทบาท: ${editingRole?.name}` : 'สร้างบทบาทใหม่' }}
            </h3>
            <button @click="closeModal" aria-label="ปิด" class="text-white/80 hover:text-white">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.x"/>
              </svg>
            </button>
          </div>

          <!-- Modal Body -->
          <div class="p-6 max-h-[70vh] overflow-y-auto space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">ชื่อบทบาท (Role Name) <span class="text-red-500">*</span></label>
                <input
                  type="text"
                  v-model="form.name"
                  placeholder="เช่น AcademicOfficer, DepartmentHead"
                  class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-[#800000] focus:outline-none"
                  :disabled="isEditing && ['SuperAdmin', 'Director', 'Employee'].includes(editingRole?.name)"
                />
              </div>
              <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">คำอธิบายหน้าที่ (Description)</label>
                <input
                  type="text"
                  v-model="form.description"
                  placeholder="คำอธิบายบทบาทและขอบเขตความรับผิดชอบ"
                  class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-[#800000] focus:outline-none"
                />
              </div>
            </div>

            <!-- Permission Selection Matrix -->
            <div>
              <div class="flex justify-between items-center mb-3">
                <label class="block text-sm font-semibold text-gray-900">
                  เลือกชุดสิทธิ์การใช้งาน (Permissions Matrix):
                </label>
                <input
                  type="text"
                  v-model="searchQuery"
                  placeholder="ค้นหาสิทธิ์..."
                  class="px-3 py-1 text-xs border border-gray-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-[#800000]"
                />
              </div>

              <div class="space-y-4">
                <div
                  v-for="(perms, groupName) in filteredGroupedPermissions"
                  :key="groupName"
                  class="border border-gray-200 rounded-xl p-4 bg-gray-50/50"
                >
                  <div class="flex justify-between items-center mb-2 pb-1 border-b border-gray-200">
                    <h4 class="flex items-center gap-1.5 text-xs font-bold text-gray-800 uppercase tracking-wider">
                      <svg class="w-4 h-4 flex-shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.folder"/>
                      </svg>
                      {{ groupName }}
                    </h4>
                    <button
                      type="button"
                      @click="toggleGroupPermissions(perms)"
                      class="text-xs text-[#800000] hover:underline font-medium"
                    >
                      {{ isAllGroupSelected(perms) ? 'ยกเลิกทั้งหมด' : 'เลือกทั้งหมดในกลุ่มนี้' }}
                    </button>
                  </div>
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                    <label
                      v-for="perm in perms"
                      :key="perm.id"
                      class="flex items-center gap-2 p-2 rounded-lg hover:bg-white border border-transparent hover:border-gray-200 transition-colors cursor-pointer"
                    >
                      <input
                        type="checkbox"
                        :value="perm.id"
                        v-model="form.permission_ids"
                        class="rounded border-gray-300 text-[#800000] focus:ring-[#800000] h-4 w-4"
                      />
                      <span class="text-xs text-gray-800">{{ permissionLabel(perm.name) }}</span>
                      <span class="text-[10px] text-gray-400 font-mono block">{{ perm.name }}</span>
                    </label>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Modal Footer -->
          <div class="bg-gray-50 px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
            <button
              @click="closeModal"
              class="px-4 py-2 bg-white border border-gray-300 rounded-xl text-gray-700 font-medium text-sm hover:bg-gray-100 transition-colors"
            >
              ยกเลิก
            </button>
            <button
              @click="saveRole"
              :disabled="saving || !form.name"
              class="px-5 py-2 bg-[#800000] hover:bg-[#600000] text-white font-medium rounded-xl text-sm transition-colors flex items-center gap-2 disabled:opacity-50"
            >
              <span v-if="saving" class="inline-block animate-spin rounded-full h-4 w-4 border-2 border-white border-t-transparent"></span>
              <span>บันทึกข้อมูล</span>
            </button>
          </div>
        </div>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../axios';
import { useAuthStore } from '../../stores/auth';
import { useConfirm } from '../../composables/useConfirm';
import LoadingState from '../../components/ui/LoadingState.vue';
import { ICONS } from '../../utils/icons';
import { roleLabel, roleDescription, permissionLabel, groupPermissions, SYSTEM_ROLES } from '../../utils/roleLabel';

const authStore = useAuthStore();
const { confirmDanger } = useConfirm();
const roles = ref([]);
const availablePermissions = ref([]);
const loading = ref(true);
const saving = ref(false);
const successMessage = ref('');
const errorMessage = ref('');

const showModal = ref(false);
const isEditing = ref(false);
const editingRole = ref(null);
const searchQuery = ref('');

const form = ref({
  name: '',
  description: '',
  permission_ids: [],
});

const fetchRoles = async () => {
  loading.value = true;
  errorMessage.value = '';
  try {
    const [rolesRes, permsRes] = await Promise.all([
      api.get('/admin/roles'),
      api.get('/admin/permissions'),
    ]);
    if (rolesRes.data) {
      roles.value = Array.isArray(rolesRes.data.data) ? rolesRes.data.data : (Array.isArray(rolesRes.data) ? rolesRes.data : []);
    }
    if (permsRes.data) {
      availablePermissions.value = Array.isArray(permsRes.data.data) ? permsRes.data.data : (Array.isArray(permsRes.data) ? permsRes.data : []);
    }
  } catch (error) {
    console.error('Failed to fetch roles:', error);
    errorMessage.value = 'เกิดข้อผิดพลาดในการโหลดบทบาท: ' + (error.response?.data?.message || error.message);
  } finally {
    loading.value = false;
  }
};

const groupedPermissions = computed(() => {
  return groupPermissions(availablePermissions.value);
});

const filteredGroupedPermissions = computed(() => {
  if (!searchQuery.value) return groupedPermissions.value;
  const query = searchQuery.value.toLowerCase();
  const filtered = {};

  Object.keys(groupedPermissions.value).forEach(groupName => {
    const matches = groupedPermissions.value[groupName].filter(p => p.name.toLowerCase().includes(query));
    if (matches.length > 0) {
      filtered[groupName] = matches;
    }
  });

  return filtered;
});

const isAllGroupSelected = (perms) => {
  return perms.every(p => form.value.permission_ids.includes(p.id));
};

const toggleGroupPermissions = (perms) => {
  const allSelected = isAllGroupSelected(perms);
  const ids = perms.map(p => p.id);

  if (allSelected) {
    form.value.permission_ids = form.value.permission_ids.filter(id => !ids.includes(id));
  } else {
    const newSet = new Set([...form.value.permission_ids, ...ids]);
    form.value.permission_ids = Array.from(newSet);
  }
};

const openCreateModal = () => {
  isEditing.value = false;
  editingRole.value = null;
  form.value = { name: '', description: '', permission_ids: [] };
  showModal.value = true;
};

const openEditModal = (role) => {
  isEditing.value = true;
  editingRole.value = role;
  form.value = {
    name: role.name,
    description: role.description || '',
    permission_ids: role.permissions ? role.permissions.map(p => p.id) : [],
  };
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  editingRole.value = null;
};

const saveRole = async () => {
  saving.value = true;
  errorMessage.value = '';
  try {
    let response;
    if (isEditing.value && editingRole.value) {
      response = await api.put(`/admin/roles/${editingRole.value.id}`, form.value);
    } else {
      response = await api.post('/admin/roles', form.value);
    }

    if (response.data && response.data.success) {
      showToast(isEditing.value ? 'อัปเดตบทบาทสำเร็จ!' : 'สร้างบทบาทใหม่สำเร็จ!');
      closeModal();
      await fetchRoles();
      await authStore.fetchUser();
    }
  } catch (error) {
    console.error('Failed to save role:', error);
    errorMessage.value = error.response?.data?.message || 'เกิดข้อผิดพลาดในการบันทึกข้อมูล';
  } finally {
    saving.value = false;
  }
};

const confirmDeleteRole = async (role) => {
  const confirmed = await confirmDanger({
    title: 'ลบบทบาท',
    message: `คุณแน่ใจหรือไม่ที่จะลบบทบาท "${roleLabel(role.name)}"?`,
    detail: 'ผู้ใช้ที่ถือบทบาทนี้จะเสียสิทธิ์ที่มากับบทบาททันที',
    confirmText: 'ลบบทบาท',
  });

  if (!confirmed) return;
  try {
    const response = await api.delete(`/admin/roles/${role.id}`);
    if (response.data && response.data.success) {
      showToast(`ลบบทบาท "${role.name}" เรียบร้อยแล้ว`);
      await fetchRoles();
    }
  } catch (error) {
    console.error('Failed to delete role:', error);
    errorMessage.value = error.response?.data?.message || 'ไม่สามารถลบบทบาทได้';
  }
};

const getRoleBadgeIcon = (roleName) => {
  const map = {
    'SuperAdmin': ICONS.shieldCheck,
    'Director': ICONS.building,
    'DeputyDirector': ICONS.briefcase,
    'HeadOfDepartment': ICONS.briefcase,
    'HROfficer': ICONS.clipboard,
    'SarabanOfficer': ICONS.documentDuplicate,
    'Employee': ICONS.user,
  };
  return map[roleName] || ICONS.tag;
};

const showToast = (msg) => {
  successMessage.value = msg;
  setTimeout(() => { successMessage.value = ''; }, 4000);
};

onMounted(() => {
  fetchRoles();
});
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
