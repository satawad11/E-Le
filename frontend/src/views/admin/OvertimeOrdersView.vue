<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-end gap-3">
      <div>
        <h2 class="text-2xl font-bold text-gray-800">คำสั่งปฏิบัติงานล่วงเวลา (OT)</h2>
        <p class="text-gray-500 text-sm mt-1">
          ออกคำสั่ง อนุมัติ มอบหมายผู้ปฏิบัติงาน และรับรองเวลาปฏิบัติงานจริง
        </p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button
          v-if="canExecute"
          @click="openCreate"
          class="inline-flex items-center gap-2 px-4 py-2 bg-[#800000] hover:bg-[#600000] text-white text-sm font-semibold rounded-xl transition-colors shadow-sm"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          ออกคำสั่ง OT
        </button>
        <button
          @click="reload"
          class="inline-flex items-center gap-2 px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white text-sm font-semibold rounded-xl transition-colors shadow-sm"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          รีเฟรช
        </button>
      </div>
    </div>

    <!-- Tabs -->
    <div class="border-b border-gray-200">
      <nav class="-mb-px flex gap-6">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          @click="activeTab = tab.key"
          :class="[
            'py-3 px-1 border-b-2 text-sm font-semibold transition-colors',
            activeTab === tab.key
              ? 'border-[#800000] text-[#800000]'
              : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
          ]"
        >
          {{ tab.label }}
        </button>
      </nav>
    </div>

    <div v-if="errorMessage" class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl px-4 py-3">
      {{ errorMessage }}
    </div>

    <!-- ============================ รายการคำสั่ง ============================ -->
    <div v-show="activeTab === 'orders'" class="space-y-4">
      <div v-if="loading" class="text-center text-gray-500 py-10">กำลังโหลด...</div>

      <div v-else-if="orders.length === 0" class="bg-white rounded-xl shadow-sm border border-gray-100 p-10 text-center">
        <p class="text-gray-500">ยังไม่มีคำสั่ง OT</p>
        <p v-if="canExecute" class="text-gray-400 text-sm mt-1">กด "ออกคำสั่ง OT" เพื่อเริ่มต้น</p>
      </div>

      <div
        v-for="order in orders"
        :key="order.uuid"
        class="bg-white rounded-xl shadow-sm border border-gray-100 p-6"
      >
        <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
              <h3 class="text-lg font-bold text-gray-800">{{ order.title }}</h3>
              <span :class="statusClass(order.status)" class="px-2.5 py-0.5 rounded-full text-xs font-semibold">
                {{ statusLabel(order.status) }}
              </span>
              <span v-if="order.status === 'pending' && order.current_step" class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-100 text-indigo-800">
                รอ{{ order.current_step }}
              </span>
              <span v-if="order.order_number" class="text-xs text-gray-500">
                เลขที่คำสั่ง {{ order.order_number }}
              </span>
            </div>

            <p v-if="order.description" class="text-sm text-gray-600 mt-2 whitespace-pre-line">{{ order.description }}</p>

            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 mt-3 text-sm">
              <div class="flex gap-2">
                <dt class="text-gray-500 shrink-0">ช่วงเวลาที่ขอ</dt>
                <dd class="text-gray-800 font-medium">{{ formatRange(order.scheduled_start, order.scheduled_end) }}</dd>
              </div>
              <div v-if="order.approved_start" class="flex gap-2">
                <dt class="text-gray-500 shrink-0">ช่วงเวลาที่อนุมัติ</dt>
                <dd class="text-green-700 font-medium">{{ formatRange(order.approved_start, order.approved_end) }}</dd>
              </div>
            </dl>
          </div>

          <div class="flex flex-wrap gap-2 shrink-0">
            <template v-if="canReview && order.status === 'pending'">
              <button
                @click="openApprove(order)"
                class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-lg transition-colors"
              >
                {{ decisionVerb(order) }}
              </button>
              <button
                @click="openReject(order)"
                class="px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-lg transition-colors"
              >
                ไม่{{ decisionVerb(order) }}
              </button>
            </template>
            <button
              v-if="canReview && order.status === 'approved'"
              @click="openAssign(order)"
              class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-lg transition-colors"
            >
              มอบหมายผู้ปฏิบัติงาน
            </button>
          </div>
        </div>

        <!-- ผู้ปฏิบัติงานที่มอบหมายแล้ว -->
        <div v-if="order.assignments && order.assignments.length" class="mt-5 border-t border-gray-100 pt-4">
          <h4 class="text-sm font-semibold text-gray-700 mb-2">
            ผู้ปฏิบัติงาน ({{ order.assignments.length }} คน)
          </h4>
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead>
                <tr class="text-left text-gray-500 border-b border-gray-100">
                  <th class="py-2 pr-4 font-medium">ชื่อ</th>
                  <th class="py-2 pr-4 font-medium">เวลาจริง</th>
                  <th class="py-2 pr-4 font-medium">เวลาที่รับรอง</th>
                  <th class="py-2 pr-4 font-medium">สถานะ</th>
                  <th class="py-2 font-medium"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="a in order.assignments" :key="a.id" class="border-b border-gray-50 last:border-0">
                  <td class="py-2 pr-4 text-gray-800">{{ a.user?.name || '—' }}</td>
                  <td class="py-2 pr-4 text-gray-600">
                    {{ a.time_record?.actual_start ? formatRange(a.time_record.actual_start, a.time_record.actual_end) : 'ยังไม่ลงเวลา' }}
                  </td>
                  <td class="py-2 pr-4 text-gray-600">
                    {{ a.time_record?.certified_start ? formatRange(a.time_record.certified_start, a.time_record.certified_end) : '—' }}
                  </td>
                  <td class="py-2 pr-4">
                    <span v-if="a.time_record" :class="recordStatusClass(a.time_record.status)" class="px-2 py-0.5 rounded-full text-xs font-semibold">
                      {{ recordStatusLabel(a.time_record.status) }}
                    </span>
                    <span v-else class="text-gray-400 text-xs">รอปฏิบัติงาน</span>
                  </td>
                  <td class="py-2 text-right">
                    <button
                      v-if="canReview && a.time_record?.actual_end"
                      @click="openCertify(a.time_record, a.user)"
                      class="px-3 py-1 bg-[#800000] hover:bg-[#600000] text-white text-xs font-semibold rounded-lg transition-colors"
                    >
                      รับรองเวลา
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Pagination -->
      <div v-if="pagination.last_page > 1" class="flex justify-center gap-2 pt-2">
        <button
          :disabled="pagination.current_page <= 1"
          @click="fetchOrders(pagination.current_page - 1)"
          class="px-3 py-1.5 text-sm rounded-lg border border-gray-200 disabled:opacity-40 hover:bg-gray-50"
        >
          ก่อนหน้า
        </button>
        <span class="px-3 py-1.5 text-sm text-gray-600">
          หน้า {{ pagination.current_page }} / {{ pagination.last_page }}
        </span>
        <button
          :disabled="pagination.current_page >= pagination.last_page"
          @click="fetchOrders(pagination.current_page + 1)"
          class="px-3 py-1.5 text-sm rounded-lg border border-gray-200 disabled:opacity-40 hover:bg-gray-50"
        >
          ถัดไป
        </button>
      </div>
    </div>

    <!-- ============================ รายงาน ============================ -->
    <div v-show="activeTab === 'reports'" class="space-y-4">
      <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">ปี (พ.ศ.)</label>
            <select v-model="reportFilters.year" @change="fetchReports()" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]">
              <option v-for="y in yearOptions" :key="y" :value="y">{{ y + 543 }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">เดือน</label>
            <select v-model="reportFilters.month" @change="fetchReports()" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]">
              <option value="">ทั้งปี</option>
              <option v-for="(name, index) in thaiMonths" :key="index + 1" :value="index + 1">{{ name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">บุคลากร</label>
            <select v-model="reportFilters.user_id" @change="fetchReports()" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]">
              <option value="">ทั้งหมด</option>
              <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
          </div>
        </div>
      </div>

      <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div v-if="reportLoading" class="text-center text-gray-500 py-10">กำลังโหลด...</div>
        <div v-else-if="reports.length === 0" class="text-center text-gray-500 py-10">ไม่พบข้อมูล</div>
        <div v-else class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50">
              <tr class="text-left text-gray-600">
                <th class="px-4 py-3 font-semibold">บุคลากร</th>
                <th class="px-4 py-3 font-semibold">คำสั่ง</th>
                <th class="px-4 py-3 font-semibold text-right">อนุมัติ (นาที)</th>
                <th class="px-4 py-3 font-semibold text-right">จริง (นาที)</th>
                <th class="px-4 py-3 font-semibold text-right">รับรอง (นาที)</th>
                <th class="px-4 py-3 font-semibold">สถานะ</th>
                <th class="px-4 py-3 font-semibold">การกระทบยอด</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in reports" :key="r.uuid" class="border-t border-gray-100">
                <td class="px-4 py-3 text-gray-800">{{ r.user?.name || '—' }}</td>
                <td class="px-4 py-3 text-gray-600">{{ r.order?.title || '—' }}</td>
                <td class="px-4 py-3 text-right text-gray-600">{{ r.approved_minutes ?? '—' }}</td>
                <td class="px-4 py-3 text-right text-gray-600">{{ r.actual_minutes ?? '—' }}</td>
                <td class="px-4 py-3 text-right font-semibold text-gray-800">{{ r.certified_minutes ?? '—' }}</td>
                <td class="px-4 py-3">
                  <span :class="recordStatusClass(r.status)" class="px-2 py-0.5 rounded-full text-xs font-semibold">
                    {{ recordStatusLabel(r.status) }}
                  </span>
                </td>
                <td class="px-4 py-3 text-gray-600">{{ r.reconciliation_status || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ============================ Modal: ออกคำสั่ง ============================ -->
    <div v-if="createModal.show" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="createModal.show = false">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-gray-100">
          <h3 class="text-lg font-bold text-gray-900">ออกคำสั่งปฏิบัติงานล่วงเวลา</h3>
          <p class="text-sm text-gray-500 mt-1">คำสั่งจะถูกส่งเข้าสู่ขั้นตอนอนุมัติทันทีหลังบันทึก</p>
        </div>
        <div class="px-6 py-4 space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">เรื่อง <span class="text-red-500">*</span></label>
            <input v-model="createForm.title" type="text" maxlength="255" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]" placeholder="เช่น ปฏิบัติงานนอกเวลาราชการเพื่อจัดเตรียมงานพิธี" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">เลขที่คำสั่ง</label>
            <input v-model="createForm.order_number" type="text" maxlength="100" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]" placeholder="เว้นว่างได้ ระบบจะไม่กำหนดให้อัตโนมัติ" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">รายละเอียด</label>
            <textarea v-model="createForm.description" rows="3" maxlength="5000" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]"></textarea>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">เริ่ม <span class="text-red-500">*</span></label>
              <input v-model="createForm.scheduled_start" type="datetime-local" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">สิ้นสุด <span class="text-red-500">*</span></label>
              <input v-model="createForm.scheduled_end" type="datetime-local" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]" />
            </div>
          </div>
          <p class="text-xs text-gray-500">
            คำสั่งจะผูกกับหน่วยงานต้นสังกัดของผู้ออกคำสั่งโดยอัตโนมัติ
          </p>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-2">
          <button @click="createModal.show = false" class="px-4 py-2 text-sm font-semibold text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-xl">ยกเลิก</button>
          <button :disabled="submitting" @click="submitCreate" class="px-4 py-2 text-sm font-semibold text-white bg-[#800000] hover:bg-[#600000] rounded-xl disabled:opacity-50">
            {{ submitting ? 'กำลังบันทึก...' : 'บันทึกและส่งอนุมัติ' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ============================ Modal: อนุมัติ ============================ -->
    <div v-if="approveModal.show" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="approveModal.show = false">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg">
        <div class="px-6 py-4 border-b border-gray-100">
          <h3 class="text-lg font-bold text-gray-900">{{ decisionVerb(approveModal.order || {}) }}คำสั่ง OT</h3>
          <p class="text-sm text-gray-500 mt-1">{{ approveModal.order?.title }}</p>
        </div>
        <div class="px-6 py-4 space-y-4">
          <p class="text-sm text-gray-600">
            ปรับช่วงเวลาได้ หากไม่แก้ไขจะใช้ช่วงเวลาที่ขอมา
            <span class="font-medium text-gray-800">ผู้ปฏิบัติงานจะลงเวลา OT ได้เฉพาะภายในช่วงนี้เท่านั้น</span>
          </p>
          <p v-if="decisionVerb(approveModal.order || {}) === 'อนุญาต'" class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
            นี่เป็นขั้นแรกของสายอนุมัติ คำสั่งจะยังใช้งานไม่ได้จนกว่าผู้อำนวยการจะอนุมัติ
          </p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">เริ่ม</label>
              <input v-model="approveForm.approved_start" type="datetime-local" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">สิ้นสุด</label>
              <input v-model="approveForm.approved_end" type="datetime-local" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]" />
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">ความเห็น</label>
            <textarea v-model="approveForm.comment" rows="2" maxlength="2000" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]"></textarea>
          </div>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-2">
          <button @click="approveModal.show = false" class="px-4 py-2 text-sm font-semibold text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-xl">ยกเลิก</button>
          <button :disabled="submitting" @click="submitApprove" class="px-4 py-2 text-sm font-semibold text-white bg-green-600 hover:bg-green-700 rounded-xl disabled:opacity-50">
            {{ submitting ? 'กำลังบันทึก...' : `ยืนยัน${decisionVerb(approveModal.order || {})}` }}
          </button>
        </div>
      </div>
    </div>

    <!-- ============================ Modal: ไม่อนุมัติ ============================ -->
    <div v-if="rejectModal.show" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="rejectModal.show = false">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg">
        <div class="px-6 py-4 border-b border-gray-100">
          <h3 class="text-lg font-bold text-gray-900">ไม่{{ decisionVerb(rejectModal.order || {}) }}คำสั่ง OT</h3>
          <p class="text-sm text-gray-500 mt-1">{{ rejectModal.order?.title }}</p>
        </div>
        <div class="px-6 py-4">
          <label class="block text-sm font-medium text-gray-700 mb-1">เหตุผล <span class="text-red-500">*</span></label>
          <textarea v-model="rejectForm.comment" rows="3" maxlength="2000" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]" placeholder="ระบุเหตุผลที่ไม่เห็นชอบ"></textarea>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-2">
          <button @click="rejectModal.show = false" class="px-4 py-2 text-sm font-semibold text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-xl">ยกเลิก</button>
          <button :disabled="submitting || !rejectForm.comment" @click="submitReject" class="px-4 py-2 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl disabled:opacity-50">
            {{ submitting ? 'กำลังบันทึก...' : 'ยืนยันไม่อนุมัติ' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ============================ Modal: มอบหมาย ============================ -->
    <div v-if="assignModal.show" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="assignModal.show = false">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg max-h-[90vh] flex flex-col">
        <div class="px-6 py-4 border-b border-gray-100">
          <h3 class="text-lg font-bold text-gray-900">มอบหมายผู้ปฏิบัติงาน</h3>
          <p class="text-sm text-gray-500 mt-1">{{ assignModal.order?.title }}</p>
        </div>
        <div class="px-6 py-4 overflow-y-auto flex-1">
          <input
            v-model="assignSearch"
            type="text"
            placeholder="ค้นหาชื่อ"
            class="w-full border-gray-300 rounded-lg text-sm mb-3 focus:ring-[#800000] focus:border-[#800000]"
          />
          <p class="text-xs text-gray-500 mb-2">
            เลือกได้เฉพาะบุคลากรที่อยู่ในขอบเขตหน่วยงานของคำสั่งนี้ หากเลือกคนนอกขอบเขต ระบบจะปฏิเสธ
          </p>
          <div class="space-y-1">
            <label
              v-for="u in filteredUsers"
              :key="u.id"
              class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-gray-50 cursor-pointer"
            >
              <input type="checkbox" :value="u.id" v-model="assignForm.user_ids" class="rounded border-gray-300 text-[#800000] focus:ring-[#800000]" />
              <span class="text-sm text-gray-800">{{ u.name }}</span>
            </label>
          </div>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 flex justify-between items-center gap-2">
          <span class="text-sm text-gray-500">เลือกแล้ว {{ assignForm.user_ids.length }} คน</span>
          <div class="flex gap-2">
            <button @click="assignModal.show = false" class="px-4 py-2 text-sm font-semibold text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-xl">ยกเลิก</button>
            <button :disabled="submitting || assignForm.user_ids.length === 0" @click="submitAssign" class="px-4 py-2 text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-xl disabled:opacity-50">
              {{ submitting ? 'กำลังบันทึก...' : 'ยืนยันมอบหมาย' }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ============================ Modal: รับรองเวลา ============================ -->
    <div v-if="certifyModal.show" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="certifyModal.show = false">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg">
        <div class="px-6 py-4 border-b border-gray-100">
          <h3 class="text-lg font-bold text-gray-900">รับรองเวลาปฏิบัติงาน OT</h3>
          <p class="text-sm text-gray-500 mt-1">{{ certifyModal.userName }}</p>
        </div>
        <div class="px-6 py-4 space-y-4">
          <p class="text-sm text-gray-600">
            เวลาที่ลงจริง
            <span class="font-medium text-gray-800">
              {{ formatRange(certifyModal.record?.actual_start, certifyModal.record?.actual_end) }}
            </span>
          </p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">รับรองตั้งแต่ <span class="text-red-500">*</span></label>
              <input v-model="certifyForm.certified_start" type="datetime-local" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">ถึง <span class="text-red-500">*</span></label>
              <input v-model="certifyForm.certified_end" type="datetime-local" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]" />
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">เหตุผลประกอบการรับรอง <span class="text-red-500">*</span></label>
            <textarea v-model="certifyForm.reason" rows="3" maxlength="2000" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]" placeholder="เช่น รับรองตามเวลาที่ปฏิบัติงานจริง"></textarea>
          </div>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-2">
          <button @click="certifyModal.show = false" class="px-4 py-2 text-sm font-semibold text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-xl">ยกเลิก</button>
          <button :disabled="submitting || !certifyForm.reason" @click="submitCertify" class="px-4 py-2 text-sm font-semibold text-white bg-[#800000] hover:bg-[#600000] rounded-xl disabled:opacity-50">
            {{ submitting ? 'กำลังบันทึก...' : 'ยืนยันรับรอง' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '../../stores/auth'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'

const authStore = useAuthStore()
const alertStore = useAlertStore()

/*
 * สิทธิ์ตรงกับที่ routes/api.php บังคับไว้:
 *   overtime.operation.execute  ออกคำสั่ง + ลงเวลา OT
 *   overtime.management.review  อนุมัติ / มอบหมาย / รับรอง / รายงาน
 */
const canExecute = computed(() => authStore.hasPermission('overtime.operation.execute'))
const canReview = computed(() => authStore.hasPermission('overtime.management.review'))

const tabs = [
  { key: 'orders', label: 'คำสั่ง OT' },
  { key: 'reports', label: 'รายงานเวลา OT' },
]
const activeTab = ref('orders')

const loading = ref(false)
const submitting = ref(false)
const errorMessage = ref('')
const orders = ref([])
const users = ref([])
const pagination = ref({ current_page: 1, last_page: 1 })

const reportLoading = ref(false)
const reports = ref([])
const thaiMonths = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม']
const currentDate = new Date()
const yearOptions = computed(() => {
  const y = currentDate.getFullYear()
  return [y - 2, y - 1, y, y + 1]
})
const reportFilters = ref({ year: currentDate.getFullYear(), month: '', user_id: '' })

const createModal = ref({ show: false })
const createForm = ref({ title: '', order_number: '', description: '', scheduled_start: '', scheduled_end: '' })

const approveModal = ref({ show: false, order: null })
const approveForm = ref({ approved_start: '', approved_end: '', comment: '' })

const rejectModal = ref({ show: false, order: null })
const rejectForm = ref({ comment: '' })

const assignModal = ref({ show: false, order: null })
const assignForm = ref({ user_ids: [] })
const assignSearch = ref('')

const certifyModal = ref({ show: false, record: null, userName: '' })
const certifyForm = ref({ certified_start: '', certified_end: '', reason: '' })

const filteredUsers = computed(() => {
  const term = assignSearch.value.trim().toLowerCase()
  if (!term) return users.value
  return users.value.filter(u => (u.name || '').toLowerCase().includes(term))
})

/*
 * สายอนุมัติสองชั้นตามระเบียบ: รอง ผอ. ฝ่ายทรัพยากร "อนุญาต" ก่อน
 * แล้ว ผอ. จึง "อนุมัติ" ปิดท้าย ใช้คำให้ตรงกับขั้นที่กำลังรออยู่
 */
const decisionVerb = (order) => (order.current_step || '').includes('อนุญาต') ? 'อนุญาต' : 'อนุมัติ'

const statusLabel = (status) => ({
  pending: 'รออนุมัติ',
  approved: 'อนุมัติแล้ว',
  rejected: 'ไม่อนุมัติ',
  cancelled: 'ยกเลิก',
}[status] || status)

const statusClass = (status) => ({
  pending: 'bg-amber-100 text-amber-800',
  approved: 'bg-green-100 text-green-800',
  rejected: 'bg-red-100 text-red-800',
  cancelled: 'bg-gray-100 text-gray-700',
}[status] || 'bg-gray-100 text-gray-700')

const recordStatusLabel = (status) => ({
  in_progress: 'กำลังปฏิบัติงาน',
  recorded: 'ลงเวลาครบแล้ว',
  completed: 'ปฏิบัติงานเสร็จ',
  certified: 'รับรองแล้ว',
}[status] || status || '—')

const recordStatusClass = (status) => ({
  in_progress: 'bg-blue-100 text-blue-800',
  recorded: 'bg-amber-100 text-amber-800',
  completed: 'bg-amber-100 text-amber-800',
  certified: 'bg-green-100 text-green-800',
}[status] || 'bg-gray-100 text-gray-700')

const formatDateTime = (value) => {
  if (!value) return '—'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return '—'
  return d.toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' })
}

const formatRange = (start, end) => {
  if (!start) return '—'
  return `${formatDateTime(start)} – ${end ? formatDateTime(end) : 'ยังไม่สิ้นสุด'}`
}

/** แปลงค่าจาก API เป็นรูปแบบที่ input[type=datetime-local] รับได้ */
const toLocalInput = (value) => {
  if (!value) return ''
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return ''
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

const errorFrom = (error, fallback) => {
  const data = error?.response?.data
  if (data?.errors) return Object.values(data.errors).flat().join(' ')
  return data?.message || fallback
}

const fetchOrders = async (page = 1) => {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get('/overtime/orders', { params: { page } })
    orders.value = res.data.data || []
    pagination.value = { current_page: res.data.current_page || 1, last_page: res.data.last_page || 1 }
  } catch (error) {
    errorMessage.value = errorFrom(error, 'ไม่สามารถโหลดรายการคำสั่ง OT ได้')
  } finally {
    loading.value = false
  }
}

const fetchUsers = async () => {
  try {
    const res = await api.get('/users/list')
    users.value = res.data || []
  } catch {
    users.value = []
  }
}

const fetchReports = async () => {
  reportLoading.value = true
  try {
    const params = { year: reportFilters.value.year }
    if (reportFilters.value.month) params.month = reportFilters.value.month
    if (reportFilters.value.user_id) params.user_id = reportFilters.value.user_id
    const res = await api.get('/overtime/reports', { params })
    reports.value = res.data.data || []
  } catch (error) {
    errorMessage.value = errorFrom(error, 'ไม่สามารถโหลดรายงาน OT ได้')
  } finally {
    reportLoading.value = false
  }
}

const reload = async () => {
  await fetchOrders(pagination.value.current_page)
  if (canReview.value) await fetchReports()
}

const openCreate = () => {
  createForm.value = { title: '', order_number: '', description: '', scheduled_start: '', scheduled_end: '' }
  createModal.value = { show: true }
}

const submitCreate = async () => {
  submitting.value = true
  try {
    const payload = { ...createForm.value }
    if (!payload.order_number) delete payload.order_number
    if (!payload.description) delete payload.description
    const res = await api.post('/overtime/orders', payload)
    alertStore.addAlert(res.data.message || 'ส่งคำสั่ง OT เข้าสู่ขั้นตอนอนุมัติแล้ว', 'success')
    createModal.value.show = false
    await fetchOrders(1)
  } catch (error) {
    alertStore.addAlert(errorFrom(error, 'ไม่สามารถบันทึกคำสั่ง OT ได้'), 'error')
  } finally {
    submitting.value = false
  }
}

const openApprove = (order) => {
  approveForm.value = {
    approved_start: toLocalInput(order.scheduled_start),
    approved_end: toLocalInput(order.scheduled_end),
    comment: '',
  }
  approveModal.value = { show: true, order }
}

const submitApprove = async () => {
  submitting.value = true
  try {
    const res = await api.post(`/overtime/orders/${approveModal.value.order.uuid}/approve`, approveForm.value)
    alertStore.addAlert(res.data.message || 'อนุมัติคำสั่ง OT แล้ว', 'success')
    approveModal.value.show = false
    await fetchOrders(pagination.value.current_page)
  } catch (error) {
    alertStore.addAlert(errorFrom(error, 'ไม่สามารถอนุมัติคำสั่ง OT ได้'), 'error')
  } finally {
    submitting.value = false
  }
}

const openReject = (order) => {
  rejectForm.value = { comment: '' }
  rejectModal.value = { show: true, order }
}

const submitReject = async () => {
  submitting.value = true
  try {
    const res = await api.post(`/overtime/orders/${rejectModal.value.order.uuid}/reject`, rejectForm.value)
    alertStore.addAlert(res.data.message || 'ไม่อนุมัติคำสั่ง OT', 'success')
    rejectModal.value.show = false
    await fetchOrders(pagination.value.current_page)
  } catch (error) {
    alertStore.addAlert(errorFrom(error, 'ไม่สามารถบันทึกผลการพิจารณาได้'), 'error')
  } finally {
    submitting.value = false
  }
}

const openAssign = (order) => {
  assignSearch.value = ''
  assignForm.value = { user_ids: (order.assignments || []).map(a => a.user_id).filter(Boolean) }
  assignModal.value = { show: true, order }
}

const submitAssign = async () => {
  submitting.value = true
  try {
    const res = await api.post(`/overtime/orders/${assignModal.value.order.uuid}/assignments`, assignForm.value)
    alertStore.addAlert(res.data.message || 'มอบหมายผู้ปฏิบัติงาน OT แล้ว', 'success')
    assignModal.value.show = false
    await fetchOrders(pagination.value.current_page)
  } catch (error) {
    alertStore.addAlert(errorFrom(error, 'ไม่สามารถมอบหมายผู้ปฏิบัติงานได้'), 'error')
  } finally {
    submitting.value = false
  }
}

const openCertify = (record, user) => {
  certifyForm.value = {
    certified_start: toLocalInput(record.actual_start),
    certified_end: toLocalInput(record.actual_end),
    reason: '',
  }
  certifyModal.value = { show: true, record, userName: user?.name || '' }
}

const submitCertify = async () => {
  submitting.value = true
  try {
    const res = await api.post(`/overtime/time-records/${certifyModal.value.record.uuid}/certify`, certifyForm.value)
    alertStore.addAlert(res.data.message || 'รับรองเวลาปฏิบัติงาน OT แล้ว', 'success')
    certifyModal.value.show = false
    await fetchOrders(pagination.value.current_page)
    if (canReview.value) await fetchReports()
  } catch (error) {
    alertStore.addAlert(errorFrom(error, 'ไม่สามารถรับรองเวลาได้'), 'error')
  } finally {
    submitting.value = false
  }
}

onMounted(async () => {
  await fetchOrders()
  if (canReview.value) {
    await fetchUsers()
    await fetchReports()
  }
})
</script>
