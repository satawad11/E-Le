<template>
  <div>
    <!-- Page header -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">รายการใบลาของฉัน</h1>
        <p class="text-gray-500 mt-1 text-sm">ดูและจัดการใบลาที่ยื่นไว้ทั้งหมด</p>
      </div>
      <router-link to="/request"
        class="inline-flex items-center gap-2 px-4 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm transition-colors shadow-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        ยื่นใบลาใหม่
      </router-link>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="py-8">
      <LoadingState type="skeleton-card" :rows="3" message="กำลังโหลดรายการใบลา..." />
    </div>

    <!-- Error State -->
    <div v-else-if="loadError" class="py-6">
      <ErrorState :message="loadError" @retry="loadLeaves" />
    </div>

    <div v-else>
      <!-- Filters and toggles -->
      <div class="flex flex-col md:flex-row gap-4 justify-between items-start md:items-center mb-6">
        <!-- Filter tabs -->
        <div class="flex flex-wrap gap-1 bg-gray-100 p-1 rounded-xl text-xs font-semibold">
          <button v-for="f in filters" :key="f.value"
            @click="activeFilter = f.value; currentPage = 1"
            :class="['px-3 py-1.5 rounded-lg transition-all', activeFilter === f.value
              ? 'bg-[#800000] text-white shadow-sm'
              : 'text-gray-500 hover:text-gray-700']">
            {{ f.label }}
            <span v-if="f.value !== 'all'" class="ml-1 opacity-70">({{ countByStatus(f.value) }})</span>
          </button>
        </div>

        <!-- Toggle View Mode -->
        <div v-if="allAllowed" class="flex bg-gray-100 p-1 rounded-xl text-xs font-semibold">
          <button @click="viewMode = 'mine'; currentPage = 1"
            :class="['px-4 py-1.5 rounded-lg transition-all', viewMode === 'mine' ? 'bg-white text-[#800000] shadow-sm' : 'text-gray-500 hover:text-gray-700']">
            ใบลาของฉัน
          </button>
          <button @click="viewMode = 'all'; currentPage = 1"
            :class="['px-4 py-1.5 rounded-lg transition-all', viewMode === 'all' ? 'bg-white text-[#800000] shadow-sm' : 'text-gray-500 hover:text-gray-700']">
            ของทั้งองค์กร
          </button>
        </div>
      </div>

      <!-- Empty state -->
      <EmptyState
        v-if="filteredLeaves.length === 0"
        title="ไม่มีรายการใบลา"
        description="ยังไม่มีรายการใบลาในเงื่อนไขนี้ หรือลองปรับเปลี่ยนตัวกรอง"
        action-text="ยื่นใบลาใหม่"
        @action="$router.push('/request')"
      />

      <!-- Leave cards -->
      <div v-else class="space-y-4">
        <div v-for="leave in paginatedLeaves" :key="leave.id"
          class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden hover:shadow-md transition-shadow">

          <div class="p-5">
            <div class="flex flex-col sm:flex-row sm:items-start gap-4">
              <!-- Left: info -->
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1.5 flex-wrap">
                  <h3 class="font-bold text-gray-900 text-base">
                    {{ viewMode === 'all' ? (leave.user?.name + ' - ') : '' }}{{ leave.type?.name }}
                  </h3>
                  <span :class="['px-2.5 py-0.5 text-xs font-bold rounded-full', compositeStatusBadgeClass(leave)]">
                    {{ compositeStatusLabel(leave) }}
                  </span>
                </div>
                <p class="text-sm text-gray-600 mb-1">
                  <span class="font-medium">วันที่:</span>
                  {{ ThaiDateFormatter.toThaiSlash(leave.start_date) }}
                  <template v-if="leave.start_date !== leave.end_date">
                    — {{ ThaiDateFormatter.toThaiSlash(leave.end_date) }}
                  </template>
                  <span class="ml-2 text-gray-400">({{ leave.total_days }} วัน)</span>
                </p>
                <p class="text-sm text-gray-500 line-clamp-2">
                  <span class="font-medium text-gray-600">เหตุผล:</span> {{ leave.reason }}
                </p>
                <p class="text-xs text-gray-400 mt-1.5">
                  ยื่นเมื่อ {{ ThaiDateFormatter.toThaiSlash(leave.created_at?.slice(0, 10)) }}
                </p>
              </div>

              <!-- Right: actions -->
              <div class="flex flex-col gap-2 flex-shrink-0">
                <!--
                  ร่างยังไม่เข้าสู่กระบวนการ — ยังไม่มีเลขที่หนังสือ ยังไม่กันวันลา
                  จึงมีได้แค่สองทางคือส่งอนุมัติ หรือลบทิ้ง
                -->
                <template v-if="leave.status === 'Draft'">
                  <router-link :to="{ path: '/request', query: { draft: leave.id } }"
                    class="inline-flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-semibold text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors min-h-[38px]">
                    แก้ไขร่าง
                  </router-link>
                  <button @click="submitDraft(leave)" :disabled="submittingDraft === leave.id"
                    class="inline-flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-bold text-white bg-[#800000] hover:bg-[#600000] rounded-lg transition-colors min-h-[38px] disabled:opacity-50">
                    <span v-if="submittingDraft === leave.id" class="animate-spin rounded-full h-3.5 w-3.5 border-b-2 border-white"></span>
                    {{ submittingDraft === leave.id ? 'กำลังส่ง...' : 'ส่งอนุมัติ' }}
                  </button>
                  <button @click="deleteLeave(leave.id)"
                    class="inline-flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-semibold text-red-600 border border-red-200 rounded-lg hover:bg-red-50 transition-colors min-h-[38px]">
                    ลบร่าง
                  </button>
                  <p class="text-xs text-gray-400 leading-relaxed max-w-[13rem]">
                    ยังไม่ออกเลขที่หนังสือและยังไม่กันวันลา — ส่งอนุมัติแล้วจะลบไม่ได้อีก
                  </p>
                </template>

                <!--
                  แบบฟอร์มใบลาพิมพ์ได้ทุกสถานะโดยตั้งใจ — ผู้ลาต้องพิมพ์ออกมาเสนอผู้บังคับบัญชา
                  ลงนาม ใบที่ยังรออนุมัติจึงเป็นกรณีที่ต้องพิมพ์ได้ที่สุด
                -->
                <div v-if="leave.status !== 'Draft'" class="flex flex-wrap gap-2">
                  <button @click="downloadPdf(leave)" aria-label="พิมพ์แบบฟอร์มใบลาเป็นไฟล์ PDF"
                    :disabled="pdfLoading === leave.id || wordLoading === leave.id"
                    class="inline-flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-semibold text-[#800000] border border-[#800000]/20 rounded-lg hover:bg-[#800000]/5 transition-colors min-h-[38px] flex-1 sm:flex-none">
                    <svg v-if="pdfLoading === leave.id" class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24">
                      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                    </svg>
                    <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    PDF
                  </button>
                  <button @click="downloadWord(leave)" aria-label="ดาวน์โหลดแบบฟอร์มใบลาเป็นไฟล์ Word"
                    :disabled="wordLoading === leave.id || pdfLoading === leave.id"
                    class="inline-flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-semibold text-blue-600 border border-blue-600/20 rounded-lg hover:bg-blue-50 transition-colors min-h-[38px] flex-1 sm:flex-none">
                    <svg v-if="wordLoading === leave.id" class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24">
                      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                    </svg>
                    <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Word
                  </button>
                </div>
                <!-- บอกให้ชัดว่าฉบับที่พิมพ์ตอนนี้ยังไม่ผ่านการอนุมัติ ช่องลงนามจึงยังว่าง -->
                <p v-if="leave.status === 'Pending'" class="text-xs text-gray-400 leading-relaxed max-w-[13rem] -mt-1">
                  พิมพ์เพื่อเสนอผู้บังคับบัญชาลงนาม — ช่องลงนามในเอกสารจะยังว่างจนกว่าจะอนุมัติในระบบ
                </p>

                <!-- Cancel button (Pending, within 5 days or Admin) -->
                <button v-if="canCancel(leave)"
                  @click="confirmCancel(leave)"
                  :disabled="cancelLoading === leave.id"
                  class="inline-flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-semibold text-red-600 border border-red-200 rounded-lg hover:bg-red-50 transition-colors min-h-[38px]">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                  </svg>
                  ยกเลิกใบลา
                </button>
                <button v-if="canRequestCancellation(leave)"
                  @click="confirmCancel(leave)"
                  :disabled="cancelLoading === leave.id"
                  class="inline-flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-semibold text-orange-600 border border-orange-200 rounded-lg hover:bg-orange-50 transition-colors min-h-[38px]">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                  ขอยกเลิก
                </button>
                
                <!--
                  ปุ่ม "ลบ" ของผู้ดูแลถูกถอดออก — ใบลาที่ยื่นแล้วต้องตรวจสอบย้อนหลังได้เสมอ
                  ใบที่ยังรออนุมัติใช้ "ยกเลิกใบลา" ส่วนใบที่อนุมัติแล้วใช้ "เพิกถอนเอกสาร"
                  ที่ศูนย์งานกำกับการลา ซึ่งบังคับใส่เหตุผลและเก็บประวัติไว้
                -->

                <!-- Print Cancellation Form (when cancellation is requested or approved or cancelled) -->
                <div v-if="hasCancellationForm(leave)" class="flex gap-2">
                  <button @click="downloadCancelPdf(leave)"
                    :disabled="pdfLoading === 'cancel_'+leave.id"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-orange-600 border border-orange-200 rounded-lg hover:bg-orange-50 transition-colors">
                    <svg v-if="pdfLoading === 'cancel_'+leave.id" class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24">
                      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                    </svg>
                    <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    PDF ยกเลิก
                  </button>
                  <button @click="downloadCancelWord(leave)"
                    :disabled="wordLoading === 'cancel_'+leave.id"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-indigo-600 border border-indigo-200 rounded-lg hover:bg-indigo-50 transition-colors">
                    <svg v-if="wordLoading === 'cancel_'+leave.id" class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24">
                      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                    </svg>
                    <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Word ยกเลิก
                  </button>
                </div>

                <!-- Substitute actions -->
                <div v-if="leave.substitute_id === authStore.user?.id && leave.substitute_status === 'Pending'" class="flex gap-2">
                  <button @click="acceptSubstitute(leave.id)" class="px-3 py-1.5 text-xs font-semibold text-green-600 border border-green-200 rounded-lg hover:bg-green-50">รับทราบ</button>
                  <button @click="rejectSubstitute(leave.id)" class="px-3 py-1.5 text-xs font-semibold text-red-600 border border-red-200 rounded-lg hover:bg-red-50">ปฏิเสธ</button>
                </div>
                <span v-if="leave.substitute_id === authStore.user?.id && leave.substitute_status === 'Accepted'" class="text-xs text-green-600 font-semibold px-3 py-1.5">
                  รับทราบแล้ว
                </span>
                <span v-if="leave.substitute_id === authStore.user?.id && leave.substitute_status === 'Rejected'" class="text-xs text-red-600 font-semibold px-3 py-1.5">
                  ปฏิเสธแล้ว
                </span>
              </div>
            </div>

            <!-- Workflow Timeline Toggle & Component -->
            <div class="mt-4 pt-3 border-t border-gray-100">
              <button
                type="button"
                @click="toggleTimeline(leave.id)"
                class="text-xs font-semibold text-[#800000] hover:text-[#600000] flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#800000] rounded py-1 px-1.5 transition-colors"
                :aria-expanded="expandedLeaveIds.includes(leave.id)"
              >
                <svg
                  class="w-4 h-4 transition-transform duration-200"
                  :class="expandedLeaveIds.includes(leave.id) ? 'rotate-180' : ''"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
                <span>{{ expandedLeaveIds.includes(leave.id) ? 'ซ่อนขั้นตอนและเอกสารประกอบ' : 'ดูขั้นตอนการอนุมัติ หลักฐาน และประวัติการดำเนินการ' }}</span>
              </button>

              <transition name="slide-down">
                <div v-if="expandedLeaveIds.includes(leave.id)" class="mt-4 pt-4 border-t border-dashed border-gray-200 space-y-5">
                  <WorkflowTimeline :leave="leave" />

                  <!--
                    CLEANUP-01 (D10) — บริการและเอกสารใบลา ย้ายมารวมที่นี่
                    เดิมอยู่ที่หน้า /leave-services ซึ่งแสดง "ใบลาของฉัน" ซ้ำอีกหน้าหนึ่ง
                    ผู้ใช้ทั่วไปจึงเห็นสองเมนูที่ดูเหมือนกัน
                  -->
                  <div class="grid gap-4 lg:grid-cols-2">
                    <LeaveEvidenceManager :leave-id="leave.id" />

                    <section class="rounded-xl border border-gray-100 p-4">
                      <h4 class="font-bold text-sm text-gray-800">บริการเพิ่มเติม</h4>
                      <div class="mt-3 flex flex-wrap gap-2">
                        <button
                          type="button"
                          class="rounded-lg border border-gray-200 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                          @click="showAudit(leave)"
                        >
                          ประวัติการดำเนินการ
                        </button>
                        <button
                          v-if="leave.status === 'Approved'"
                          type="button"
                          class="rounded-lg border border-amber-300 px-3 py-2 text-xs font-semibold text-amber-800 hover:bg-amber-50"
                          @click="openPartialCancel(leave)"
                        >
                          ขอยกเลิกบางช่วง
                        </button>
                      </div>
                    </section>
                  </div>
                </div>
              </transition>
            </div>
          </div>
        </div>

        <!-- Pagination -->
        <div v-if="totalPages > 1" class="flex items-center justify-between py-4">
          <span class="text-sm text-gray-500">แสดง {{ (currentPage - 1) * itemsPerPage + 1 }} ถึง {{ Math.min(currentPage * itemsPerPage, filteredLeaves.length) }} จาก {{ filteredLeaves.length }} รายการ</span>
          <div class="flex items-center gap-1">
            <button @click="changePage(currentPage - 1)" :disabled="currentPage === 1" class="px-3 py-1.5 text-sm border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
              ก่อนหน้า
            </button>
            <span class="text-sm text-gray-700 px-3">หน้า {{ currentPage }} / {{ totalPages }}</span>
            <button @click="changePage(currentPage + 1)" :disabled="currentPage === totalPages" class="px-3 py-1.5 text-sm border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
              ถัดไป
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Cancel Confirmation Modal -->
    <transition name="fade">
      <div v-if="showCancelModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6">
          <div class="w-14 h-14 rounded-full flex items-center justify-center mx-auto mb-4"
            :class="cancelTarget?.status === 'Approved' ? 'bg-yellow-100' : 'bg-red-100'">
            <!-- ไอคอนสำหรับ Approved = ส่งคำขอยกเลิก -->
            <svg v-if="cancelTarget?.status === 'Approved'" class="w-7 h-7 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <!-- ไอคอนสำหรับ Pending = ยกเลิกทันที -->
            <svg v-else class="w-7 h-7 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
          </div>

          <!-- หัวข้อต่างกันตามสถานะใบลา -->
          <h3 class="font-bold text-gray-900 text-lg text-center mb-1">
            <template v-if="cancelTarget?.status === 'Approved'">ส่งคำขอยกเลิกใบลา</template>
            <template v-else>ยืนยันยกเลิกใบลา</template>
          </h3>

          <!-- คำเตือนสำหรับ Approved leave (ทุก Role) -->
          <div v-if="cancelTarget?.status === 'Approved'"
            class="bg-yellow-50 border border-yellow-200 rounded-lg px-3 py-2 mb-3 text-xs text-yellow-800 text-center">
            <svg class="w-4 h-4 inline-block align-text-bottom mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.warning"/>
            </svg>
            ใบลานี้ผ่านการอนุมัติแล้ว การยกเลิกจะต้องรอ<strong>การอนุมัติจากผู้มีอำนาจ</strong>อีกครั้ง
          </div>

          <p class="text-gray-500 text-sm text-center mb-2">
            ประเภท: <strong>{{ cancelTarget?.type?.name }}</strong>
          </p>
          <p class="text-gray-400 text-xs text-center mb-4">
            วันที่ {{ ThaiDateFormatter.toThaiSlash(cancelTarget?.start_date) }}
            — {{ ThaiDateFormatter.toThaiSlash(cancelTarget?.end_date) }}
            ({{ cancelTarget?.total_days }} วัน)
          </p>

          <!-- เหตุผลการขอยกเลิก (บังคับสำหรับ Approved leave) -->
          <div v-if="cancelTarget?.status === 'Approved'" class="mb-4">
            <label class="block text-xs font-semibold text-gray-700 mb-1">
              เหตุผลการขอยกเลิก <span class="text-red-500">*</span>
            </label>
            <textarea aria-label="เหตุผลในการขอยกเลิกใบลา" v-model="cancellationReason" rows="2" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:border-[#800000] resize-none" placeholder="ระบุเหตุผล..."></textarea>
          </div>

          <div class="flex gap-3">
            <button @click="showCancelModal = false"
              class="flex-1 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold rounded-xl text-sm transition-colors">
              ยกเลิก
            </button>
            <button @click="doCancel"
              :disabled="cancelLoading !== null"
              class="flex-1 py-2.5 text-white font-semibold rounded-xl text-sm transition-colors disabled:opacity-60"
              :class="cancelTarget?.status === 'Approved' ? 'bg-yellow-500 hover:bg-yellow-600' : 'bg-red-600 hover:bg-red-700'">
              <template v-if="cancelLoading">กำลังดำเนินการ...</template>
              <template v-else-if="cancelTarget?.status === 'Approved'">ส่งคำขอยกเลิก</template>
              <template v-else>ยืนยันยกเลิก</template>
            </button>
          </div>
        </div>
      </div>
    </transition>

    <!-- ประวัติการดำเนินการ (CLEANUP-01 · D10 — ย้ายมาจากหน้า /leave-services) -->
    <div v-if="auditTarget" class="fixed inset-0 z-50 grid place-items-center bg-black/50 backdrop-blur-sm p-4" @click.self="auditTarget = null">
      <section role="dialog" aria-modal="true" aria-labelledby="audit-title" class="max-h-[85vh] w-full max-w-2xl overflow-auto rounded-2xl bg-white p-6">
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 id="audit-title" class="text-xl font-bold text-gray-900">ประวัติการดำเนินการ</h2>
            <p class="text-sm text-gray-500">{{ auditTarget.document_number || `รายการ #${auditTarget.id}` }}</p>
          </div>
          <button type="button" aria-label="ปิด" class="p-2 rounded-lg text-gray-400 hover:bg-gray-100" @click="auditTarget = null">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.x"/>
            </svg>
          </button>
        </div>
        <ol class="mt-4 space-y-3">
          <li v-for="log in auditLogs" :key="log.id" class="border-l-2 border-[#800000] pl-4">
            <p class="font-medium text-gray-800">{{ auditActionLabel(log.action) }}</p>
            <p class="text-xs text-gray-500">
              {{ ThaiDateFormatter.toThaiSlash(log.created_at?.slice(0, 10)) }}
              <span v-if="log.actor?.name" class="text-gray-400">· โดย {{ log.actor.name }}</span>
            </p>
          </li>
          <li v-if="!auditLogs.length" class="text-gray-500 text-sm">ยังไม่มีประวัติ</li>
        </ol>
      </section>
    </div>

    <!-- ขอยกเลิกวันลาบางช่วง (CLEANUP-01 · D10 — ย้ายมาจากหน้า /leave-services) -->
    <div v-if="partialTarget" class="fixed inset-0 z-50 grid place-items-center bg-black/50 backdrop-blur-sm p-4" @click.self="partialTarget = null">
      <form role="dialog" aria-modal="true" aria-labelledby="partial-title" class="w-full max-w-lg space-y-4 rounded-2xl bg-white p-6" @submit.prevent="submitPartialCancel">
        <h2 id="partial-title" class="text-xl font-bold text-gray-900">ขอยกเลิกวันลาบางช่วง</h2>
        <label class="block text-sm font-medium text-gray-700">
          ตั้งแต่วันที่
          <input v-model="partialForm.cancel_from" required type="date" class="mt-1 w-full rounded-lg border-gray-300 p-2 focus:ring-[#800000] focus:border-[#800000]">
        </label>
        <label class="block text-sm font-medium text-gray-700">
          ถึงวันที่
          <input v-model="partialForm.cancel_to" required type="date" class="mt-1 w-full rounded-lg border-gray-300 p-2 focus:ring-[#800000] focus:border-[#800000]">
        </label>
        <label class="block text-sm font-medium text-gray-700">
          เหตุผล
          <textarea v-model="partialForm.reason" required maxlength="1000" rows="3" class="mt-1 w-full rounded-lg border-gray-300 p-2 focus:ring-[#800000] focus:border-[#800000]"></textarea>
        </label>
        <div class="flex justify-end gap-2">
          <button type="button" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700" @click="partialTarget = null">ยกเลิก</button>
          <button type="submit" class="rounded-lg bg-[#800000] px-4 py-2 text-sm font-semibold text-white">ส่งคำขอ</button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useLeaveStore } from '../stores/leave'
import { useAuthStore } from '../stores/auth'
import { useAlertStore } from '../stores/alert'
import { compositeStatusLabel, compositeStatusBadgeClass } from '../utils/statusHelper'
import { ThaiDateFormatter } from '../utils/ThaiDateFormatter'
import { getErrorMessage } from '../utils/errorHandler'
import { buildFilename, filenameFromHeaders } from '../utils/downloadFilename'
import LoadingState from '../components/ui/LoadingState.vue'
import EmptyState from '../components/ui/EmptyState.vue'
import { ICONS } from '../utils/icons'
import ErrorState from '../components/ui/ErrorState.vue'
import WorkflowTimeline from '../components/ui/WorkflowTimeline.vue'
import LeaveEvidenceManager from '../components/LeaveEvidenceManager.vue'
import api from '../axios'

const leaveStore = useLeaveStore()
const authStore  = useAuthStore()
const alertStore = useAlertStore()

const loading      = ref(true)
const loadError    = ref('')
const pdfLoading   = ref(null)
const wordLoading  = ref(null)
const cancelLoading = ref(null)
const submittingDraft = ref(null)
const showCancelModal = ref(false)
const cancelTarget = ref(null)
const cancellationReason = ref('')
const activeFilter = ref('all')
const viewMode = ref('mine')
const expandedLeaveIds = ref([])

function toggleTimeline(leaveId) {
  const index = expandedLeaveIds.value.indexOf(leaveId)
  if (index > -1) {
    expandedLeaveIds.value.splice(index, 1)
  } else {
    expandedLeaveIds.value.push(leaveId)
  }
}

const currentPage = ref(1)
const itemsPerPage = 10

const filters = [
  { label: 'ทั้งหมด',    value: 'all'       },
  { label: 'ร่าง',        value: 'Draft'     },
  { label: 'รออนุมัติ', value: 'Pending'   },
  { label: 'อนุมัติแล้ว', value: 'Approved'  },
  { label: 'ไม่อนุมัติ', value: 'Rejected'  },
  { label: 'ยกเลิกแล้ว', value: 'Cancelled' },
  { label: 'งานที่ได้รับมอบหมาย', value: 'Substitute' },
]

const allAllowed = computed(() => authStore.isSuperAdmin || authStore.isHRHead || authStore.isManager || authStore.hasAnyPermission(['leave.report.view', 'leave.approval.dept', 'leave.approval.director']))
const isAdminOrHR = computed(() => authStore.isSuperAdmin || authStore.isHRHead || authStore.hasPermission('leave.management.manage'))

const canCancel = (leave) => {
  if (leave.status !== 'Pending') return false;
  if (isAdminOrHR.value) return true;
  if (leave.user_id !== authStore.user?.id) return false;
  
  const createdDate = new Date(leave.created_at);
  const now = new Date();
  const diffTime = Math.abs(now - createdDate);
  const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)); 
  return diffDays <= 5;
}

/*
 * ขอยกเลิกใบลาที่อนุมัติแล้ว
 *
 * เดิมเงื่อนไขบังคับให้ `cancellation_status === 'None'` เป๊ะ ๆ ใบลาที่คำขอยกเลิก
 * เคยถูกปฏิเสธ (`Rejected`) หรือแถวเก่าที่คอลัมน์ยังเป็น null จึงไม่มีปุ่มให้กดเลย
 * ทั้งที่ฝั่ง server ยอมรับคำขอใหม่ได้ — ปุ่มจะซ่อนเฉพาะตอนมีคำขอค้างอยู่จริงเท่านั้น
 */
const canRequestCancellation = (leave) => {
  if (leave.status !== 'Approved') return false
  if (leave.user_id !== authStore.user?.id && !isAdminOrHR.value) return false
  return leave.cancellation_status !== 'Pending' && leave.cancellation_status !== 'Approved'
}

/*
 * มีใบยกเลิกให้พิมพ์ก็ต่อเมื่อเคยยื่นคำขอยกเลิกจริง
 *
 * ใบที่ผู้ยื่นถอนเองตั้งแต่ยังรออนุมัติไม่เข้าข่าย เพราะไม่เคยมีเอกสารให้ยกเลิก
 * เงื่อนไข `Cancelled` คงไว้รองรับข้อมูลเก่าที่ยกเลิกก่อนระบบบันทึกสถานะคำขอยกเลิก
 */
const hasCancellationForm = (leave) =>
  ['Pending', 'Approved', 'Rejected'].includes(leave.cancellation_status)
  || (leave.status === 'Cancelled' && wasIssued(leave))

/*
 * ใบลานี้เคยได้รับอนุมัติจนออกเป็นเอกสารจริงแล้วหรือยัง — เงื่อนไขเดียวกับ `Leave::wasIssued()`
 * ฝั่ง server ซึ่งเป็นผู้บังคับจริง ตรงนี้เพียงไม่แสดงปุ่มที่กดแล้วจะถูกปฏิเสธ
 *
 * ใบที่อนุมัติแล้วภายหลังถูกยกเลิกหรือเพิกถอนยังนับว่าออกเอกสารไปแล้ว จึงยังพิมพ์ได้
 */
const wasIssued = (leave) =>
  leave.content_hash != null
  || leave.status === 'Approved'
  || ['Pending', 'Approved', 'Rejected'].includes(leave.cancellation_status)

// Show ALL leaves for non-employees if viewMode is 'all', but always ONLY OWN leaves for Employees or viewMode 'mine'
const myLeaves = computed(() => {
  if (!authStore.user) return []
  if (viewMode.value === 'all' && allAllowed.value) {
    return leaveStore.leaves
  }
  return leaveStore.leaves.filter(l => l.user_id === authStore.user.id)
})

const filteredLeaves = computed(() => {
  if (activeFilter.value === 'all') return myLeaves.value
  if (activeFilter.value === 'Substitute') return myLeaves.value.filter(l => l.substitute_id === authStore.user?.id)
  return myLeaves.value.filter(l => l.status === activeFilter.value)
})

const countByStatus = (status) => {
  if (status === 'Substitute') return myLeaves.value.filter(l => l.substitute_id === authStore.user?.id).length
  return myLeaves.value.filter(l => l.status === status).length
}

const totalPages = computed(() => Math.ceil(filteredLeaves.value.length / itemsPerPage))

const paginatedLeaves = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage
  return filteredLeaves.value.slice(start, start + itemsPerPage)
})

const changePage = (p) => {
  if (p >= 1 && p <= totalPages.value) {
    currentPage.value = p
  }
}

/*
 * ชื่อไฟล์สำรอง — ใช้ต่อเมื่อคำตอบไม่มี `Content-Disposition` ให้อ่าน
 *
 * ต้องให้ผลตรงกับ `App\Support\DocumentFilename` ฝั่งเซิร์ฟเวอร์ ผู้ใช้จึงได้ชื่อไฟล์
 * แบบเดียวกันเสมอ ไม่ว่าเส้นทางไหนเป็นคนตั้งชื่อ (ดู `LeaveDocumentController::filenameFor`)
 */
const generateFilename = (leave, extension, isCancel = false) =>
  buildFilename([
    isCancel ? 'ใบยกเลิกวันลา' : 'ใบลา',
    leave?.type?.name || 'การลา',
    leave?.user?.name || authStore.user?.name,
    leave?.document_number || leave?.id,
  ], extension)

/*
 * เมื่อขอเป็น blob ตัว error body จะเป็น Blob ไม่ใช่ JSON
 * ต้องอ่านกลับมาเป็นข้อความก่อน ผู้ใช้จึงจะเห็นสาเหตุจริง
 * (เช่น 429 "ส่งคำขอถี่เกินไป") แทนข้อความรวม ๆ ว่าดาวน์โหลดไม่สำเร็จ
 */
const readBlobError = async (error) => {
  const body = error?.response?.data
  if (!(body instanceof Blob)) return
  try {
    error.response.data = JSON.parse(await body.text())
  } catch {
    error.response.data = null
  }
}

/*
 * LS-07 — ชื่อไฟล์และชนิดไฟล์ต้องมาจากคำตอบของเซิร์ฟเวอร์
 *
 * เดิมหน้าจอตั้งชื่อเป็น `.docx` และประกาศ MIME ของ DOCX เอง ขณะที่เซิร์ฟเวอร์ส่ง Word
 * แบบ HTML (`.doc`) ผู้ใช้จึงได้ไฟล์ที่นามสกุลไม่ตรงกับเนื้อใน และเปิดแล้วโปรแกรมเตือน
 *
 * ตอนนี้อ่านจาก `Content-Disposition` กับ `Content-Type` ที่เซิร์ฟเวอร์ประกาศ
 * ถ้าวันหนึ่งเปลี่ยนไปออกเป็น DOCX จริง หน้าจอก็ตามได้เองโดยไม่ต้องแก้
 */
const downloadLeaveDocument = async (leave, { format, isCancel = false }) => {
  const loadingRef = format === 'pdf' ? pdfLoading : wordLoading
  const loadingKey = isCancel ? 'cancel_' + leave.id : leave.id
  if (loadingRef.value === loadingKey) return
  loadingRef.value = loadingKey
  try {
    const path = format === 'pdf' ? 'pdf' : 'word'
    const response = await api.get(`/leaves/${leave.id}/${path}${isCancel ? '?form=cancel' : ''}`, { responseType: 'blob' })

    // ชนิดและชื่อไฟล์ยึดตามที่เซิร์ฟเวอร์ประกาศ ถอยไปใช้ค่าที่ตั้งเองเฉพาะเมื่อไม่มี header
    const contentType = response.headers?.['content-type'] || (format === 'pdf' ? 'application/pdf' : 'application/vnd.ms-word')
    const serverFilename = filenameFromHeaders(response.headers)
    const fallbackExtension = format === 'pdf' ? 'pdf' : 'doc'

    const url = window.URL.createObjectURL(new Blob([response.data], { type: contentType }))
    const link = document.createElement('a')
    link.href = url
    link.setAttribute('download', serverFilename || generateFilename(leave, fallbackExtension, isCancel))
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  } catch (e) {
    await readBlobError(e)
    console.error('Download error:', e)
    const label = isCancel ? `${format === 'pdf' ? 'PDF' : 'Word'} ใบยกเลิก` : (format === 'pdf' ? 'PDF' : 'Word')
    alertStore.error(getErrorMessage(e, `ไม่สามารถดาวน์โหลด ${label} ได้`))
  } finally {
    loadingRef.value = null
  }
}

const downloadPdf = (leave) => downloadLeaveDocument(leave, { format: 'pdf' })
const downloadWord = (leave) => downloadLeaveDocument(leave, { format: 'word' })
const downloadCancelPdf = (leave) => downloadLeaveDocument(leave, { format: 'pdf', isCancel: true })
const downloadCancelWord = (leave) => downloadLeaveDocument(leave, { format: 'word', isCancel: true })

const confirmCancel = (leave) => {
  cancelTarget.value = leave
  cancellationReason.value = ''
  showCancelModal.value = true
}

const doCancel = async () => {
  if (!cancelTarget.value) return
  if (cancelTarget.value.status === 'Approved' && !cancellationReason.value.trim()) {
    alertStore.error('กรุณาระบุเหตุผลการขอยกเลิก')
    return
  }
  cancelLoading.value = cancelTarget.value.id
  try {
    await leaveStore.cancelLeave(cancelTarget.value.id, cancellationReason.value)
    alertStore.success('ดำเนินการยกเลิกใบลาเรียบร้อยแล้ว')
    showCancelModal.value = false
    cancelTarget.value = null
  } catch (e) {
    alertStore.error(e?.response?.data?.message || 'ยกเลิกไม่สำเร็จ กรุณาลองใหม่')
  } finally {
    cancelLoading.value = null
  }
}

/*
 * ลบได้เฉพาะร่างเท่านั้น — ใบที่ยื่นแล้วต้องใช้ "ยกเลิกใบลา" หรือ "เพิกถอนเอกสาร"
 * เพื่อให้ตรวจสอบย้อนหลังได้ ฝั่ง server เป็นผู้บังคับจริง ตรงนี้เพียงไม่แสดงปุ่ม
 */
const deleteLeave = async (leaveId) => {
  if (!confirm('ยืนยันการลบร่างใบลานี้?')) return
  try {
    await api.delete(`/leaves/${leaveId}`)
    alertStore.success('ลบร่างใบลาเรียบร้อยแล้ว')
    await leaveStore.fetchLeaves()
  } catch (e) {
    alertStore.error(e?.response?.data?.message || 'ลบไม่สำเร็จ')
  }
}

/** ส่งร่างเข้าสายอนุมัติ — จุดที่ใบลาออกเลขที่หนังสือและกันวันลา */
const submitDraft = async (leave) => {
  if (!confirm('ส่งร่างนี้เข้าสายอนุมัติ? เมื่อส่งแล้วจะออกเลขที่หนังสือและลบไม่ได้อีก')) return
  submittingDraft.value = leave.id
  try {
    await leaveStore.submitDraft(leave.id)
    alertStore.success('ส่งคำขอลาเรียบร้อยแล้ว')
  } catch (e) {
    const errors = e?.response?.data?.errors
    alertStore.error(errors ? Object.values(errors).flat().join(' ') : (e?.response?.data?.message || 'ส่งคำขอไม่สำเร็จ'))
  } finally {
    submittingDraft.value = null
  }
}

const acceptSubstitute = async (leaveId) => {
  try {
    await leaveStore.acceptSubstitute(leaveId)
    alertStore.success('รับทราบการปฏิบัติหน้าที่แทนแล้ว')
  } catch (e) {
    alertStore.error(e?.response?.data?.message || 'เกิดข้อผิดพลาด')
  }
}

const rejectSubstitute = async (leaveId) => {
  try {
    await leaveStore.rejectSubstitute(leaveId)
    alertStore.success('ปฏิเสธการปฏิบัติหน้าที่แทนแล้ว')
  } catch (e) {
    alertStore.error(e?.response?.data?.message || 'เกิดข้อผิดพลาด')
  }
}

const loadLeaves = async () => {
  loading.value = true
  loadError.value = ''
  try {
    await leaveStore.fetchLeaves()
  } catch (e) {
    console.error(e)
    loadError.value = getErrorMessage(e, 'ไม่สามารถโหลดรายการใบลาได้')
  } finally {
    loading.value = false
  }
}

/*
 * บริการและเอกสารใบลา (CLEANUP-01 · D10)
 *
 * ย้ายมาจากหน้า `/leave-services` ที่แสดงรายการ "ใบลาของฉัน" ซ้ำอีกหน้าหนึ่ง
 * ต่างกันแค่ปุ่มบริการ ผู้ใช้ทั่วไปจึงเห็นสองเมนูที่ดูเหมือนกัน
 */
const auditTarget = ref(null)
const auditLogs = ref([])
const partialTarget = ref(null)
const partialForm = ref({ cancel_from: '', cancel_to: '', reason: '' })

/*
 * ชื่อการกระทำในประวัติ (CLEANUP-02 · D6)
 *
 * ประวัติอ่านจากทะเบียนกลาง `audit_logs` ซึ่งรวมทั้งสองแหล่งของโมดูลลาไว้แล้ว
 * ชื่อจึงมีทั้งรูปแบบ `leave.<action>` และ `leave.action.<action>`
 */
const AUDIT_ACTION_LABELS = {
  created: 'ยื่นใบลา',
  updated: 'แก้ไขใบลา',
  evidence_uploaded: 'เพิ่มหลักฐาน',
  evidence_verified: 'ตรวจสอบหลักฐาน',
  voided: 'ยกเลิกเอกสาร',
  approval_approved: 'อนุมัติ',
  approval_rejected: 'ไม่อนุมัติ',
  cancellation_approved: 'อนุมัติการยกเลิก',
  cancellation_rejected: 'ไม่อนุมัติการยกเลิก',
  partial_cancellation_Approved: 'อนุมัติยกเลิกบางช่วง',
  partial_cancellation_Rejected: 'ไม่อนุมัติยกเลิกบางช่วง',
  Cancelled: 'ยกเลิกใบลา',
  'Cancellation Requested': 'ขอยกเลิกใบลา',
  Submitted: 'ส่งคำขอ',
  Deleted: 'ลบใบลา',
}

const auditActionLabel = (action) => {
  const key = String(action || '').replace(/^leave\.(action\.)?/, '')
  return AUDIT_ACTION_LABELS[key] || key.replace(/\s*\(Level \d+\)$/, '') || action
}

const showAudit = async (leave) => {
  auditTarget.value = leave
  auditLogs.value = []
  try {
    const { data } = await api.get(`/leaves/${leave.id}/audit`)
    auditLogs.value = data.data || data
  } catch (e) {
    alertStore.error(getErrorMessage(e, 'โหลดประวัติการดำเนินการไม่สำเร็จ'))
  }
}

const openPartialCancel = (leave) => {
  partialTarget.value = leave
  partialForm.value = {
    cancel_from: String(leave.start_date).slice(0, 10),
    cancel_to: String(leave.end_date).slice(0, 10),
    reason: '',
  }
}

const submitPartialCancel = async () => {
  try {
    await api.post(`/leaves/${partialTarget.value.id}/partial-cancellation`, partialForm.value)
    alertStore.success('ส่งคำขอยกเลิกบางช่วงแล้ว')
    partialTarget.value = null
    // การยกเลิกบางช่วงคืนวันลาที่ตัดไปแล้วบางส่วน ยอดคงเหลือจึงต้องดึงใหม่ด้วย
    await Promise.all([loadLeaves(), leaveStore.fetchBalances()])
  } catch (e) {
    alertStore.error(
      Object.values(e.response?.data?.errors || {})[0]?.[0]
        || getErrorMessage(e, 'ส่งคำขอไม่สำเร็จ'),
    )
  }
}

onMounted(() => {
  loadLeaves()
})
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>
