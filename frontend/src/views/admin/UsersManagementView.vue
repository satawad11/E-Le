<template>
  <div>
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">จัดการผู้ใช้งาน (บุคลากร)</h1>
        <p class="text-gray-500 mt-1">เพิ่ม แก้ไข และลบข้อมูลบุคลากรภายในระบบ</p>
      </div>
      <div class="flex gap-2">
        <button
          @click="downloadCsvTemplate"
          class="flex items-center gap-2 px-4 py-2.5 bg-gray-600 hover:bg-gray-700 text-white font-semibold rounded-xl text-sm transition-all shadow-md hover:shadow-lg"
          title="ดาวน์โหลดรูปแบบไฟล์ CSV สำหรับนำเข้า"
        >
          <i class="fas fa-download"></i> ฟอร์ม CSV
        </button>

        <button
          @click="triggerCsvUpload"
          class="flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl text-sm transition-all shadow-md hover:shadow-lg"
        >
          <i class="fas fa-file-csv"></i> นำเข้าด้วย CSV
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
      <div v-if="loading" class="flex justify-center py-16">
        <div class="animate-spin h-10 w-10 rounded-full border-4 border-[#800000] border-t-transparent"></div>
      </div>

      <div v-else>
        <div class="overflow-x-auto">
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
              <tr v-if="filteredUsers.length === 0">
                <td colspan="7" class="text-center text-gray-400 py-12">ไม่พบข้อมูลผู้ใช้งาน</td>
              </tr>
              <tr v-for="(user, index) in filteredUsers" :key="user.id" class="hover:bg-gray-50 transition-colors">
                <td class="px-4 py-3 text-center text-gray-500">{{ index + 1 }}</td>
                <td class="px-4 py-3 text-blue-600 font-medium">{{ user.profile?.id_card_number || '-' }}</td>
                <td class="px-4 py-3">
                  <div class="font-medium text-gray-900">{{ user.name }}</div>
                </td>
                <td class="px-4 py-3 text-gray-600">{{ user.profile?.position || (user.role ? roleLabel(user.role.name) : '-') }}</td>
                <td class="px-4 py-3 text-center text-gray-600">{{ formatDate(user.profile?.start_date) || '-' }}</td>
                <td class="px-4 py-3 text-center">
                  <span class="inline-block px-2 py-1 text-xs font-medium rounded-md" :class="user.profile?.status === 'ปฏิบัติหน้าที่' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'">
                    {{ user.profile?.status || 'ปฏิบัติหน้าที่' }}
                  </span>
                </td>
                <td class="px-4 py-3 text-center">
                  <div class="flex flex-wrap items-center justify-center gap-2">
                    <button @click="toggleStatus(user)" class="px-3 py-1 rounded text-xs transition" :class="user.is_active ? 'bg-amber-500 hover:bg-amber-600 text-white' : 'bg-green-500 hover:bg-green-600 text-white'" :disabled="submitting" title="เปิด-ปิด การใช้งาน">
                      <i class="fas" :class="user.is_active ? 'fa-ban' : 'fa-check'"></i> {{ user.is_active ? 'ระงับ' : 'เปิดใช้งาน' }}
                    </button>
                    <button @click="openPermissionModal(user)" class="px-3 py-1 bg-indigo-600 text-white rounded text-xs hover:bg-indigo-700 transition" title="จัดการสิทธิ์การเข้าถึงรายบุคคล">
                      <i class="fas fa-shield-alt mr-1"></i> สิทธิ์
                    </button>
                    <button @click="openEditModal(user)" class="px-3 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700 transition">
                      <i class="fas fa-edit mr-1"></i> แก้ไข
                    </button>
                    <button v-if="user.id !== authStore.user?.id" @click="confirmDelete(user)" class="px-3 py-1 bg-red-600 text-white rounded text-xs hover:bg-red-700 transition">
                      <i class="fas fa-trash mr-1"></i> ลบ
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

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
                <i class="fas fa-save"></i> บันทึก
              </button>
            </div>
          </div>

          <div class="flex flex-col md:flex-row flex-1 p-6 gap-6">
            <!-- Sidebar / Profile Image -->
            <div class="w-full md:w-64 flex-shrink-0">
              <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 text-center mb-4 relative">
                <div class="w-32 h-32 mx-auto bg-gray-200 rounded-full flex items-center justify-center mb-4 overflow-hidden border-4 border-white shadow cursor-pointer group" @click="$refs.imageInput.click()">
                  <img v-if="imagePreview" :src="imagePreview" class="w-full h-full object-cover" />
                  <img v-else-if="form.profile.profile_photo_path" :src="form.profile.profile_photo_path" class="w-full h-full object-cover" />
                  <svg v-else class="w-16 h-16 text-gray-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path></svg>
                  
                  <div class="absolute inset-0 bg-black/40 flex flex-col items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity rounded-full w-32 h-32 mx-auto border-4 border-transparent mt-4">
                    <i class="fas fa-camera text-white text-xl mb-1"></i>
                    <span class="text-white text-xs">อัปโหลดรูป</span>
                  </div>
                </div>
                <input type="file" ref="imageInput" @change="onImageSelected" accept="image/jpeg, image/png, image/jpg" class="hidden" />
                <div class="font-medium text-blue-800 text-sm mb-1">{{ form.profile.title || '' }}{{ form.profile.first_name || 'ชื่อ' }} {{ form.profile.last_name || 'นามสกุล' }}</div>
                <div class="text-xs text-blue-600">{{ form.profile.id_card_number || 'เลขบัตรประชาชน' }}</div>
              </div>

              <!-- Tabs Navigation -->
              <div class="flex flex-col gap-1">
                <button @click="activeTab = 'personal'" :class="activeTab === 'personal' ? 'bg-red-500 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="px-4 py-3 text-left text-sm font-medium rounded transition">ข้อมูลส่วนตัวบุคลากร</button>
                <button @click="activeTab = 'address'" :class="activeTab === 'address' ? 'bg-red-500 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="px-4 py-3 text-left text-sm font-medium rounded transition">ข้อมูลที่อยู่</button>
                <button @click="activeTab = 'employment'" :class="activeTab === 'employment' ? 'bg-red-500 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="px-4 py-3 text-left text-sm font-medium rounded transition">ข้อมูลการจ้าง</button>
                <button @click="activeTab = 'system'" :class="activeTab === 'system' ? 'bg-red-500 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="px-4 py-3 text-left text-sm font-medium rounded transition">ข้อมูลระบบ (Account)</button>
                <button v-if="authStore.isAdmin" @click="activeTab = 'permissions'" :class="activeTab === 'permissions' ? 'bg-red-500 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="px-4 py-3 text-left text-sm font-medium rounded transition">สิทธิ์การเข้าถึงพิเศษ</button>
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
                    <input v-model="form.profile.position" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
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
                    <select v-model="form.role_id" required class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500">
                      <option value="" disabled>เลือกสิทธิ์</option>
                      <option v-for="role in roles" :key="role.id" :value="role.id">{{ roleLabel(role.name) }}</option>
                    </select>
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
                    <p class="text-xs text-gray-600 bg-amber-50 border-l-4 border-amber-400 p-3 rounded mb-3">
                      <i class="fas fa-info-circle text-amber-500 mr-2"></i> กำหนดสิทธิ์การเข้าถึงระบบเพิ่มเติมให้กับผู้ใช้งานคนนี้เป็นกรณีพิเศษ (Direct Custom Permissions)
                    </p>
                  </div>
                  
                  <div v-for="(groupPerms, groupName) in groupedPermissions" :key="groupName" class="border border-gray-200 rounded-lg p-3 bg-gray-50">
                    <h5 class="text-xs font-bold text-gray-700 uppercase tracking-wider mb-2.5 flex items-center gap-1.5">
                      <span>📌</span>
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
import { ref, reactive, computed, onMounted } from 'vue'
import { useAuthStore } from '../../stores/auth'
import { useAlertStore } from '../../stores/alert'
import api from '../../axios'
import ThaiDatePicker from '../../components/ThaiDatePicker.vue'
import UserPermissionModal from '../../components/admin/UserPermissionModal.vue'

const authStore = useAuthStore()
const alertStore = useAlertStore()

const loading = ref(true)
const submitting = ref(false)
const users = ref([])
const departments = ref([])
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

const roleLabel = (name) => {
  const map = {
    'ผู้ดูแลระบบสูงสุด': '👑 ผู้ดูแลระบบสูงสุด',
    'Super Admin': '👑 ผู้ดูแลระบบสูงสุด',
    'SuperAdmin':  '👑 ผู้ดูแลระบบสูงสุด',
    'ผู้อำนวยการ': '🏢 ผู้อำนวยการ',
    'Director':    '🏢 ผู้อำนวยการ',
    'รองผู้อำนวยการ': '👔 รองผู้อำนวยการ',
    'Deputy Director': '👔 รองผู้อำนวยการ',
    'หัวหน้าแผนกวิชา / หัวหน้าฝ่าย': '💼 หัวหน้าแผนกวิชา / หัวหน้าฝ่าย',
    'HeadOfDepartment': '💼 หัวหน้าแผนกวิชา / หัวหน้าฝ่าย',
    'เจ้าหน้าที่บุคลากร (HR)': '📋 เจ้าหน้าที่บุคลากร (HR)',
    'HROfficer':   '📋 เจ้าหน้าที่บุคลากร (HR)',
    'HR Head':     '📋 เจ้าหน้าที่บุคลากร (HR)',
    'เจ้าหน้าที่สารบรรณกลาง': '📑 เจ้าหน้าที่สารบรรณกลาง',
    'SarabanOfficer': '📑 เจ้าหน้าที่สารบรรณกลาง',
    'Document Officer': '📑 เจ้าหน้าที่สารบรรณกลาง',
    'บุคลากร / ครู / เจ้าหน้าที่': '👤 บุคลากร / ครู / เจ้าหน้าที่',
    'Employee':    '👤 บุคลากร / ครู / เจ้าหน้าที่',
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
  department_id: '',
  role_id: '',
  telegram_chat_id: '',
  work_start_time: '',
  work_end_time: '',
  custom_permissions: [],
  profile: getDefaultProfile()
})

const updateFullName = () => {
  const title = form.profile.title || ''
  const fname = form.profile.first_name || ''
  const lname = form.profile.last_name || ''
  form.name = `${title}${fname} ${lname}`.trim()
}

onMounted(async () => {
  try {
    await Promise.all([
      fetchUsers(),
      fetchDepartments(),
      fetchRoles(),
      fetchPermissions()
    ])
  } catch (e) {
    console.error(e)
    alertStore.error('เกิดข้อผิดพลาดในการดึงข้อมูล')
  } finally {
    loading.value = false
  }
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
  const res = await api.get('/admin/roles')
  roles.value = Array.isArray(res.data.data) ? res.data.data : (Array.isArray(res.data) ? res.data : [])
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
  if (!dateStr) return ''
  const d = new Date(dateStr)
  return `${d.getDate()}/${d.getMonth()+1}/${d.getFullYear() + 543}`
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
  form.telegram_chat_id = ''
  form.work_start_time = ''
  form.work_end_time = ''
  form.custom_permissions = []
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
    formData.append('department_id', form.department_id)
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
    alertStore.error(e.response?.data?.message || 'เกิดข้อผิดพลาด กรุณาลองใหม่')
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
  const headers = ['id_card_number', 'name', 'email', 'role_id', 'department_id', 'title', 'first_name', 'last_name', 'gender', 'phone', 'position']
  const sample = ['1234567890123', 'นายสมชาย ใจดี', 'somchai@example.com', '1', '1', 'นาย', 'สมชาย', 'ใจดี', 'ชาย', '0812345678', 'พนักงานทั่วไป']
  
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
    }
    alert(msg) // Or use a proper modal for details
    
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
