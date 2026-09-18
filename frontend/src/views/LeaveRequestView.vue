<template>
  <div class="max-w-2xl mx-auto">
    <!-- Breadcrumb Navigation -->
    <AppBreadcrumb
      :items="[
        { title: 'ระบบการลา', to: '/leaves' },
        { title: draftId ? 'แก้ไขร่างใบลา' : 'ยื่นใบลา' }
      ]"
      :backTo="'/leaves'"
    />

    <div class="mb-8">
      <h1 class="text-2xl font-bold text-gray-900">{{ draftId ? 'แก้ไขร่างใบลา' : 'ยื่นใบลา' }}</h1>
      <p class="text-gray-500 mt-1">
        {{ draftId ? 'แก้ไขแล้วบันทึกร่างไว้ก่อน หรือส่งอนุมัติได้เลย' : 'กรอกข้อมูลเพื่อส่งคำขอลา' }}
      </p>
    </div>

    <!-- Local Draft Detected Alert Banner -->
    <transition name="fade">
      <div v-if="hasDraft && !draftId" class="mb-6 p-4 rounded-2xl bg-amber-50 border border-amber-200 text-amber-900 flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-xs">
        <div class="flex items-center gap-2.5 text-sm">
          <span class="text-lg">📝</span>
          <div>
            <p class="font-bold">พบข้อมูลร่างที่เคยพิมพ์ไว้ในเครื่อง</p>
            <p class="text-xs text-amber-700">บันทึกอัตโนมัติล่าสุดเมื่อ {{ lastSavedAt }}</p>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <button type="button" @click="handleRestoreDraft" class="px-3.5 py-2 bg-[#800000] hover:bg-[#600000] text-white text-xs font-bold rounded-xl shadow-xs transition-colors flex items-center gap-1">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            กู้คืนข้อมูลร่าง
          </button>
          <button type="button" @click="clearDraft" class="px-3.5 py-2 bg-white hover:bg-amber-100 text-amber-800 border border-amber-200 text-xs font-semibold rounded-xl transition-colors">
            ล้างทิ้ง
          </button>
        </div>
      </div>
    </transition>

    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 md:p-8">
      <form @submit.prevent="handleSubmit" enctype="multipart/form-data" class="space-y-6">
        <!-- แถบแจ้งเตือนเกณฑ์ประเมินเลื่อนขั้นเงินเดือน ก.ค.ศ. (เกณฑ์ไม่เกิน ๒๓ วัน) -->
        <div v-if="currentCycleInfo" class="p-4 rounded-xl border transition-all"
          :class="currentCycleInfo.statusColor === 'red' ? 'bg-red-50 border-red-200 text-red-900' :
                  currentCycleInfo.statusColor === 'orange' ? 'bg-amber-50 border-amber-200 text-amber-900' :
                  'bg-emerald-50 border-emerald-200 text-emerald-900'">
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div class="flex items-center gap-2">
              <span class="w-3 h-3 rounded-full flex-shrink-0"
                :class="currentCycleInfo.statusColor === 'red' ? 'bg-red-500' :
                        currentCycleInfo.statusColor === 'orange' ? 'bg-amber-500' : 'bg-emerald-500'"></span>
              <span class="text-xs sm:text-sm font-bold">เกณฑ์วันลาประเมิน ก.ค.ศ. ({{ currentCycleInfo.cycleName }}):</span>
              <span class="text-xs font-semibold px-2 py-0.5 rounded-full"
                :class="currentCycleInfo.statusColor === 'red' ? 'bg-red-200 text-red-800' :
                        currentCycleInfo.statusColor === 'orange' ? 'bg-amber-200 text-amber-800' :
                        'bg-emerald-200 text-emerald-800'">
                {{ currentCycleInfo.statusLabel }}
              </span>
            </div>
            <div class="text-xs font-medium">
              ลาป่วย+ลากิจสะสม: <strong class="font-bold">{{ currentCycleInfo.totalUsed }}</strong> / ๒๓ วันทำการ
            </div>
          </div>
          <div class="w-full bg-gray-200 rounded-full h-2 mt-2.5 overflow-hidden">
            <div class="h-2 rounded-full transition-all duration-500"
              :class="currentCycleInfo.statusColor === 'red' ? 'bg-red-500' :
                      currentCycleInfo.statusColor === 'orange' ? 'bg-amber-500' : 'bg-emerald-500'"
              :style="{ width: `${Math.min(100, (currentCycleInfo.totalUsed / 23) * 100)}%` }"></div>
          </div>
        </div>

        <!-- Leave type -->
        <div>
          <label for="leave-type" class="block text-sm font-semibold text-gray-700 mb-1.5">ประเภทการลา <span class="text-red-500">*</span></label>
          <select v-model="form.leave_type_id" required id="leave-type" @change="onLeaveTypeChange" class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 text-gray-900 focus:outline-none focus:border-[#800000] transition-colors bg-white">
            <option value="" disabled>เลือกประเภทการลา...</option>
            <option v-for="type in eligibleLeaveTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
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
                <span class="text-xs text-gray-500 font-medium">อนุมัติแล้ว:</span>
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
              <!--
                หน้านี้ตัวเลขที่สำคัญที่สุดคือ "ยื่นได้อีก" เพราะเป็นตัวที่ตัดสินว่าใบนี้จะถูกรับหรือไม่
                ส่วน "คงเหลือตามสิทธิ์" (หักเฉพาะวันที่อนุมัติแล้ว) แสดงคู่กันเมื่อสองค่าต่างกัน
              -->
              <div v-if="!selectedBalance.unlimited" class="flex items-center gap-1.5">
                <span class="text-xs text-gray-500 font-medium">คงเหลือตามสิทธิ์:</span>
                <span class="text-sm font-bold text-gray-800">{{ approvedRemaining }} วัน</span>
              </div>
              <div v-if="!selectedBalance.unlimited" class="w-px h-4 bg-gray-300"></div>
              <div v-if="!selectedBalance.unlimited" class="flex items-center gap-1.5">
                <span class="text-xs text-gray-500 font-medium">ยื่นได้อีก:</span>
                <span class="text-sm font-bold px-2.5 py-0.5 rounded-full" :class="remainingDays <= 2 ? 'bg-red-100 text-red-700' : remainingDays <= 5 ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700'">
                  {{ remainingDays }} วัน
                </span>
              </div>
            </div>
          </transition>

          <!--
            หลักเกณฑ์ของนโยบายที่ใช้กับประเภทการลานี้
            เดิมผู้ใช้กรอกจนเสร็จแล้วค่อยถูกปฏิเสธตอนกดส่ง (ต้องแนบหลักฐาน / ลาครึ่งวันไม่ได้ /
            เกินเพดานต่อครั้ง / ยื่นล่วงหน้าไม่พอ) ทั้งที่กติกาเหล่านี้มากับข้อมูลสิทธิอยู่แล้ว
          -->
          <transition name="fade">
            <ul v-if="policyRules.length" class="mt-2 flex flex-wrap gap-x-4 gap-y-1 px-4 text-xs text-gray-500">
              <li v-for="rule in policyRules" :key="rule.text" class="flex items-center gap-1"
                :class="rule.active ? 'text-amber-700 font-semibold' : ''">
                <span aria-hidden="true">•</span>{{ rule.text }}
              </li>
            </ul>
          </transition>
        </div>


        <!--
          ช่องกรอกเฉพาะแบบฟอร์มของประเภทการลา
          นิยามมาจากหน้าตั้งค่าประเภทการลา (leave_types.extra_fields)
          ค่าที่กรอกจะถูกเติมลงช่องว่างของแบบฟอร์มราชการตอนพิมพ์ โดยไม่ต้องแก้โค้ด
        -->
        <div v-if="extraFieldDefinitions.length" class="space-y-4 rounded-xl border border-gray-200 p-4">
          <h2 class="text-sm font-bold text-gray-700">ข้อมูลเพิ่มเติมสำหรับแบบฟอร์ม{{ selectedLeaveTypeName }}</h2>
          <div v-for="field in extraFieldDefinitions" :key="field.key">
            <label :for="`extra-${field.key}`" class="block text-sm font-semibold text-gray-700 mb-1.5">
              {{ field.label }}
              <span v-if="field.required" class="text-red-500">*</span>
            </label>
            <select v-if="field.type === 'select'" :id="`extra-${field.key}`" v-model="extraValues[field.key]" :required="!!field.required"
              class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 text-gray-900 focus:outline-none focus:border-[#800000] bg-white">
              <option value="">เลือก...</option>
              <option v-for="option in field.options || []" :key="option" :value="option">{{ option }}</option>
            </select>
            <textarea v-else-if="field.type === 'textarea'" :id="`extra-${field.key}`" v-model="extraValues[field.key]" :required="!!field.required" rows="2"
              class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 text-gray-900 focus:outline-none focus:border-[#800000]"></textarea>
            <ThaiDatePicker v-else-if="field.type === 'date'" v-model="extraValues[field.key]" :id="`extra-${field.key}`" />
            <input v-else :id="`extra-${field.key}`" v-model="extraValues[field.key]" :type="field.type === 'number' ? 'number' : 'text'" :required="!!field.required"
              class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 text-gray-900 focus:outline-none focus:border-[#800000]">
          </div>
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
          <!--
            ซ่อนตัวเลือกครึ่งวันเมื่อนโยบายของประเภทนี้ไม่อนุญาต
            เดิมเลือกได้เสมอ แล้วถูกปฏิเสธตอนกดส่งว่า "การลาประเภทนี้ไม่อนุญาตให้ลาครึ่งวัน"
          -->
          <div class="grid gap-2 sm:gap-4" :class="allowsHalfDay ? 'grid-cols-3' : 'grid-cols-1'">
            <label class="flex items-center justify-center gap-2 p-3 rounded-xl border-2 transition-all cursor-pointer select-none text-center min-h-[44px]" :class="form.leave_period === 'full_day' ? 'border-[#800000] bg-[#800000]/5 text-[#800000] font-bold shadow-sm' : 'border-gray-200 text-gray-600 hover:border-gray-300'">
              <input type="radio" v-model="form.leave_period" value="full_day" @change="onPeriodChange" class="sr-only">
              <span class="text-xs sm:text-sm">เต็มวัน</span>
            </label>
            <label v-if="allowsHalfDay" class="flex items-center justify-center gap-2 p-3 rounded-xl border-2 transition-all cursor-pointer select-none text-center min-h-[44px]" :class="form.leave_period === 'morning' ? 'border-[#800000] bg-[#800000]/5 text-[#800000] font-bold shadow-sm' : 'border-gray-200 text-gray-600 hover:border-gray-300'">
              <input type="radio" v-model="form.leave_period" value="morning" @change="onPeriodChange" class="sr-only">
              <span class="text-xs sm:text-sm">ครึ่งวันเช้า</span>
            </label>
            <label v-if="allowsHalfDay" class="flex items-center justify-center gap-2 p-3 rounded-xl border-2 transition-all cursor-pointer select-none text-center min-h-[44px]" :class="form.leave_period === 'afternoon' ? 'border-[#800000] bg-[#800000]/5 text-[#800000] font-bold shadow-sm' : 'border-gray-200 text-gray-600 hover:border-gray-300'">
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
              <input aria-label="จะอุปสมบท ณ วัด" type="text" v-model="form.meta_data.temple_name" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ตั้งอยู่ ณ (ที่อยู่)</label>
              <input aria-label="ตั้งอยู่ ณ (ที่อยู่)" type="text" v-model="form.meta_data.temple_location" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
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
              <input aria-label="จะจำพรรษาอยู่ ณ วัด" type="text" v-model="form.meta_data.stay_temple_name" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ตั้งอยู่ ณ (ที่อยู่)</label>
              <input aria-label="ตั้งอยู่ ณ (ที่อยู่)" type="text" v-model="form.meta_data.stay_temple_location" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
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
              <input aria-label="ชื่อคู่สมรส" type="text" v-model="form.meta_data.spouse_name" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ตำแหน่งคู่สมรส</label>
              <input aria-label="ตำแหน่งคู่สมรส" type="text" v-model="form.meta_data.spouse_position" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">สังกัด</label>
              <input aria-label="สังกัด" type="text" v-model="form.meta_data.spouse_department" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ไปปฏิบัติราชการ ณ ประเทศ</label>
              <input aria-label="ไปปฏิบัติราชการ ณ ประเทศ" type="text" v-model="form.meta_data.spouse_country" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
          </div>
          <hr class="border-blue-200 my-2">
          <p class="text-xs text-blue-700 font-semibold">ข้อมูลการติดตามคู่สมรสครั้งก่อน (ถ้ามี)</p>
          <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">ประเทศที่เคยติดตามไป</label>
            <input aria-label="ประเทศที่เคยติดตามไป" type="text" v-model="form.meta_data.last_follow_country" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">รวมระยะเวลา</label>
              <input aria-label="รวมระยะเวลา" type="text" v-model="form.meta_data.last_follow_duration" placeholder="เช่น 1 ปี 2 เดือน" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
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
              <input aria-label="ระดับ (ปริญญา/หลักสูตร)" type="text" v-model="form.meta_data.study_level" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">สถานที่/สาขาวิชา</label>
              <input aria-label="สถานที่/สาขาวิชา" type="text" v-model="form.meta_data.study_subject" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ประเทศ</label>
              <input aria-label="ประเทศ" type="text" v-model="form.meta_data.study_country" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ด้วยทุน</label>
              <input aria-label="ด้วยทุน" type="text" v-model="form.meta_data.study_funding" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
          </div>
        </div>

        <!-- Substitute / Handover -->
        <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
          <h3 class="text-sm font-semibold text-gray-800 mb-3">ผู้รับมอบหมายงาน (ถ้ามี)</h3>
          <div class="space-y-4">
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">เลือกผู้รักษาราชการแทน</label>
              <select aria-label="เลือกผู้รักษาราชการแทน" v-model="form.substitute_id" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:border-[#800000] bg-white">
                <option value="">-- ไม่ระบุ --</option>
                <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }} ({{ user.department?.name || 'ไม่ระบุหน่วยงาน' }})</option>
              </select>
            </div>
            <div v-if="form.substitute_id">
              <label class="block text-xs font-semibold text-gray-700 mb-1">งานที่มอบหมาย</label>
              <textarea aria-label="งานที่มอบหมาย" v-model="form.substitute_reason" rows="2" maxlength="1000"
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
                    <span v-if="canvasSaved" class="text-xs text-green-600 flex items-center gap-1">
                      <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.check"/>
                      </svg>
                      วาดเสร็จแล้ว
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Attachment with FileUploadZone -->
        <div>
          <FileUploadZone
            v-model="selectedFile"
            :label="evidenceRequired ? `เอกสารแนบ (${evidenceLabel})` : 'เอกสารแนบ (ถ้ามี)'"
            :required="evidenceRequired"
            :accept="'.pdf,.jpg,.jpeg,.png'"
            :maxSizeMb="5"
          />
        </div>

        <!-- Auto-Save status & Draft control -->
        <div v-if="!draftId" class="flex items-center justify-between text-xs text-gray-500 pt-1">
          <div class="flex items-center gap-2">
            <span
              class="w-2 h-2 rounded-full"
              :class="isSaving ? 'bg-amber-400 animate-ping' : lastSavedAt ? 'bg-emerald-500' : 'bg-gray-300'"
            ></span>
            <span>
              {{ isSaving ? 'กำลังบันทึกร่างลงในเครื่อง...' : lastSavedAt ? `บันทึกร่างอัตโนมัติแล้วเมื่อ ${lastSavedAt}` : 'ระบบบันทึกร่างอัตโนมัติในเครื่อง' }}
            </span>
          </div>
          <button
            v-if="lastSavedAt"
            type="button"
            @click="clearDraft"
            class="text-gray-400 hover:text-red-600 transition-colors"
          >
            ล้างร่างในเครื่อง
          </button>
        </div>

        <!-- Submit & Draft Action Buttons -->
        <div class="flex flex-col sm:flex-row sm:justify-end gap-3 pt-2">
          <button type="button" :disabled="loading" @click="handleSubmit(true)"
            class="w-full sm:w-auto px-6 py-3.5 border border-gray-300 text-gray-700 font-semibold rounded-xl transition-colors hover:bg-gray-50 disabled:opacity-50 flex items-center justify-center gap-2 text-base sm:text-sm min-h-[48px] focus-visible:ring-2 focus-visible:ring-gray-400">
            <span v-if="savingDraft" class="animate-spin rounded-full h-4 w-4 border-b-2 border-gray-500" aria-hidden="true"></span>
            {{ savingDraft ? 'กำลังบันทึก...' : 'บันทึกร่างบนเซิร์ฟเวอร์' }}
          </button>
          <button type="submit" id="submit-leave" :disabled="loading" class="w-full sm:w-auto px-8 py-3.5 bg-[#800000] hover:bg-[#600000] text-white font-bold rounded-xl shadow-md transition-all hover:shadow-lg disabled:opacity-50 flex items-center justify-center gap-2 text-base sm:text-sm min-h-[48px] focus-visible:ring-2 focus-visible:ring-[#800000]">
            <span v-if="loading && !savingDraft" class="animate-spin rounded-full h-4 w-4 border-b-2 border-white" aria-hidden="true"></span>
            {{ loading && !savingDraft ? 'กำลังส่ง...' : 'ส่งคำขอลา' }}
          </button>
        </div>
        <p class="text-xs text-gray-400 text-right pt-1">
          ใบลาที่ส่งแล้วจะออกเลขที่หนังสือและลบไม่ได้ — หากยังไม่แน่ใจให้บันทึกเป็นร่างไว้ก่อน
        </p>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useLeaveStore } from '../stores/leave'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useAlertStore } from '../stores/alert'
import ThaiDatePicker from '../components/ThaiDatePicker.vue'
import AppBreadcrumb from '../components/ui/AppBreadcrumb.vue'
import FileUploadZone from '../components/ui/FileUploadZone.vue'
import { useFormAutoSave } from '../composables/useFormAutoSave'
import api from '../axios'
import { ICONS } from '../utils/icons'

const authStore = useAuthStore()

const leaveStore = useLeaveStore()
const router = useRouter()
const route = useRoute()
// id ของร่างที่กำลังแก้ — ว่างแปลว่ากำลังยื่นใบใหม่
const draftId = ref(null)
const alertStore = useAlertStore()
const loading = ref(false)
const savingDraft = ref(false)
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

const eligibleLeaveTypes = computed(() => {
  const allTypes = leaveStore.types || []
  const user = authStore.user || {}
  const gender = (user.gender || '').toLowerCase()

  return allTypes.filter(type => {
    const name = type.name || ''
    // บุคลากรเพศชาย: ซ่อน "ลาคลอดบุตร"
    if (gender === 'male' || gender === 'ชาย' || gender === 'm') {
      if (name.includes('คลอดบุตร') && !name.includes('ช่วยเหลือภริยา')) {
        return false
      }
    }
    // บุคลากรเพศหญิง: ซ่อน "ลาอุปสมบท" และ "ลาไปช่วยเหลือภริยาที่คลอดบุตร"
    if (gender === 'female' || gender === 'หญิง' || gender === 'f') {
      if (name.includes('อุปสมบท') || name.includes('ช่วยเหลือภริยา')) {
        return false
      }
    }
    return true
  })
})

const currentCycleInfo = computed(() => {
  const now = new Date()
  const month = now.getMonth() + 1
  const year = now.getFullYear()
  const thaiYear = year + 543

  let cycleName = ''
  let startDate = ''
  let endDate = ''

  if (month >= 4 && month <= 9) {
    cycleName = `รอบที่ ๒ (๑ เม.ย. – ๓๐ ก.ย. ${thaiYear})`
    startDate = `${year}-04-01`
    endDate = `${year}-09-30`
  } else if (month >= 10) {
    cycleName = `รอบที่ ๑ (๑ ต.ค. ${thaiYear} – ๓๑ มี.ค. ${thaiYear + 1})`
    startDate = `${year}-10-01`
    endDate = `${year + 1}-03-31`
  } else {
    cycleName = `รอบที่ ๑ (๑ ต.ค. ${thaiYear - 1} – ๓๑ มี.ค. ${thaiYear})`
    startDate = `${year - 1}-10-01`
    endDate = `${year}-03-31`
  }

  const sickBalance = leaveStore.balances.find(b => (b.type?.name || '').includes('ป่วย'))
  const personalBalance = leaveStore.balances.find(b => (b.type?.name || '').includes('กิจส่วนตัว'))

  const totalUsed = Number(sickBalance?.used || 0) + Number(personalBalance?.used || 0)
  const remainingThreshold = Math.max(0, 23 - totalUsed)

  let statusColor = 'green'
  let statusLabel = 'ปกติ'
  if (totalUsed >= 23) {
    statusColor = 'red'
    statusLabel = 'แตะหรือเกินเกณฑ์ ๒๓ วัน'
  } else if (totalUsed >= 16) {
    statusColor = 'orange'
    statusLabel = 'ใกล้แตะเพดาน ๒๓ วัน'
  }

  return {
    cycleName,
    startDate,
    endDate,
    totalUsed,
    remainingThreshold,
    statusColor,
    statusLabel,
  }
})

const selectedLeaveTypeName = computed(() => {
  return leaveStore.types.find(t => t.id === form.leave_type_id)?.name || ''
})

const selectedBalance = computed(() => {
  if (!form.leave_type_id) return null
  return leaveStore.balances.find(b => b.leave_type_id === form.leave_type_id || b.type?.id === form.leave_type_id) || null
})

/*
 * คงเหลือตามสิทธิ์ — หักเฉพาะวันที่ได้รับอนุมัติแล้วจริง เป็นตัวเลขทางทะเบียน
 * ที่ไม่ขยับตอนกดยื่น
 */
const approvedRemaining = computed(() => {
  if (!selectedBalance.value) return 0
  const b = selectedBalance.value

  return (Number(b.quota || 0) + Number(b.carried_forward || 0)) - Number(b.used || 0)
})

/*
 * ยื่นได้อีก — หักวันที่กันไว้รออนุมัติออกด้วย เป็นตัวเลขที่ฝั่ง server ใช้ตัดสินจริง
 * ว่าจะรับใบลาใบถัดไปหรือไม่ จึงต้องเป็นตัวที่ใช้เตือนผู้ใช้บนหน้านี้
 */
const remainingDays = computed(() => {
  if (!selectedBalance.value) return 0

  return approvedRemaining.value - Number(selectedBalance.value.pending || 0)
})

/*
 * หลักเกณฑ์ของนโยบายที่ผู้ยื่นต้องรู้ "ก่อน" กรอก
 *
 * ทุกข้อมาจากนโยบายที่แนบมากับข้อมูลสิทธิอยู่แล้ว (`/leaves/balances`) ไม่ใช่ค่าที่ตั้งตายไว้
 * `active` = ข้อที่คำขอปัจจุบันกำลังชนเกณฑ์ ใช้เน้นให้เห็นก่อนกดส่ง
 */
const policyRules = computed(() => {
  const policy = selectedBalance.value?.policy

  if (!policy) return []

  const rules = []
  const days = Number(form.total_days || 0)

  if (policy.attachment_required_after_days !== null && policy.attachment_required_after_days !== undefined) {
    const threshold = Number(policy.attachment_required_after_days)
    rules.push({
      text: `ต้องแนบ${evidenceLabel.value}เมื่อลาตั้งแต่ ${threshold} วันขึ้นไป`,
      active: days >= threshold && !selectedFile.value,
    })
  }

  if (policy.max_days_per_request) {
    rules.push({
      text: `ลาได้ครั้งละไม่เกิน ${Number(policy.max_days_per_request)} วัน`,
      active: days > Number(policy.max_days_per_request),
    })
  }

  if (Number(policy.notice_period_days) > 0) {
    rules.push({ text: `ต้องยื่นล่วงหน้าอย่างน้อย ${Number(policy.notice_period_days)} วัน`, active: false })
  }

  if (policy.allow_half_day === false) {
    rules.push({ text: 'ประเภทนี้ลาครึ่งวันไม่ได้', active: form.leave_period !== 'full_day' })
  }

  if (policy.allow_past_dates === false) {
    rules.push({ text: 'ยื่นย้อนหลังไม่ได้', active: false })
  }

  return rules
})

/** ประเภทนี้ลาครึ่งวันได้หรือไม่ — ปิดตัวเลือกไปเลยดีกว่าให้เลือกแล้วถูกปฏิเสธตอนส่ง */
const allowsHalfDay = computed(() => selectedBalance.value?.policy?.allow_half_day !== false)

/** คำขอนี้เข้าเกณฑ์ต้องแนบหลักฐานแล้วหรือยัง */
const evidenceRequired = computed(() => {
  const threshold = selectedBalance.value?.policy?.attachment_required_after_days

  return threshold !== null && threshold !== undefined && Number(form.total_days || 0) >= Number(threshold)
})

/*
 * ชื่อหลักฐานที่ผู้ใช้อ่านรู้เรื่อง
 *
 * `required_evidence_type` เก็บเป็นรหัสภาษาอังกฤษ (เช่น `medical_certificate`)
 * การพิมพ์ค่าดิบลงประโยคไทยตรง ๆ ทำให้ได้ข้อความอย่าง "ต้องแนบmedical_certificateเมื่อ..."
 * รหัสที่ไม่รู้จักจะถูกแสดงโดยเว้นวรรครอบไว้ ยังอ่านออกและไม่ต้องแก้โค้ดเมื่อมีรหัสใหม่
 */
const EVIDENCE_LABELS = {
  medical_certificate: 'ใบรับรองแพทย์',
  supporting_document: 'หลักฐานประกอบการลา',
  official_document: 'หนังสือราชการ',
}

const evidenceLabel = computed(() => {
  const type = selectedBalance.value?.policy?.required_evidence_type

  if (! type) return 'หลักฐานประกอบการลา'

  return EVIDENCE_LABELS[type] || ` ${type} `
})

// จำนวนวันของคำขอนี้ที่จะตกเป็นวันไม่ได้รับเงินเดือน (เกินเพดาน max_days_with_pay)
const unpaidWarningDays = computed(() => {
  const b = selectedBalance.value
  if (!b || b.paid_remaining === null || b.paid_remaining === undefined) return 0
  if (!calculatedDays.value) return 0
  return Math.max(0, calculatedDays.value - b.paid_remaining)
})

// ช่องกรอกเฉพาะแบบฟอร์มของประเภทการลาที่เลือก — ผู้ดูแลกำหนดเองได้ ไม่ผูกกับโค้ด
const extraFieldDefinitions = computed(() => {
  const type = leaveStore.types.find(t => t.id === form.leave_type_id)
  return Array.isArray(type?.extra_fields) ? type.extra_fields : []
})

const extraValues = reactive({})

const onLeaveTypeChange = () => {
  Object.keys(extraValues).forEach(key => delete extraValues[key])
  extraFieldDefinitions.value.forEach(field => { extraValues[field.key] = '' })
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

const autoSaveKey = computed(() => `ela_draft_leave_form_${authStore.user?.id || 'guest'}`)
const {
  hasDraft,
  lastSavedAt,
  isSaving,
  restoreDraft,
  clearDraft,
} = useFormAutoSave(autoSaveKey.value, form, {
  debounceMs: 1200,
  isFormPopulated: (f) => !!(f.leave_type_id || f.reason || f.start_date || f.contact_address),
})

const handleRestoreDraft = () => {
  restoreDraft()
  calcDays()
}

onMounted(async () => {
  await Promise.all([
    leaveStore.fetchTypes(),
    leaveStore.fetchBalances()
  ])
  try {
    const res = await api.get('/users/list')
    // Exclude self
    users.value = res.data.filter(u => u.id !== authStore.user?.id)
  } catch {
    console.error('Failed to load users for substitute dropdown')
  }

  await loadDraftIfRequested()

  // ดึงที่อยู่และเบอร์โทรศัพท์จากประวัติส่วนตัวมาเติมอัตโนมัติหากยังไม่ได้ระบุ
  if (!form.contact_address && authStore.user) {
    const user = authStore.user
    const addr = user.address || user.profile?.address || ''
    const phone = user.phone || user.profile?.phone || ''
    if (addr || phone) {
      form.contact_address = [addr, phone ? `โทร. ${phone}` : ''].filter(Boolean).join(' ')
    }
  }

  // Setup Canvas Context initially in case 'draw' is selected later
  setupCanvas()
})

/*
 * เปิดร่างขึ้นมาแก้ — เข้าจากปุ่ม "แก้ไข" บนการ์ดร่างในหน้ารายการใบลาของฉัน
 *
 * โหลดค่าที่บันทึกไว้กลับเข้าฟอร์มทั้งหมด เพื่อให้แก้เฉพาะจุดที่ต้องการได้จริง
 * ไม่ต้องกรอกใหม่ทั้งใบ
 */
const loadDraftIfRequested = async () => {
  const id = route.query.draft

  if (! id) return

  try {
    const { data } = await api.get(`/leaves/${id}`)

    if (data.status !== 'Draft') {
      alertStore.error('ใบลานี้ถูกส่งเข้าสายอนุมัติแล้ว จึงแก้ไขไม่ได้')
      router.replace('/leaves')

      return
    }

    draftId.value = data.id
    form.leave_type_id = data.leave_type_id
    form.start_date = String(data.start_date).slice(0, 10)
    form.end_date = String(data.end_date).slice(0, 10)
    form.leave_period = data.leave_period || 'full_day'
    form.reason = data.reason || ''
    form.contact_address = data.contact_address || ''
    form.substitute_id = data.substitute_id || ''
    form.substitute_reason = data.substitute_reason || ''

    if (data.meta_data && typeof data.meta_data === 'object') {
      Object.assign(form.meta_data, data.meta_data)

      Object.entries(data.meta_data.extra_fields || {}).forEach(([key, value]) => {
        extraValues[key] = value
      })
    }

    calcDays()
  } catch {
    alertStore.error('เปิดร่างใบลาไม่สำเร็จ')
    router.replace('/leaves')
  }
}


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

/*
 * เปลี่ยนไปประเภทที่ลาครึ่งวันไม่ได้ ต้องดึงค่ากลับเป็นเต็มวันให้เอง
 * มิฉะนั้นค่าที่เลือกไว้ก่อนหน้าจะติดไปกับคำขอและถูกปฏิเสธตอนส่ง
 *
 * ต้องประกาศ watcher ตรงนี้ ไม่ใช่ตอนนิยาม `allowsHalfDay` — `watch` อ่านค่าทันทีที่ลงทะเบียน
 * เพื่อเก็บ dependency ถ้าลงทะเบียนก่อนบรรทัดที่สร้าง `form` จะอ่าน `form` ก่อนถูกสร้าง
 * (temporal dead zone) แล้วทั้งหน้าจะว่างเปล่าโดยไม่มีข้อความบอกสาเหตุบนหน้าจอ
 */
watch(allowsHalfDay, (allowed) => {
  if (! allowed && form.leave_period !== 'full_day') {
    form.leave_period = 'full_day'
    onPeriodChange()
  }
})

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

const handleSubmit = async (asDraft = false) => {
  loading.value = true
  savingDraft.value = asDraft === true
  try {
    const formData = new FormData()
    if (asDraft === true) formData.append('save_as_draft', '1')
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

    extraFieldDefinitions.value.forEach(field => {
      const value = extraValues[field.key]
      if (value !== undefined && value !== null && value !== '') {
        formData.append(`extra_fields[${field.key}]`, value)
      }
    })

    if (selectedFile.value) formData.append('attachment', selectedFile.value)

    if (sigType.value === 'draw' && sigBase64.value) {
      formData.append('signature_base64', sigBase64.value)
    }

    /*
     * กำลังแก้ร่างอยู่ → บันทึกทับร่างเดิม แล้วส่งอนุมัติต่อถ้าผู้ใช้กดปุ่มส่ง
     * ไม่งั้นการกดส่งจากหน้าแก้ร่างจะสร้างใบใหม่ทิ้งร่างเดิมค้างไว้
     */
    if (draftId.value) {
      await leaveStore.updateDraft(draftId.value, formData)
      clearDraft()

      if (asDraft === true) {
        alertStore.success('บันทึกร่างใบลาแล้ว')
        setTimeout(() => router.push('/leaves'), 1200)
      } else {
        await leaveStore.submitDraft(draftId.value)
        alertStore.success('ส่งคำขอลาเรียบร้อยแล้ว! กำลังกลับสู่หน้าหลัก...')
        setTimeout(() => router.push('/'), 1500)
      }

      return
    }

    await leaveStore.submitLeaveFormData(formData)
    clearDraft()

    if (asDraft === true) {
      alertStore.success('บันทึกร่างใบลาแล้ว — เปิดแก้ไขและส่งอนุมัติได้ที่รายการใบลาของฉัน')
      setTimeout(() => router.push('/leaves'), 1200)
    } else {
      alertStore.success('ส่งคำขอลาเรียบร้อยแล้ว! กำลังกลับสู่หน้าหลัก...')
      setTimeout(() => router.push('/'), 1500)
    }
  } catch (error) {
    const errors = error.response?.data?.errors
    if (errors) {
      alertStore.error(Object.values(errors).flat().join(' '))
    } else {
      alertStore.error(error.response?.data?.message || 'เกิดข้อผิดพลาด กรุณาลองใหม่')
    }
  } finally {
    loading.value = false
    savingDraft.value = false
  }
}
</script>
