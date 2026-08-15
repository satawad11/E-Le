<template>
  <div class="max-w-2xl mx-auto">
    <div class="mb-8">
      <h1 class="text-2xl font-bold text-gray-900">ยื่นใบลา</h1>
      <p class="text-gray-500 mt-1">กรอกข้อมูลเพื่อส่งคำขอลา</p>
    </div>

    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 md:p-8">
      <form @submit.prevent="handleSubmit" enctype="multipart/form-data" class="space-y-6">
        <!-- Leave type -->
        <div>
          <label for="leave-type" class="block text-sm font-semibold text-gray-700 mb-1.5">ประเภทการลา <span class="text-red-500">*</span></label>
          <select v-model="form.leave_type_id" required id="leave-type" @change="onLeaveTypeChange" class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 text-gray-900 focus:outline-none focus:border-[#800000] transition-colors bg-white">
            <option value="" disabled>เลือกประเภทการลา...</option>
            <option v-for="type in leaveStore.types" :key="type.id" :value="type.id">{{ type.name }}</option>
          </select>

          <!-- Contextual Leave Balance (แสดงเมื่อเลือกประเภทการลาแล้ว) -->
          <transition name="fade">
            <div v-if="selectedBalance" class="mt-2 flex flex-wrap items-center gap-3 px-4 py-3 rounded-xl bg-gradient-to-r from-[#800000]/5 to-amber-50 border border-[#800000]/15">
              <div class="flex items-center gap-1.5">
                <span class="text-xs text-gray-500 font-medium">สิทธิทั้งหมด:</span>
                <span class="text-sm font-bold text-gray-800">{{ selectedBalance.unlimited ? 'ไม่จำกัดโควตา' : `${selectedBalance.quota + (selectedBalance.carried_forward || 0)} วัน` }}</span>
              </div>
              <div class="w-px h-4 bg-gray-300"></div>
              <div class="flex items-center gap-1.5">
                <span class="text-xs text-gray-500 font-medium">ใช้ไปแล้ว:</span>
                <span class="text-sm font-bold text-orange-600">{{ selectedBalance.used }} วัน</span>
              </div>
              <div class="w-px h-4 bg-gray-300"></div>
              <div v-if="selectedBalance.pending" class="flex items-center gap-1.5">
                <span class="text-xs text-gray-500 font-medium">รออนุมัติ:</span>
                <span class="text-sm font-bold text-amber-600">{{ selectedBalance.pending }} วัน</span>
              </div>
              <div v-if="selectedBalance.pending" class="w-px h-4 bg-gray-300"></div>
              <div v-if="selectedBalance.paid_quota !== null && selectedBalance.paid_quota !== undefined" class="flex items-center gap-1.5">
                <span class="text-xs text-gray-500 font-medium">ได้รับเงินเดือนอีก:</span>
                <span class="text-sm font-bold text-emerald-700">{{ selectedBalance.paid_remaining }} / {{ selectedBalance.paid_quota }} วัน</span>
              </div>
              <div v-if="selectedBalance.paid_quota !== null && selectedBalance.paid_quota !== undefined" class="w-px h-4 bg-gray-300"></div>
              <div v-if="!selectedBalance.unlimited" class="flex items-center gap-1.5">
                <span class="text-xs text-gray-500 font-medium">คงเหลือ:</span>
                <span class="text-sm font-bold px-2.5 py-0.5 rounded-full" :class="remainingDays <= 2 ? 'bg-red-100 text-red-700' : remainingDays <= 5 ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700'">
                  {{ remainingDays }} วัน
                </span>
              </div>
            </div>
          </transition>
        </div>


        <!-- Dates -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1.5">วันที่เริ่มลา <span class="text-red-500">*</span></label>
            <ThaiDatePicker
              v-model="form.start_date"
              @update:modelValue="onStartDateChange"
              id="start-date"
            />
          </div>
          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1.5">วันที่กลับ <span class="text-red-500">*</span></label>
            <ThaiDatePicker
              v-model="form.end_date"
              @update:modelValue="calcDays"
              :minDate="form.start_date || ''"
              id="end-date"
              :disabled="form.leave_period !== 'full_day'"
            />
          </div>
        </div>

        <!-- Leave Period -->
        <div class="pt-2">
          <label class="block text-sm font-semibold text-gray-700 mb-2">ระยะเวลาการลา <span class="text-red-500">*</span></label>
          <div class="grid grid-cols-3 gap-2 sm:gap-4">
            <label class="flex items-center justify-center gap-2 p-3 rounded-xl border-2 transition-all cursor-pointer select-none text-center min-h-[44px]" :class="form.leave_period === 'full_day' ? 'border-[#800000] bg-[#800000]/5 text-[#800000] font-bold shadow-sm' : 'border-gray-200 text-gray-600 hover:border-gray-300'">
              <input type="radio" v-model="form.leave_period" value="full_day" @change="onPeriodChange" class="sr-only">
              <span class="text-xs sm:text-sm">เต็มวัน</span>
            </label>
            <label class="flex items-center justify-center gap-2 p-3 rounded-xl border-2 transition-all cursor-pointer select-none text-center min-h-[44px]" :class="form.leave_period === 'morning' ? 'border-[#800000] bg-[#800000]/5 text-[#800000] font-bold shadow-sm' : 'border-gray-200 text-gray-600 hover:border-gray-300'">
              <input type="radio" v-model="form.leave_period" value="morning" @change="onPeriodChange" class="sr-only">
              <span class="text-xs sm:text-sm">ครึ่งวันเช้า</span>
            </label>
            <label class="flex items-center justify-center gap-2 p-3 rounded-xl border-2 transition-all cursor-pointer select-none text-center min-h-[44px]" :class="form.leave_period === 'afternoon' ? 'border-[#800000] bg-[#800000]/5 text-[#800000] font-bold shadow-sm' : 'border-gray-200 text-gray-600 hover:border-gray-300'">
              <input type="radio" v-model="form.leave_period" value="afternoon" @change="onPeriodChange" class="sr-only">
              <span class="text-xs sm:text-sm">ครึ่งวันบ่าย</span>
            </label>
          </div>
        </div>

        <!-- Auto-calculated days -->
        <div v-if="calculatedDays !== null" class="flex flex-col gap-1 bg-[#800000]/5 border border-[#800000]/20 rounded-xl px-4 py-3">
          <div class="flex items-center gap-3">
            <svg class="w-5 h-5 text-[#800000] flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            <span class="text-sm text-gray-700">จำนวนวันทำการเบื้องต้น: <strong class="text-[#800000]">{{ calculatedDays }} วัน</strong> (ไม่นับเสาร์-อาทิตย์)</span>
          </div>
          <p class="text-xs text-gray-500 ml-8">* ระบบจะทำการคำนวณหักลบวันหยุดราชการเพิ่มเติมให้โดยอัตโนมัติตามประเภทการลาเมื่อส่งคำขอ</p>
        </div>

        <!-- เตือนเมื่อจำนวนวันที่ขอลาเกินส่วนที่ได้รับเงินเดือน -->
        <div v-if="unpaidWarningDays > 0" class="flex items-start gap-3 bg-amber-50 border border-amber-300 rounded-xl px-4 py-3">
          <svg class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86a2 2 0 001.71-3.03l-6.93-11a2 2 0 00-3.42 0l-6.93 11A2 2 0 005.07 19z"/></svg>
          <div class="text-sm text-amber-900">
            <p class="font-semibold">การลาครั้งนี้จะมี {{ unpaidWarningDays }} วันที่ไม่ได้รับเงินเดือน</p>
            <p class="text-xs mt-0.5">สิทธิ์ลาโดยได้รับเงินเดือนของท่านเหลืออีก {{ selectedBalance?.paid_remaining }} วัน ส่วนที่เกินต้องได้รับอนุญาตจากผู้บังคับบัญชาและจะไม่ได้รับเงินเดือนระหว่างลา</p>
          </div>
        </div>

        <!-- Reason -->
        <div>
          <label for="reason" class="block text-sm font-semibold text-gray-700 mb-1.5">เหตุผลการลา <span class="text-red-500">*</span></label>
          <textarea v-model="form.reason" id="reason" required rows="4" maxlength="1000"
            class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 text-gray-900 focus:outline-none focus:border-[#800000] transition-colors resize-none"
            placeholder="ระบุเหตุผลการลา..."></textarea>
          <p class="text-xs text-gray-400 mt-1 text-right">{{ form.reason.length }}/1000</p>
        </div>

        <!-- Contact Address -->
        <div>
          <label for="contact_address" class="block text-sm font-semibold text-gray-700 mb-1.5">สถานที่ติดต่อระหว่างลา <span class="text-red-500">*</span></label>
          <textarea v-model="form.contact_address" id="contact_address" required rows="2" maxlength="1000"
            class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 text-gray-900 focus:outline-none focus:border-[#800000] transition-colors resize-none"
            placeholder="ระบุสถานที่ที่สามารถติดต่อได้ระหว่างการลา..."></textarea>
        </div>

        <!-- Extra Fields for Specific Leave Types -->
        <div v-if="selectedLeaveTypeName === 'ลาอุปสมบท'" class="bg-blue-50 rounded-xl p-4 border border-blue-200 space-y-4">
          <h3 class="text-sm font-semibold text-blue-800">ข้อมูลเพิ่มเติม: ลาอุปสมบท</h3>
          <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">เคยอุปสมบทมาก่อนหรือไม่</label>
            <div class="flex gap-4">
              <label class="flex items-center gap-2 text-sm"><input type="radio" v-model="form.meta_data.ordain_status" value="ยังไม่เคย"> ยังไม่เคย</label>
              <label class="flex items-center gap-2 text-sm"><input type="radio" v-model="form.meta_data.ordain_status" value="เคย"> เคย</label>
            </div>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">จะอุปสมบท ณ วัด</label>
              <input type="text" v-model="form.meta_data.temple_name" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ตั้งอยู่ ณ (ที่อยู่)</label>
              <input type="text" v-model="form.meta_data.temple_location" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">กำหนดอุปสมบทวันที่</label>
              <ThaiDatePicker v-model="form.meta_data.ordain_date" />
            </div>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">จะจำพรรษาอยู่ ณ วัด</label>
              <input type="text" v-model="form.meta_data.stay_temple_name" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ตั้งอยู่ ณ (ที่อยู่)</label>
              <input type="text" v-model="form.meta_data.stay_temple_location" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
          </div>
        </div>

        <div v-else-if="selectedLeaveTypeName === 'ลาไปประกอบพิธีฮัจญ์'" class="bg-blue-50 rounded-xl p-4 border border-blue-200 space-y-4">
          <h3 class="text-sm font-semibold text-blue-800">ข้อมูลเพิ่มเติม: ลาไปประกอบพิธีฮัจญ์</h3>
          <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">เคยไปประกอบพิธีฮัจญ์มาก่อนหรือไม่</label>
            <div class="flex gap-4">
              <label class="flex items-center gap-2 text-sm"><input type="radio" v-model="form.meta_data.hajj_status" value="ยังไม่เคย"> ยังไม่เคย</label>
              <label class="flex items-center gap-2 text-sm"><input type="radio" v-model="form.meta_data.hajj_status" value="เคย"> เคย</label>
            </div>
          </div>
        </div>

        <div v-else-if="selectedLeaveTypeName === 'ลาติดตามคู่สมรส'" class="bg-blue-50 rounded-xl p-4 border border-blue-200 space-y-4">
          <h3 class="text-sm font-semibold text-blue-800">ข้อมูลเพิ่มเติม: ลาติดตามคู่สมรส</h3>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ชื่อคู่สมรส</label>
              <input type="text" v-model="form.meta_data.spouse_name" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ตำแหน่งคู่สมรส</label>
              <input type="text" v-model="form.meta_data.spouse_position" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">สังกัด</label>
              <input type="text" v-model="form.meta_data.spouse_department" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ไปปฏิบัติราชการ ณ ประเทศ</label>
              <input type="text" v-model="form.meta_data.spouse_country" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
          </div>
          <hr class="border-blue-200 my-2">
          <p class="text-xs text-blue-700 font-semibold">ข้อมูลการติดตามคู่สมรสครั้งก่อน (ถ้ามี)</p>
          <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">ประเทศที่เคยติดตามไป</label>
            <input type="text" v-model="form.meta_data.last_follow_country" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">รวมระยะเวลา</label>
              <input type="text" v-model="form.meta_data.last_follow_duration" placeholder="เช่น 1 ปี 2 เดือน" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ตั้งแต่วันที่</label>
              <ThaiDatePicker v-model="form.meta_data.last_follow_start" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ถึงวันที่</label>
              <ThaiDatePicker v-model="form.meta_data.last_follow_end" />
            </div>
          </div>
        </div>

        <div v-else-if="selectedLeaveTypeName === 'ลาไปศึกษาต่อ ดูงาน ฝึกอบรม'" class="bg-blue-50 rounded-xl p-4 border border-blue-200 space-y-4">
          <h3 class="text-sm font-semibold text-blue-800">ข้อมูลเพิ่มเติม: ลาไปศึกษาต่อ ดูงาน ฝึกอบรม</h3>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ระดับ (ปริญญา/หลักสูตร)</label>
              <input type="text" v-model="form.meta_data.study_level" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">สถานที่/สาขาวิชา</label>
              <input type="text" v-model="form.meta_data.study_subject" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ประเทศ</label>
              <input type="text" v-model="form.meta_data.study_country" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ด้วยทุน</label>
              <input type="text" v-model="form.meta_data.study_funding" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
          </div>
        </div>

        <!-- Substitute / Handover -->
        <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
          <h3 class="text-sm font-semibold text-gray-800 mb-3">ผู้รับมอบหมายงาน (ถ้ามี)</h3>
          <div class="space-y-4">
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">เลือกผู้รักษาราชการแทน</label>
              <select v-model="form.substitute_id" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:border-[#800000] bg-white">
                <option value="">-- ไม่ระบุ --</option>
                <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }} ({{ user.department?.name || 'ไม่ระบุหน่วยงาน' }})</option>
              </select>
            </div>
            <div v-if="form.substitute_id">
              <label class="block text-xs font-semibold text-gray-700 mb-1">งานที่มอบหมาย</label>
              <textarea v-model="form.substitute_reason" rows="2" maxlength="1000"
                class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:border-[#800000] resize-none"
                placeholder="ระบุรายละเอียดงานที่มอบหมายให้รับผิดชอบแทน..."></textarea>
            </div>
          </div>
        </div>

        <!-- Signature Section -->
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1.5">ลายเซ็น <span class="text-red-500">*</span></label>
          <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
            <div class="flex flex-col md:flex-row gap-6">
              <div class="flex flex-col gap-2 md:w-1/3 border-r border-gray-200 pr-4">
                <label class="flex items-center gap-2 text-sm cursor-pointer">
                  <input type="radio" v-model="sigType" value="profile" class="text-red-600 focus:ring-red-500">
                  ใช้ลายเซ็นเดิมจากโปรไฟล์
                </label>
                <label class="flex items-center gap-2 text-sm cursor-pointer">
                  <input type="radio" v-model="sigType" value="draw" class="text-red-600 focus:ring-red-500">
                  วาดลายเซ็นใหม่ (ใช้เฉพาะใบลานี้)
                </label>
              </div>
              <div class="md:w-2/3 flex flex-col items-center">
                <div v-if="sigType === 'profile'" class="w-full max-w-xs h-24 bg-white border border-gray-200 rounded-lg flex items-center justify-center">
                  <img v-if="authStore.user?.profile?.signature_path" v-secure-src="authStore.user.profile.signature_path" alt="รูปลายเซ็นจากโปรไฟล์ผู้ใช้งาน" class="max-h-full object-contain p-2" />
                  <span v-else class="text-gray-400 text-sm">ไม่พบลายเซ็นในโปรไฟล์</span>
                </div>
                <div v-else class="w-full flex flex-col items-center">
                  <canvas ref="sigCanvas" width="300" height="120" role="img" aria-label="กระดานวาดลายเซ็นดิจิทัล" class="border border-gray-300 bg-white rounded shadow-inner cursor-crosshair touch-none"
                    @mousedown="startDraw" @mousemove="draw" @mouseup="endDraw" @mouseout="endDraw"
                    @touchstart="startDrawTouch" @touchmove="drawTouch" @touchend="endDraw"></canvas>
                  <div class="mt-2 flex gap-2 w-full max-w-[300px] justify-between">
                    <button type="button" @click="clearCanvas" class="px-3 py-1 text-xs font-semibold bg-gray-200 hover:bg-gray-300 text-gray-700 rounded transition-colors focus-visible:ring-2 focus-visible:ring-[#800000]">ล้างลายเซ็น</button>
                    <span v-if="canvasSaved" class="text-xs text-green-600 flex items-center gap-1"><i class="fas fa-check-circle" aria-hidden="true"></i> วาดเสร็จแล้ว</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Attachment -->
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1.5">เอกสารแนบ (ถ้ามี)</label>
          <div
            class="border-2 border-dashed border-gray-200 rounded-xl p-4 text-center hover:border-[#800000] transition-colors cursor-pointer focus-visible:ring-2 focus-visible:ring-[#800000] focus:outline-none"
            role="button"
            tabindex="0"
            aria-label="คลิกหรือกด Enter เพื่อเลือกแนบไฟล์เอกสารประกอบการลา"
            @click="$refs.fileInput.click()"
            @keydown.enter.prevent="$refs.fileInput.click()"
            @keydown.space.prevent="$refs.fileInput.click()"
          >
            <input ref="fileInput" type="file" accept=".pdf,.jpg,.jpeg,.png" @change="handleFile" class="hidden" aria-hidden="true">
            <svg class="w-8 h-8 text-gray-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
            <p class="text-sm text-gray-500">{{ fileName || 'คลิกเพื่อแนบไฟล์ (PDF, JPG, PNG ไม่เกิน 5MB)' }}</p>
          </div>
        </div>

        <div class="flex justify-end pt-2">
          <button type="submit" id="submit-leave" :disabled="loading" class="w-full sm:w-auto px-8 py-3.5 bg-[#800000] hover:bg-[#600000] text-white font-bold rounded-xl shadow-md transition-all hover:shadow-lg disabled:opacity-50 flex items-center justify-center gap-2 text-base sm:text-sm min-h-[48px] focus-visible:ring-2 focus-visible:ring-[#800000]">
            <span v-if="loading" class="animate-spin rounded-full h-4 w-4 border-b-2 border-white" aria-hidden="true"></span>
            {{ loading ? 'กำลังส่ง...' : 'ส่งคำขอลา' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useLeaveStore } from '../stores/leave'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useAlertStore } from '../stores/alert'
import ThaiDatePicker from '../components/ThaiDatePicker.vue'
import api from '../axios'

const authStore = useAuthStore()

const leaveStore = useLeaveStore()
const router = useRouter()
const alertStore = useAlertStore()
const loading = ref(false)
const calculatedDays = ref(null)
const fileName = ref('')
const fileInput = ref(null)
const selectedFile = ref(null)
const users = ref([])

const sigType = ref('profile')
const sigCanvas = ref(null)
const sigBase64 = ref(null)
let isDrawing = false
let ctx = null
const canvasSaved = ref(false)

const getImageUrl = (path) => {
  if (!path) return '';
  if (path.startsWith('http')) return path;
  const base = api.defaults.baseURL?.replace('/api', '') || '';
  return `${base}${path.startsWith('/') ? '' : '/'}${path}`;
}

const selectedLeaveTypeName = computed(() => {
  return leaveStore.types.find(t => t.id === form.leave_type_id)?.name || ''
})

const selectedBalance = computed(() => {
  if (!form.leave_type_id) return null
  return leaveStore.balances.find(b => b.leave_type_id === form.leave_type_id || b.type?.id === form.leave_type_id) || null
})

const remainingDays = computed(() => {
  if (!selectedBalance.value) return 0
  const b = selectedBalance.value
  return (b.quota + (b.carried_forward || 0)) - b.used - (b.pending || 0)
})

// จำนวนวันของคำขอนี้ที่จะตกเป็นวันไม่ได้รับเงินเดือน (เกินเพดาน max_days_with_pay)
const unpaidWarningDays = computed(() => {
  const b = selectedBalance.value
  if (!b || b.paid_remaining === null || b.paid_remaining === undefined) return 0
  if (!calculatedDays.value) return 0
  return Math.max(0, calculatedDays.value - b.paid_remaining)
})

const onLeaveTypeChange = () => {
  // Reset date calculation if needed
  calcDays()
}


const form = reactive({
  leave_type_id: '',
  start_date: '',
  end_date: '',
  leave_period: 'full_day',
  reason: '',
  contact_address: '',
  substitute_id: '',
  substitute_reason: '',
  meta_data: {
    ordain_status: 'ยังไม่เคย',
    temple_name: '',
    temple_location: '',
    ordain_date: '',
    stay_temple_name: '',
    stay_temple_location: '',
    hajj_status: 'ยังไม่เคย',
    spouse_name: '',
    spouse_position: '',
    spouse_department: '',
    spouse_country: '',
    last_follow_country: '',
    last_follow_duration: '',
    last_follow_start: '',
    last_follow_end: '',
    study_level: '',
    study_subject: '',
    study_country: '',
    study_funding: ''
  }
})

onMounted(async () => {
  await Promise.all([
    leaveStore.fetchTypes(),
    leaveStore.fetchBalances()
  ])
  try {
    const res = await api.get('/users/list')
    // Exclude self
    users.value = res.data.filter(u => u.id !== authStore.user?.id)
  } catch (e) {
    console.error('Failed to load users for substitute dropdown')
  }

  // Setup Canvas Context initially in case 'draw' is selected later
  setupCanvas()
})


import { watch } from 'vue'
watch(sigType, (val) => {
  if (val === 'draw') {
    setTimeout(setupCanvas, 50)
  }
})

const setupCanvas = () => {
  if (sigCanvas.value) {
    ctx = sigCanvas.value.getContext('2d')
    ctx.lineWidth = 2
    ctx.lineCap = 'round'
    ctx.strokeStyle = '#000000'
  }
}

// Canvas Methods
const getCanvasPos = (evt) => {
  if (!sigCanvas.value) return { x: 0, y: 0 }
  const rect = sigCanvas.value.getBoundingClientRect()
  const x = (evt.clientX || evt.touches?.[0]?.clientX) - rect.left
  const y = (evt.clientY || evt.touches?.[0]?.clientY) - rect.top
  return { x, y }
}

const startDraw = (e) => {
  isDrawing = true
  canvasSaved.value = false
  sigBase64.value = null
  const { x, y } = getCanvasPos(e)
  if (ctx) {
    ctx.beginPath()
    ctx.moveTo(x, y)
  }
}

const startDrawTouch = (e) => {
  e.preventDefault()
  startDraw(e)
}

const draw = (e) => {
  if (!isDrawing || !ctx) return
  const { x, y } = getCanvasPos(e)
  ctx.lineTo(x, y)
  ctx.stroke()
}

const drawTouch = (e) => {
  e.preventDefault()
  draw(e)
}

const endDraw = () => {
  if (isDrawing && ctx) {
    ctx.closePath()
    isDrawing = false
    saveCanvas()
  }
}

const clearCanvas = () => {
  if (ctx && sigCanvas.value) {
    ctx.clearRect(0, 0, sigCanvas.value.width, sigCanvas.value.height)
    canvasSaved.value = false
    sigBase64.value = null
  }
}

const saveCanvas = () => {
  if (sigCanvas.value) {
    sigBase64.value = sigCanvas.value.toDataURL('image/png')
    canvasSaved.value = true
  }
}

// When start date changes, clear end_date if it's before start_date
const onStartDateChange = () => {
  if (form.leave_period !== 'full_day') {
    form.end_date = form.start_date
  } else if (form.end_date && form.start_date && form.end_date < form.start_date) {
    form.end_date = ''
  }
  calcDays()
}

const onPeriodChange = () => {
  if (form.leave_period !== 'full_day') {
    form.end_date = form.start_date
  }
  calcDays()
}

const calcDays = () => {
  if (!form.start_date || !form.end_date) { calculatedDays.value = null; return }
  if (form.leave_period !== 'full_day') {
    calculatedDays.value = 0.5
    return
  }
  const start = new Date(form.start_date)
  const end   = new Date(form.end_date)
  if (end < start) { calculatedDays.value = null; return }
  let count = 0
  const cur = new Date(start)
  while (cur <= end) {
    const day = cur.getDay()
    if (day !== 0 && day !== 6) count++
    cur.setDate(cur.getDate() + 1)
  }
  calculatedDays.value = count || 0.5
}

const handleFile = (e) => {
  const file = e.target.files[0]
  if (file) {
    selectedFile.value = file
    fileName.value = file.name
  }
}

const handleSubmit = async () => {
  loading.value = true
  try {
    const formData = new FormData()
    formData.append('leave_type_id', form.leave_type_id)
    formData.append('start_date', form.start_date)
    formData.append('end_date', form.end_date)
    formData.append('leave_period', form.leave_period)
    formData.append('reason', form.reason)
    formData.append('contact_address', form.contact_address)
    if (form.substitute_id) {
      formData.append('substitute_id', form.substitute_id)
      formData.append('substitute_reason', form.substitute_reason)
    }
    
    // Check if it's one of the specialized forms, if so pass meta_data
    const specializedTypes = ['ลาอุปสมบท', 'ลาไปประกอบพิธีฮัจญ์', 'ลาติดตามคู่สมรส', 'ลาไปศึกษาต่อ ดูงาน ฝึกอบรม']
    if (specializedTypes.includes(selectedLeaveTypeName.value)) {
      formData.append('meta_data', JSON.stringify(form.meta_data))
    }

    if (selectedFile.value) formData.append('attachment', selectedFile.value)

    if (sigType.value === 'draw' && sigBase64.value) {
      formData.append('signature_base64', sigBase64.value)
    }

    await leaveStore.submitLeaveFormData(formData)
    alertStore.success('ส่งคำขอลาเรียบร้อยแล้ว! กำลังกลับสู่หน้าหลัก...')
    setTimeout(() => router.push('/'), 1500)
  } catch (error) {
    const errors = error.response?.data?.errors
    if (errors) {
      alertStore.error(Object.values(errors).flat().join(' '))
    } else {
      alertStore.error(error.response?.data?.message || 'เกิดข้อผิดพลาด กรุณาลองใหม่')
    }
  } finally {
    loading.value = false
  }
}
</script>
