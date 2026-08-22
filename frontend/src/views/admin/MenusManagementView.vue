<template>
  <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8 space-y-6">

    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <h2 class="text-2xl font-bold text-gray-900 flex items-center gap-3">
          <span class="p-2 bg-indigo-100 rounded-xl text-indigo-700">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
            </svg>
          </span>
          ภาพรวมโมดูลและสิทธิ์การเข้าถึง
        </h2>
        <p class="mt-1 text-sm text-gray-500">
          แสดงโครงสร้างโมดูลทั้งหมดในระบบ พร้อมรายการสิทธิ์ (Permissions) ที่กำหนดไว้ในแต่ละโมดูล<br>
          หากต้องการกำหนดสิทธิ์รายบุคคล ให้ไปที่ <router-link to="/admin/users" class="text-indigo-600 hover:underline font-medium">จัดการผู้ใช้งาน → ปุ่มสิทธิ์</router-link>
        </p>
      </div>
      <button
        @click="loadData"
        :disabled="loading"
        class="flex items-center gap-2 px-4 py-2.5 text-sm font-semibold text-slate-600 bg-white border border-slate-200 rounded-xl hover:bg-slate-50 transition shadow-sm disabled:opacity-50"
      >
        <svg class="w-4 h-4" :class="{ 'animate-spin': loading }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
        รีเฟรชข้อมูล
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="py-20 flex flex-col items-center text-slate-400">
      <div class="w-10 h-10 border-4 border-indigo-500 border-t-transparent rounded-full animate-spin"></div>
      <p class="mt-3 text-sm">กำลังโหลดข้อมูลโมดูล...</p>
    </div>

    <template v-else>
      <!-- Notice Banner -->
      <div class="p-4 bg-blue-50 border border-blue-200 rounded-xl flex items-start gap-3">
        <svg class="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <div class="text-sm text-blue-800">
          <strong class="font-bold">วิธีการทำงานของระบบสิทธิ์ปัจจุบัน:</strong>
          สิทธิ์ทั้งหมดถูกกำหนดจากโค้ด (<code class="bg-blue-100 px-1 rounded font-mono text-xs">config/modules.php</code>)
          เมนูใน Sidebar จะแสดงหรือซ่อนโดยอัตโนมัติตาม <strong>สิทธิ์สุทธิ (Effective Permissions)</strong> ของผู้ใช้แต่ละคน
          ซึ่งประกอบด้วยสิทธิ์จากบทบาท (Role) + สิทธิ์เฉพาะบุคคล (Direct Permission)
        </div>
      </div>

      <!-- Module Cards -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div
          v-for="(module, moduleId) in modules"
          :key="moduleId"
          class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden"
        >
          <!-- Module Header -->
          <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white font-bold text-sm flex-shrink-0"
                :class="moduleColors[moduleId] || 'bg-slate-500'">
                {{ moduleId.substring(0, 2).toUpperCase() }}
              </div>
              <div>
                <h3 class="text-sm font-bold text-slate-900">{{ module.name }}</h3>
                <p class="text-xs text-slate-500">{{ module.description }}</p>
              </div>
            </div>

            <!-- Module Enable/Disable toggle -->
            <div v-if="moduleId === 'saraban' || moduleId === 'attendance'" class="flex items-center gap-2 flex-shrink-0">
              <!-- Saraban toggle -->
              <template v-if="moduleId === 'saraban'">
                <span class="text-xs text-slate-500">{{ settingStore.isSarabanEnabled ? 'เปิดใช้งาน' : 'ปิดใช้งาน' }}</span>
                <button
                  @click="toggleSarabanModule"
                  role="switch"
                  aria-label="เปิดหรือปิดโมดูลสารบรรณ"
                  :aria-checked="settingStore.isSarabanEnabled"
                  :class="settingStore.isSarabanEnabled ? 'bg-emerald-500' : 'bg-slate-300'"
                  class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none"
                  :disabled="savingModule"
                >
                  <span
                    :class="settingStore.isSarabanEnabled ? 'translate-x-6' : 'translate-x-1'"
                    class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                  />
                </button>
              </template>
              <!-- Attendance toggle -->
              <template v-if="moduleId === 'attendance'">
                <span class="text-xs text-slate-500">{{ settingStore.isAttendanceEnabled ? 'เปิดใช้งาน' : 'ปิดใช้งาน' }}</span>
                <button
                  @click="toggleAttendanceModule"
                  role="switch"
                  aria-label="เปิดหรือปิดโมดูลลงเวลา"
                  :aria-checked="settingStore.isAttendanceEnabled"
                  :class="settingStore.isAttendanceEnabled ? 'bg-emerald-500' : 'bg-slate-300'"
                  class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none"
                  :disabled="savingModule"
                >
                  <span
                    :class="settingStore.isAttendanceEnabled ? 'translate-x-6' : 'translate-x-1'"
                    class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                  />
                </button>
              </template>
            </div>
          </div>

          <!-- Permissions List -->
          <div class="p-4 space-y-2">
            <div
              v-for="(desc, permKey) in module.permissions"
              :key="permKey"
              class="flex items-start gap-3 p-2.5 rounded-lg hover:bg-slate-50 group"
            >
              <!-- Status dot -->
              <div class="mt-0.5 w-2 h-2 rounded-full bg-emerald-400 flex-shrink-0 ring-2 ring-emerald-100"></div>

              <div class="flex-1 min-w-0">
                <p class="text-xs font-semibold text-slate-800">{{ desc }}</p>
                <code class="text-[10px] text-slate-400 font-mono">{{ permKey }}</code>
              </div>

              <!-- Usage badge -->
              <span class="flex-shrink-0 text-[10px] font-medium px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">
                {{ permissionUserCount[permKey] !== undefined ? permissionUserCount[permKey] + ' คน' : '...' }}
              </span>
            </div>
          </div>

          <!-- Module Footer Stats -->
          <div class="px-5 py-3 bg-slate-50/70 border-t border-slate-100 flex items-center gap-4 text-xs text-slate-500">
            <span>{{ Object.keys(module.permissions).length }} สิทธิ์</span>
            <span class="text-slate-300">|</span>
            <span>กำหนดใน <code class="font-mono bg-slate-100 px-1 rounded text-slate-600">config/modules.php</code></span>
          </div>
        </div>
      </div>

      <!-- Permission–User Summary Table -->
      <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
          <div>
            <h3 class="text-sm font-bold text-slate-900">สรุปสิทธิ์ที่ผู้ใช้งานได้รับ</h3>
            <p class="text-xs text-slate-500 mt-0.5">แสดงจำนวนผู้ใช้งานที่ได้รับสิทธิ์แต่ละรายการ (ทั้งจาก Role และ Direct Permission)</p>
          </div>
          <router-link
            to="/admin/users"
            class="flex items-center gap-1.5 text-xs font-semibold text-indigo-600 hover:text-indigo-800 transition"
          >
            จัดการสิทธิ์รายบุคคล
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
          </router-link>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-slate-50 border-b border-slate-100">
              <tr>
                <th class="text-left px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">สิทธิ์ (Permission)</th>
                <th class="text-left px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">คำอธิบาย</th>
                <th class="text-left px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">โมดูล</th>
                <th class="text-center px-6 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">จำนวนผู้ใช้</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <template v-for="(module, moduleId) in modules" :key="moduleId">
                <tr v-for="(desc, permKey) in module.permissions" :key="permKey" class="hover:bg-slate-50/60 transition">
                  <td class="px-6 py-3">
                    <code class="text-xs font-mono text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded">{{ permKey }}</code>
                  </td>
                  <td class="px-6 py-3 text-xs text-slate-700">{{ desc }}</td>
                  <td class="px-6 py-3">
                    <span class="text-xs font-medium px-2.5 py-0.5 rounded-full"
                      :class="moduleColors[moduleId] ? moduleColors[moduleId].replace('bg-', 'bg-opacity-10 text-').replace('500', '700') + ' bg-' + moduleColors[moduleId].split('-')[1] + '-100' : 'bg-slate-100 text-slate-700'">
                      {{ moduleId }}
                    </span>
                  </td>
                  <td class="px-6 py-3 text-center">
                    <span class="inline-flex items-center justify-center min-w-[2rem] px-2 py-0.5 rounded-full text-xs font-bold"
                      :class="(permissionUserCount[permKey] || 0) > 0 ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-400'">
                      {{ permissionUserCount[permKey] !== undefined ? permissionUserCount[permKey] : '-' }}
                    </span>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../axios';
import { useAlertStore } from '../../stores/alert';
import { useSettingStore } from '../../stores/settingStore';

const alertStore = useAlertStore();
const settingStore = useSettingStore();

const loading = ref(true);
const savingModule = ref(false);
const modules = ref({});
const permissionUserCount = ref({});

const moduleColors = {
  leave:      'bg-sky-500',
  saraban:    'bg-violet-500',
  admin:      'bg-rose-500',
  attendance: 'bg-amber-500',
  finance:    'bg-emerald-500',
};

async function loadData() {
  loading.value = true;
  try {
    const [modulesRes, countsRes] = await Promise.all([
      api.get('/admin/permissions/modules'),
      api.get('/admin/permissions/user-counts').catch(() => ({ data: {} })),
    ]);

    await settingStore.fetchSettings();

    if (modulesRes.data?.success) {
      modules.value = modulesRes.data.modules || {};
    }

    if (countsRes.data && typeof countsRes.data === 'object') {
      permissionUserCount.value = countsRes.data;
    } else {
      const counts = {};
      for (const module of Object.values(modules.value)) {
        for (const key of Object.keys(module.permissions || {})) {
          counts[key] = 0;
        }
      }
      permissionUserCount.value = counts;
    }
  } catch (err) {
    console.error(err);
    alertStore.showError('ไม่สามารถโหลดข้อมูลโมดูลได้');
  } finally {
    loading.value = false;
  }
}

async function toggleSarabanModule() {
  savingModule.value = true;
  const newValue = settingStore.isSarabanEnabled ? '0' : '1';
  try {
    await settingStore.updateSetting('enable_saraban_menu', newValue);
    alertStore.showSuccess(newValue !== '0' ? 'เปิดใช้งานโมดูลสารบรรณแล้ว' : 'ปิดใช้งานโมดูลสารบรรณแล้ว');
  } catch {
    alertStore.showError('ไม่สามารถเปลี่ยนสถานะโมดูลได้');
  } finally {
    savingModule.value = false;
  }
}

async function toggleAttendanceModule() {
  savingModule.value = true;
  const newValue = settingStore.isAttendanceEnabled ? '0' : '1';
  try {
    await settingStore.updateSetting('enable_attendance_menu', newValue);
    alertStore.showSuccess(newValue !== '0' ? 'เปิดใช้งานระบบลงเวลาออนไลน์แล้ว' : 'ปิดใช้งานระบบลงเวลาออนไลน์แล้ว');
  } catch {
    alertStore.showError('ไม่สามารถเปลี่ยนสถานะระบบลงเวลาได้');
  } finally {
    savingModule.value = false;
  }
}

onMounted(loadData);
</script>

