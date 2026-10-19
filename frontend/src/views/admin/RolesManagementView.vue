<template>
  <div class="max-w-6xl mx-auto space-y-6">
    <!-- หัวเรื่อง -->
    <header class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-3">
          <svg class="w-7 h-7 flex-shrink-0 text-[#800000]" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.users"/>
          </svg>
          บทบาทและสิทธิ์
        </h1>
        <p class="mt-1 text-sm text-gray-600 max-w-2xl">
          บทบาทคือชุดสิทธิ์การใช้งานที่มอบให้ผู้ใช้ เช่น เห็นเมนูใด ทำงานใดได้บ้าง
          ส่วนอำนาจอนุมัติใบลามาจากคำสั่งแต่งตั้งหัวหน้าหน่วยงาน ไม่ได้มาจากบทบาท
        </p>
      </div>
      <button
        type="button"
        @click="openCreate"
        class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#800000] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#600000]"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.plus"/>
        </svg>
        สร้างบทบาทใหม่
      </button>
    </header>

    <!-- ข้อความแจ้งผล -->
    <p v-if="notice" role="status" class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ notice }}</p>
    <p v-if="error && !showForm" role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ error }}</p>

    <LoadingState v-if="loading" type="spinner" message="กำลังโหลดบทบาทและสิทธิ์..." />

    <template v-else>
      <!-- สรุป -->
      <section class="grid grid-cols-1 gap-3 sm:grid-cols-3" aria-label="สรุปบทบาท">
        <div class="rounded-2xl border bg-white p-4">
          <p class="text-xs text-gray-500">บทบาททั้งหมด</p>
          <p class="mt-1 text-2xl font-bold text-gray-900">{{ roles.length }}</p>
        </div>
        <div class="rounded-2xl border bg-white p-4">
          <p class="text-xs text-gray-500">ผู้ใช้ที่ได้รับบทบาท</p>
          <p class="mt-1 text-2xl font-bold text-gray-900">{{ totalUsers }} <span class="text-sm font-normal text-gray-500">คน</span></p>
        </div>
        <div class="rounded-2xl border bg-white p-4">
          <p class="text-xs text-gray-500">บทบาทที่ยังไม่มีผู้ใช้</p>
          <p class="mt-1 text-2xl font-bold text-gray-900">{{ unusedCount }}</p>
        </div>
      </section>

      <!-- รายการบทบาท -->
      <section class="space-y-3" aria-label="รายการบทบาท">
        <article
          v-for="role in roles"
          :key="role.id"
          class="rounded-2xl border bg-white p-5"
        >
          <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div class="min-w-0 space-y-2">
              <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-lg font-bold text-gray-900">{{ role.display_name }}</h2>
                <span v-if="role.is_core" class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs text-slate-700">บทบาทหลักของระบบ</span>
                <span v-if="role.is_full_access" class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs text-amber-800">ใช้ได้ทุกเมนู</span>
                <span v-if="!role.users_count" class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-600">ยังไม่มีผู้ใช้</span>
              </div>
              <p class="text-sm text-gray-600">
                ผู้ใช้ {{ role.users_count }} คน · สิทธิ์ {{ role.permissions.length }} รายการ
              </p>
              <ul v-if="role.permissions.length" class="flex flex-wrap gap-1.5" aria-label="สิทธิ์แยกตามระบบ">
                <li
                  v-for="m in moduleSummary(role)"
                  :key="m.name"
                  class="rounded-lg border bg-gray-50 px-2.5 py-1 text-xs text-gray-700"
                >
                  {{ m.name }} <span class="font-semibold">{{ m.count }}</span>
                </li>
              </ul>
              <p v-else class="text-xs text-gray-400">ยังไม่ได้กำหนดสิทธิ์</p>
            </div>
            <div class="flex flex-shrink-0 gap-2">
              <button
                type="button"
                @click="openEdit(role)"
                class="rounded-lg border px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                :aria-label="`แก้ไขบทบาท ${role.display_name}`"
              >
                แก้ไข
              </button>
              <button
                v-if="role.can_delete"
                type="button"
                @click="remove(role)"
                class="rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50"
                :aria-label="`ลบบทบาท ${role.display_name}`"
              >
                ลบ
              </button>
            </div>
          </div>
        </article>
        <p v-if="!roles.length" class="rounded-2xl border bg-white p-10 text-center text-gray-500">ยังไม่มีบทบาทในระบบ</p>
      </section>
    </template>

    <!-- หน้าต่างสร้าง/แก้ไขบทบาท -->
    <div
      v-if="showForm"
      class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4"
      @click.self="closeForm"
    >
      <section role="dialog" aria-modal="true" aria-labelledby="role-form-title" class="my-6 w-full max-w-3xl rounded-2xl bg-white shadow-xl">
        <div class="flex items-center justify-between border-b px-6 py-4">
          <h2 id="role-form-title" class="text-lg font-bold">{{ editing ? 'แก้ไขบทบาท' : 'สร้างบทบาทใหม่' }}</h2>
          <button type="button" @click="closeForm" aria-label="ปิด" class="rounded-lg p-1 text-gray-500 hover:bg-gray-100">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.x"/>
            </svg>
          </button>
        </div>

        <div class="max-h-[70vh] space-y-5 overflow-y-auto px-6 py-5">
          <p v-if="error" role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ error }}</p>
          <label class="block text-sm font-semibold text-gray-800">
            ชื่อบทบาท <span class="text-red-600" aria-hidden="true">*</span>
            <input
              v-model="form.display_name"
              type="text"
              required
              maxlength="255"
              placeholder="เช่น เจ้าหน้าที่งานการเงิน"
              class="mt-1 w-full rounded-xl border px-3 py-2 text-sm font-normal focus:outline-none focus:ring-2 focus:ring-[#800000]"
            />
          </label>
          <p v-if="editing?.is_full_access" class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">
            บทบาทนี้เป็นผู้ดูแลระบบสูงสุด ควรมีสิทธิ์ครบทุกข้อ
          </p>

          <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm font-semibold text-gray-800">สิทธิ์การใช้งาน <span class="font-normal text-gray-500">(เลือกแล้ว {{ form.permission_ids.length }} รายการ)</span></p>
            <div class="flex flex-wrap items-center gap-3">
              <label class="flex items-center gap-2 text-sm text-gray-700">
                <input v-model="onlySelected" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-[#800000]" />
                แสดงเฉพาะที่เลือก
              </label>
              <input
                v-model="search"
                type="search"
                placeholder="ค้นหาสิทธิ์"
                aria-label="ค้นหาสิทธิ์"
                class="rounded-lg border px-3 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-[#800000]"
              />
            </div>
          </div>

          <div class="space-y-3">
            <fieldset
              v-for="group in visibleGroups"
              :key="group.name"
              class="rounded-xl border"
            >
              <legend class="sr-only">{{ group.name }}</legend>
              <div class="flex items-center justify-between gap-2 rounded-t-xl bg-gray-50 px-4 py-2.5">
                <button
                  type="button"
                  class="flex items-center gap-2 text-left text-sm font-semibold text-gray-900"
                  :aria-expanded="isOpen(group.name)"
                  @click="toggleOpen(group.name)"
                >
                  <svg class="w-4 h-4 transition-transform" :class="isOpen(group.name) ? 'rotate-90' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                  </svg>
                  {{ group.name }}
                  <span class="font-normal text-gray-500">({{ selectedIn(group) }}/{{ group.all.length }})</span>
                </button>
                <button type="button" class="text-xs font-medium text-[#800000] hover:underline" @click="toggleGroup(group)">
                  {{ selectedIn(group) === group.all.length ? 'ไม่เลือกทั้งหมด' : 'เลือกทั้งหมด' }}
                </button>
              </div>
              <div v-show="isOpen(group.name)" class="grid grid-cols-1 gap-1 p-3 md:grid-cols-2">
                <label
                  v-for="perm in group.items"
                  :key="perm.id"
                  class="flex cursor-pointer items-start gap-2 rounded-lg p-2 text-sm text-gray-800 hover:bg-gray-50"
                >
                  <input
                    v-model="form.permission_ids"
                    type="checkbox"
                    :value="perm.id"
                    class="mt-0.5 h-4 w-4 flex-shrink-0 rounded border-gray-300 text-[#800000]"
                  />
                  <span>{{ perm.label }}</span>
                </label>
              </div>
            </fieldset>
            <p v-if="!visibleGroups.length" class="rounded-xl border p-6 text-center text-sm text-gray-500">ไม่พบสิทธิ์ที่ค้นหา</p>
          </div>
        </div>

        <div class="flex justify-end gap-3 border-t bg-gray-50 px-6 py-4 rounded-b-2xl">
          <button type="button" @click="closeForm" class="rounded-xl border bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">ยกเลิก</button>
          <button
            type="button"
            @click="save"
            :disabled="saving || !form.display_name.trim()"
            class="rounded-xl bg-[#800000] px-5 py-2 text-sm font-semibold text-white hover:bg-[#600000] disabled:opacity-50"
          >
            {{ saving ? 'กำลังบันทึก...' : 'บันทึก' }}
          </button>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../../axios';
import { useAuthStore } from '../../stores/auth';
import LoadingState from '../../components/ui/LoadingState.vue';
import { ICONS } from '../../utils/icons';
import { getErrorMessage } from '../../utils/errorHandler';

const authStore = useAuthStore();
const roles = ref([]);
const groups = ref([]);
const loading = ref(true);
const saving = ref(false);
const notice = ref('');
const error = ref('');

const showForm = ref(false);
const editing = ref(null);
const form = ref({ display_name: '', permission_ids: [] });
const search = ref('');
const onlySelected = ref(false);
const openGroups = ref(new Set());

const totalUsers = computed(() => roles.value.reduce((sum, r) => sum + (r.users_count || 0), 0));
const unusedCount = computed(() => roles.value.filter(r => !r.users_count).length);

const load = async () => {
  loading.value = true;
  error.value = '';
  try {
    const [rolesRes, permsRes] = await Promise.all([api.get('/admin/roles'), api.get('/admin/permissions')]);
    roles.value = rolesRes.data?.data || [];
    groups.value = permsRes.data?.groups || [];
  } catch (e) {
    error.value = getErrorMessage(e, 'โหลดบทบาทและสิทธิ์ไม่สำเร็จ');
  } finally {
    loading.value = false;
  }
};

/** จำนวนสิทธิ์ของบทบาทแยกตามระบบ เรียงตามลำดับระบบในหน้าแก้ไข */
const moduleSummary = (role) => {
  const counts = {};
  role.permissions.forEach(p => { counts[p.module] = (counts[p.module] || 0) + 1; });
  const order = groups.value.map(g => g.name);
  return Object.entries(counts)
    .map(([name, count]) => ({ name, count }))
    .sort((a, b) => order.indexOf(a.name) - order.indexOf(b.name));
};

const visibleGroups = computed(() => {
  const q = search.value.trim();
  const selected = new Set(form.value.permission_ids);
  return groups.value
    .map(g => ({
      name: g.name,
      all: g.permissions,
      items: g.permissions.filter(p => (!q || p.label.includes(q)) && (!onlySelected.value || selected.has(p.id))),
    }))
    .filter(g => g.items.length);
});

const selectedIn = (group) => group.all.filter(p => form.value.permission_ids.includes(p.id)).length;

const toggleGroup = (group) => {
  const ids = group.all.map(p => p.id);
  form.value.permission_ids = selectedIn(group) === ids.length
    ? form.value.permission_ids.filter(id => !ids.includes(id))
    : Array.from(new Set([...form.value.permission_ids, ...ids]));
};

// เปิดหมวดอัตโนมัติเมื่อค้นหา หรือหมวดที่มีสิทธิ์ที่เลือกไว้
const isOpen = (name) => !!search.value.trim() || onlySelected.value || openGroups.value.has(name);
const toggleOpen = (name) => {
  const next = new Set(openGroups.value);
  next.has(name) ? next.delete(name) : next.add(name);
  openGroups.value = next;
};

const openWith = (role) => {
  const ids = role ? role.permissions.map(p => p.id) : [];
  form.value = { display_name: role?.display_name || '', permission_ids: ids };
  openGroups.value = new Set(groups.value.filter(g => g.permissions.some(p => ids.includes(p.id))).map(g => g.name));
  search.value = '';
  onlySelected.value = false;
  error.value = '';
  showForm.value = true;
};
const openCreate = () => { editing.value = null; openWith(null); };
const openEdit = (role) => { editing.value = role; openWith(role); };
const closeForm = () => { showForm.value = false; editing.value = null; error.value = ''; };

const flash = (message) => {
  notice.value = message;
  setTimeout(() => { notice.value = ''; }, 4000);
};

const save = async () => {
  saving.value = true;
  error.value = '';
  try {
    const payload = { display_name: form.value.display_name.trim(), permission_ids: form.value.permission_ids };
    const res = editing.value
      ? await api.put(`/admin/roles/${editing.value.id}`, payload)
      : await api.post('/admin/roles', payload);
    flash(res.data?.message || 'บันทึกเรียบร้อยแล้ว');
    closeForm();
    await load();
    await authStore.fetchUser();
  } catch (e) {
    error.value = getErrorMessage(e, 'บันทึกบทบาทไม่สำเร็จ');
  } finally {
    saving.value = false;
  }
};

const remove = async (role) => {
  if (!confirm(`ต้องการลบบทบาท "${role.display_name}" หรือไม่`)) return;
  error.value = '';
  try {
    const res = await api.delete(`/admin/roles/${role.id}`);
    flash(res.data?.message || 'ลบบทบาทเรียบร้อยแล้ว');
    await load();
  } catch (e) {
    error.value = getErrorMessage(e, 'ลบบทบาทไม่สำเร็จ');
  }
};

onMounted(load);
</script>
