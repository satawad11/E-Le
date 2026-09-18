<template>
  <div class="max-w-7xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
      <div class="flex-1 min-w-0">
        <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl sm:truncate flex items-center gap-3">
          <span class="p-2 bg-red-50 text-[#800000] rounded-xl">
            <svg class="w-7 h-7 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.users"/>
            </svg>
          </span>
          จัดการบทบาทและสิทธิ์ (Role &amp; Permission Management)
        </h2>
        <p class="mt-1 text-sm text-gray-500">
          บริหารจัดการบทบาทในระบบ (Roles) และกำหนดชุดสิทธิ์การใช้งาน (Permission Matrix) สำหรับแต่ละกลุ่มผู้ใช้
        </p>
      </div>

      <!-- Actions & View Mode Toggle -->
      <div class="flex flex-wrap items-center gap-3">
        <!-- View Mode Switcher -->
        <div class="inline-flex p-1 bg-gray-100 rounded-xl border border-gray-200">
          <button
            type="button"
            @click="viewMode = 'cards'"
            :class="[
              'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer',
              viewMode === 'cards'
                ? 'bg-white text-gray-900 shadow-xs'
                : 'text-gray-600 hover:text-gray-900'
            ]"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.viewGrid || 'M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z'"/>
            </svg>
            มุมมองการ์ด
          </button>
          <button
            type="button"
            @click="viewMode = 'matrix'"
            :class="[
              'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer',
              viewMode === 'matrix'
                ? 'bg-white text-[#800000] shadow-xs font-bold'
                : 'text-gray-600 hover:text-gray-900'
            ]"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.table || 'M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z'"/>
            </svg>
            ตารางสิทธิ์ (Matrix)
          </button>
        </div>

        <button
          type="button"
          @click="fetchRoles"
          :disabled="loading"
          class="inline-flex items-center gap-2 px-3.5 py-2 border border-gray-300 rounded-xl shadow-2xs text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors disabled:opacity-50 cursor-pointer"
        >
          <svg class="w-4 h-4 flex-shrink-0" :class="{ 'animate-spin': loading }" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.refresh"/>
          </svg>
          รีเฟรช
        </button>

        <button
          type="button"
          @click="openCreateModal"
          class="inline-flex items-center gap-2 px-4 py-2 border border-transparent rounded-xl shadow-sm text-sm font-semibold text-white bg-[#800000] hover:bg-[#600000] transition-colors cursor-pointer"
        >
          <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.plus"/>
          </svg>
          สร้างบทบาทใหม่
        </button>
      </div>
    </div>

    <!-- Alert Banners -->
    <div v-if="successMessage" class="bg-emerald-50 border-l-4 border-emerald-500 p-4 rounded-xl shadow-xs">
      <div class="flex items-center">
        <svg class="w-5 h-5 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.check"/>
        </svg>
        <p class="ml-3 text-sm text-emerald-800 font-medium">{{ successMessage }}</p>
      </div>
    </div>
    <div v-if="errorMessage" class="bg-red-50 border-l-4 border-red-500 p-4 rounded-xl shadow-xs">
      <div class="flex items-center">
        <svg class="w-5 h-5 text-red-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.error"/>
        </svg>
        <p class="ml-3 text-sm text-red-800 font-medium">{{ errorMessage }}</p>
      </div>
    </div>

    <!-- Search & Filter Bar -->
    <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-2xs flex flex-col md:flex-row gap-3 items-center justify-between">
      <div class="w-full md:w-80 relative">
        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
          <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.search"/>
          </svg>
        </div>
        <input
          v-model="searchQuery"
          type="text"
          placeholder="ค้นหาบทบาท หรือสิทธิ์การใช้งาน..."
          class="w-full pl-9 pr-4 py-2 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-[#800000] focus:border-transparent outline-none transition"
        />
        <button
          v-if="searchQuery"
          @click="searchQuery = ''"
          class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.x"/>
          </svg>
        </button>
      </div>

      <!-- Module Filter Tabs -->
      <div class="flex flex-wrap gap-1.5 w-full md:w-auto">
        <button
          type="button"
          @click="selectedModuleFilter = 'all'"
          :class="[
            'px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors cursor-pointer',
            selectedModuleFilter === 'all'
              ? 'bg-[#800000] text-white shadow-2xs'
              : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
          ]"
        >
          ทั้งหมด ({{ availablePermissions.length }})
        </button>
        <button
          v-for="(perms, groupName) in groupedPermissions"
          :key="groupName"
          type="button"
          @click="selectedModuleFilter = groupName"
          :class="[
            'px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors cursor-pointer flex items-center gap-1',
            selectedModuleFilter === groupName
              ? 'bg-[#800000] text-white shadow-2xs'
              : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
          ]"
        >
          <span>{{ groupName }}</span>
          <span class="text-[10px] opacity-80">({{ perms.length }})</span>
        </button>
      </div>
    </div>

    <!-- Loading State -->
    <LoadingState v-if="loading" type="spinner" message="กำลังโหลดรายการบทบาทและสิทธิ์..." />

    <!-- Empty State -->
    <div v-else-if="!roles || roles.length === 0" class="text-center py-12 bg-white rounded-2xl shadow-sm border border-gray-200 p-8">
      <svg class="w-14 h-14 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="ICONS.users"/>
      </svg>
      <h3 class="text-lg font-bold text-gray-800 mb-2">ไม่พบรายการบทบาทในระบบ</h3>
      <p class="text-sm text-gray-500 mb-6 max-w-md mx-auto">
        ยังไม่มีรายการบทบาทแสดงผลในขณะนี้ กดปุ่มดึงข้อมูลอีกครั้งหรือสร้างบทบาทใหม่
      </p>
      <div class="flex justify-center gap-3">
        <button @click="fetchRoles" class="inline-flex items-center gap-2 px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium text-sm rounded-xl transition-colors cursor-pointer">
          <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.refresh"/>
          </svg>
          โหลดข้อมูลอีกครั้ง
        </button>
        <button @click="openCreateModal" class="inline-flex items-center gap-2 px-4 py-2 bg-[#800000] hover:bg-[#600000] text-white font-medium text-sm rounded-xl transition-colors cursor-pointer">
          <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.plus"/>
          </svg>
          สร้างบทบาทใหม่
        </button>
      </div>
    </div>

    <!-- VIEW MODE 1: CARDS VIEW -->
    <div v-else-if="viewMode === 'cards'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <div
        v-for="role in filteredRoles"
        :key="role.id"
        class="bg-white rounded-2xl shadow-sm border border-gray-200 hover:shadow-md transition-shadow flex flex-col justify-between overflow-hidden group"
      >
        <div class="p-6">
          <!-- Role Header -->
          <div class="flex justify-between items-start mb-3">
            <div>
              <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                <span class="p-1.5 bg-red-50 text-[#800000] rounded-lg">
                  <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="getRoleBadgeIcon(role.name)"/>
                  </svg>
                </span>
                <span>{{ roleLabel(role.name) }}</span>
              </h3>
              <p class="text-xs text-gray-500 mt-1 line-clamp-2">{{ role.description || roleDescription(role.name) || 'ไม่มีคำอธิบาย' }}</p>
              <span v-if="role.is_system || SYSTEM_ROLES.includes(role.name)" class="inline-block mt-1 px-2 py-0.5 text-[10px] font-semibold bg-gray-100 text-gray-600 rounded">
                บทบาทหลักของระบบ
              </span>
            </div>
            <span class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-full bg-amber-50 text-amber-800 border border-amber-200 flex-shrink-0">
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
            <div v-if="role.name === 'SuperAdmin' || role.name === 'Super Admin'" class="flex items-center gap-1.5 p-2.5 bg-amber-50 rounded-xl border border-amber-200 text-xs text-amber-900 font-medium">
              <svg class="w-4 h-4 flex-shrink-0 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.shieldCheck"/>
              </svg>
              สิทธิ์เต็มรูปแบบ (All System Permissions)
            </div>
            <div v-else-if="role.permissions && role.permissions.length > 0" class="flex flex-wrap gap-1.5 max-h-40 overflow-y-auto pr-1">
              <span
                v-for="perm in role.permissions.slice(0, 10)"
                :key="perm.id"
                class="px-2 py-0.5 rounded text-[11px] bg-slate-100 text-slate-700 border border-slate-200"
              >
                {{ permissionLabel(perm.name) }}
              </span>
              <span v-if="role.permissions.length > 10" class="px-2 py-0.5 rounded text-[11px] bg-gray-200 text-gray-600 font-medium">
                + อีก {{ role.permissions.length - 10 }} สิทธิ์
              </span>
            </div>
            <div v-else class="text-xs text-gray-400 italic p-2 bg-gray-50 rounded-lg">ยังไม่ได้กำหนดสิทธิ์</div>
          </div>
        </div>

        <!-- Role Card Footer -->
        <div class="bg-gray-50 px-6 py-3 border-t border-gray-100 flex justify-end gap-2">
          <button
            @click="openEditModal(role)"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-gray-300 text-gray-700 hover:bg-gray-100 rounded-xl text-xs font-semibold transition-colors cursor-pointer shadow-2xs"
          >
            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.pencil"/>
            </svg>
            แก้ไขสิทธิ์
          </button>
          <button
            v-if="!role.is_system && !SYSTEM_ROLES.includes(role.name)"
            @click="confirmDeleteRole(role)"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-red-50 text-red-600 hover:bg-red-100 rounded-xl text-xs font-semibold transition-colors border border-red-200 cursor-pointer"
          >
            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.trash"/>
            </svg>
            ลบ
          </button>
        </div>
      </div>
    </div>

    <!-- VIEW MODE 2: INTERACTIVE PERMISSION MATRIX VIEW -->
    <div v-else-if="viewMode === 'matrix'" class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col">
      <!-- Matrix Header Info Bar -->
      <div class="px-6 py-4 bg-slate-50 border-b border-gray-200 flex flex-wrap items-center justify-between gap-4">
        <div>
          <h3 class="text-base font-bold text-gray-800 flex items-center gap-2">
            <svg class="w-5 h-5 text-[#800000]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.table || 'M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z'"/>
            </svg>
            ตารางสิทธิ์การใช้งาน (Permission Matrix Grid)
          </h3>
          <p class="text-xs text-gray-500 mt-0.5">
            คลิกที่ช่องเพื่อเพิ่มหรือถอนสิทธิ์ของแต่ละบทบาทได้ทันที และกดบันทึกเพื่อนำการเปลี่ยนแปลงไปใช้
          </p>
        </div>

        <div class="flex items-center gap-2">
          <span v-if="hasDirtyRoles" class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-semibold rounded-full bg-amber-100 text-amber-800">
            <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
            มี {{ dirtyRoleIds.length }} บทบาทที่มีการแก้ไขยังไม่บันทึก
          </span>
          <button
            v-if="hasDirtyRoles"
            @click="saveAllDirtyRoles"
            :disabled="savingMatrix"
            class="inline-flex items-center gap-2 px-4 py-2 bg-[#800000] hover:bg-[#600000] text-white text-xs font-bold rounded-xl shadow-xs transition-colors cursor-pointer disabled:opacity-50"
          >
            <span v-if="savingMatrix" class="inline-block animate-spin rounded-full h-3.5 w-3.5 border-2 border-white border-t-transparent"></span>
            <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.check"/>
            </svg>
            บันทึกการเปลี่ยนแปลงทั้งหมด
          </button>
        </div>
      </div>

      <!-- Matrix Table Wrapper -->
      <div class="overflow-x-auto max-h-[70vh]">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-gray-100/80 border-b border-gray-200 sticky top-0 z-20 backdrop-blur-xs">
              <!-- Permission Column Header -->
              <th class="py-3.5 px-4 text-xs font-bold text-gray-700 uppercase tracking-wider min-w-[300px] sticky left-0 bg-gray-100 z-30 shadow-[1px_0_0_0_#e5e7eb]">
                โมดูล / รายละเอียดสิทธิ์
              </th>
              <!-- Role Columns Headers -->
              <th
                v-for="role in roles"
                :key="role.id"
                class="py-3.5 px-3 text-center min-w-[140px] border-l border-gray-200"
              >
                <div class="flex flex-col items-center gap-1">
                  <span class="text-xs font-bold text-gray-900">{{ roleLabel(role.name) }}</span>
                  <span class="text-[10px] text-gray-500 font-mono">{{ role.name }}</span>
                  <div class="flex items-center gap-1 mt-1">
                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-slate-200 text-slate-700 font-semibold">
                      {{ getRolePermissionCount(role.id) }} สิทธิ์
                    </span>
                    <button
                      v-if="isRoleDirty(role.id)"
                      @click="saveRolePermissions(role.id)"
                      :disabled="savingRoleId === role.id"
                      class="px-2 py-0.5 bg-[#800000] text-white text-[10px] font-semibold rounded hover:bg-[#600000] transition cursor-pointer"
                      title="บันทึกสิทธิ์ของบทบาทนี้"
                    >
                      {{ savingRoleId === role.id ? '...' : 'บันทึก' }}
                    </button>
                  </div>
                </div>
              </th>
            </tr>
          </thead>

          <tbody class="divide-y divide-gray-100 text-sm">
            <!-- Iterate over each group -->
            <template v-for="(perms, groupName) in filteredMatrixGroups" :key="groupName">
              <!-- Group Separator Row -->
              <tr class="bg-slate-50/90 font-bold border-t border-b border-gray-200">
                <td class="py-2.5 px-4 text-xs text-gray-900 uppercase tracking-wider sticky left-0 bg-slate-50 z-10 shadow-[1px_0_0_0_#e5e7eb] flex items-center justify-between">
                  <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-[#800000]"></span>
                    <span>{{ groupName }} ({{ perms.length }} สิทธิ์)</span>
                  </div>
                </td>
                <!-- Group Quick Action Buttons per Role -->
                <td
                  v-for="role in roles"
                  :key="role.id"
                  class="py-2 px-2 text-center border-l border-gray-200 bg-slate-50"
                >
                  <div class="flex items-center justify-center gap-1">
                    <button
                      type="button"
                      @click="toggleGroupForRole(role.id, perms, true)"
                      class="text-[10px] text-emerald-700 hover:text-emerald-900 font-medium px-1 py-0.5 hover:bg-emerald-50 rounded"
                      title="เลือกทั้งหมดในกลุ่มนี้"
                    >
                      เลือก
                    </button>
                    <span class="text-gray-300">|</span>
                    <button
                      type="button"
                      @click="toggleGroupForRole(role.id, perms, false)"
                      class="text-[10px] text-red-600 hover:text-red-800 font-medium px-1 py-0.5 hover:bg-red-50 rounded"
                      title="ถอนทั้งหมดในกลุ่มนี้"
                    >
                      ถอน
                    </button>
                  </div>
                </td>
              </tr>

              <!-- Permission Rows inside this group -->
              <tr
                v-for="perm in perms"
                :key="perm.id"
                class="hover:bg-amber-50/30 transition-colors"
              >
                <!-- Permission Info Cell -->
                <td class="py-2.5 px-4 text-xs sticky left-0 bg-white hover:bg-amber-50/30 z-10 shadow-[1px_0_0_0_#e5e7eb]">
                  <div class="font-semibold text-gray-900">{{ permissionLabel(perm.name) }}</div>
                  <code class="text-[10px] text-gray-400 font-mono block">{{ perm.name }}</code>
                </td>

                <!-- Checkbox Cells for Each Role -->
                <td
                  v-for="role in roles"
                  :key="role.id"
                  class="py-2.5 px-3 text-center border-l border-gray-100"
                >
                  <!-- SuperAdmin has everything always -->
                  <div v-if="role.name === 'SuperAdmin' || role.name === 'Super Admin'" class="flex justify-center" title="สิทธิ์ของผู้ดูแลระบบสูงสุด">
                    <span class="w-5 h-5 rounded-full bg-amber-100 text-amber-800 flex items-center justify-center text-xs font-bold">✓</span>
                  </div>
                  <!-- Other Roles checkbox -->
                  <div v-else class="flex justify-center items-center">
                    <input
                      type="checkbox"
                      :checked="hasMatrixPermission(role.id, perm.id)"
                      @change="toggleMatrixPermission(role.id, perm.id)"
                      class="w-4 h-4 text-[#800000] rounded border-gray-300 focus:ring-[#800000] cursor-pointer"
                    />
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Create / Edit Role Modal -->
    <transition name="fade">
      <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4 overflow-y-auto" @click.self="closeModal">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl overflow-hidden my-auto max-h-[90vh] flex flex-col">
          <!-- Modal Header -->
          <div class="bg-[#800000] text-white px-6 py-4 flex justify-between items-center flex-shrink-0">
            <h3 class="font-bold text-lg">
              {{ isEditing ? `แก้ไขบทบาท: ${editingRole?.name}` : 'สร้างบทบาทใหม่' }}
            </h3>
            <button @click="closeModal" aria-label="ปิด" class="text-white/80 hover:text-white cursor-pointer">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.x"/>
              </svg>
            </button>
          </div>

          <!-- Modal Body -->
          <div class="p-6 overflow-y-auto flex-1 space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">
                  ชื่อบทบาท (Role Name) <span class="text-red-500">*</span>
                </label>
                <input
                  type="text"
                  v-model="form.name"
                  placeholder="เช่น AcademicOfficer, DepartmentHead"
                  class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-[#800000] focus:outline-none"
                  :disabled="isEditing && (editingRole?.is_system || SYSTEM_ROLES.includes(editingRole?.name))"
                />
                <p v-if="isEditing && (editingRole?.is_system || SYSTEM_ROLES.includes(editingRole?.name))" class="text-[11px] text-amber-600 mt-1">
                  * บทบาทหลักของระบบไม่สามารถเปลี่ยนชื่อได้เพื่อความปลอดภัย
                </p>
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

            <!-- Permission Selection Matrix Inside Modal -->
            <div>
              <div class="flex flex-wrap justify-between items-center mb-3 gap-2">
                <label class="block text-sm font-bold text-gray-900">
                  เลือกชุดสิทธิ์การใช้งาน (Permissions Matrix):
                </label>
                <input
                  type="text"
                  v-model="modalSearchQuery"
                  placeholder="ค้นหาสิทธิ์ในโมดอล..."
                  class="px-3 py-1 text-xs border border-gray-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-[#800000]"
                />
              </div>

              <div class="space-y-4 max-h-80 overflow-y-auto pr-1">
                <div
                  v-for="(perms, groupName) in filteredModalGroupedPermissions"
                  :key="groupName"
                  class="border border-gray-200 rounded-xl p-4 bg-gray-50/50"
                >
                  <div class="flex justify-between items-center mb-2 pb-1 border-b border-gray-200">
                    <h4 class="flex items-center gap-1.5 text-xs font-bold text-gray-800 uppercase tracking-wider">
                      <svg class="w-4 h-4 flex-shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.folder"/>
                      </svg>
                      {{ groupName }} ({{ perms.length }})
                    </h4>
                    <button
                      type="button"
                      @click="toggleGroupPermissions(perms)"
                      class="text-xs text-[#800000] hover:underline font-semibold cursor-pointer"
                    >
                      {{ isAllGroupSelected(perms) ? 'ยกเลิกทั้งหมด' : 'เลือกทั้งหมดในกลุ่มนี้' }}
                    </button>
                  </div>
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                    <label
                      v-for="perm in perms"
                      :key="perm.id"
                      class="flex items-start gap-2 p-2 rounded-lg hover:bg-white border border-transparent hover:border-gray-200 transition-colors cursor-pointer"
                    >
                      <input
                        type="checkbox"
                        :value="perm.id"
                        v-model="form.permission_ids"
                        class="mt-0.5 rounded border-gray-300 text-[#800000] focus:ring-[#800000] h-4 w-4 cursor-pointer"
                      />
                      <div>
                        <span class="text-xs text-gray-800 font-medium block">{{ permissionLabel(perm.name) }}</span>
                        <span class="text-[10px] text-gray-400 font-mono block">{{ perm.name }}</span>
                      </div>
                    </label>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Modal Footer -->
          <div class="bg-gray-50 px-6 py-4 border-t border-gray-200 flex justify-end gap-3 flex-shrink-0">
            <button
              @click="closeModal"
              class="px-4 py-2 bg-white border border-gray-300 rounded-xl text-gray-700 font-medium text-sm hover:bg-gray-100 transition-colors cursor-pointer"
            >
              ยกเลิก
            </button>
            <button
              @click="saveRole"
              :disabled="saving || !form.name"
              class="px-5 py-2 bg-[#800000] hover:bg-[#600000] text-white font-medium rounded-xl text-sm transition-colors flex items-center gap-2 disabled:opacity-50 cursor-pointer"
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
import { ref, computed, onMounted, reactive } from 'vue';
import api from '../../axios';
import { useAuthStore } from '../../stores/auth';
import { useConfirm } from '../../composables/useConfirm';
import LoadingState from '../../components/ui/LoadingState.vue';
import { ICONS } from '../../utils/icons';
import { roleLabel, roleDescription, permissionLabel, groupPermissions, SYSTEM_ROLES } from '../../utils/roleLabel';

const authStore = useAuthStore();
const { confirmDanger } = useConfirm();

const viewMode = ref('cards'); // 'cards' | 'matrix'
const roles = ref([]);
const availablePermissions = ref([]);
const loading = ref(true);
const saving = ref(false);
const savingMatrix = ref(false);
const savingRoleId = ref(null);
const successMessage = ref('');
const errorMessage = ref('');

const searchQuery = ref('');
const modalSearchQuery = ref('');
const selectedModuleFilter = ref('all');

// Matrix State Tracking: { [roleId]: Set<permissionId> }
const matrixState = reactive({});
const originalMatrixState = reactive({});

const showModal = ref(false);
const isEditing = ref(false);
const editingRole = ref(null);

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
    initMatrixState();
  } catch (error) {
    console.error('Failed to fetch roles:', error);
    errorMessage.value = 'เกิดข้อผิดพลาดในการโหลดบทบาท: ' + (error.response?.data?.message || error.message);
  } finally {
    loading.value = false;
  }
};

const initMatrixState = () => {
  roles.value.forEach(role => {
    const permIds = role.permissions ? role.permissions.map(p => p.id) : [];
    matrixState[role.id] = [...permIds];
    originalMatrixState[role.id] = [...permIds];
  });
};

const groupedPermissions = computed(() => {
  return groupPermissions(availablePermissions.value);
});

const filteredRoles = computed(() => {
  if (!searchQuery.value) return roles.value;
  const q = searchQuery.value.toLowerCase();
  return roles.value.filter(r => 
    r.name.toLowerCase().includes(q) ||
    roleLabel(r.name).toLowerCase().includes(q) ||
    (r.description && r.description.toLowerCase().includes(q))
  );
});

const filteredMatrixGroups = computed(() => {
  let groups = groupedPermissions.value;
  if (selectedModuleFilter.value !== 'all') {
    groups = { [selectedModuleFilter.value]: groups[selectedModuleFilter.value] || [] };
  }

  if (!searchQuery.value) return groups;
  const q = searchQuery.value.toLowerCase();
  const res = {};

  Object.keys(groups).forEach(gName => {
    const matched = groups[gName].filter(p => 
      p.name.toLowerCase().includes(q) || 
      permissionLabel(p.name).toLowerCase().includes(q)
    );
    if (matched.length > 0) {
      res[gName] = matched;
    }
  });

  return res;
});

const filteredModalGroupedPermissions = computed(() => {
  if (!modalSearchQuery.value) return groupedPermissions.value;
  const q = modalSearchQuery.value.toLowerCase();
  const filtered = {};

  Object.keys(groupedPermissions.value).forEach(groupName => {
    const matches = groupedPermissions.value[groupName].filter(p => 
      p.name.toLowerCase().includes(q) || 
      permissionLabel(p.name).toLowerCase().includes(q)
    );
    if (matches.length > 0) {
      filtered[groupName] = matches;
    }
  });

  return filtered;
});

// Matrix Interaction Methods
const hasMatrixPermission = (roleId, permId) => {
  return (matrixState[roleId] || []).includes(permId);
};

const toggleMatrixPermission = (roleId, permId) => {
  if (!matrixState[roleId]) matrixState[roleId] = [];
  const idx = matrixState[roleId].indexOf(permId);
  if (idx > -1) {
    matrixState[roleId].splice(idx, 1);
  } else {
    matrixState[roleId].push(permId);
  }
};

const toggleGroupForRole = (roleId, perms, grantAll) => {
  if (!matrixState[roleId]) matrixState[roleId] = [];
  const permIds = perms.map(p => p.id);
  if (grantAll) {
    matrixState[roleId] = Array.from(new Set([...matrixState[roleId], ...permIds]));
  } else {
    matrixState[roleId] = matrixState[roleId].filter(id => !permIds.includes(id));
  }
};

const getRolePermissionCount = (roleId) => {
  return (matrixState[roleId] || []).length;
};

const isRoleDirty = (roleId) => {
  const current = (matrixState[roleId] || []).slice().sort();
  const original = (originalMatrixState[roleId] || []).slice().sort();
  return JSON.stringify(current) !== JSON.stringify(original);
};

const dirtyRoleIds = computed(() => {
  return roles.value.filter(r => isRoleDirty(r.id)).map(r => r.id);
});

const hasDirtyRoles = computed(() => dirtyRoleIds.value.length > 0);

const saveRolePermissions = async (roleId) => {
  const role = roles.value.find(r => r.id === roleId);
  if (!role) return;

  savingRoleId.value = roleId;
  errorMessage.value = '';
  try {
    const res = await api.put(`/admin/roles/${roleId}`, {
      name: role.name,
      description: role.description,
      permission_ids: matrixState[roleId],
    });

    if (res.data && res.data.success) {
      originalMatrixState[roleId] = [...matrixState[roleId]];
      showToast(`บันทึกสิทธิ์บทบาท "${roleLabel(role.name)}" สำเร็จ`);
      await fetchRoles();
      await authStore.fetchUser();
    }
  } catch (error) {
    console.error('Failed to save role permissions:', error);
    errorMessage.value = error.response?.data?.message || 'เกิดข้อผิดพลาดในการบันทึกสิทธิ์';
  } finally {
    savingRoleId.value = null;
  }
};

const saveAllDirtyRoles = async () => {
  savingMatrix.value = true;
  errorMessage.value = '';
  try {
    for (const roleId of dirtyRoleIds.value) {
      const role = roles.value.find(r => r.id === roleId);
      if (role) {
        await api.put(`/admin/roles/${roleId}`, {
          name: role.name,
          description: role.description,
          permission_ids: matrixState[roleId],
        });
        originalMatrixState[roleId] = [...matrixState[roleId]];
      }
    }
    showToast('บันทึกการเปลี่ยนแปลงสิทธิ์ทุกบทบาทเรียบร้อยแล้ว');
    await fetchRoles();
    await authStore.fetchUser();
  } catch (error) {
    console.error('Failed to save all matrix changes:', error);
    errorMessage.value = error.response?.data?.message || 'เกิดข้อผิดพลาดในการบันทึกการเปลี่ยนแปลง';
  } finally {
    savingMatrix.value = false;
  }
};

// Modal Methods
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
  modalSearchQuery.value = '';
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
  modalSearchQuery.value = '';
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
    'Super Admin': ICONS.shieldCheck,
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

