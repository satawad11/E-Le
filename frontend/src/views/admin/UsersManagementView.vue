<template>
  <div>
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">จัดการผู้ใช้งาน (บุคลากร)</h1>
        <p class="text-gray-500 mt-1">เพิ่ม แก้ไข และระงับการใช้งานบัญชีบุคลากร (ระบบไม่ลบบัญชีเพื่อรักษาประวัติ)</p>
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
            v-model="filters.search"
            type="text"
            placeholder="ค้นหาชื่อ, เลขประจำตัวประชาชน..."
            class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-gray-200 focus:outline-none focus:border-[#800000] text-sm transition-colors"
          />
        </div>

        <!-- Department filter -->
        <div>
          <select
            v-model="filters.department_id"
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
            v-model="filters.status"
            class="w-full px-4 py-2.5 rounded-xl border border-gray-200 focus:outline-none focus:border-[#800000] text-sm transition-colors text-gray-700 bg-white"
          >
            <option value="">สถานะทั้งหมด</option>
            <option value="ปฏิบัติหน้าที่">ปฏิบัติหน้าที่</option>
            <option value="ลาออก">ลาออก</option>
          </select>
        </div>
      </div>
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
        <!-- Empty State -->
        <EmptyState
          v-if="filteredUsers.length === 0"
          title="ไม่พบข้อมูลบุคลากร"
          description="ไม่มีข้อมูลบุคลากรที่ตรงกับเงื่อนไขการค้นหาหรือตัวกรอง"
          action-text="เพิ่มบุคลากรใหม่"
          @action="openAddModal"
        />

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
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
                <td class="px-4 py-3 text-center text-gray-500">{{ index + 1 }}</td>
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
                  <div class="flex flex-wrap items-center justify-center gap-2">
                    <button v-if="user.id !== authStore.user?.id || !user.is_active" @click="toggleStatus(user)" class="inline-flex items-center gap-1 px-3 py-1 rounded text-xs transition" :class="user.is_active ? 'bg-amber-500 hover:bg-amber-600 text-white' : 'bg-green-500 hover:bg-green-600 text-white'" :disabled="submitting" title="เปิด-ปิด การใช้งาน">
                      <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="user.is_active ? ICONS.ban : ICONS.check"/>
                      </svg>
                      {{ user.is_active ? 'ระงับ' : 'เปิดใช้งาน' }}
                    </button>
                    <button v-if="user.is_active && user.id !== authStore.user?.id" @click="openSeparation(user)" class="inline-flex items-center gap-1 px-3 py-1 rounded text-xs bg-gray-600 hover:bg-gray-700 text-white transition" :disabled="submitting" title="บันทึกพ้นสภาพ (ย้าย ลาออก เกษียณ หมดสัญญา)">
                      พ้นสภาพ
                    </button>
                    <span v-if="!user.is_active && user.separated_at" class="text-xs text-gray-500">พ้นสภาพ {{ formatDate(user.separated_at) }}</span>
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
                    <button v-if="authStore.hasPermission('system.position.manage')" @click="openPositionAssign(user)" class="inline-flex items-center gap-1 px-3 py-1 bg-teal-600 text-white rounded text-xs hover:bg-teal-700 transition" title="กำหนดตำแหน่งจากทะเบียนตำแหน่ง">
                      ตำแหน่ง
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ORG-ACCESS-02: กำหนดตำแหน่งอย่างเดียว (งานบุคลากร) — ไม่แตะบทบาท/สิทธิ์ของผู้ใช้ -->
    <AppModal v-model="separation.show" :title="`บันทึกพ้นสภาพ: ${separation.name}`" size="sm">
      <form id="separation-form" class="space-y-4" @submit.prevent="saveSeparation">
        <div>
          <label for="separation-date" class="block text-sm font-medium text-gray-700 mb-1">วันที่พ้นสภาพ</label>
          <input id="separation-date" v-model="separation.separated_at" type="date" required class="w-full border rounded px-3 py-2 text-sm">
        </div>
        <div>
          <label for="separation-reason" class="block text-sm font-medium text-gray-700 mb-1">เหตุที่พ้นสภาพ</label>
          <input id="separation-reason" v-model="separation.reason" type="text" required maxlength="255" class="w-full border rounded px-3 py-2 text-sm" placeholder="เช่น ย้ายไปวิทยาลัยอื่น ตามคำสั่งที่ ... / เกษียณอายุราชการ / หมดสัญญาจ้าง">
        </div>
        <p class="text-xs text-gray-500">
          ระบบจะระงับบัญชี ปิดวาระหัวหน้าหน่วยงาน เพิกถอนการรักษาราชการแทน/ปฏิบัติราชการแทน และลบขอบเขตสิทธิ์พิเศษ ณ วันที่ระบุ
          บัญชีและประวัติการลา การลงเวลาไม่ถูกลบ
        </p>
      </form>
      <template #footer>
        <div class="flex justify-end gap-2">
          <button type="button" class="px-4 py-2 rounded border border-gray-300 text-sm" @click="separation.show = false">ยกเลิก</button>
          <button type="submit" form="separation-form" :disabled="submitting" class="px-4 py-2 rounded bg-gray-700 hover:bg-gray-800 text-white text-sm disabled:opacity-50">บันทึกพ้นสภาพ</button>
        </div>
      </template>
    </AppModal>

    <AppModal v-model="positionAssign.show" title="กำหนดตำแหน่ง" size="sm">
      <p class="text-sm text-gray-600 mb-3">{{ positionAssign.user?.personnel?.full_name || positionAssign.user?.name }}</p>
      <label class="block text-sm text-gray-700">
        ตำแหน่งในทะเบียน
        <select v-model="positionAssign.uuid" class="mt-1 w-full px-3 py-2 border border-gray-300 rounded text-sm">
          <option value="">ไม่ระบุจากทะเบียน</option>
          <optgroup v-for="group in positionGroups" :key="group.uuid" :label="group.name">
            <option v-for="position in selectablePositions(group, positionAssign.uuid)" :key="position.uuid" :value="position.uuid">
              {{ position.name }}{{ position.is_active ? '' : ' (ปิดใช้งาน)' }}
            </option>
          </optgroup>
        </select>
      </label>
      <p class="mt-2 text-xs text-gray-500">ตำแหน่งใช้แสดงผลและแยกรายงานเท่านั้น ไม่เปลี่ยนสิทธิ์การใช้งานระบบ</p>
      <template #footer>
        <div class="flex justify-end gap-2">
          <button type="button" class="px-4 py-2 rounded border border-gray-300 text-sm" @click="positionAssign.show = false">ยกเลิก</button>
          <button type="button" :disabled="submitting" class="px-4 py-2 rounded bg-[#800000] hover:bg-[#660000] text-white text-sm disabled:opacity-50" @click="savePositionAssign">บันทึก</button>
        </div>
      </template>
    </AppModal>

    <!-- Form Modal (Full Screen or Large Modal) -->
    <transition name="fade">
      <div v-if="modal.show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 overflow-y-auto">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-6xl my-8 flex flex-col">
          <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center bg-gray-50 rounded-t-xl sticky top-0 z-10">
            <h3 class="font-bold text-gray-900 text-xl">
              {{ modal.isEdit ? 'แก้ไขข้อมูลบุคลากร' : 'เพิ่มข้อมูลบุคลากร' }}
            </h3>
            <div class="flex gap-2">
              <button @click="modal.show = false" class="px-4 py-2 border border-gray-300 rounded text-gray-700 hover:bg-gray-100 transition">ย้อนกลับ</button>
              <button @click="submitForm" :disabled="submitting" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition flex items-center gap-2">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.save"/>
                </svg>
                บันทึก
              </button>
            </div>
          </div>

          <div class="flex flex-col md:flex-row flex-1 p-6 gap-6">
            <!-- Sidebar / Profile Image -->
            <div class="w-full md:w-64 flex-shrink-0">
              <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 text-center mb-4 relative">
                <div class="w-32 h-32 mx-auto bg-gray-200 rounded-full flex items-center justify-center mb-4 overflow-hidden border-4 border-white shadow cursor-pointer group" @click="$refs.imageInput.click()">
                  <img v-if="imagePreview" :src="imagePreview" class="w-full h-full object-cover" />
                  <img v-else-if="form.profile.profile_photo_path" v-secure-src="form.profile.profile_photo_path" class="w-full h-full object-cover" />
                  <svg v-else class="w-16 h-16 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="ICONS.user"/>
                  </svg>
                  
                  <div class="absolute inset-0 bg-black/40 flex flex-col items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity rounded-full w-32 h-32 mx-auto border-4 border-transparent mt-4">
                    <svg class="w-6 h-6 text-white mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.camera"/>
                    </svg>
                    <span class="text-white text-xs">อัปโหลดรูป</span>
                  </div>
                </div>
                <input type="file" ref="imageInput" @change="onImageSelected" accept="image/jpeg, image/png, image/jpg" class="hidden" />
                <div class="font-medium text-blue-800 text-sm mb-1">{{ form.profile.title || '' }}{{ form.profile.first_name || 'ชื่อ' }} {{ form.profile.last_name || 'นามสกุล' }}</div>
                <div class="text-xs text-blue-600">{{ form.profile.id_card_number || 'เลขบัตรประชาชน' }}</div>
              </div>

              <!-- Tabs Navigation -->
              <div class="flex flex-col gap-1">
                <button @click="activeTab = 'personal'" :class="activeTab === 'personal' ? 'bg-[#800000] text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="px-4 py-3 text-left text-sm font-medium rounded transition">ข้อมูลส่วนตัวบุคลากร</button>
                <button @click="activeTab = 'address'" :class="activeTab === 'address' ? 'bg-[#800000] text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="px-4 py-3 text-left text-sm font-medium rounded transition">ข้อมูลที่อยู่</button>
                <button @click="activeTab = 'employment'" :class="activeTab === 'employment' ? 'bg-[#800000] text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="px-4 py-3 text-left text-sm font-medium rounded transition">ข้อมูลการจ้าง</button>
                <button @click="activeTab = 'system'" :class="activeTab === 'system' ? 'bg-[#800000] text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="px-4 py-3 text-left text-sm font-medium rounded transition">ข้อมูลระบบ (Account)</button>
                <button v-if="canManageAccess" @click="activeTab = 'permissions'" :class="activeTab === 'permissions' ? 'bg-[#800000] text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="px-4 py-3 text-left text-sm font-medium rounded transition">สิทธิ์การเข้าถึงพิเศษ</button>
              </div>

            </div>

            <!-- Tab Content -->
            <div class="flex-1 bg-white border border-gray-200 rounded-lg p-6">
              <h4 class="text-lg font-bold text-red-600 mb-4 border-b border-red-200 pb-2">
                {{ tabTitles[activeTab] }}
              </h4>

              <form @submit.prevent="submitForm">
                <!-- Tab: Personal -->
                <div v-show="activeTab === 'personal'" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">เลขประจำตัวประชาชน *</label>
                    <input v-model="form.profile.id_card_number" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" required />
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">วันเกิด *</label>
                    <ThaiDatePicker v-model="form.profile.date_of_birth" />
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">สัญชาติ *</label>
                    <input v-model="form.profile.nationality" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" required />
                  </div>
                  <!-- เลิกเก็บ "เชื้อชาติ" — เป็นข้อมูลอ่อนไหวตาม ม.26 พ.ร.บ.คุ้มครองข้อมูลส่วนบุคคล
                       และไม่มีส่วนใดของระบบนำไปใช้ จึงไม่มีฐานทางกฎหมายรองรับการเก็บ -->
                  
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">คำนำหน้าชื่อ *</label>
                    <select v-model="form.profile.title" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" required>
                      <option value="นาย">นาย</option>
                      <option value="นาง">นาง</option>
                      <option value="นางสาว">นางสาว</option>
                    </select>
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">ชื่อ *</label>
                    <input v-model="form.profile.first_name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" required @input="updateFullName" />
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">นามสกุล *</label>
                    <input v-model="form.profile.last_name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" required @input="updateFullName" />
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">ชื่อกลาง</label>
                    <input v-model="form.profile.middle_name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
                  </div>

                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">เพศ *</label>
                    <select v-model="form.profile.gender" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" required>
                      <option value="ชาย">ชาย</option>
                      <option value="หญิง">หญิง</option>
                    </select>
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">ชื่อ (อังกฤษ)</label>
                    <input v-model="form.profile.first_name_en" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">นามสกุล (อังกฤษ)</label>
                    <input v-model="form.profile.last_name_en" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
                  </div>
                  <!-- เลิกเก็บ "ศาสนา" ด้วยเหตุผลเดียวกับเชื้อชาติ (ข้อมูลอ่อนไหวตาม ม.26) -->
                </div>

                <!-- Tab: Address -->
                <div v-show="activeTab === 'address'" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">รหัสประจำบ้าน</label>
                    <input v-model="form.profile.house_registration_number" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">บ้านเลขที่ *</label>
                    <input v-model="form.profile.house_number" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" required />
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">หมู่ที่</label>
                    <input v-model="form.profile.moo" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">ซอย</label>
                    <input v-model="form.profile.soi" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
                  </div>
                  <div class="md:col-span-4">
                    <label class="block text-xs text-gray-700 mb-1">ถนน</label>
                    <input v-model="form.profile.road" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
                  </div>

                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">จังหวัด *</label>
                    <input v-model="form.profile.province" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" required />
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">อำเภอ/เขต *</label>
                    <input v-model="form.profile.district" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" required />
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">ตำบล/แขวง *</label>
                    <input v-model="form.profile.sub_district" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" required />
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">รหัสไปรษณีย์ *</label>
                    <input v-model="form.profile.zipcode" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" required />
                  </div>

                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">เบอร์โทรศัพท์</label>
                    <input v-model="form.phone" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">Fax</label>
                    <input v-model="form.profile.fax" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">E-Mail</label>
                    <input v-model="form.email" type="email" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">เว็บไซต์</label>
                    <input v-model="form.profile.website" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
                  </div>
                </div>

                <!-- Tab: Employment -->
                <div v-show="activeTab === 'employment'" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">รหัสบุคลากร</label>
                    <input v-model="form.profile.personnel_code" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">ตำแหน่ง</label>
                    <select v-model="form.position_uuid" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" @change="applyCatalogPosition">
                      <option value="">ไม่ระบุจากทะเบียน (พิมพ์เอง)</option>
                      <optgroup v-for="group in positionGroups" :key="group.uuid" :label="group.name">
                        <option v-for="position in selectablePositions(group)" :key="position.uuid" :value="position.uuid">
                          {{ position.name }}{{ position.is_active ? '' : ' (ปิดใช้งาน)' }}
                        </option>
                      </optgroup>
                    </select>
                    <input v-if="!form.position_uuid" v-model="form.profile.position" type="text" placeholder="ข้อความตำแหน่ง" class="mt-2 w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
                  </div>
                  <div class="md:col-span-2">
                    <label class="block text-xs text-gray-700 mb-1">แผนกวิชา</label>
                    <select v-model="form.department_id" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500">
                      <option value="">เลือกแผนกวิชา</option>
                      <option v-for="dept in departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                    </select>
                  </div>
                  <div class="md:col-span-2">
                    <label class="block text-xs text-gray-700 mb-1">วุฒิการศึกษา</label>
                    <input v-model="form.profile.education_level" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
                  </div>
                  <div class="md:col-span-2">
                    <label class="block text-xs text-gray-700 mb-1">วุฒิที่ได้รับการบรรจุ</label>
                    <input v-model="form.profile.degree" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
                  </div>
                  <div class="md:col-span-2">
                    <label class="block text-xs text-gray-700 mb-1">ประเภทใบประกอบวิชาชีพ</label>
                    <input v-model="form.profile.license_type" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
                  </div>
                  <div class="md:col-span-2">
                    <label class="block text-xs text-gray-700 mb-1">เลขที่การอนุญาต</label>
                    <input v-model="form.profile.license_number" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">วันที่ออกใบฯ</label>
                    <ThaiDatePicker v-model="form.profile.license_issue_date" />
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">วันที่ใบฯหมดอายุ</label>
                    <ThaiDatePicker v-model="form.profile.license_expiry_date" />
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">วันที่เริ่มปฏิบัติงาน</label>
                    <ThaiDatePicker v-model="form.profile.start_date" />
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">สถานะ</label>
                    <select v-model="form.profile.status" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500">
                      <option value="ปฏิบัติหน้าที่">ปฏิบัติหน้าที่</option>
                      <option value="ลาออก">ลาออก</option>
                      <option value="พักงาน">พักงาน</option>
                    </select>
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">เวลาเข้างาน</label>
                    <input v-model="form.work_start_time" type="time" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">เวลาออกงาน</label>
                    <input v-model="form.work_end_time" type="time" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
                  </div>
                </div>

                <!-- Tab: System (Account Data) -->
                <div v-show="activeTab === 'system'" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div class="md:col-span-2">
                    <label class="block text-xs text-gray-700 mb-1">ชื่อที่แสดงในระบบ</label>
                    <input v-model="form.name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" readonly />
                    <span class="text-xs text-gray-400">สร้างอัตโนมัติจาก ชื่อและนามสกุล</span>
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">บทบาทในระบบ *</label>
                    <select v-if="!modal.isEdit || canManageAccess" v-model="form.role_id" required class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500">
                      <option value="" disabled>เลือกสิทธิ์</option>
                      <option v-for="role in roles" :key="role.id" :value="role.id">{{ roleLabel(role.name) }}</option>
                    </select>
                    <!-- K-ORG-01: เปลี่ยนบทบาทได้เฉพาะผู้มีสิทธิ์กำหนดสิทธิ์รายบุคคล (backend ตรวจซ้ำ) -->
                    <template v-else>
                      <input :value="editingRoleName ? roleLabel(editingRoleName) : '-'" type="text" readonly class="w-full px-3 py-2 border border-gray-300 rounded text-sm bg-gray-50 text-gray-600" />
                      <span class="text-xs text-gray-400">เปลี่ยนบทบาทได้เฉพาะผู้มีสิทธิ์ "กำหนดสิทธิ์รายบุคคล"</span>
                    </template>
                  </div>
                  <!-- ACCESS-GOV-01 · C3: ทะเบียนการมอบบทบาท -->
                  <div v-if="canManageAccess" class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">เลขที่คำสั่งมอบบทบาท</label>
                    <input v-model="form.role_order_number" type="text" maxlength="100" placeholder="เช่น 123/2569" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
                  </div>
                  <div v-if="canManageAccess" class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">บทบาทหมดอายุ (เว้นว่าง = ไม่กำหนด)</label>
                    <input v-model="form.role_expires_at" type="date" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
                    <span class="text-xs text-gray-400">ถึงวันนั้นระบบจะคืนเป็นบทบาทพื้นฐานอัตโนมัติ</span>
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">รหัสผ่าน <span v-if="modal.isEdit" class="text-gray-400 font-normal">(เว้นว่างหากไม่เปลี่ยน)</span></label>
                    <input v-model="form.password" type="password" :required="!modal.isEdit" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
                  </div>
                  <div class="md:col-span-1">
                    <label class="block text-xs text-gray-700 mb-1">Telegram Chat ID</label>
                    <input v-model="form.telegram_chat_id" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
                  </div>
                </div>

                <!-- Tab: Permissions (Custom Individual Direct Permissions) -->
                <div v-show="activeTab === 'permissions'" class="space-y-4 max-h-[450px] overflow-y-auto pr-2">
                  <div>
                    <p class="flex items-start gap-2 text-xs text-gray-600 bg-amber-50 border-l-4 border-amber-400 p-3 rounded mb-3">
                      <svg class="w-4 h-4 flex-shrink-0 text-amber-500 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.info"/>
                      </svg>
                      <span>กำหนดสิทธิ์การเข้าถึงระบบเพิ่มเติมให้กับผู้ใช้งานคนนี้เป็นกรณีพิเศษ (Direct Custom Permissions)</span>
                    </p>
                  </div>
                  
                  <div v-for="(groupPerms, groupName) in groupedPermissions" :key="groupName" class="border border-gray-200 rounded-lg p-3 bg-gray-50">
                    <h5 class="text-xs font-bold text-gray-700 uppercase tracking-wider mb-2.5 flex items-center gap-1.5">
                      <svg class="w-4 h-4 flex-shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.folder"/>
                      </svg>
                      <span>{{ groupName }}</span>
                    </h5>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                      <div v-for="perm in groupPerms" :key="perm.id" class="flex items-center gap-2 bg-white p-2 rounded border border-gray-200 hover:border-red-300 transition-colors">
                        <input
                          type="checkbox"
                          :id="'perm-' + perm.id"
                          :value="perm.name"
                          v-model="form.custom_permissions"
                          class="w-4 h-4 text-red-600 rounded border-gray-300 focus:ring-red-500 cursor-pointer"
                        />
                        <label :for="'perm-' + perm.id" class="text-xs font-mono text-gray-800 cursor-pointer w-full select-none">
                          {{ perm.name }}
                        </label>
                      </div>
                    </div>
                  </div>
                </div>

              </form>
            </div>
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
import { ref, reactive, computed, onMounted } from 'vue'
import { useAuthStore } from '../../stores/auth'
import { useAlertStore } from '../../stores/alert'
import api from '../../axios'
import ThaiDatePicker from '../../components/ThaiDatePicker.vue'
import UserPermissionModal from '../../components/admin/UserPermissionModal.vue'
import { ThaiDateFormatter } from '../../utils/ThaiDateFormatter'
import { getErrorMessage } from '../../utils/errorHandler'
import { ICONS } from '../../utils/icons'
import LoadingState from '../../components/ui/LoadingState.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import AppModal from '../../components/ui/AppModal.vue'

const authStore = useAuthStore()
const alertStore = useAlertStore()

const loading = ref(true)
const loadError = ref('')
const submitting = ref(false)
const users = ref([])
const departments = ref([])
const roles = ref([])
// K-ORG-01: ผู้ที่เปลี่ยนบทบาท/สิทธิ์รายบุคคลได้ — ค่าจาก backend (/admin/assignable-roles)
const canManageAccess = ref(false)
const editingRoleName = ref('')
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

const roleLabel = (name) => {
  const map = {
    'ผู้ดูแลระบบสูงสุด': 'ผู้ดูแลระบบสูงสุด',
    'Super Admin': 'ผู้ดูแลระบบสูงสุด',
    'SuperAdmin':  'ผู้ดูแลระบบสูงสุด',
    'ผู้อำนวยการ': 'ผู้อำนวยการ',
    'Director':    'ผู้อำนวยการ',
    'รองผู้อำนวยการ': 'รองผู้อำนวยการ',
    'Deputy Director': 'รองผู้อำนวยการ',
    'หัวหน้าแผนกวิชา / หัวหน้าฝ่าย': 'หัวหน้าแผนกวิชา / หัวหน้าฝ่าย',
    'HeadOfDepartment': 'หัวหน้าแผนกวิชา / หัวหน้าฝ่าย',
    'เจ้าหน้าที่บุคลากร (HR)': 'เจ้าหน้าที่บุคลากร (HR)',
    'HROfficer':   'เจ้าหน้าที่บุคลากร (HR)',
    'HR Head':     'เจ้าหน้าที่บุคลากร (HR)',
    'เจ้าหน้าที่สารบรรณกลาง': 'เจ้าหน้าที่สารบรรณกลาง',
    'SarabanOfficer': 'เจ้าหน้าที่สารบรรณกลาง',
    'Document Officer': 'เจ้าหน้าที่สารบรรณกลาง',
    'บุคลากร / ครู / เจ้าหน้าที่': 'บุคลากร / ครู / เจ้าหน้าที่',
    'Employee':    'บุคลากร / ครู / เจ้าหน้าที่',
  }
  return map[name] || name || ''
}

const groupedPermissions = computed(() => {
  const groups = {}
  availablePermissions.value.forEach(p => {
    let groupName = 'ทั่วไป (GENERAL)'
    if (p.name.includes('.')) {
      groupName = p.name.split('.')[0].toUpperCase() + ' MODULE'
    } else if (p.name.includes('_')) {
      groupName = p.name.split('_')[0].toUpperCase() + ' MODULE'
    }
    if (!groups[groupName]) groups[groupName] = []
    groups[groupName].push(p)
  })
  return groups
})

const activeTab = ref('personal')
const csvInput = ref(null)
const imageInput = ref(null)
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
  department_id: '',
  status: ''
})

const modal = reactive({
  show: false,
  isEdit: false,
  userId: null
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
  department_id: '',
  role_id: '',
  role_order_number: '',
  role_expires_at: '',
  telegram_chat_id: '',
  work_start_time: '',
  work_end_time: '',
  custom_permissions: [],
  position_uuid: '',
  profile: getDefaultProfile()
})

// ORG-ACCESS-02: ทะเบียนตำแหน่ง (ผู้บริหาร / ครู / เจ้าหน้าที่) — ตำแหน่งไม่ให้สิทธิ์ใด ๆ
const positionGroups = ref([])

const fetchPositions = async () => {
  try {
    const res = await api.get('/positions')
    positionGroups.value = Array.isArray(res.data) ? res.data : []
  } catch (e) {
    console.error('Failed to fetch positions', e)
  }
}

// ตำแหน่งที่ปิดใช้งานแล้วเลือกใหม่ไม่ได้ แต่ยังแสดงถ้าเป็นตำแหน่งเดิมของผู้ใช้ที่กำลังแก้ไข
const selectablePositions = (group, currentUuid = form.position_uuid) => group.positions.filter(p => p.is_active || p.uuid === currentUuid)

const positionAssign = reactive({ show: false, user: null, uuid: '' })

const openPositionAssign = (user) => {
  positionAssign.user = user
  positionAssign.uuid = user.current_assignment?.position_ref?.uuid || ''
  positionAssign.show = true
}

const savePositionAssign = async () => {
  submitting.value = true
  try {
    await api.put(`/admin/users/${positionAssign.user.uuid}/position`, { position_uuid: positionAssign.uuid || null })
    alertStore.success('บันทึกตำแหน่งแล้ว')
    positionAssign.show = false
    await fetchUsers()
  } catch (e) {
    alertStore.error(getErrorMessage(e, 'บันทึกตำแหน่งไม่สำเร็จ'))
  } finally {
    submitting.value = false
  }
}

const applyCatalogPosition = () => {
  const position = positionGroups.value.flatMap(g => g.positions).find(p => p.uuid === form.position_uuid)
  if (position) form.profile.position = position.name
}

const updateFullName = () => {
  const title = form.profile.title || ''
  const fname = form.profile.first_name || ''
  const lname = form.profile.last_name || ''
  form.name = `${title}${fname} ${lname}`.trim()
}

const loadInitialData = async () => {
  loading.value = true
  loadError.value = ''
  try {
    await Promise.all([
      fetchUsers(),
      fetchDepartments(),
      fetchRoles(),
      fetchPermissions(),
      fetchPositions()
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

const fetchUsers = async () => {
  const res = await api.get('/admin/users')
  users.value = res.data
}

const fetchDepartments = async () => {
  const res = await api.get('/admin/departments')
  departments.value = res.data
}

const fetchRoles = async () => {
  // ผู้ที่ดูรายชื่อผู้ใช้ได้ อาจไม่มีสิทธิ์จัดการบทบาท — ให้หน้าจอทำงานต่อได้
  // แทนที่จะพังทั้งหน้า (เหมือนที่ fetchPermissions ทำอยู่แล้ว)
  // K-ORG-01: ได้เฉพาะบทบาทที่ผู้ใช้คนนี้มอบได้ แทน /admin/roles ซึ่งเป็นของผู้จัดการบทบาท
  try {
    const res = await api.get('/admin/assignable-roles')
    roles.value = Array.isArray(res.data?.roles) ? res.data.roles : []
    canManageAccess.value = res.data?.can_manage_access === true
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

const filteredUsers = computed(() => {
  return users.value.filter(u => {
    const searchMatch = !filters.search ||
      u.name.toLowerCase().includes(filters.search.toLowerCase()) ||
      (u.profile?.id_card_number && u.profile.id_card_number.includes(filters.search))
    
    const deptMatch = !filters.department_id || u.department_id === Number(filters.department_id)
    const statusMatch = !filters.status || (u.profile?.status || 'ปฏิบัติหน้าที่') === filters.status

    return searchMatch && deptMatch && statusMatch
  })
})

const openAddModal = () => {
  modal.isEdit = false
  modal.userId = null
  activeTab.value = 'personal'
  
  form.name = ''
  form.email = ''
  form.phone = ''
  form.password = ''
  form.department_id = ''
  form.role_id = ''
  form.role_order_number = ''
  form.role_expires_at = ''
  form.telegram_chat_id = ''
  form.work_start_time = ''
  form.work_end_time = ''
  form.custom_permissions = []
  form.position_uuid = ''
  form.profile = getDefaultProfile()
  
  imageFile.value = null
  imagePreview.value = null

  modal.show = true
}

const openEditModal = (user) => {
  modal.isEdit = true
  modal.userId = user.id
  activeTab.value = 'personal'
  
  form.name = user.name
  form.email = user.email
  form.phone = user.phone || ''
  form.password = ''
  form.department_id = user.department_id || ''
  form.role_id = user.role_id || user.role?.id || (user.roles && user.roles[0] ? user.roles[0].id : '')
  editingRoleName.value = user.role?.name || (user.roles && user.roles[0] ? user.roles[0].name : '')
  form.role_order_number = user.current_role_grant?.order_number || ''
  form.role_expires_at = (user.current_role_grant?.expires_at || '').slice(0, 10)
  form.telegram_chat_id = user.telegram_chat_id || ''
  form.work_start_time = user.work_start_time || ''
  form.work_end_time = user.work_end_time || ''
  form.custom_permissions = Array.isArray(user.custom_permissions) ? [...user.custom_permissions] : (user.permissions ? user.permissions.map(p => typeof p === 'string' ? p : p.name) : [])
  
  form.profile = { ...getDefaultProfile(), ...(user.profile || {}) }
  form.position_uuid = user.current_assignment?.position_ref?.uuid || ''
  
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
    formData.append('department_id', form.department_id)
    formData.append('role_id', form.role_id)
    if (canManageAccess.value) {
      formData.append('role_order_number', form.role_order_number || '')
      formData.append('role_expires_at', form.role_expires_at || '')
    }
    formData.append('telegram_chat_id', form.telegram_chat_id || '')
    if (form.work_start_time) formData.append('work_start_time', form.work_start_time)
    if (form.work_end_time) formData.append('work_end_time', form.work_end_time)
    // K-ORG-01: ส่งสิทธิ์รายบุคคลเฉพาะผู้ที่กำหนดสิทธิ์ได้ — ผู้อื่นไม่ส่ง เพื่อคงสิทธิ์เดิมของผู้ใช้ไว้
    if (canManageAccess.value) {
      formData.append('custom_permissions', JSON.stringify(form.custom_permissions || []))
    }
    formData.append('position_uuid', form.position_uuid || '')
    
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

// ACCESS-GOV-01: บุคลากรพ้นสภาพ — ระงับบัญชีและปิดอำนาจทั้งหมด ไม่ลบบัญชี
const separation = reactive({ show: false, user: null, name: '', separated_at: '', reason: '' })
const openSeparation = (user) => {
  Object.assign(separation, {
    show: true,
    user,
    name: user.personnel?.full_name || user.name,
    separated_at: new Date().toISOString().slice(0, 10),
    reason: '',
  })
}
const saveSeparation = async () => {
  submitting.value = true
  try {
    const res = await api.post(`/admin/users/${separation.user.id}/separate`, { separated_at: separation.separated_at, reason: separation.reason })
    alertStore.success(res.data.message)
    separation.user.is_active = false
    separation.user.separated_at = separation.separated_at
    separation.show = false
  } catch (e) {
    alertStore.error(getErrorMessage(e, 'บันทึกการพ้นสภาพไม่สำเร็จ'))
  } finally {
    submitting.value = false
  }
}

const toggleStatus = async (user) => {
  if (!confirm(`คุณต้องการ ${user.is_active ? 'ระงับ' : 'เปิดใช้งาน'} ผู้ใช้นี้ใช่หรือไม่?`)) return
  
  submitting.value = true
  try {
    const res = await api.post(`/admin/users/${user.id}/toggle-status`)
    alertStore.success(res.data.message)
    user.is_active = res.data.user?.is_active ?? !user.is_active
    if (user.is_active) user.separated_at = null
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
  const headers = ['id_card_number', 'name', 'email', 'role_id', 'department_id', 'title', 'first_name', 'last_name', 'gender', 'phone', 'position', 'personnel_type_id']
  const sample = ['1234567890123', 'นายสมชาย ใจดี', 'somchai@example.com', '1', '1', 'นาย', 'สมชาย', 'ใจดี', 'ชาย', '0812345678', 'พนักงานทั่วไป', '']
  
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
