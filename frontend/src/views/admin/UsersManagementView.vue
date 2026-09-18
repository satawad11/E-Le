<template>
  <div>
    <!-- Breadcrumb Navigation -->
    <AppBreadcrumb
      :items="[
        { title: 'ตั้งค่าระบบ', to: '/admin' },
        { title: 'จัดการผู้ใช้งาน (บุคลากร)' }
      ]"
      :backTo="'/admin'"
    />

    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">จัดการผู้ใช้งาน (บุคลากร)</h1>
        <p class="text-gray-500 mt-1">เพิ่ม แก้ไข และลบข้อมูลบุคลากรภายในระบบ</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button
          @click="downloadCsvTemplate"
          class="flex items-center gap-2 px-4 py-2.5 bg-gray-600 hover:bg-gray-700 text-white font-semibold rounded-xl text-sm transition-all shadow-md hover:shadow-lg"
          title="ดาวน์โหลดรูปแบบไฟล์ CSV สำหรับนำเข้า"
        >
          <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.download"/>
          </svg>
          ฟอร์ม CSV
        </button>

        <button
          @click="triggerCsvUpload"
          class="flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl text-sm transition-all shadow-md hover:shadow-lg"
        >
          <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.upload"/>
          </svg>
          นำเข้าด้วย CSV
        </button>
        <input type="file" ref="csvInput" @change="handleCsvUpload" accept=".csv" class="hidden" />

        <button
          @click="openAddModal"
          class="flex items-center gap-2 px-5 py-2.5 bg-[#10b981] hover:bg-[#059669] text-white font-semibold rounded-xl text-sm transition-all shadow-md hover:shadow-lg"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
          </svg>
          เพิ่มข้อมูลบุคลากร
        </button>
      </div>
    </div>

    <!-- Filters Section -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mb-6">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <!-- Search bar -->
        <div class="relative md:col-span-2">
          <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-gray-400">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
          </span>
          <input
            aria-label="ค้นหาบุคลากรจากชื่อหรือเลขประจำตัวประชาชน"
            v-model="filters.search"
            type="text"
            placeholder="ค้นหาชื่อ, เลขประจำตัวประชาชน..."
            class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-gray-200 focus:outline-none focus:border-[#800000] text-sm transition-colors"
          />
        </div>

        <!-- Department filter -->
        <div>
          <select
            aria-label="กรองตามแผนก"
            v-model="filters.org_unit_id"
            class="w-full px-4 py-2.5 rounded-xl border border-gray-200 focus:outline-none focus:border-[#800000] text-sm transition-colors text-gray-700 bg-white"
          >
            <option value="">แผนกทั้งหมด</option>
            <option v-for="dept in departments" :key="dept.id" :value="dept.id">
              {{ dept.name }}
            </option>
          </select>
        </div>

        <!-- Status filter -->
        <div>
          <select
            aria-label="กรองตามสถานะ"
            v-model="filters.status"
            class="w-full px-4 py-2.5 rounded-xl border border-gray-200 focus:outline-none focus:border-[#800000] text-sm transition-colors text-gray-700 bg-white"
          >
            <option value="">สถานะทั้งหมด</option>
            <option value="ปฏิบัติหน้าที่">ปฏิบัติหน้าที่</option>
            <option value="ลาออก">ลาออก</option>
          </select>
        </div>
      </div>

      <!--
        บัญชีที่ถูกลบ — การลบเป็นแบบเก็บประวัติไว้ ไม่ได้ลบแถวจริง
        ถ้าไม่มีทางเห็น ผู้ดูแลก็กู้คืนไม่ได้และไม่รู้ว่าเคยลบใครไปบ้าง
      -->
      <label class="mt-3 flex items-center gap-2 text-sm text-gray-600">
        <input v-model="showTrashed" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-[#800000] focus:ring-[#800000]" @change="reloadFirstPage">
        แสดงเฉพาะบัญชีที่ถูกลบ (กู้คืนได้)
      </label>
    </div>

    <!-- Users Table -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
      <!-- Loading State -->
      <div v-if="loading" class="p-6">
        <LoadingState type="skeleton-table" :rows="5" :cols="7" message="กำลังโหลดรายชื่อบุคลากร..." />
      </div>

      <!-- Error State -->
      <div v-else-if="loadError" class="p-6">
        <ErrorState :message="loadError" @retry="loadInitialData" />
      </div>

      <div v-else>
        <!--
          แถบดำเนินการหลายคนพร้อมกัน — งานจริงตอนต้นปีการศึกษาคือย้ายคนทั้งกลุ่ม
          ไปอีกหน่วยงาน หรือระงับบัญชีผู้พ้นหน้าที่พร้อมกันหลายคน
        -->
        <div v-if="selectedIds.length" class="flex flex-wrap items-center gap-3 border-b border-gray-100 bg-amber-50 px-4 py-3 text-sm">
          <span class="font-semibold text-amber-900">เลือกไว้ {{ selectedIds.length }} คน</span>
          <select v-model="bulkOrgUnitId" aria-label="หน่วยงานปลายทางสำหรับการย้ายสังกัดหลายคน" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm">
            <option value="">— เลือกหน่วยงานปลายทาง —</option>
            <option v-for="dept in departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
          </select>
          <button class="rounded-lg bg-[#800000] px-3 py-1.5 text-white disabled:opacity-40" :disabled="!bulkOrgUnitId || bulkRunning" @click="runBulk('move_org_unit')">
            ย้ายสังกัด
          </button>
          <button class="rounded-lg bg-amber-600 px-3 py-1.5 text-white disabled:opacity-40" :disabled="bulkRunning" @click="runBulk('suspend')">
            ระงับการใช้งาน
          </button>
          <button class="rounded-lg bg-emerald-600 px-3 py-1.5 text-white disabled:opacity-40" :disabled="bulkRunning" @click="runBulk('activate')">
            เปิดใช้งาน
          </button>
          <button class="text-gray-600 underline underline-offset-2" @click="selectedIds = []">ยกเลิกการเลือก</button>
        </div>

        <!-- Empty State -->
        <EmptyState
          v-if="filteredUsers.length === 0"
          title="ไม่พบข้อมูลบุคลากร"
          description="ไม่มีข้อมูลบุคลากรที่ตรงกับเงื่อนไขการค้นหาหรือตัวกรอง"
          action-text="เพิ่มบุคลากรใหม่"
          @action="openAddModal"
        />

        <!-- Desktop Table (md:block) -->
        <div v-else class="hidden md:block overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="text-center px-4 py-3">
                  <input
                    type="checkbox"
                    aria-label="เลือกทั้งหน้า"
                    class="h-4 w-4 rounded border-gray-300 text-[#800000] focus:ring-[#800000]"
                    :checked="allOnPageSelected"
                    @change="toggleSelectPage($event.target.checked)"
                  >
                </th>
                <th class="text-center px-4 py-3 text-xs font-semibold text-gray-600">#</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-600">เลขประจำตัวประชาชน</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-600">ชื่อบุคลากร</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-600">ตำแหน่ง</th>
                <th class="text-center px-4 py-3 text-xs font-semibold text-gray-600">วันที่เริ่มปฏิบัติงาน</th>
                <th class="text-center px-4 py-3 text-xs font-semibold text-gray-600">สถานะ</th>
                <th class="text-center px-4 py-3 text-xs font-semibold text-gray-600">การดำเนินการ</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="(user, index) in filteredUsers" :key="user.id" class="hover:bg-gray-50 transition-colors">
                <td class="px-4 py-3 text-center">
                  <input
                    type="checkbox"
                    :aria-label="`เลือก ${user.name}`"
                    class="h-4 w-4 rounded border-gray-300 text-[#800000] focus:ring-[#800000]"
                    :value="user.id"
                    v-model="selectedIds"
                  >
                </td>
                <!-- ลำดับต่อเนื่องข้ามหน้า ไม่ใช่เริ่มที่ 1 ใหม่ทุกหน้า -->
                <td class="px-4 py-3 text-center text-gray-500">{{ (pagination.current_page - 1) * pagination.per_page + index + 1 }}</td>
                <td class="px-4 py-3 text-blue-600 font-medium">{{ user.profile?.id_card_number || '-' }}</td>
                <td class="px-4 py-3">
                  <div class="font-medium text-gray-900">{{ user.personnel?.full_name || user.name }}</div>
                  <div v-if="user.personnel_code || user.personnel?.personnel_code" class="text-xs text-gray-500">รหัส: {{ user.personnel?.personnel_code || user.personnel_code }}</div>
                </td>
                <td class="px-4 py-3 text-gray-600">{{ user.current_assignment?.position || user.position || user.profile?.position || (user.role ? roleLabel(user.role.name) : '-') }}</td>
                <td class="px-4 py-3 text-center text-gray-600">{{ formatDate(user.personnel?.effective_from || user.profile?.start_date) || '-' }}</td>
                <td class="px-4 py-3 text-center">
                  <span class="inline-block px-2 py-1 text-xs font-medium rounded-md" :class="(user.personnel?.status || user.personnel_status || user.profile?.status) === 'active' || (user.personnel?.status || user.personnel_status || user.profile?.status) === 'ปฏิบัติหน้าที่' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'">
                    {{ user.personnel?.status === 'active' ? 'ปฏิบัติหน้าที่' : (user.personnel?.status || user.profile?.status || 'ปฏิบัติหน้าที่') }}
                  </span>
                </td>
                <td class="px-4 py-3 text-center">
                  <!-- บัญชีที่ถูกลบทำได้อย่างเดียวคือกู้คืน — แก้ไข/ระงับ/ลบซ้ำไม่มีความหมาย -->
                  <div v-if="showTrashed" class="flex items-center justify-center">
                    <button @click="restoreUser(user)" class="inline-flex items-center gap-1 px-3 py-1 bg-emerald-600 text-white rounded text-xs hover:bg-emerald-700 transition">
                      <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9"/>
                      </svg>
                      กู้คืน
                    </button>
                  </div>
                  <div v-else class="flex flex-wrap items-center justify-center gap-2">
                    <button @click="toggleStatus(user)" class="inline-flex items-center gap-1 px-3 py-1 rounded text-xs transition" :class="user.is_active ? 'bg-amber-500 hover:bg-amber-600 text-white' : 'bg-green-500 hover:bg-green-600 text-white'" :disabled="submitting" title="เปิด-ปิด การใช้งาน">
                      <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="user.is_active ? ICONS.ban : ICONS.check"/>
                      </svg>
                      {{ user.is_active ? 'ระงับ' : 'เปิดใช้งาน' }}
                    </button>
                    <button @click="openPermissionModal(user)" class="inline-flex items-center gap-1 px-3 py-1 bg-indigo-600 text-white rounded text-xs hover:bg-indigo-700 transition" title="จัดการสิทธิ์การเข้าถึงรายบุคคล">
                      <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.shieldCheck"/>
                      </svg>
                      สิทธิ์
                    </button>
                    <button @click="openEditModal(user)" class="inline-flex items-center gap-1 px-3 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700 transition">
                      <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.pencil"/>
                      </svg>
                      แก้ไข
                    </button>
                    <button v-if="user.id !== authStore.user?.id" @click="confirmDelete(user)" class="inline-flex items-center gap-1 px-3 py-1 bg-red-600 text-white rounded text-xs hover:bg-red-700 transition">
                      <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.trash"/>
                      </svg>
                      ลบ
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobile Cards View (< md) -->
        <div v-if="filteredUsers.length" class="block md:hidden divide-y divide-gray-100">
          <div
            v-for="user in filteredUsers"
            :key="`mob-${user.id}`"
            class="p-4 space-y-3 bg-white hover:bg-gray-50/70 transition-colors"
          >
            <div class="flex items-start justify-between gap-2">
              <div class="flex items-center gap-3">
                <input
                  type="checkbox"
                  :aria-label="`เลือก ${user.name}`"
                  class="h-4 w-4 rounded border-gray-300 text-[#800000] focus:ring-[#800000]"
                  :value="user.id"
                  v-model="selectedIds"
                >
                <div>
                  <h4 class="font-bold text-gray-900 text-sm">{{ user.personnel?.full_name || user.name }}</h4>
                  <p class="text-xs text-blue-600 font-mono">{{ user.profile?.id_card_number || '-' }}</p>
                </div>
              </div>
              <span
                class="inline-block px-2 py-0.5 text-xs font-semibold rounded-full"
                :class="(user.personnel?.status || user.personnel_status || user.profile?.status) === 'active' || (user.personnel?.status || user.personnel_status || user.profile?.status) === 'ปฏิบัติหน้าที่' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'"
              >
                {{ user.personnel?.status === 'active' ? 'ปฏิบัติหน้าที่' : (user.personnel?.status || user.profile?.status || 'ปฏิบัติหน้าที่') }}
              </span>
            </div>

            <div class="grid grid-cols-2 gap-2 text-xs text-gray-600 bg-gray-50 p-2.5 rounded-xl">
              <div>
                <span class="text-gray-400 block text-[11px]">ตำแหน่ง:</span>
                <span class="font-medium text-gray-800">{{ user.current_assignment?.position || user.position || user.profile?.position || (user.role ? roleLabel(user.role.name) : '-') }}</span>
              </div>
              <div>
                <span class="text-gray-400 block text-[11px]">เริ่มงาน:</span>
                <span class="font-medium text-gray-800">{{ formatDate(user.personnel?.effective_from || user.profile?.start_date) || '-' }}</span>
              </div>
            </div>

            <!-- Mobile Action Buttons -->
            <div v-if="showTrashed" class="flex justify-end pt-1">
              <button @click="restoreUser(user)" class="inline-flex items-center gap-1 px-3 py-1.5 bg-emerald-600 text-white rounded-lg text-xs font-semibold hover:bg-emerald-700 transition">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9"/></svg>
                กู้คืน
              </button>
            </div>
            <div v-else class="flex flex-wrap items-center justify-end gap-1.5 pt-1">
              <button @click="toggleStatus(user)" class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-semibold transition" :class="user.is_active ? 'bg-amber-500 hover:bg-amber-600 text-white' : 'bg-green-500 hover:bg-green-600 text-white'" :disabled="submitting">
                {{ user.is_active ? 'ระงับ' : 'เปิดใช้งาน' }}
              </button>
              <button @click="openPermissionModal(user)" class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-indigo-600 text-white rounded-lg text-xs font-semibold hover:bg-indigo-700 transition">
                สิทธิ์
              </button>
              <button @click="openEditModal(user)" class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-blue-600 text-white rounded-lg text-xs font-semibold hover:bg-blue-700 transition">
                แก้ไข
              </button>
              <button v-if="user.id !== authStore.user?.id" @click="confirmDelete(user)" class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-red-600 text-white rounded-lg text-xs font-semibold hover:bg-red-700 transition">
                ลบ
              </button>
            </div>
          </div>
        </div>

        <!-- แถบแบ่งหน้า — รายชื่อโหลดทีละหน้าจากเซิร์ฟเวอร์ -->
        <div v-if="pagination.total > 0" class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 px-4 py-3 text-sm">
          <p class="text-gray-500">
            หน้า {{ pagination.current_page }} จาก {{ pagination.last_page }} · ทั้งหมด {{ pagination.total }} คน
          </p>
          <div class="flex items-center gap-2">
            <button
              class="rounded-lg border border-gray-200 px-3 py-1.5 text-gray-700 disabled:opacity-40"
              :disabled="pagination.current_page <= 1"
              @click="goToPage(pagination.current_page - 1)"
            >
              ก่อนหน้า
            </button>
            <button
              class="rounded-lg border border-gray-200 px-3 py-1.5 text-gray-700 disabled:opacity-40"
              :disabled="pagination.current_page >= pagination.last_page"
              @click="goToPage(pagination.current_page + 1)"
            >
              ถัดไป
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ฟอร์มเพิ่ม/แก้ไขบุคลากร — แยกเป็นคอมโพเนนต์ของตัวเอง -->
    <UserFormModal
      :modal="modal"
      :form="form"
      :active-tab="activeTab"
      :tab-titles="tabTitles"
      :departments="departments"
      :personnel-types="personnelTypes"
      :roles="roles"
      :available-permissions="availablePermissions"
      :image-preview="imagePreview"
      :submitting="submitting"
      :is-admin="authStore.isAdmin"
      @update:active-tab="activeTab = $event"
      @submit="submitForm"
      @image-selected="onImageSelected"
    />


    <!-- Delete Confirmation Modal -->
    <transition name="fade">
      <div v-if="deleteModal.show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/45 backdrop-blur-sm p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 text-center">
          <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-4v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
          </div>
          <h3 class="font-bold text-gray-900 text-lg mb-1">ยืนยันการลบ?</h3>
          <p class="text-gray-500 text-sm mb-6">คุณแน่ใจว่าต้องการลบผู้ใช้งาน <strong class="text-gray-800">{{ deleteModal.userName }}</strong> หรือไม่?</p>
          <div class="flex gap-3">
            <button @click="deleteModal.show = false" class="flex-1 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold rounded-xl text-sm transition-colors">ยกเลิก</button>
            <button @click="doDelete" :disabled="submitting" class="flex-1 py-2.5 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-xl text-sm transition-colors">ลบผู้ใช้งาน</button>
          </div>
        </div>
      </div>
    </transition>
    <!-- User Individual Permissions Modal -->
    <UserPermissionModal 
      :show="permissionModal.show" 
      :user-id="permissionModal.userId" 
      @close="permissionModal.show = false" 
      @saved="onPermissionsSaved" 
    />
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useAuthStore } from '../../stores/auth'
import { useAlertStore } from '../../stores/alert'
import { useConfirm } from '../../composables/useConfirm'
import api from '../../axios'
import UserPermissionModal from '../../components/admin/UserPermissionModal.vue'
import { ThaiDateFormatter } from '../../utils/ThaiDateFormatter'
import { getErrorMessage } from '../../utils/errorHandler'
import { ICONS } from '../../utils/icons'
import { roleLabel } from '../../utils/roleLabel'
import AppBreadcrumb from '../../components/ui/AppBreadcrumb.vue'
import LoadingState from '../../components/ui/LoadingState.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import UserFormModal from '../../components/admin/UserFormModal.vue'

const authStore = useAuthStore()
const alertStore = useAlertStore()
const { confirmAction } = useConfirm()

const loading = ref(true)
const loadError = ref('')
const submitting = ref(false)
const users = ref([])
const departments = ref([])
const personnelTypes = ref([])
const roles = ref([])
const availablePermissions = ref([])

const permissionModal = reactive({
  show: false,
  userId: null
})

const openPermissionModal = (user) => {
  permissionModal.userId = user.id
  permissionModal.show = true
}

const onPermissionsSaved = async () => {
  await fetchUsers()
  await authStore.fetchUser()
}

const activeTab = ref('personal')
const csvInput = ref(null)
const imageFile = ref(null)
const imagePreview = ref(null)

const tabTitles = {
  personal: 'ข้อมูลส่วนตัว',
  address: 'ข้อมูลที่อยู่',
  employment: 'ข้อมูลการจ้าง',
  system: 'ข้อมูลระบบ',
  permissions: 'สิทธิ์การเข้าถึงพิเศษ'
}

const filters = reactive({
  search: '',
  org_unit_id: '',
  personnel_type_id: '',
  status: ''
})

const modal = reactive({
  show: false,
  isEdit: false,
  userId: null
})

const deleteModal = reactive({
  show: false,
  userId: null,
  userName: ''
})

const getDefaultProfile = () => ({
  id_card_number: '',
  date_of_birth: '',
  nationality: 'ไทย',

  passport_number: '',
  passport_issue_date: '',
  passport_expiry_date: '',
  title: 'นาย',
  first_name: '',
  last_name: '',
  middle_name: '',
  gender: 'ชาย',
  first_name_en: '',
  last_name_en: '',

  house_registration_number: '',
  house_number: '',
  moo: '',
  soi: '',
  road: '',
  province: '',
  district: '',
  sub_district: '',
  zipcode: '',
  fax: '',
  website: '',
  personnel_code: '',
  position: '',
  education_level: '',
  degree: '',
  start_date: '',
  license_type: '',
  license_number: '',
  license_issue_date: '',
  license_expiry_date: '',
  status: 'ปฏิบัติหน้าที่',
  profile_photo_path: ''
})

const form = reactive({
  name: '',
  email: '',
  phone: '',
  password: '',
  org_unit_id: '',
  personnel_type_id: '',
  role_id: '',
  telegram_chat_id: '',
  work_start_time: '',
  work_end_time: '',
  custom_permissions: [],
  profile: getDefaultProfile()
})

const loadInitialData = async () => {
  loading.value = true
  loadError.value = ''
  try {
    await Promise.all([
      fetchUsers(),
      fetchDepartments(),
      fetchPersonnelTypes(),
      fetchRoles(),
      fetchPermissions()
    ])
  } catch (e) {
    console.error(e)
    loadError.value = getErrorMessage(e, 'เกิดข้อผิดพลาดในการดึงข้อมูลบุคลากร')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadInitialData()
})

/**
 * SEC-030 — รายการบุคลากรไม่มีเลขประจำตัวประชาชนฉบับเต็มอีกต่อไป
 *
 * ตารางแสดงเลขแบบปิดบัง (เห็น 4 หลักท้าย) ส่วนการค้นหาด้วยเลขเต็ม 13 หลัก
 * ส่งไปให้ server เทียบด้วยค่าแฮช จึงยังค้นเจอได้โดยไม่ต้องส่งเลขของใครออกมา
 */
const showTrashed = ref(false)

/*
 * รายชื่อบุคลากรโหลดทีละหน้า และกรอง/ค้นหาที่ฝั่งเซิร์ฟเวอร์
 *
 * เดิมหน้านี้ดึงบุคลากร "ทุกคน" พร้อมความสัมพันธ์ครบชุดมาในคำขอเดียว แล้วกรองใน
 * เบราว์เซอร์ — ทะเบียนประวัติทั้งสถานศึกษาจึงถูกส่งออกไปทุกครั้งที่เปิดหน้า
 * ทั้งที่ผู้ใช้ดูจริงแค่ไม่กี่แถว
 */
const pagination = reactive({ current_page: 1, last_page: 1, total: 0, per_page: 25 })

// ไม่ระบุหน้า = โหลดหน้าเดิมซ้ำ (เช่นหลังบันทึกหรือระงับ) ผู้ดูแลจึงไม่ถูกดีดกลับหน้าแรก
const fetchUsers = async (page = pagination.current_page) => {
  const params = { page: Number(page) || 1, per_page: pagination.per_page }

  // เลขบัตรครบ 13 หลักส่งไปให้ server เทียบด้วยค่าแฮช ที่เหลือค้นจากชื่อ/รหัสบุคลากร
  const fullIdCard = asFullIdCard(filters.search)
  if (fullIdCard) params.id_card = fullIdCard
  else if (filters.search) params.search = filters.search

  if (filters.org_unit_id) params.org_unit_id = filters.org_unit_id
  if (filters.status) params.status = filters.status
  if (showTrashed.value) params.trashed = 'only'

  const res = await api.get('/admin/users', { params })

  // เปลี่ยนหน้า/ตัวกรองแล้วล้างการเลือก — สั่งกับคนที่ไม่เห็นบนจอไม่ได้
  selectedIds.value = []
  users.value = res.data.data ?? res.data
  Object.assign(pagination, {
    current_page: res.data.current_page ?? 1,
    last_page: res.data.last_page ?? 1,
    total: res.data.total ?? users.value.length,
    per_page: res.data.per_page ?? pagination.per_page,
  })
}

/** โหลดหน้าแรกใหม่ — ใช้เมื่อเงื่อนไขการค้นหาเปลี่ยน */
const reloadFirstPage = () => fetchUsers(1)

/*
 * ดำเนินการหลายคนพร้อมกัน
 *
 * การเลือกผูกกับหน้าที่กำลังดูอยู่ — เปลี่ยนหน้าหรือตัวกรองแล้วล้างการเลือกทิ้ง
 * เพื่อไม่ให้ผู้ดูแลสั่งกับคนที่มองไม่เห็นบนจอโดยไม่รู้ตัว
 */
const selectedIds = ref([])
const bulkOrgUnitId = ref('')
const bulkRunning = ref(false)

const allOnPageSelected = computed(
  () => users.value.length > 0 && users.value.every(u => selectedIds.value.includes(u.id)),
)

const toggleSelectPage = (checked) => {
  selectedIds.value = checked ? users.value.map(u => u.id) : []
}

const bulkLabels = {
  move_org_unit: 'ย้ายสังกัด',
  suspend: 'ระงับการใช้งาน',
  activate: 'เปิดใช้งาน',
}

const runBulk = async (action) => {
  const count = selectedIds.value.length
  if (!count) return

  const confirmed = await confirmAction({
    title: bulkLabels[action],
    message: `${bulkLabels[action]} ของบุคลากร ${count} คน ใช่หรือไม่?`,
    confirmText: bulkLabels[action],
  })

  if (!confirmed) return

  bulkRunning.value = true
  try {
    const payload = { action, user_ids: selectedIds.value }
    if (action === 'move_org_unit') payload.org_unit_id = bulkOrgUnitId.value

    const res = await api.post('/admin/users/bulk', payload)

    // บอกให้ครบว่าอะไรถูกข้ามและเพราะอะไร ไม่ใช่แค่ "สำเร็จ"
    const notes = []
    if (res.data.skipped?.length) notes.push(`ข้าม ${res.data.skipped.join(', ')}`)
    if (res.data.out_of_scope) notes.push(`อยู่นอกขอบเขตที่รับผิดชอบ ${res.data.out_of_scope} คน`)

    alertStore.success([res.data.message, ...notes].join(' · '))
    selectedIds.value = []
    bulkOrgUnitId.value = ''
    await fetchUsers()
  } catch (error) {
    alertStore.error(getErrorMessage(error, 'ดำเนินการไม่สำเร็จ'))
  } finally {
    bulkRunning.value = false
  }
}

const goToPage = (page) => {
  if (page < 1 || page > pagination.last_page || page === pagination.current_page) return
  fetchUsers(page)
}

/*
 * กู้บัญชีที่ถูกลบคืน — กลับมาในสถานะระงับการใช้งานเสมอ
 * ให้ผู้ดูแลตัดสินใจอีกครั้งก่อนให้เข้าระบบได้จริง
 */
const restoreUser = async (user) => {
  const confirmed = await confirmAction({
    title: 'กู้บัญชีคืน',
    message: `กู้บัญชี “${user.name}” คืนหรือไม่?`,
    detail: 'บัญชีจะกลับมาในสถานะระงับการใช้งาน — ต้องเปิดใช้งานอีกครั้งจึงจะเข้าระบบได้',
    confirmText: 'กู้บัญชีคืน',
  })

  if (!confirmed) return

  try {
    const res = await api.post(`/admin/users/${user.id}/restore`)
    alertStore.success(res.data.message || 'กู้บัญชีคืนแล้ว')
    await fetchUsers()
  } catch (error) {
    alertStore.error(getErrorMessage(error, 'กู้บัญชีคืนไม่สำเร็จ'))
  }
}

/** ข้อความค้นหาเป็นเลขบัตรประชาชนครบ 13 หลักหรือไม่ */
const asFullIdCard = (value) => {
  const digits = String(value || '').replace(/\D/g, '')
  return digits.length === 13 ? digits : ''
}

/*
 * ทุกตัวกรองถามเซิร์ฟเวอร์ใหม่เสมอ — หน่วงการพิมพ์ไว้ไม่ให้ยิงทุกตัวอักษร
 */
let searchTimer = null
watch(() => filters.search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(reloadFirstPage, 300)
})

watch([() => filters.org_unit_id, () => filters.status], reloadFirstPage)

const fetchDepartments = async () => {
  const res = await api.get('/admin/org-units')
  departments.value = res.data
}

const fetchPersonnelTypes = async () => {
  const res = await api.get('/personnel-types')
  personnelTypes.value = res.data.data || res.data
}

const fetchRoles = async () => {
  // ผู้ที่ดูรายชื่อผู้ใช้ได้ อาจไม่มีสิทธิ์จัดการบทบาท — ให้หน้าจอทำงานต่อได้
  // แทนที่จะพังทั้งหน้า (เหมือนที่ fetchPermissions ทำอยู่แล้ว)
  try {
    const res = await api.get('/admin/roles')
    roles.value = Array.isArray(res.data.data) ? res.data.data : (Array.isArray(res.data) ? res.data : [])
  } catch (e) {
    console.error('Failed to fetch roles', e)
  }
}

const fetchPermissions = async () => {
  try {
    const res = await api.get('/admin/permissions')
    availablePermissions.value = Array.isArray(res.data.data) ? res.data.data : (Array.isArray(res.data) ? res.data : [])
  } catch (e) {
    console.error('Failed to fetch permissions', e)
  }
}

const formatDate = (dateStr) => {
  return ThaiDateFormatter.toThaiSlash(dateStr)
}

// เซิร์ฟเวอร์กรองและแบ่งหน้ามาให้แล้ว หน้าจอจึงแสดงตามที่ได้รับมาตรง ๆ
const filteredUsers = computed(() => users.value)

const openAddModal = () => {
  modal.isEdit = false
  modal.userId = null
  activeTab.value = 'personal'
  
  form.name = ''
  form.email = ''
  form.phone = ''
  form.password = ''
  form.org_unit_id = ''
  form.personnel_type_id = ''
  form.role_id = ''
  form.telegram_chat_id = ''
  form.work_start_time = ''
  form.work_end_time = ''
  form.custom_permissions = []
  form.profile = getDefaultProfile()
  
  imageFile.value = null
  imagePreview.value = null

  modal.show = true
}

/**
 * SEC-030 — โหลดทะเบียนประวัติฉบับเต็มก่อนเปิดฟอร์มแก้ไข
 *
 * รายการส่งเลขประจำตัวประชาชนมาแบบปิดบัง ถ้าเอาค่าจากแถวในตารางไปใส่ฟอร์มตรง ๆ
 * แล้วกดบันทึก เลขที่ถูกปิดบังจะถูกเขียนทับข้อมูลจริง · เลขเต็มมาจาก endpoint
 * รายละเอียดเท่านั้น ซึ่งตรวจสิทธิ์ ขอบเขต และบันทึกการเข้าถึงข้อมูลส่วนบุคคลให้แล้ว
 */
const openEditModal = async (row) => {
  let user
  try {
    const res = await api.get(`/admin/users/${row.id}`)
    user = res.data
  } catch (e) {
    alertStore.error(getErrorMessage(e, 'เปิดทะเบียนประวัติของบุคลากรรายนี้ไม่สำเร็จ'))
    return
  }

  modal.isEdit = true
  modal.userId = user.id
  activeTab.value = 'personal'

  form.name = user.name
  form.email = user.email
  form.phone = user.phone || ''
  form.password = ''
  form.org_unit_id = user.org_unit_id || ''
  form.personnel_type_id = user.personnel_type_id || ''
  form.role_id = user.role_id || user.role?.id || (user.roles && user.roles[0] ? user.roles[0].id : '')
  form.telegram_chat_id = user.telegram_chat_id || ''
  form.work_start_time = user.work_start_time || ''
  form.work_end_time = user.work_end_time || ''
  form.custom_permissions = Array.isArray(user.custom_permissions) ? [...user.custom_permissions] : (user.permissions ? user.permissions.map(p => typeof p === 'string' ? p : p.name) : [])
  
  form.profile = { ...getDefaultProfile(), ...(user.profile || {}) }
  
  imageFile.value = null
  imagePreview.value = null

  modal.show = true
}

const onImageSelected = (e) => {
  const file = e.target.files[0]
  if (!file) return
  
  imageFile.value = file
  
  // Preview
  const reader = new FileReader()
  reader.onload = (e) => {
    imagePreview.value = e.target.result
  }
  reader.readAsDataURL(file)
}

const submitForm = async () => {
  submitting.value = true
  try {
    const formData = new FormData()
    formData.append('name', form.name)
    formData.append('email', form.email)
    formData.append('phone', form.phone || '')
    if (form.password) formData.append('password', form.password)
    formData.append('org_unit_id', form.org_unit_id)
    formData.append('personnel_type_id', form.personnel_type_id)
    formData.append('role_id', form.role_id)
    formData.append('telegram_chat_id', form.telegram_chat_id || '')
    if (form.work_start_time) formData.append('work_start_time', form.work_start_time)
    if (form.work_end_time) formData.append('work_end_time', form.work_end_time)
    formData.append('custom_permissions', JSON.stringify(form.custom_permissions || []))
    
    // Add profile data as individual fields (Server expects an array)
    for (const key in form.profile) {
      if (form.profile[key] !== null && form.profile[key] !== undefined) {
        formData.append(`profile[${key}]`, form.profile[key])
      }
    }

    if (imageFile.value) {
      formData.append('profile_photo', imageFile.value)
    }

    if (modal.isEdit) {
      formData.append('_method', 'PUT')
      const res = await api.post(`/admin/users/${modal.userId}`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' }
      })
      alertStore.success(res.data.message || 'แก้ไขข้อมูลบุคลากรเรียบร้อยแล้ว')
    } else {
      const res = await api.post('/admin/users', formData, {
        headers: { 'Content-Type': 'multipart/form-data' }
      })
      alertStore.success(res.data.message || 'สร้างบุคลากรเรียบร้อยแล้ว')
    }
    
    await fetchUsers()
    modal.show = false
  } catch (e) {
    console.error(e)
    const errors = e.response?.data?.errors
    if (errors) {
      alertStore.error(Object.values(errors).flat().join(' | '))
    } else {
      alertStore.error(e.response?.data?.message || 'เกิดข้อผิดพลาด กรุณาลองใหม่')
    }
  } finally {
    submitting.value = false
  }
}

const toggleStatus = async (user) => {
  const suspending = user.is_active

  const confirmed = await confirmAction({
    title: suspending ? 'ระงับการใช้งานบัญชี' : 'เปิดใช้งานบัญชี',
    message: `คุณต้องการ ${suspending ? 'ระงับ' : 'เปิดใช้งาน'} ผู้ใช้นี้ใช่หรือไม่?`,
    detail: suspending ? 'ผู้ใช้จะเข้าสู่ระบบไม่ได้จนกว่าจะเปิดใช้งานอีกครั้ง' : '',
    confirmText: suspending ? 'ระงับการใช้งาน' : 'เปิดใช้งาน',
    tone: suspending ? 'danger' : 'default',
  })

  if (!confirmed) return
  
  submitting.value = true
  try {
    const res = await api.post(`/admin/users/${user.id}/toggle-status`)
    alertStore.success(res.data.message)
    user.is_active = res.data.is_active
  } catch (e) {
    alertStore.error(e.response?.data?.message || 'เกิดข้อผิดพลาดในการเปลี่ยนสถานะ')
  } finally {
    submitting.value = false
  }
}

const triggerCsvUpload = () => {
  csvInput.value.click()
}

const downloadCsvTemplate = () => {
  const headers = ['id_card_number', 'name', 'email', 'role_id', 'org_unit_id', 'personnel_type_id', 'title', 'first_name', 'last_name', 'gender', 'phone', 'position']
  // ตัวอย่างต้องเป็นตำแหน่งที่มีจริงในสถานศึกษา ไม่ใช่คำว่า "พนักงาน" ซึ่งไม่ใช่คำที่ราชการใช้
  const sample = ['1234567890123', 'นายสมชาย ใจดี', 'somchai@example.com', '1', '1', '1', 'นาย', 'สมชาย', 'ใจดี', 'ชาย', '0812345678', 'ครู']
  
  const csvContent = "data:text/csv;charset=utf-8,\uFEFF" + 
    headers.join(",") + "\n" +
    sample.join(",")
    
  const encodedUri = encodeURI(csvContent)
  const link = document.createElement("a")
  link.setAttribute("href", encodedUri)
  link.setAttribute("download", "user_import_template.csv")
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

const handleCsvUpload = async (e) => {
  const file = e.target.files[0]
  if (!file) return

  const formData = new FormData()
  formData.append('file', file)

  submitting.value = true
  try {
    const res = await api.post('/admin/users/import', formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })
    
    let msg = res.data.message
    if (res.data.errors && res.data.errors.length > 0) {
      msg += "\n" + res.data.errors.join("\n")
      alertStore.info(msg, 6000)
    } else {
      alertStore.success(msg)
    }
    
    await fetchUsers()
  } catch (e) {
    console.error(e)
    alertStore.error(e.response?.data?.message || 'เกิดข้อผิดพลาดในการนำเข้า CSV')
  } finally {
    submitting.value = false
    e.target.value = null // reset input
  }
}

const confirmDelete = (user) => {
  deleteModal.userId = user.id
  deleteModal.userName = user.name
  deleteModal.show = true
}

const doDelete = async () => {
  submitting.value = true
  try {
    const res = await api.delete(`/admin/users/${deleteModal.userId}`)
    alertStore.success(res.data.message || 'ลบผู้ใช้เรียบร้อยแล้ว')
    await fetchUsers()
    deleteModal.show = false
  } catch (e) {
    console.error(e)
    alertStore.error(e.response?.data?.message || 'ไม่สามารถลบผู้ใช้ได้')
  } finally {
    submitting.value = false
  }
}
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
