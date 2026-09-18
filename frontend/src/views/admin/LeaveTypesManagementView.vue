<template>
  <div class="max-w-7xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">จัดการประเภทการลาและนโยบายสิทธิ (Consolidated Leave Management)</h1>
        <p class="text-gray-500 mt-1 text-sm">กำหนดประเภทการลา แบบฟอร์ม PDF โควตาวันลา และเงื่อนไขสิทธิตามประเภทบุคลากร</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button
          v-if="currentTab === 'types'"
          @click="openCreate"
          class="inline-flex items-center gap-2 px-4 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm transition-colors shadow-sm"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
          </svg>
          เพิ่มประเภทการลา
        </button>
        <button
          v-else
          @click="openCreatePolicy"
          class="inline-flex items-center gap-2 px-4 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm transition-colors shadow-sm"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
          </svg>
          เพิ่มนโยบายสิทธิ
        </button>
      </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="inline-flex p-1 bg-gray-200/80 rounded-xl">
      <button
        type="button"
        @click="currentTab = 'types'"
        class="px-4 py-2 rounded-lg text-xs sm:text-sm font-bold transition-all flex items-center gap-2"
        :class="currentTab === 'types' ? 'bg-white text-[#800000] shadow-xs' : 'text-gray-600 hover:text-gray-900'"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
        <span>ประเภทการลา & แบบฟอร์ม</span>
      </button>

      <button
        type="button"
        @click="currentTab = 'policies'"
        class="px-4 py-2 rounded-lg text-xs sm:text-sm font-bold transition-all flex items-center gap-2"
        :class="currentTab === 'policies' ? 'bg-white text-[#800000] shadow-xs' : 'text-gray-600 hover:text-gray-900'"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>
        <span>นโยบายสิทธิ & โควตาบุคลากร</span>
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex justify-center py-16">
      <div class="animate-spin h-10 w-10 rounded-full border-4 border-[#800000] border-t-transparent"></div>
    </div>

    <!-- ===================================== -->
    <!-- TAB 1: ประเภทการลา & แบบฟอร์ม (TYPES) -->
    <!-- ===================================== -->
    <div v-else-if="currentTab === 'types'" class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-100">
            <tr>
              <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase">ชื่อประเภทการลา</th>
              <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase">รหัส</th>
              <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase">นโยบายโควตาที่ผูกอยู่</th>
              <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase">แบบฟอร์มที่ใช้พิมพ์</th>
              <th class="px-6 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-if="leaveTypes.length === 0">
              <td colspan="5" class="text-center text-gray-400 py-8">ไม่มีข้อมูลประเภทการลา</td>
            </tr>
            <tr v-for="lt in leaveTypes" :key="lt.id" class="hover:bg-gray-50 transition-colors">
              <td class="px-6 py-4 font-semibold text-gray-900">
                <div>{{ lt.name }}</div>
                <div v-if="lt.description" class="text-xs text-gray-400 mt-0.5 max-w-xs truncate">{{ lt.description }}</div>
              </td>
              <td class="px-6 py-4 text-gray-700 font-mono text-xs">{{ lt.code_prefix || '—' }}</td>

              <!-- โควตาตามประเภทบุคลากรที่ผูกกับประเภทลานี้ -->
              <td class="px-6 py-4">
                <div class="flex flex-wrap gap-1.5 max-w-sm">
                  <template v-if="getPoliciesForType(lt.id).length > 0">
                    <span
                      v-for="pol in getPoliciesForType(lt.id)"
                      :key="pol.id"
                      class="px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800"
                    >
                      {{ pol.personnel_type?.name }}: <strong class="text-[#800000]">{{ pol.annual_quota ?? 'ไม่จำกัด' }}</strong> วัน
                    </span>
                  </template>
                  <span v-else class="text-xs text-gray-400">
                    ค่าตั้งต้น: {{ lt.max_days === null ? 'ตามหลักเกณฑ์' : `${lt.max_days} วัน` }} (ยังไม่มีนโยบายเฉพาะ)
                  </span>
                </div>
              </td>

              <!-- สถานะแบบฟอร์มของประเภทนี้ -->
              <td class="px-6 py-4">
                <template v-if="formStatus[lt.id]">
                  <div class="text-gray-700 font-medium text-xs">{{ formStatus[lt.id].template_name || 'ยังไม่ได้ผูกแบบฟอร์ม' }}</div>
                  <div class="text-xs mt-0.5 flex flex-wrap items-center gap-1.5">
                    <span v-if="formStatus[lt.id].version" class="text-gray-400">
                      v{{ formStatus[lt.id].version }} · {{ formStatus[lt.id].field_count }} ฟิลด์
                    </span>
                    <span v-if="formStatus[lt.id].engine_enabled"
                      class="px-1.5 py-0.2 rounded bg-green-50 text-green-700 font-semibold text-[11px]">PDF Engine</span>
                  </div>
                </template>
                <span v-else class="text-gray-400 text-xs">{{ formStatusError || '—' }}</span>
              </td>

              <td class="px-6 py-4">
                <div class="flex items-center gap-2 justify-end">
                  <button @click="openEdit(lt)"
                    class="p-1.5 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="แก้ไข">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                  </button>
                  <button @click="confirmDelete(lt)"
                    class="p-1.5 text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="ลบ">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ========================================== -->
    <!-- TAB 2: นโยบายสิทธิ & โควตา (POLICIES) -->
    <!-- ========================================== -->
    <div v-else class="space-y-6">
      <!-- ตั้งค่าทั่วไปของระบบลา -->
      <form @submit.prevent="saveGeneral" class="bg-white rounded-2xl border border-gray-100 p-5 shadow-sm space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 class="font-bold text-gray-900">ตั้งค่าทั่วไปของระบบการลา</h2>
            <p class="text-xs text-gray-500">มีผลกับใบลาทุกประเภทและทุกประเภทบุคลากร</p>
          </div>
          <button :disabled="isSavingGeneral" class="px-4 py-2 rounded-xl bg-[#800000] text-white text-xs font-semibold disabled:opacity-50">
            {{ isSavingGeneral ? 'กำลังบันทึก...' : 'บันทึกการตั้งค่า' }}
          </button>
        </div>
        <div class="grid sm:grid-cols-2 gap-4 pt-1">
          <label class="text-xs text-gray-700 font-medium">
            ยกเลิกใบลาได้ภายใน (วัน)
            <input v-model.number="general.cancel_allowed_days" type="number" min="0" max="365" class="mt-1 w-full border border-gray-200 rounded-xl px-3 py-2 text-sm">
            <span class="text-[11px] text-gray-400 mt-1 block">นับจากวันเริ่มลา หากเกินกำหนดผู้ใช้จะยื่นยกเลิกเองไม่ได้</span>
          </label>
          <label class="text-xs text-gray-700 font-medium">
            เก็บหลักฐานใบลา (วัน)
            <input v-model.number="general.leave_evidence_retention_days" type="number" min="1" max="36500" class="mt-1 w-full border border-gray-200 rounded-xl px-3 py-2 text-sm">
            <span class="text-[11px] text-gray-400 mt-1 block">ระยะเวลาเก็บไฟล์หลักฐานก่อนถูกลบตามนโยบายคุ้มครองข้อมูลส่วนบุคคล</span>
          </label>
        </div>
      </form>

      <!-- Policies Table -->
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-3">
            <span class="text-xs font-semibold text-gray-500">กรองประเภทบุคลากร:</span>
            <select v-model="filterType" @change="fetchPolicies" class="border border-gray-200 rounded-xl px-3 py-1.5 text-xs">
              <option value="">ทุกประเภทบุคลากร</option>
              <option v-for="p in personnel" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </div>
          <span class="text-xs text-gray-400">พบ {{ policies.length }} นโยบาย</span>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600 text-xs">
              <tr>
                <th class="p-3 text-left font-semibold">ประเภทบุคลากร</th>
                <th class="p-3 text-left font-semibold">ประเภทการลา</th>
                <th class="p-3 text-center font-semibold">โควตาต่อปี</th>
                <th class="p-3 text-center font-semibold">ได้รับเงินเดือน</th>
                <th class="p-3 text-center font-semibold">หลักฐานเมื่อ</th>
                <th class="p-3 text-center font-semibold">ช่วงวันที่มีผล</th>
                <th class="p-3"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-for="p in policies" :key="p.id" class="hover:bg-gray-50">
                <td class="p-3 font-medium text-gray-900">{{ p.personnel_type?.name }}</td>
                <td class="p-3 text-gray-700">{{ p.leave_type?.name }}</td>
                <td class="p-3 text-center font-bold text-[#800000]">{{ p.annual_quota ?? 'ไม่จำกัด' }} วัน</td>
                <td class="p-3 text-center text-gray-600">{{ p.max_days_with_pay ? `${p.max_days_with_pay} วัน` : 'ทุกวัน' }}</td>
                <td class="p-3 text-center text-gray-500 text-xs">{{ p.attachment_required_after_days ? `${p.attachment_required_after_days} วัน` : '—' }}</td>
                <td class="p-3 text-center text-xs text-gray-500">{{ formatDate(p.effective_from) }} – {{ p.effective_to ? formatDate(p.effective_to) : 'ไม่มีกำหนด' }}</td>
                <td class="p-3 text-right">
                  <button @click="editPolicy(p)" class="text-xs text-[#800000] hover:underline font-semibold">แก้ไข</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Create/Edit Leave Type Modal -->
    <transition name="fade">
      <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 overflow-y-auto" @click.self="showModal = false">
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg my-auto max-h-[90vh] flex flex-col overflow-hidden">
          <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between flex-shrink-0 bg-white">
            <h3 class="font-bold text-gray-900 text-lg">
              {{ editMode ? 'แก้ไขประเภทการลา' : 'เพิ่มประเภทการลา' }}
            </h3>
            <button @click="showModal = false" class="p-1.5 text-gray-400 hover:text-gray-600 rounded-lg transition-colors" aria-label="ปิด">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>
          <form @submit.prevent="submitForm" class="flex flex-col flex-1 overflow-hidden min-h-0">
            <div class="overflow-y-auto flex-1 p-6 space-y-4">
              <!-- Error -->
              <div v-if="formError" class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl px-4 py-3">
                {{ formError }}
              </div>

              <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">ชื่อประเภทการลา <span class="text-red-500">*</span></label>
                <input v-model="form.name" type="text" required placeholder="เช่น ลาป่วย"
                  class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#800000]/30 focus:border-[#800000]"/>
              </div>

              <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">รหัสเริ่มต้นใบลา</label>
                <input v-model="form.code_prefix" type="text" placeholder="เช่น L01" maxlength="10"
                  class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#800000]/30 focus:border-[#800000]"/>
              </div>

              <div v-if="!editMode">
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">ค่าตั้งต้น (วัน) <span class="text-gray-400 font-normal">(เว้นว่างหากเป็นไปตามหลักเกณฑ์)</span></label>
                <input v-model.number="form.max_days" type="number" min="1" max="3650" placeholder="30"
                  class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#800000]/30 focus:border-[#800000]"/>
                <p class="text-xs text-gray-500 mt-1.5">ใช้เป็นค่าเริ่มต้นจนกว่าจะกำหนดนโยบายสิทธิการลาให้ประเภทลานี้</p>
              </div>

              <!-- ผูกประเภทการลาเข้ากับแบบฟอร์มที่ใช้พิมพ์ -->
              <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">แบบฟอร์มที่ใช้พิมพ์</label>
                <select v-model="form.document_template_code"
                  class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#800000]/30 focus:border-[#800000]">
                  <option value="">— ยังไม่กำหนด (ใช้แบบฟอร์มลาป่วย/กิจ/คลอด) —</option>
                  <option v-for="template in leaveTemplates" :key="template.code" :value="template.code">{{ template.name }}</option>
                </select>
                <p v-if="templatesError" class="text-xs text-amber-700 mt-1.5">{{ templatesError }}</p>
              </div>

              <!-- ช่องกรอกเฉพาะแบบฟอร์ม -->
              <div class="border-t border-gray-100 pt-3">
                <div class="flex items-center justify-between mb-2">
                  <label class="text-sm font-semibold text-gray-700">ช่องกรอกเฉพาะแบบฟอร์ม</label>
                  <button type="button" @click="addExtraField" class="text-xs font-semibold text-[#800000] hover:underline">+ เพิ่มช่อง</button>
                </div>
                <div v-for="(field, index) in form.extra_fields" :key="index" class="grid grid-cols-12 gap-2 mb-2">
                  <input v-model="field.key" placeholder="คีย์" class="col-span-3 border border-gray-200 rounded-lg px-2 py-1.5 text-xs">
                  <input v-model="field.label" placeholder="ชื่อช่อง" class="col-span-4 border border-gray-200 rounded-lg px-2 py-1.5 text-xs">
                  <select v-model="field.type" class="col-span-2 border border-gray-200 rounded-lg px-2 py-1.5 text-xs bg-white">
                    <option value="text">ข้อความ</option>
                    <option value="textarea">ยาว</option>
                    <option value="date">วันที่</option>
                    <option value="number">ตัวเลข</option>
                  </select>
                  <button type="button" @click="form.extra_fields.splice(index, 1)" class="col-span-3 text-red-600 text-xs">ลบ</button>
                </div>
              </div>

              <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">คำอธิบาย</label>
                <textarea v-model="form.description" rows="2" placeholder="รายละเอียดเพิ่มเติม..."
                  class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#800000]/30 focus:border-[#800000] resize-none"/>
              </div>

              <div class="border-t border-gray-100 pt-3">
                <label class="block text-sm font-medium text-gray-700 mb-1.5">ช่องในใบลงเวลารายเดือน</label>
                <select v-model="form.attendance_bucket"
                  class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#800000]/30 focus:border-[#800000]">
                  <option v-for="bucket in attendanceBuckets" :key="bucket.value" :value="bucket.value">{{ bucket.label }}</option>
                </select>
              </div>
            </div>

            <div class="flex gap-3 px-6 py-4 border-t border-gray-100 bg-gray-50/50 flex-shrink-0">
              <button type="button" @click="showModal = false"
                class="flex-1 py-2.5 bg-white border border-gray-200 hover:bg-gray-100 text-gray-700 font-semibold rounded-xl text-sm transition-colors shadow-sm">
                ยกเลิก
              </button>
              <button type="submit" :disabled="saving"
                class="flex-1 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm transition-colors shadow-sm disabled:opacity-60">
                {{ saving ? 'กำลังบันทึก...' : (editMode ? 'บันทึกการเปลี่ยนแปลง' : 'เพิ่มประเภทการลา') }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </transition>

    <!-- Policy Modal -->
    <div v-if="showPolicyModal" class="fixed inset-0 bg-black/40 z-50 grid place-items-center p-4">
      <form @submit.prevent="savePolicy" class="bg-white rounded-2xl p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto space-y-4">
        <h2 class="text-xl font-bold mb-4">{{ policyForm.id ? 'แก้ไข' : 'เพิ่ม' }}นโยบายสิทธิการลา</h2>
        <div class="grid sm:grid-cols-2 gap-4">
          <label class="text-xs font-medium text-gray-700">ประเภทบุคลากร
            <select v-model="policyForm.personnel_type_id" required class="mt-1 w-full border border-gray-200 rounded-xl p-2 text-sm">
              <option v-for="p in personnel" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </label>
          <label class="text-xs font-medium text-gray-700">ประเภทการลา
            <select v-model="policyForm.leave_type_id" required class="mt-1 w-full border border-gray-200 rounded-xl p-2 text-sm">
              <option v-for="t in leaveTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
            </select>
          </label>
          <label class="text-xs font-medium text-gray-700">มีผลตั้งแต่
            <input v-model="policyForm.effective_from" type="date" required class="mt-1 w-full border border-gray-200 rounded-xl p-2 text-sm">
          </label>
          <label class="text-xs font-medium text-gray-700">ถึงวันที่
            <input v-model="policyForm.effective_to" type="date" class="mt-1 w-full border border-gray-200 rounded-xl p-2 text-sm">
          </label>
          <label class="text-xs font-medium text-gray-700">โควตาต่อปี (วัน)
            <input v-model.number="policyForm.annual_quota" type="number" step="0.5" class="mt-1 w-full border border-gray-200 rounded-xl p-2 text-sm">
          </label>
          <label class="text-xs font-medium text-gray-700">ในจำนวนนี้ ได้รับเงินเดือน (วัน)
            <input v-model.number="policyForm.max_days_with_pay" type="number" step="0.5" placeholder="เว้นว่าง = ทุกวัน" class="mt-1 w-full border border-gray-200 rounded-xl p-2 text-sm">
          </label>
        </div>

        <div class="flex justify-end gap-2 pt-4 border-t border-gray-100">
          <button type="button" @click="showPolicyModal = false" :disabled="isSavingPolicy" class="px-4 py-2 text-sm text-gray-600">ยกเลิก</button>
          <button :disabled="isSavingPolicy" class="px-5 py-2 rounded-xl bg-[#800000] text-white text-sm font-semibold disabled:opacity-50">
            {{ isSavingPolicy ? 'กำลังบันทึก...' : 'บันทึกนโยบาย' }}
          </button>
        </div>
      </form>
    </div>

    <!-- Delete Confirmation Modal -->
    <transition name="fade">
      <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 text-center">
          <h3 class="font-bold text-gray-900 text-lg mb-1">ลบประเภทการลา?</h3>
          <p class="text-gray-500 text-sm mb-6">
            ต้องการลบ "<strong>{{ deleteTarget?.name }}</strong>" ใช่หรือไม่?
          </p>
          <div class="flex gap-3">
            <button @click="showDeleteModal = false"
              class="flex-1 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold rounded-xl text-sm transition-colors">
              ยกเลิก
            </button>
            <button @click="doDelete" :disabled="deleting"
              class="flex-1 py-2.5 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-xl text-sm transition-colors disabled:opacity-60">
              {{ deleting ? 'กำลังลบ...' : 'ลบ' }}
            </button>
          </div>
        </div>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { ThaiDateFormatter } from '../../utils/ThaiDateFormatter'

const alertStore = useAlertStore()
const currentTab = ref('types')
const loading = ref(true)
const saving  = ref(false)
const deleting = ref(false)
const leaveTypes = ref([])
const policies = ref([])
const personnel = ref([])
const filterType = ref('')
const showModal = ref(false)
const showDeleteModal = ref(false)
const showPolicyModal = ref(false)
const editMode = ref(false)
const editId   = ref(null)
const deleteTarget = ref(null)
const formError = ref('')

const form = ref({
  name: '',
  code_prefix: '',
  max_days: 30,
  description: '',
  requires_doc: false,
  is_carry_forward: false,
  max_carry_forward: null,
  is_prorated: false,
  counts_toward_promotion: true,
  attendance_bucket: 'other',
  document_template_code: '',
  extra_fields: [],
})

const attendanceBuckets = [
  { value: 'sick', label: 'ลาป่วย' },
  { value: 'personal', label: 'ลากิจ' },
  { value: 'maternity', label: 'ลาคลอดบุตร' },
  { value: 'vacation', label: 'ลาพักผ่อน' },
  { value: 'official', label: 'ราชการ' },
  { value: 'other', label: 'อื่น ๆ' },
]

const leaveTemplates = ref([])
const templatesError = ref('')
const formStatus = ref({})
const formStatusError = ref('')

const todayStr = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const policyForm = ref({
  id: null,
  personnel_type_id: '',
  leave_type_id: '',
  effective_from: todayStr(),
  effective_to: '',
  annual_quota: 10,
  max_days_with_pay: null,
})
const isSavingPolicy = ref(false)

const general = ref({ cancel_allowed_days: 5, leave_evidence_retention_days: 2555 })
const isSavingGeneral = ref(false)

const formatDate = (v) => ThaiDateFormatter.toThaiShortText(v)

const resetForm = () => {
  form.value = { name: '', code_prefix: '', max_days: 30, description: '', requires_doc: false, is_carry_forward: false, max_carry_forward: null, is_prorated: false, counts_toward_promotion: true, attendance_bucket: 'other', document_template_code: '', extra_fields: [] }
  formError.value = ''
}

const addExtraField = () => {
  form.value.extra_fields.push({ key: '', label: '', type: 'text', required: false })
}

const getPoliciesForType = (leaveTypeId) => {
  return policies.value.filter(p => p.leave_type_id === leaveTypeId)
}

const fetchTypes = async () => {
  const res = await api.get('/admin/leave-types')
  leaveTypes.value = res.data
}

const fetchPolicies = async () => {
  try {
    const [pRes, typeRes] = await Promise.all([
      api.get('/admin/leave-policies', { params: { personnel_type_id: filterType.value || undefined } }),
      api.get('/personnel-types'),
    ])
    policies.value = pRes.data
    personnel.value = typeRes.data
  } catch (e) {
    console.warn('Failed to load policies', e)
  }
}

const fetchFormStatus = async () => {
  try {
    const res = await api.get('/admin/document-engine/leave-type-forms')
    formStatus.value = Object.fromEntries(res.data.rows.map(row => [row.leave_type_id, row]))
    formStatusError.value = ''
  } catch (error) {
    formStatus.value = {}
    formStatusError.value = error.response?.status === 403 ? 'ไม่มีสิทธิ์ดูสถานะแบบฟอร์ม' : 'ดูสถานะแบบฟอร์มไม่ได้'
  }
}

const fetchTemplates = async () => {
  try {
    const res = await api.get('/admin/document-engine/templates', { params: { module: 'leave' } })
    leaveTemplates.value = res.data
    templatesError.value = ''
  } catch (error) {
    leaveTemplates.value = []
    templatesError.value = error.response?.status === 403 ? 'ไม่มีสิทธิ์ดูรายการแบบฟอร์ม' : 'โหลดรายการแบบฟอร์มไม่สำเร็จ'
  }
}

const openCreate = () => {
  editMode.value = false
  editId.value = null
  resetForm()
  showModal.value = true
}

const openEdit = (lt) => {
  editMode.value = true
  editId.value = lt.id
  form.value = {
    name: lt.name,
    code_prefix: lt.code_prefix || '',
    max_days: lt.max_days,
    description: lt.description ?? '',
    requires_doc: !!lt.requires_doc,
    is_carry_forward: !!lt.is_carry_forward,
    max_carry_forward: lt.max_carry_forward ?? null,
    is_prorated: !!lt.is_prorated,
    counts_toward_promotion: lt.counts_toward_promotion ?? true,
    attendance_bucket: lt.attendance_bucket || 'other',
    document_template_code: lt.document_template_code || '',
    extra_fields: lt.extra_fields || [],
  }
  formError.value = ''
  showModal.value = true
}

const submitForm = async () => {
  saving.value = true
  formError.value = ''
  try {
    const payloadBase = {
      ...form.value,
      document_template_code: form.value.document_template_code || null,
      extra_fields: form.value.extra_fields.filter(field => field.key && field.label),
    }

    if (editMode.value) {
      const { max_days: _ignored, ...payload } = payloadBase
      await api.put(`/admin/leave-types/${editId.value}`, payload)
    } else {
      await api.post('/admin/leave-types', payloadBase)
    }
    await fetchTypes()
    showModal.value = false
  } catch (e) {
    formError.value = e?.response?.data?.message || 'เกิดข้อผิดพลาด'
  } finally {
    saving.value = false
  }
}

const openCreatePolicy = () => {
  policyForm.value = {
    id: null,
    personnel_type_id: personnel.value[0]?.id || '',
    leave_type_id: leaveTypes.value[0]?.id || '',
    effective_from: todayStr(),
    effective_to: '',
    annual_quota: 10,
    max_days_with_pay: null,
  }
  showPolicyModal.value = true
}

const editPolicy = (p) => {
  policyForm.value = {
    id: p.id,
    personnel_type_id: p.personnel_type_id,
    leave_type_id: p.leave_type_id,
    effective_from: p.effective_from?.slice(0, 10),
    effective_to: p.effective_to?.slice(0, 10) || '',
    annual_quota: p.annual_quota,
    max_days_with_pay: p.max_days_with_pay,
  }
  showPolicyModal.value = true
}

const savePolicy = async () => {
  isSavingPolicy.value = true
  try {
    const payload = {
      ...policyForm.value,
      effective_to: policyForm.value.effective_to || null,
    }
    if (policyForm.value.id) {
      await api.put(`/admin/leave-policies/${policyForm.value.id}`, payload)
    } else {
      await api.post('/admin/leave-policies', payload)
    }
    alertStore.success('บันทึกนโยบายสิทธิเรียบร้อยแล้ว')
    showPolicyModal.value = false
    await fetchPolicies()
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'เกิดข้อผิดพลาดในการบันทึกนโยบาย')
  } finally {
    isSavingPolicy.value = false
  }
}

const confirmDelete = (lt) => {
  deleteTarget.value = lt
  showDeleteModal.value = true
}

const doDelete = async () => {
  if (!deleteTarget.value) return
  deleting.value = true
  try {
    await api.delete(`/admin/leave-types/${deleteTarget.value.id}`)
    alertStore.success('ลบประเภทการลาเรียบร้อยแล้ว')
    await fetchTypes()
    showDeleteModal.value = false
    deleteTarget.value = null
  } catch (e) {
    alertStore.error(e?.response?.data?.message || 'ลบไม่สำเร็จ')
  } finally {
    deleting.value = false
  }
}

const saveGeneral = async () => {
  isSavingGeneral.value = true
  try {
    await api.post('/admin/settings', general.value)
    alertStore.success('บันทึกการตั้งค่าทั่วไปเรียบร้อยแล้ว')
  } catch (error) {
    alertStore.error('บันทึกการตั้งค่าไม่สำเร็จ')
  } finally {
    isSavingGeneral.value = false
  }
}

onMounted(async () => {
  try {
    await Promise.all([fetchTypes(), fetchPolicies(), fetchTemplates(), fetchFormStatus()])
  } finally {
    loading.value = false
  }
})
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
