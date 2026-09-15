<template>
  <div>
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
      <div>
        <div class="flex items-center gap-3">
          <h1 class="text-2xl font-bold text-gray-900">จัดการลำดับการอนุมัติ</h1>
          <span v-if="hasUnsavedChanges" class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 animate-pulse">
            ยังไม่ได้บันทึก
          </span>
        </div>
        <p class="text-gray-500 mt-1 text-sm">กำหนดขั้นตอนและเงื่อนไขการค้นหาผู้อนุมัติใบลาตามโครงสร้างบุคลากรและหน่วยงาน</p>
      </div>

      <div class="flex items-center gap-2 flex-wrap">
        <button
          @click="fetchWorkflow"
          :disabled="loading || saving"
          class="flex items-center gap-1.5 px-4 py-2.5 bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 font-semibold rounded-xl text-sm transition-all shadow-sm disabled:opacity-50 min-h-[44px]"
          title="โหลดข้อมูลล่าสุด"
        >
          <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          รีเฟรช
        </button>

        <button
          @click="openPreview"
          :disabled="loading || saving || (steps.length === 0 && !isCancellationChain)"
          class="flex items-center gap-1.5 px-4 py-2.5 bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 font-semibold rounded-xl text-sm transition-all shadow-sm disabled:opacity-50 min-h-[44px]"
          title="ทดลองว่าใครคือผู้อนุมัติจริงของแต่ละขั้น"
        >
          <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
          </svg>
          ดูตัวอย่างผู้อนุมัติจริง
        </button>

        <button
          @click="saveWorkflow"
          :disabled="saving || loading || (steps.length === 0 && !isCancellationChain)"
          class="flex items-center gap-2 px-5 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm transition-all shadow-md hover:shadow-lg disabled:opacity-50 min-h-[44px]"
        >
          <div v-if="saving" class="animate-spin h-4 w-4 rounded-full border-2 border-white border-t-transparent"></div>
          <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2v-3M9 19v-6a2 2 0 00-2-2H5m14 0a2 2 0 012 2v2m-6 4h3m-3 0h-3"/>
          </svg>
          {{ saving ? 'กำลังบันทึก...' : 'บันทึกการตั้งค่า' }}
        </button>
      </div>
    </div>

    <!-- Chain switcher -->
    <div class="mb-6 flex flex-wrap gap-2 border-b border-gray-200">
      <button
        v-for="c in chains"
        :key="c.value"
        @click="switchChain(c.value)"
        :disabled="saving"
        class="px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px transition-colors min-h-[44px] disabled:opacity-50"
        :class="activeChain === c.value
          ? 'border-[#800000] text-[#800000]'
          : 'border-transparent text-gray-500 hover:text-gray-800'"
      >
        {{ c.label }}
      </button>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center py-16">
      <div class="animate-spin h-10 w-10 rounded-full border-4 border-[#800000] border-t-transparent"></div>
    </div>

    <!-- Content Grid -->
    <div v-else class="grid grid-cols-1 lg:grid-cols-3 gap-8">
      <!-- Info sidebar -->
      <div class="lg:col-span-1 space-y-4">
        <!--
          ตรวจความพร้อมของข้อมูลก่อนใบลาค้าง

          ตัวหาผู้อนุมัติเป็น fail-closed — หาไม่เจอคือไม่มีใครมีสิทธิ์ ผู้ใช้จึงเห็นแค่
          "ใบลาค้าง" โดยไม่มีข้อความบอกสาเหตุ กล่องนี้เดินตัวหาผู้อนุมัติตัวเดียวกัน
          ให้ครบทุกคนในองค์กร แล้วบอกล่วงหน้าว่าใครจะติดและเพราะอะไร
        -->
        <div class="rounded-2xl border p-5 text-sm" :class="readinessTone.box">
          <div class="flex items-start justify-between gap-2 mb-2">
            <h3 class="font-bold flex items-center gap-2" :class="readinessTone.title">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
              ความพร้อมของข้อมูล
            </h3>
            <button
              @click="runReadiness"
              :disabled="readiness.loading"
              class="text-xs font-semibold underline underline-offset-2 disabled:opacity-50"
              :class="readinessTone.title"
            >
              {{ readiness.loading ? 'กำลังตรวจ...' : 'ตรวจอีกครั้ง' }}
            </button>
          </div>

          <p v-if="readiness.error" class="text-gray-600">{{ readiness.error }}</p>

          <template v-else-if="readiness.data">
            <p class="font-semibold mb-3">{{ readinessSummary }}</p>

            <!-- ขั้นที่ตั้งค่าไว้แล้วใช้ไม่ได้จริง หรือมีคนติด -->
            <ul v-if="readinessStepIssues.length" class="space-y-2 mb-3">
              <li v-for="issue in readinessStepIssues" :key="issue.step_order" class="rounded-xl bg-white/70 px-3 py-2">
                <p class="font-semibold text-gray-800">ขั้นที่ {{ issue.step_order }} · {{ issue.name }}</p>
                <p v-if="issue.config_problem" class="text-xs text-gray-600 mt-0.5">{{ issue.config_problem }}</p>
                <p v-if="issue.no_approver_count" class="text-xs text-gray-600 mt-0.5">
                  ไม่มีผู้อนุมัติสำหรับผู้ยื่น {{ issue.no_approver_count }} คน
                  <span v-if="issue.no_approver_sample.length" class="text-gray-500">
                    ({{ issue.no_approver_sample.map(u => u.name).join(', ') }})
                  </span>
                </p>
                <p v-if="issue.self_only_count" class="text-xs text-gray-600 mt-0.5">
                  ผู้อนุมัติที่หาได้คือผู้ยื่นเอง {{ issue.self_only_count }} คน — อนุมัติใบของตนเองไม่ได้
                  <span v-if="issue.self_only_sample.length" class="text-gray-500">
                    ({{ issue.self_only_sample.map(u => u.name).join(', ') }})
                  </span>
                </p>
              </li>
            </ul>

            <!-- ข้อมูลบุคลากรที่ยังไม่ครบ -->
            <ul v-if="readinessDataGaps.length" class="space-y-1.5 text-xs">
              <li v-for="gap in readinessDataGaps" :key="gap.key" class="flex gap-2">
                <span class="text-gray-400">•</span>
                <span class="text-gray-700">{{ gap.text }}</span>
              </li>
            </ul>
          </template>

          <p v-else-if="!readiness.loading" class="text-gray-600">ยังไม่ได้ตรวจ</p>
        </div>

        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5 text-amber-900 text-sm">
          <h3 class="font-bold flex items-center gap-2 text-amber-800 mb-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            หลักการทำงานและการค้นหาผู้อนุมัติ
          </h3>
          <p class="mb-2">ระบบค้นหาผู้อนุมัติอัตโนมัติตาม<strong>สังกัดของผู้ยื่นคำขอ</strong> และโครงสร้างหน่วยงาน</p>
          <p class="mb-2">คำขอลาเดิมที่สร้างไว้แล้วจะยึด <strong>สำเนาขั้นตอน</strong> ณ เวลาที่สร้างคำขอ ไม่ถูกเปลี่ยนแปลงย้อนหลัง</p>
          <p>รองรับการทำหน้าที่แทนของทุกระดับอย่างถูกต้อง</p>
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 space-y-3">
          <h4 class="font-bold text-gray-800 text-sm flex items-center justify-between">
            <span>รูปแบบผู้อนุมัติที่รองรับ</span>
            <span class="text-xs font-normal text-gray-500">{{ resolverOptions.length }} รูปแบบ</span>
          </h4>
          <div class="space-y-2">
            <div v-for="res in resolverOptions" :key="res.value" class="flex justify-between items-center text-xs py-1.5 border-b border-gray-50 last:border-0">
              <span class="font-semibold text-gray-700">{{ res.label }}</span>
              <span class="text-gray-400 font-mono text-[10px]">{{ res.value }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Workflow Timeline / Steps Builder -->
      <div class="lg:col-span-2 space-y-4">
        <!-- สายยกเลิกที่ยังไม่ได้ตั้งค่าแยก = ใช้สายเดียวกับใบลา -->
        <div v-if="isCancellationChain" class="rounded-2xl border p-4 text-sm"
          :class="steps.length === 0 ? 'bg-blue-50 border-blue-200 text-blue-900' : 'bg-white border-gray-100 text-gray-600 shadow-sm'">
          <p v-if="steps.length === 0">
            <span class="font-bold">ขณะนี้คำขอยกเลิกใบลาใช้สายอนุมัติเดียวกับใบลา</span> —
            เพิ่มขั้นตอนด้านล่างเมื่อต้องการให้การยกเลิกใช้สายที่ต่างออกไป เช่นสั้นกว่าหรือเสนอผู้อนุมัติคนละคน
          </p>
          <div v-else class="flex flex-wrap items-center justify-between gap-3">
            <span>คำขอยกเลิกใช้สายอนุมัติเฉพาะของตัวเองตามที่ตั้งค่าไว้ด้านล่าง</span>
            <button
              @click="useLeaveChainForCancellation"
              :disabled="saving"
              class="px-4 py-2 rounded-xl border border-gray-200 text-gray-700 font-semibold text-xs hover:bg-gray-50 disabled:opacity-50 min-h-[40px]"
            >
              กลับไปใช้สายเดียวกับใบลา
            </button>
          </div>
        </div>

        <!-- Empty State -->
        <div v-if="steps.length === 0 && !isCancellationChain" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-12 text-center">
          <svg class="mx-auto h-12 w-12 text-gray-300 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
          </svg>
          <h3 class="text-gray-700 font-bold text-base mb-1">ยังไม่มีขั้นตอนการอนุมัติ</h3>
          <p class="text-gray-400 text-sm mb-6">กรุณาสร้างขั้นตอนการอนุมัติอย่างน้อย 1 ขั้นตอน เพื่อเปิดใช้งานกระบวนการยื่นคำขอลา</p>
          <button
            @click="addStep"
            class="px-5 py-2.5 bg-[#800000] hover:bg-[#600000] text-white text-sm font-semibold rounded-xl transition-all inline-flex items-center gap-1.5 min-h-[44px]"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            เพิ่มขั้นตอนแรก
          </button>
        </div>

        <div v-if="steps.length > 0 || isCancellationChain" class="space-y-4">
          <!-- Steps Loop -->
          <div
            v-for="(step, index) in steps"
            :key="index"
            class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:border-gray-200 transition-colors relative flex flex-col sm:flex-row gap-4 items-start sm:items-center"
          >
            <!-- Step index badge -->
            <div class="w-9 h-9 rounded-full bg-[#800000]/10 text-[#800000] font-bold text-sm flex items-center justify-center flex-shrink-0">
              {{ index + 1 }}
            </div>

            <!-- Form fields -->
            <div class="flex-1 grid grid-cols-1 md:grid-cols-2 gap-4 w-full">
              <div class="md:col-span-2">
                <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">
                  ชื่อขั้นตอนการทำงาน <span class="text-red-500">*</span>
                </label>
                <input
                  aria-label="ชื่อขั้นตอนการอนุมัติ"
                  v-model="step.name"
                  type="text"
                  required
                  placeholder="เช่น ตรวจสอบโดยหัวหน้าแผนก / เสนอผู้อำนวยการ"
                  class="w-full px-4 py-2.5 rounded-xl border border-gray-200 focus:outline-none focus:border-[#800000] text-sm transition-colors"
                />
              </div>

              <div>
                <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">
                  เงื่อนไขผู้อนุมัติ (Resolver) <span class="text-red-500">*</span>
                </label>
                <select
                  aria-label="วิธีค้นหาผู้อนุมัติของขั้นตอนนี้"
                  v-model="step.approver_type"
                  required
                  class="w-full px-4 py-2.5 rounded-xl border border-gray-200 focus:outline-none focus:border-[#800000] text-sm transition-colors text-gray-700 bg-white"
                >
                  <option v-for="res in resolverOptions" :key="res.value" :value="res.value">
                    {{ res.label }}
                  </option>
                </select>
              </div>

              <!-- Conditional: Specific Role -->
              <div v-if="step.approver_type === 'ROLE'">
                <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">
                  บทบาทที่รับผิดชอบ <span class="text-red-500">*</span>
                </label>
                <select
                  aria-label="บทบาทของผู้อนุมัติ"
                  v-model="step.role_id"
                  required
                  class="w-full px-4 py-2.5 rounded-xl border border-gray-200 focus:outline-none focus:border-[#800000] text-sm transition-colors text-gray-700 bg-white"
                >
                  <option value="" disabled>เลือกบทบาท</option>
                  <option v-for="role in approvableRoles" :key="role.id" :value="role.id">
                    {{ roleLabel(role.name) }}
                  </option>
                </select>
              </div>

              <!-- Conditional: Specific User -->
              <div v-else-if="step.approver_type === 'SPECIFIC_USER'">
                <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">
                  ระบุผู้ใช้งานเฉพาะเจาะจง <span class="text-red-500">*</span>
                </label>
                <select
                  aria-label="ผู้อนุมัติที่ระบุตัวบุคคล"
                  v-model="step.specific_user_id"
                  required
                  class="w-full px-4 py-2.5 rounded-xl border border-gray-200 focus:outline-none focus:border-[#800000] text-sm transition-colors text-gray-700 bg-white"
                >
                  <option value="" disabled>เลือกผู้ใช้งาน</option>
                  <option v-for="u in usersList" :key="u.id" :value="u.id">
                    {{ u.personnel?.full_name || u.name }} ({{ u.email }})
                  </option>
                </select>
              </div>

              <!-- Info hint for automatic resolvers -->
              <div v-else class="flex items-center text-xs text-blue-700 bg-blue-50 p-2.5 rounded-xl">
                <svg class="w-4 h-4 mr-1.5 text-blue-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>ระบบจะค้นหาผู้ดำรงตำแหน่ง/หัวหน้าหน่วยงานของผู้ยื่นคำขออัตโนมัติ</span>
              </div>
            </div>

            <!-- Reorder and actions buttons -->
            <div class="flex items-center gap-1.5 flex-shrink-0 self-end sm:self-center">
              <!-- Move Up -->
              <button
                @click="moveUp(index)"
                :disabled="index === 0 || saving"
                class="w-10 h-10 rounded-xl hover:bg-gray-100 text-gray-500 hover:text-gray-800 disabled:opacity-30 transition-colors flex items-center justify-center"
                title="เลื่อนขึ้น"
                aria-label="เลื่อนขั้นตอนขึ้น"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>
                </svg>
              </button>
              <!-- Move Down -->
              <button
                @click="moveDown(index)"
                :disabled="index === steps.length - 1 || saving"
                class="w-10 h-10 rounded-xl hover:bg-gray-100 text-gray-500 hover:text-gray-800 disabled:opacity-30 transition-colors flex items-center justify-center"
                title="เลื่อนลง"
                aria-label="เลื่อนขั้นตอนลง"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
              </button>
              <!-- Delete -->
              <button
                @click="confirmRemoveStep(index)"
                :disabled="saving"
                class="w-10 h-10 rounded-xl hover:bg-red-50 text-red-500 hover:text-red-700 transition-colors flex items-center justify-center"
                title="ลบขั้นตอนนี้"
                aria-label="ลบขั้นตอนนี้"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-4v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
              </button>
            </div>
          </div>

          <!-- Add step button at the bottom -->
          <button
            @click="addStep"
            :disabled="saving"
            class="w-full py-3.5 border-2 border-dashed border-gray-200 hover:border-[#800000]/40 text-gray-500 hover:text-[#800000] font-semibold rounded-2xl text-sm transition-all flex items-center justify-center gap-2 bg-gray-50/50 hover:bg-white hover:shadow-sm min-h-[48px]"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            เพิ่มขั้นตอนอนุมัติ
          </button>
        </div>
      </div>
    </div>

    <!-- Approver Preview Modal -->
    <div
      v-if="previewModal.show"
      class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4"
      @click.self="previewModal.show = false"
    >
      <div class="bg-white rounded-2xl max-w-2xl w-full p-6 shadow-2xl space-y-5 max-h-[90vh] overflow-y-auto">
        <div>
          <h3 class="text-lg font-bold text-gray-900">ดูตัวอย่างผู้อนุมัติจริง</h3>
          <p class="text-sm text-gray-500 mt-1">
            เลือกผู้ยื่นคำขอเพื่อดูว่าระบบจะหาใครเป็นผู้อนุมัติในแต่ละขั้น โดยใช้เกณฑ์เดียวกับตอนกดอนุมัติจริง
            <span v-if="hasUnsavedChanges" class="text-amber-700 font-semibold">(ทดลองจากการตั้งค่าที่ยังไม่ได้บันทึก)</span>
          </p>
        </div>

        <div class="flex flex-col sm:flex-row gap-3">
          <select
            aria-label="เลือกผู้ยื่นใบลาเพื่อทดลองแสดงสายอนุมัติ"
            v-model="previewModal.requesterId"
            class="flex-1 px-4 py-2.5 rounded-xl border border-gray-200 focus:outline-none focus:border-[#800000] text-sm bg-white text-gray-700"
          >
            <option value="" disabled>เลือกผู้ยื่นคำขอที่ต้องการทดลอง</option>
            <option v-for="u in usersList" :key="u.id" :value="u.id">
              {{ u.personnel?.full_name || u.name }} ({{ u.email }})
            </option>
          </select>
          <button
            @click="runPreview"
            :disabled="!previewModal.requesterId || previewModal.loading"
            class="px-5 py-2.5 rounded-xl bg-[#800000] text-white font-semibold hover:bg-[#600000] text-sm shadow-md transition min-h-[44px] disabled:opacity-50 flex items-center justify-center gap-2"
          >
            <div v-if="previewModal.loading" class="animate-spin h-4 w-4 rounded-full border-2 border-white border-t-transparent"></div>
            {{ previewModal.loading ? 'กำลังตรวจสอบ...' : 'ตรวจสอบ' }}
          </button>
        </div>

        <div v-if="previewModal.result" class="space-y-3">
          <div
            v-for="step in previewModal.result.steps"
            :key="step.step_order"
            class="border rounded-2xl p-4"
            :class="step.warning ? 'border-amber-200 bg-amber-50' : 'border-gray-100 bg-white'"
          >
            <div class="flex items-start gap-3">
              <div class="w-7 h-7 rounded-full bg-[#800000]/10 text-[#800000] font-bold text-xs flex items-center justify-center flex-shrink-0">
                {{ step.step_order }}
              </div>
              <div class="flex-1 min-w-0">
                <p class="font-semibold text-gray-900 text-sm">{{ step.name }}</p>
                <p class="text-[11px] text-gray-500 uppercase mt-0.5">{{ resolverLabel(step.approver_type) }}</p>

                <ul v-if="step.approvers.length" class="mt-2 space-y-1">
                  <li v-for="approver in step.approvers" :key="approver.id" class="text-sm text-gray-700">
                    • {{ approver.name }}
                    <span v-if="approver.position" class="text-gray-500">— {{ approver.position }}</span>
                  </li>
                </ul>

                <p v-if="step.warning" class="mt-2 text-sm text-amber-800 font-medium">{{ step.warning }}</p>
              </div>
            </div>
          </div>
        </div>

        <div class="flex justify-end pt-1">
          <button
            type="button"
            @click="previewModal.show = false"
            class="px-4 py-2.5 rounded-xl border border-gray-200 text-gray-700 font-medium hover:bg-gray-50 text-sm min-h-[44px]"
          >
            ปิด
          </button>
        </div>
      </div>
    </div>

    <!-- Delete Step Confirmation Modal -->
    <div
      v-if="deleteModal.show"
      class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4"
      @click.self="deleteModal.show = false"
    >
      <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="w-12 h-12 rounded-full bg-red-100 text-red-600 flex items-center justify-center mx-auto">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-4v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
          </svg>
        </div>
        <div class="text-center">
          <h3 class="text-lg font-bold text-gray-900">ยืนยันการลบขั้นตอน?</h3>
          <p class="text-sm text-gray-500 mt-1">
            คุณต้องการลบขั้นตอนที่ {{ deleteModal.index + 1 }}
            <span v-if="deleteModal.name" class="font-semibold text-gray-800">"{{ deleteModal.name }}"</span>
            หรือไม่?
          </p>
        </div>
        <div class="flex justify-end gap-3 pt-2">
          <button
            type="button"
            @click="deleteModal.show = false"
            class="px-4 py-2.5 rounded-xl border border-gray-200 text-gray-700 font-medium hover:bg-gray-50 text-sm min-h-[44px]"
          >
            ยกเลิก
          </button>
          <button
            type="button"
            @click="executeRemoveStep"
            class="px-4 py-2.5 rounded-xl bg-red-600 text-white font-medium hover:bg-red-700 text-sm shadow-md transition min-h-[44px]"
          >
            ยืนยันลบขั้นตอน
          </button>
        </div>
      </div>
    </div>

    <!-- 409 Conflict Concurrency Modal -->
    <div
      v-if="conflictModal.show"
      class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4"
    >
      <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="w-12 h-12 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center mx-auto">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
          </svg>
        </div>
        <div class="text-center">
          <h3 class="text-lg font-bold text-gray-900">ตรวจพบการแก้ไขซ้ำซ้อน (Conflict)</h3>
          <p class="text-sm text-gray-500 mt-2">
            ข้อมูลสายการอนุมัติถูกแก้ไขหรือบันทึกโดยผู้ดูแลระบบท่านอื่นก่อนหน้านี้ เพื่อความถูกต้องของข้อมูล กรุณารีเฟรชเพื่อโหลดข้อมูลล่าสุด
          </p>
        </div>
        <div class="flex justify-end gap-3 pt-2">
          <button
            type="button"
            @click="resolveConflictReload"
            class="w-full px-5 py-2.5 rounded-xl bg-[#800000] text-white font-semibold hover:bg-[#600000] text-sm shadow-md transition min-h-[44px] flex items-center justify-center gap-2"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            โหลดข้อมูลล่าสุดทันที
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { useConfirm } from '../../composables/useConfirm'
import { roleLabel } from '../../utils/roleLabel'

const alertStore = useAlertStore()
const { confirmAction } = useConfirm()

const loading = ref(false)
const saving = ref(false)
const roles = ref([])
const usersList = ref([])
const steps = ref([])
const initialStepsSnapshot = ref('')
const currentFingerprint = ref('')

const deleteModal = ref({
  show: false,
  index: -1,
  name: ''
})

const conflictModal = ref({
  show: false
})

const previewModal = ref({
  show: false,
  requesterId: '',
  loading: false,
  result: null
})

/*
 * สายอนุมัติที่กำลังจัดการอยู่
 *
 * สายของคำขอยกเลิกที่ "ไม่มีขั้นตอนเลย" หมายถึงใช้สายเดียวกับใบลา ซึ่งเป็นค่าเริ่มต้น
 * ของระบบ ผู้ดูแลที่ไม่ต้องการแยกสายจึงไม่ต้องตั้งค่าอะไรเพิ่ม
 */
const chains = [
  { value: 'leave', label: 'สายอนุมัติใบลา' },
  { value: 'cancellation', label: 'สายอนุมัติคำขอยกเลิกใบลา' },
]
const activeChain = ref('leave')
const inheritsLeaveChain = ref(false)

const isCancellationChain = computed(() => activeChain.value === 'cancellation')

const switchChain = async (chain) => {
  if (chain === activeChain.value) return
  if (hasUnsavedChanges.value && ! await confirmAction({
    title: 'สลับสายอนุมัติ',
    message: 'มีการเปลี่ยนแปลงที่ยังไม่ได้บันทึก ต้องการสลับสายอนุมัติหรือไม่?',
    detail: 'การเปลี่ยนแปลงที่ยังไม่ได้บันทึกจะหายไป',
    confirmText: 'สลับโดยไม่บันทึก',
    cancelText: 'อยู่สายเดิม',
  })) return
  activeChain.value = chain
  await fetchWorkflow()
}

// ล้างขั้นตอนของสายยกเลิกทั้งหมด = กลับไปใช้สายเดียวกับใบลา
const useLeaveChainForCancellation = () => {
  steps.value = []
  saveWorkflow()
}

const resolverOptions = [
  { value: 'DEPARTMENT_HEAD', label: 'หัวหน้าแผนกวิชา / หัวหน้างาน (ต้นสังกัดผู้ยื่น)' },
  { value: 'REQUESTER_SUPERVISOR', label: 'ผู้บังคับบัญชาชั้นต้น' },
  { value: 'DIVISION_DIRECTOR', label: 'หัวหน้าฝ่าย / รองผู้อำนวยการ' },
  { value: 'DIRECTOR', label: 'ผู้อำนวยการสถานศึกษา' },
  { value: 'HR_OFFICER', label: 'เจ้าหน้าที่งานบุคลากร' },
  { value: 'ROLE', label: 'กำหนดตามบทบาทระบบ (System Role)' },
  { value: 'SPECIFIC_USER', label: 'ระบุตัวบุคคลรายคน (Specific User)' },
]

const resolverLabel = (value) => {
  return resolverOptions.find(o => o.value === value)?.label || value || ''
}

// ทดลองว่าใครคือผู้อนุมัติจริง โดยใช้ขั้นตอนที่กำลังแก้อยู่บนหน้าจอ (รวมที่ยังไม่บันทึก)
const openPreview = () => {
  previewModal.value = { show: true, requesterId: '', loading: false, result: null }
}

const runPreview = async () => {
  if (!previewModal.value.requesterId) return
  previewModal.value.loading = true
  try {
    const res = await api.post('/admin/workflow-steps/preview', {
      chain: activeChain.value,
      requester_id: Number(previewModal.value.requesterId),
      steps: steps.value.map(s => ({
        name: (s.name || '').trim(),
        approver_type: s.approver_type || 'ROLE',
        role_id: s.role_id ? Number(s.role_id) : null,
        specific_user_id: s.specific_user_id ? Number(s.specific_user_id) : null
      }))
    })
    previewModal.value.result = res.data
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'ไม่สามารถตรวจสอบผู้อนุมัติได้')
  } finally {
    previewModal.value.loading = false
  }
}

/*
 * ตรวจความพร้อมของข้อมูลทั้งองค์กรเทียบกับสายที่กำลังแก้อยู่บนหน้าจอ
 *
 * ส่งขั้นตอนที่ยังไม่บันทึกไปด้วยเสมอ ผู้ดูแลจึงเห็นผลของสิ่งที่กำลังจะบันทึก
 * ไม่ใช่ผลของสายเดิม · เป็นการอ่านอย่างเดียว เรียกซ้ำได้ไม่มีผลข้างเคียง
 */
const readiness = ref({ loading: false, data: null, error: '' })

const runReadiness = async () => {
  readiness.value.loading = true
  readiness.value.error = ''
  try {
    const res = await api.post('/admin/workflow-steps/readiness', {
      chain: activeChain.value,
      steps: steps.value.map(s => ({
        name: (s.name || '').trim(),
        approver_type: s.approver_type || 'ROLE',
        role_id: s.role_id ? Number(s.role_id) : null,
        specific_user_id: s.specific_user_id ? Number(s.specific_user_id) : null,
      })),
    })
    readiness.value.data = res.data
  } catch (error) {
    readiness.value.data = null
    readiness.value.error = error.response?.data?.message || 'ตรวจความพร้อมของข้อมูลไม่สำเร็จ'
  } finally {
    readiness.value.loading = false
  }
}

const readinessStepIssues = computed(() =>
  (readiness.value.data?.steps || []).filter(
    s => s.config_problem || s.no_approver_count > 0 || s.self_only_count > 0,
  ),
)

const readinessSummary = computed(() => {
  const data = readiness.value.data
  if (!data) return ''
  if (!data.steps.length) return 'ยังไม่มีขั้นตอนให้ตรวจ'

  return data.blocked_users > 0
    ? `ผู้ยื่น ${data.blocked_users} จาก ${data.checked_users} คนจะติดอยู่ที่สายนี้`
    : `ตรวจผู้ใช้ ${data.checked_users} คน ไม่พบผู้ที่จะติดในสายนี้`
})

const readinessDataGaps = computed(() => {
  const gaps = readiness.value.data?.data_gaps
  if (!gaps) return []

  const list = []
  const names = (g) => (g.sample.length ? ` (${g.sample.map(u => u.name).join(', ')})` : '')

  if (gaps.without_org_unit.count) {
    list.push({ key: 'org', text: `ยังไม่มีสังกัด ${gaps.without_org_unit.count} คน${names(gaps.without_org_unit)}` })
  }
  if (gaps.without_personnel_type.count) {
    list.push({
      key: 'ptype',
      text: `ยังไม่ระบุประเภทบุคลากร ${gaps.without_personnel_type.count} คน${names(gaps.without_personnel_type)}`,
    })
  }
  if (gaps.units_without_manager.count) {
    const units = gaps.units_without_manager.sample.map(u => `${u.name} (${u.users} คน)`).join(', ')
    list.push({ key: 'unit', text: `หน่วยงานที่ยังไม่มีหัวหน้า ${gaps.units_without_manager.count} หน่วย: ${units}` })
  }

  return list
})

const readinessTone = computed(() => {
  const data = readiness.value.data
  if (!data || readiness.value.error) {
    return { box: 'bg-gray-50 border-gray-200', title: 'text-gray-700' }
  }

  return data.blocked_users > 0 || readinessStepIssues.value.length
    ? { box: 'bg-red-50 border-red-200 text-red-900', title: 'text-red-800' }
    : { box: 'bg-emerald-50 border-emerald-200 text-emerald-900', title: 'text-emerald-800' }
})

/*
 * ชื่อบทบาทภาษาไทยใช้ชุดเดียวกับทั้งระบบที่ `utils/roleLabel.js`
 *
 * เดิมหน้านี้มีตารางแปลชื่อของตัวเองที่ไม่ตรงกับชุดกลาง — `HR Head` แสดงเป็น
 * "หัวหน้าบุคลากร" และ `Employee` แสดงเป็น "พนักงานทั่วไป" ขณะที่หน้าจัดการบทบาท
 * และหน้ารายชื่อผู้ใช้เรียกสองบทบาทเดียวกันนี้ว่า "เจ้าหน้าที่บุคลากร (HR)" และ
 * "บุคลากร / ครู / เจ้าหน้าที่" ผู้ดูแลจึงเห็นชื่อบทบาทเดียวกันคนละชื่อในแต่ละหน้า
 */

const approvableRoles = computed(() => {
  return roles.value.filter(r => r.name !== 'Employee')
})

const serializeSteps = (stepList) => {
  return JSON.stringify(stepList.map(s => ({
    role_id: Number(s.role_id) || '',
    approver_type: s.approver_type || 'ROLE',
    specific_user_id: Number(s.specific_user_id) || '',
    name: (s.name || '').trim(),
    step_order: Number(s.step_order) || 0
  })))
}

const hasUnsavedChanges = computed(() => {
  if (loading.value) return false
  return serializeSteps(steps.value) !== initialStepsSnapshot.value
})

const reindexSteps = () => {
  steps.value.forEach((step, idx) => {
    step.step_order = idx + 1
  })
}

const fetchWorkflow = async () => {
  loading.value = true
  try {
    const [rolesRes, stepsRes, usersRes] = await Promise.all([
      api.get('/admin/roles'),
      api.get('/admin/workflow-steps', { params: { chain: activeChain.value, with_meta: 1 } }),
      api.get('/users/list')
    ])
    roles.value = Array.isArray(rolesRes.data) ? rolesRes.data : (rolesRes.data?.data || [])
    usersList.value = Array.isArray(usersRes.data) ? usersRes.data : (usersRes.data?.data || [])
    
    const rawSteps = Array.isArray(stepsRes.data) 
      ? stepsRes.data 
      : (stepsRes.data?.steps || stepsRes.data?.data || [])

    currentFingerprint.value = stepsRes.headers?.['x-workflow-fingerprint'] || stepsRes.data?.fingerprint || ''
    inheritsLeaveChain.value = !!stepsRes.data?.inherits_leave_chain

    steps.value = rawSteps.map((s, idx) => ({
      id: s.id,
      role_id: s.role_id || '',
      approver_type: s.approver_type || 'ROLE',
      specific_user_id: s.specific_user_id || '',
      name: s.name,
      step_order: s.step_order || (idx + 1)
    }))
    
    reindexSteps()
    initialStepsSnapshot.value = serializeSteps(steps.value)
    conflictModal.value.show = false

    // ตรวจความพร้อมทันทีที่เห็นสาย ผู้ดูแลจึงรู้ปัญหาก่อนลงมือแก้ ไม่ใช่หลังใบลาค้าง
    runReadiness()
  } catch (e) {
    console.error(e)
    alertStore.error('เกิดข้อผิดพลาดในการดึงข้อมูลลำดับการอนุมัติ')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchWorkflow()
  window.addEventListener('beforeunload', handleBeforeUnload)
})

onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', handleBeforeUnload)
})

const handleBeforeUnload = (e) => {
  if (hasUnsavedChanges.value) {
    e.preventDefault()
    e.returnValue = ''
  }
}

onBeforeRouteLeave(async (to, from, next) => {
  if (! hasUnsavedChanges.value) {
    next()

    return
  }

  const answer = await confirmAction({
    title: 'ออกจากหน้าจัดการลำดับการอนุมัติ',
    message: 'คุณมีการเปลี่ยนแปลงลำดับการอนุมัติที่ยังไม่ได้บันทึก ต้องการออกจากหน้านี้หรือไม่?',
    detail: 'การเปลี่ยนแปลงที่ยังไม่ได้บันทึกจะหายไป',
    confirmText: 'ออกโดยไม่บันทึก',
    cancelText: 'อยู่หน้านี้ต่อ',
  })

  next(answer ? undefined : false)
})

const addStep = () => {
  steps.value.push({
    role_id: '',
    approver_type: 'DEPARTMENT_HEAD',
    specific_user_id: '',
    name: 'ตรวจสอบโดยหัวหน้างาน',
    step_order: steps.value.length + 1
  })
  reindexSteps()
}

const confirmRemoveStep = (index) => {
  deleteModal.value = {
    show: true,
    index,
    name: steps.value[index]?.name || ''
  }
}

const executeRemoveStep = () => {
  if (deleteModal.value.index >= 0 && deleteModal.value.index < steps.value.length) {
    steps.value.splice(deleteModal.value.index, 1)
    reindexSteps()
  }
  deleteModal.value.show = false
}

const moveUp = (index) => {
  if (index === 0) return
  const temp = steps.value[index]
  steps.value[index] = steps.value[index - 1]
  steps.value[index - 1] = temp
  reindexSteps()
}

const moveDown = (index) => {
  if (index === steps.value.length - 1) return
  const temp = steps.value[index]
  steps.value[index] = steps.value[index + 1]
  steps.value[index + 1] = temp
  reindexSteps()
}

const resolveConflictReload = () => {
  conflictModal.value.show = false
  fetchWorkflow()
}

const saveWorkflow = async () => {
  // สายยกเลิกบันทึกว่างได้ = ใช้สายเดียวกับใบลา ส่วนสายใบลาต้องมีอย่างน้อยหนึ่งขั้น
  if (steps.value.length === 0 && !isCancellationChain.value) {
    alertStore.error('กรุณาระบุขั้นตอนการอนุมัติอย่างน้อย 1 ขั้นตอน')
    return
  }

  for (let i = 0; i < steps.value.length; i++) {
    const s = steps.value[i]
    if (!s.name || !s.name.trim()) {
      alertStore.error(`กรุณากรอกชื่อขั้นตอนในลำดับที่ ${i + 1}`)
      return
    }
    if (s.approver_type === 'ROLE' && !s.role_id) {
      alertStore.error(`กรุณาเลือกบทบาทผู้มีสิทธิ์อนุมัติในลำดับที่ ${i + 1}`)
      return
    }
    if (s.approver_type === 'SPECIFIC_USER' && !s.specific_user_id) {
      alertStore.error(`กรุณาระบุผู้ใช้งานในลำดับที่ ${i + 1}`)
      return
    }
  }

  reindexSteps()
  saving.value = true

  try {
    const payload = {
      chain: activeChain.value,
      steps: steps.value.map(s => ({
        role_id: s.role_id ? Number(s.role_id) : null,
        approver_type: s.approver_type || 'ROLE',
        specific_user_id: s.specific_user_id ? Number(s.specific_user_id) : null,
        name: s.name.trim(),
        step_order: Number(s.step_order)
      })),
      fingerprint: currentFingerprint.value
    }

    const res = await api.post('/admin/workflow-steps', payload)
    alertStore.success(res.data.message || 'บันทึกลำดับการอนุมัติเรียบร้อยแล้ว')
    
    currentFingerprint.value = res.headers?.['x-workflow-fingerprint'] || res.data?.fingerprint || ''
    
    inheritsLeaveChain.value = !!res.data?.inherits_leave_chain

    const returnedSteps = res.data?.steps || []
    steps.value = returnedSteps.map((s, idx) => ({
      id: s.id,
      role_id: s.role_id || '',
      approver_type: s.approver_type || 'ROLE',
      specific_user_id: s.specific_user_id || '',
      name: s.name,
      step_order: s.step_order || (idx + 1)
    }))

    reindexSteps()
    initialStepsSnapshot.value = serializeSteps(steps.value)

    // สายที่เพิ่งบันทึกคือสายที่ใบลาใบต่อไปจะเดินจริง จึงต้องตรวจซ้ำด้วยของจริง
    runReadiness()
  } catch (e) {
    console.error(e)
    if (e.response?.status === 409) {
      conflictModal.value.show = true
    } else {
      alertStore.error(e.response?.data?.message || Object.values(e.response?.data?.errors || {})[0]?.[0] || 'เกิดข้อผิดพลาดในการบันทึกข้อมูล')
    }
  } finally {
    saving.value = false
  }
}
</script>
