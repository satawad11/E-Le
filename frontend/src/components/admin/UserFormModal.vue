<template>
  <!--
    eslint-disable vue/no-mutating-props

    `form` และ `modal` เป็นอ็อบเจ็กต์ reactive ตัวเดียวกับที่หน้าแม่ถืออยู่ ไม่ใช่ค่าที่คัดลอกมา
    การผูก v-model จึงเขียนกลับเข้าไปตรง ๆ — เป็นทางเลือกที่ตั้งใจ เพื่อย้ายฟอร์ม 300 บรรทัด
    ออกจากไฟล์ที่ยาวเกิน 1,200 บรรทัด โดยไม่ต้องเขียน emit ทีละช่อง ซึ่งเสี่ยงพลาดสูงกว่ามาก
    สำหรับฟอร์มที่ยังไม่มีเทสต์คอมโพเนนต์คุมอยู่

    ถ้าจะทำให้ถูกหลักกว่านี้ ควรให้คอมโพเนนต์นี้เป็นเจ้าของฟอร์มเอง แล้วส่งผลลัพธ์กลับตอนบันทึก
  -->
  <transition name="fade">
  <div v-if="modal.show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 overflow-y-auto" @click.self="modal.show = false">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-6xl my-auto max-h-[90vh] flex flex-col overflow-hidden">
      <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center bg-gray-50 rounded-t-2xl flex-shrink-0">
        <h3 class="font-bold text-gray-900 text-xl">
          {{ modal.isEdit ? 'แก้ไขข้อมูลบุคลากร' : 'เพิ่มข้อมูลบุคลากร' }}
        </h3>
        <div class="flex gap-2">
          <button @click="modal.show = false" class="px-4 py-2 border border-gray-300 rounded-xl text-gray-700 hover:bg-gray-100 transition shadow-sm text-sm font-medium">ย้อนกลับ</button>
          <button @click="$emit('submit')" :disabled="submitting" class="px-4 py-2 bg-[#800000] text-white rounded-xl hover:bg-[#600000] transition flex items-center gap-2 shadow-sm text-sm font-semibold">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.save"/>
            </svg>
            บันทึก
          </button>
        </div>
      </div>

      <div class="flex flex-col md:flex-row flex-1 p-6 gap-6 overflow-y-auto md:overflow-hidden min-h-0">
        <!-- Sidebar / Profile Image -->
        <div class="w-full md:w-64 flex-shrink-0 overflow-y-auto">
          <div class="bg-gray-50 p-4 rounded-xl border border-gray-200 text-center mb-4 relative">
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
            <input type="file" ref="imageInput" @change="$emit('image-selected', $event)" accept="image/jpeg, image/png, image/jpg" class="hidden" />
            <div class="font-medium text-blue-800 text-sm mb-1">{{ form.profile.title || '' }}{{ form.profile.first_name || 'ชื่อ' }} {{ form.profile.last_name || 'นามสกุล' }}</div>
            <div class="text-xs text-blue-600">{{ form.profile.id_card_number || 'เลขบัตรประชาชน' }}</div>
          </div>

          <!-- Tabs Navigation -->
          <div class="flex flex-col gap-1">
            <button @click="$emit('update:activeTab', 'personal')" :class="activeTab === 'personal' ? 'bg-[#800000] text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="px-4 py-3 text-left text-sm font-medium rounded-xl transition">ข้อมูลส่วนตัวบุคลากร</button>
            <button @click="$emit('update:activeTab', 'address')" :class="activeTab === 'address' ? 'bg-[#800000] text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="px-4 py-3 text-left text-sm font-medium rounded-xl transition">ข้อมูลที่อยู่</button>
            <button @click="$emit('update:activeTab', 'employment')" :class="activeTab === 'employment' ? 'bg-[#800000] text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="px-4 py-3 text-left text-sm font-medium rounded-xl transition">ข้อมูลการจ้าง</button>
            <button @click="$emit('update:activeTab', 'system')" :class="activeTab === 'system' ? 'bg-[#800000] text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="px-4 py-3 text-left text-sm font-medium rounded-xl transition">ข้อมูลระบบ (Account)</button>
            <button v-if="isAdmin" @click="$emit('update:activeTab', 'permissions')" :class="activeTab === 'permissions' ? 'bg-[#800000] text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="px-4 py-3 text-left text-sm font-medium rounded-xl transition">สิทธิ์การเข้าถึงพิเศษ</button>
          </div>

        </div>

        <!-- Tab Content -->
        <div class="flex-1 bg-white border border-gray-200 rounded-xl p-6 overflow-y-auto min-h-0">
          <h4 class="text-lg font-bold text-red-600 mb-4 border-b border-red-200 pb-2">
            {{ tabTitles[activeTab] }}
          </h4>

          <form @submit.prevent="submitForm">
            <!-- Tab: Personal -->
            <div v-show="activeTab === 'personal'" class="grid grid-cols-1 md:grid-cols-4 gap-4">
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">เลขประจำตัวประชาชน *</label>
                <input aria-label="เลขประจำตัวประชาชน" v-model="form.profile.id_card_number" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" required />
              </div>
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">วันเกิด *</label>
                <ThaiDatePicker v-model="form.profile.date_of_birth" />
              </div>
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">สัญชาติ *</label>
                <input aria-label="สัญชาติ" v-model="form.profile.nationality" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" required />
              </div>
              <!-- เลิกเก็บ "เชื้อชาติ" — เป็นข้อมูลอ่อนไหวตาม ม.26 พ.ร.บ.คุ้มครองข้อมูลส่วนบุคคล
                   และไม่มีส่วนใดของระบบนำไปใช้ จึงไม่มีฐานทางกฎหมายรองรับการเก็บ -->
              
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">คำนำหน้าชื่อ *</label>
                <select aria-label="คำนำหน้าชื่อ" v-model="form.profile.title" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" required>
                  <option value="นาย">นาย</option>
                  <option value="นาง">นาง</option>
                  <option value="นางสาว">นางสาว</option>
                </select>
              </div>
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">ชื่อ *</label>
                <input aria-label="ชื่อ" v-model="form.profile.first_name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" required @input="updateFullName" />
              </div>
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">นามสกุล *</label>
                <input aria-label="นามสกุล" v-model="form.profile.last_name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" required @input="updateFullName" />
              </div>
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">ชื่อกลาง</label>
                <input aria-label="ชื่อกลาง" v-model="form.profile.middle_name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
              </div>

              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">เพศ *</label>
                <select aria-label="เพศ" v-model="form.profile.gender" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" required>
                  <option value="ชาย">ชาย</option>
                  <option value="หญิง">หญิง</option>
                </select>
              </div>
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">ชื่อ (อังกฤษ)</label>
                <input aria-label="ชื่อ (อังกฤษ)" v-model="form.profile.first_name_en" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
              </div>
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">นามสกุล (อังกฤษ)</label>
                <input aria-label="นามสกุล (อังกฤษ)" v-model="form.profile.last_name_en" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
              </div>
              <!-- เลิกเก็บ "ศาสนา" ด้วยเหตุผลเดียวกับเชื้อชาติ (ข้อมูลอ่อนไหวตาม ม.26) -->
            </div>

            <!-- Tab: Address -->
            <div v-show="activeTab === 'address'" class="grid grid-cols-1 md:grid-cols-4 gap-4">
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">รหัสประจำบ้าน</label>
                <input aria-label="รหัสประจำบ้าน" v-model="form.profile.house_registration_number" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
              </div>
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">บ้านเลขที่ *</label>
                <input aria-label="บ้านเลขที่" v-model="form.profile.house_number" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" required />
              </div>
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">หมู่ที่</label>
                <input aria-label="หมู่ที่" v-model="form.profile.moo" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
              </div>
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">ซอย</label>
                <input aria-label="ซอย" v-model="form.profile.soi" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
              </div>
              <div class="md:col-span-4">
                <label class="block text-xs text-gray-700 mb-1">ถนน</label>
                <input aria-label="ถนน" v-model="form.profile.road" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
              </div>

              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">จังหวัด *</label>
                <input aria-label="จังหวัด" v-model="form.profile.province" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" required />
              </div>
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">อำเภอ/เขต *</label>
                <input aria-label="อำเภอ/เขต" v-model="form.profile.district" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" required />
              </div>
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">ตำบล/แขวง *</label>
                <input aria-label="ตำบล/แขวง" v-model="form.profile.sub_district" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" required />
              </div>
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">รหัสไปรษณีย์ *</label>
                <input aria-label="รหัสไปรษณีย์" v-model="form.profile.zipcode" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" required />
              </div>

              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">เบอร์โทรศัพท์</label>
                <input aria-label="เบอร์โทรศัพท์" v-model="form.phone" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
              </div>
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">Fax</label>
                <input aria-label="โทรสาร (Fax)" v-model="form.profile.fax" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
              </div>
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">E-Mail</label>
                <input aria-label="อีเมล" v-model="form.email" type="email" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
              </div>
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">เว็บไซต์</label>
                <input aria-label="เว็บไซต์" v-model="form.profile.website" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
              </div>
            </div>

            <!-- Tab: Employment -->
            <div v-show="activeTab === 'employment'" class="grid grid-cols-1 md:grid-cols-4 gap-4">
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">รหัสบุคลากร</label>
                <input aria-label="รหัสบุคลากร" v-model="form.profile.personnel_code" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
              </div>
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">ตำแหน่ง</label>
                <input aria-label="ตำแหน่ง" v-model="form.profile.position" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
              </div>
              <div class="md:col-span-2">
                <label class="block text-xs text-gray-700 mb-1">สังกัดฝ่าย / แผนกวิชา</label>
                <select aria-label="สังกัดฝ่าย / แผนกวิชา" v-model="form.org_unit_id" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500">
                  <option value="">เลือกสังกัดฝ่าย / แผนกวิชา</option>
                  <option v-for="dept in departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                </select>
                <label class="mt-2 flex items-center gap-2 text-xs text-gray-700 font-medium cursor-pointer">
                  <input type="checkbox" v-model="form.is_org_unit_head" class="rounded text-[#800000] focus:ring-[#800000]">
                  <span>แต่งตั้งเป็นหัวหน้างาน/หัวหน้าแผนกนี้ (Org Unit Manager)</span>
                </label>
              </div>
              <!--
                ประเภทบุคลากรเป็นตัวตัดสินสิทธิวันลาทั้งปี (นโยบายแยกตามประเภท)
                เดิมไม่มีช่องนี้ในฟอร์มเลย ระบบจึงเดาเป็น "ข้าราชการ" ให้ทุกคน
              -->
              <div class="md:col-span-2">
                <label class="block text-xs text-gray-700 mb-1">
                  ประเภทบุคลากร <span class="text-red-500">*</span>
                </label>
                <select aria-label="ประเภทบุคลากร" v-model="form.personnel_type_id" required class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500">
                  <option value="">เลือกประเภทบุคลากร</option>
                  <option v-for="type in personnelTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
                </select>
                <p class="mt-1 text-xs text-gray-500">ใช้กำหนดสิทธิวันลาตามนโยบายของแต่ละประเภท</p>
              </div>
              <div class="md:col-span-2">
                <label class="block text-xs text-gray-700 mb-1">ระดับวิทยฐานะ</label>
                <select aria-label="ระดับวิทยฐานะ" v-model="form.profile.academic_standing" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500">
                  <option value="">ไม่มีวิทยฐานะ / ครูผู้ช่วย</option>
                  <option value="ครู">ครู (คศ.๑)</option>
                  <option value="ครูชำนาญการ">ครูชำนาญการ (คศ.๒)</option>
                  <option value="ครูชำนาญการพิเศษ">ครูชำนาญการพิเศษ (คศ.๓)</option>
                  <option value="ครูเชี่ยวชาญ">ครูเชี่ยวชาญ (คศ.๔)</option>
                  <option value="ครูเชี่ยวชาญพิเศษ">ครูเชี่ยวชาญพิเศษ (คศ.๕)</option>
                </select>
              </div>
              <div class="md:col-span-2">
                <label class="block text-xs text-gray-700 mb-1">วุฒิการศึกษา</label>
                <input aria-label="วุฒิการศึกษา" v-model="form.profile.education_level" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
              </div>
              <div class="md:col-span-2">
                <label class="block text-xs text-gray-700 mb-1">วุฒิที่ได้รับการบรรจุ</label>
                <input aria-label="วุฒิที่ได้รับการบรรจุ" v-model="form.profile.degree" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
              </div>
              <div class="md:col-span-2">
                <label class="block text-xs text-gray-700 mb-1">ประเภทใบประกอบวิชาชีพ</label>
                <input aria-label="ประเภทใบประกอบวิชาชีพ" v-model="form.profile.license_type" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
              </div>
              <div class="md:col-span-2">
                <label class="block text-xs text-gray-700 mb-1">เลขที่การอนุญาต</label>
                <input aria-label="เลขที่การอนุญาต" v-model="form.profile.license_number" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
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
                <select aria-label="สถานะ" v-model="form.profile.status" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500">
                  <option value="ปฏิบัติหน้าที่">ปฏิบัติหน้าที่</option>
                  <option value="ลาออก">ลาออก</option>
                  <option value="พักงาน">พักงาน</option>
                </select>
              </div>
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">เวลาเข้างาน</label>
                <input aria-label="เวลาเข้างาน" v-model="form.work_start_time" type="time" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
              </div>
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">เวลาออกงาน</label>
                <input aria-label="เวลาออกงาน" v-model="form.work_end_time" type="time" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
              </div>
            </div>

            <!-- Tab: System (Account Data) -->
            <div v-show="activeTab === 'system'" class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="md:col-span-2">
                <label class="block text-xs text-gray-700 mb-1">ชื่อที่แสดงในระบบ</label>
                <input aria-label="ชื่อที่แสดงในระบบ" v-model="form.name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" readonly />
                <span class="text-xs text-gray-400">สร้างอัตโนมัติจาก ชื่อและนามสกุล</span>
              </div>
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">บทบาทในระบบ *</label>
                <select aria-label="บทบาทในระบบ" v-model="form.role_id" required class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500">
                  <option value="" disabled>เลือกสิทธิ์</option>
                  <option v-for="role in roles" :key="role.id" :value="role.id">{{ roleLabel(role.name) }}</option>
                </select>
              </div>
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">รหัสผ่าน <span v-if="modal.isEdit" class="text-gray-400 font-normal">(เว้นว่างหากไม่เปลี่ยน)</span></label>
                <input aria-label="รหัสผ่าน" v-model="form.password" type="password" :required="!modal.isEdit" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
              </div>
              <div class="md:col-span-1">
                <label class="block text-xs text-gray-700 mb-1">รหัสห้องแชท Telegram (Chat ID)</label>
                <input aria-label="รหัสห้องแชท Telegram (Chat ID)" v-model="form.telegram_chat_id" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
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
</template>

<script setup>
/* eslint-disable vue/no-mutating-props -- ดูเหตุผลในหมายเหตุด้านล่าง */
import { computed, ref } from 'vue'
import { ICONS } from '../../utils/icons'
import { roleLabel } from '../../utils/roleLabel'
import ThaiDatePicker from '../ThaiDatePicker.vue'


/*
 * ฟอร์มเพิ่ม/แก้ไขบุคลากร
 *
 * แยกออกจาก `UsersManagementView.vue` ซึ่งเคยยาวกว่า 1,200 บรรทัดและทำทุกอย่าง
 * ในไฟล์เดียว — ตาราง ตัวกรอง การเลือกหลายรายการ ฟอร์ม อัปโหลดรูป สิทธิ์ และนำเข้า CSV
 *
 * `modal` และ `form` ส่งเข้ามาเป็นอ็อบเจ็กต์ reactive ตัวเดียวกับที่หน้าแม่ถืออยู่
 * การผูก `v-model` ทั้งหมดจึงย้ายมาได้ตรง ๆ โดยไม่ต้องเขียน emit ทีละช่อง
 * ซึ่งเป็นวิธีที่เสี่ยงน้อยที่สุดสำหรับฟอร์มขนาดนี้ที่ยังไม่มีเทสต์คอมโพเนนต์คุมอยู่
 */
const props = defineProps({

  modal: { type: Object, required: true },
  form: { type: Object, required: true },
  activeTab: { type: String, required: true },
  tabTitles: { type: Object, required: true },
  departments: { type: Array, default: () => [] },
  personnelTypes: { type: Array, default: () => [] },
  roles: { type: Array, default: () => [] },
  availablePermissions: { type: Array, default: () => [] },
  imagePreview: { type: String, default: '' },
  submitting: { type: Boolean, default: false },
  isAdmin: { type: Boolean, default: false },
})

defineEmits(['update:activeTab', 'submit', 'image-selected'])

const imageInput = ref(null)

/** จัดกลุ่มสิทธิ์ตามโมดูล เพื่อให้รายการยาว ๆ อ่านออก */
const groupedPermissions = computed(() => {
  const groups = {}
  props.availablePermissions.forEach(p => {
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

/** ชื่อที่แสดงบนเอกสารประกอบจากคำนำหน้า + ชื่อ + สกุล */
const updateFullName = () => {
  const title = props.form.profile.title || ''
  const fname = props.form.profile.first_name || ''
  const lname = props.form.profile.last_name || ''
  props.form.name = `${title}${fname} ${lname}`.trim()
}
</script>
