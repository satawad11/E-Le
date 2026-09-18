<template>
  <div class="max-w-7xl mx-auto space-y-6" aria-labelledby="report-title">
    <!-- Main Header -->
    <header class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
      <div>
        <h1 id="report-title" class="text-2xl font-bold text-gray-900">ศูนย์รวมรายงานและสถิติ (Unified Report & Analytics Hub)</h1>
        <p class="text-sm text-gray-500 mt-1">สรุปข้อมูลทะเบียนการลา เวลาปฏิบัติงาน เกณฑ์ประเมิน ก.ค.ศ. ๒๓ วัน และงานสารบรรณ ๔ ฝ่าย</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <router-link
          to="/reports/vocational-evaluation"
          class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-white hover:bg-gray-50 border border-gray-200 text-[#800000] text-xs font-bold rounded-xl transition-colors shadow-2xs"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          แฟ้ม ว PA ละเอียด ↗
        </router-link>
        <router-link
          to="/admin/attendances"
          class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-white hover:bg-gray-50 border border-gray-200 text-gray-700 text-xs font-bold rounded-xl transition-colors shadow-2xs"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          จัดการเวลาลงเวลา ↗
        </router-link>
      </div>
    </header>

    <!-- Top Navigation Tabs (Unified Hub) -->
    <nav class="flex flex-wrap p-1.5 bg-gray-100 rounded-2xl gap-1" aria-label="หมวดหมู่รายงาน">
      <button
        type="button"
        @click="activeTab = 'leaves'"
        class="flex-1 min-w-[140px] px-4 py-2.5 rounded-xl text-xs sm:text-sm font-bold transition-all flex items-center justify-center gap-2"
        :class="activeTab === 'leaves' ? 'bg-white text-[#800000] shadow-sm' : 'text-gray-600 hover:text-gray-900'"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        <span>๑. ทะเบียนการลาราชการ</span>
      </button>

      <button
        type="button"
        @click="activeTab = 'attendance'"
        class="flex-1 min-w-[140px] px-4 py-2.5 rounded-xl text-xs sm:text-sm font-bold transition-all flex items-center justify-center gap-2"
        :class="activeTab === 'attendance' ? 'bg-white text-[#800000] shadow-sm' : 'text-gray-600 hover:text-gray-900'"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <span>๒. เวลาปฏิบัติงาน & OT</span>
      </button>

      <button
        type="button"
        @click="activeTab = 'vocational'"
        class="flex-1 min-w-[140px] px-4 py-2.5 rounded-xl text-xs sm:text-sm font-bold transition-all flex items-center justify-center gap-2"
        :class="activeTab === 'vocational' ? 'bg-white text-[#800000] shadow-sm' : 'text-gray-600 hover:text-gray-900'"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
        <span>๓. เกณฑ์ ก.ค.ศ. (๒๓ วัน) & PA</span>
      </button>

      <button
        type="button"
        @click="activeTab = 'saraban'"
        class="flex-1 min-w-[140px] px-4 py-2.5 rounded-xl text-xs sm:text-sm font-bold transition-all flex items-center justify-center gap-2"
        :class="activeTab === 'saraban' ? 'bg-white text-[#800000] shadow-sm' : 'text-gray-600 hover:text-gray-900'"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
        <span>๔. สารบรรณ ๔ ฝ่าย สอศ.</span>
      </button>
    </nav>

    <!-- ========================================== -->
    <!-- TAB 1: ทะเบียนการลาราชการ (LEAVES) -->
    <!-- ========================================== -->
    <div v-show="activeTab === 'leaves'" class="space-y-6">
      <!-- Summary Cards -->
      <p class="text-xs text-gray-500">
        สรุปตามตัวกรองปัจจุบัน: {{ date(filters.from) }} – {{ date(filters.to) }}
        <template v-if="filters.org_unit_id"> · เฉพาะหน่วยงานที่เลือก</template>
        <template v-if="filters.personnel_type_id"> · เฉพาะประเภทบุคลากรที่เลือก</template>
      </p>
      <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <article v-for="row in summary" :key="row.leave_type" class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
          <p class="text-sm text-gray-500 font-medium">{{ row.leave_type }}</p>
          <p class="mt-1 text-2xl font-bold text-gray-800">{{ row.approved_days || 0 }} วัน</p>
          <p class="text-xs text-gray-400 mt-1">อนุมัติ {{ row.approved }} จาก {{ row.requests }} รายการ · ยื่นรวม {{ row.days || 0 }} วัน</p>
        </article>
      </section>

      <!-- Filter Form -->
      <form class="flex flex-wrap items-end gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm" @submit.prevent="loadRegister">
        <label class="text-sm font-medium text-gray-700">
          ตั้งแต่
          <input v-model="filters.from" required type="date" class="mt-1 block rounded-xl border border-gray-200 p-2 text-sm focus:ring-2 focus:ring-[#800000]/20 focus:border-[#800000]">
        </label>
        <label class="text-sm font-medium text-gray-700">
          ถึง
          <input v-model="filters.to" required type="date" class="mt-1 block rounded-xl border border-gray-200 p-2 text-sm focus:ring-2 focus:ring-[#800000]/20 focus:border-[#800000]">
        </label>
        <label class="text-sm font-medium text-gray-700">
          หน่วยงาน
          <select v-model="filters.org_unit_id" class="mt-1 block min-w-48 rounded-xl border border-gray-200 p-2 text-sm focus:ring-2 focus:ring-[#800000]/20 focus:border-[#800000]">
            <option value="">ทั้งหมด</option>
            <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
          </select>
        </label>
        <label class="text-sm font-medium text-gray-700">
          ประเภทบุคลากร
          <select v-model="filters.personnel_type_id" class="mt-1 block min-w-48 rounded-xl border border-gray-200 p-2 text-sm focus:ring-2 focus:ring-[#800000]/20 focus:border-[#800000]">
            <option value="">ทั้งหมด</option>
            <option v-for="p in personnelTypes" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </label>
        <button type="submit" class="rounded-xl bg-[#800000] hover:bg-[#660000] px-5 py-2.5 text-white font-medium text-sm shadow-sm transition-colors">
          แสดงรายงาน
        </button>
        <button type="button" class="rounded-xl border border-gray-200 hover:bg-gray-50 px-4 py-2.5 text-gray-700 font-medium text-sm transition-colors" @click="exportCsv">
          ส่งออก CSV
        </button>
      </form>

      <!-- Loading State -->
      <div v-if="loading" class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
        <LoadingState type="skeleton-table" :rows="4" :cols="6" message="กำลังประมวลผลรายงาน..." />
      </div>

      <!-- Error State -->
      <div v-else-if="loadError" class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
        <ErrorState :message="loadError" @retry="loadRegister" />
      </div>

      <!-- Results Section -->
      <section v-else class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
        <EmptyState
          v-if="!rows.length"
          title="ไม่พบข้อมูลตามเงื่อนไข"
          description="ไม่มีรายการข้อมูลการลาที่ตรงกับช่วงวันที่และตัวกรองที่เลือก"
          :icon="ICONS.document"
        />

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100 text-gray-600">
              <tr>
                <th class="p-3 text-left font-semibold">เลขที่</th>
                <th class="p-3 text-left font-semibold">บุคลากร</th>
                <th class="p-3 text-left font-semibold">หน่วยงาน</th>
                <th class="p-3 text-left font-semibold">ประเภทการลา</th>
                <th class="p-3 text-left font-semibold">ช่วงวันที่</th>
                <th class="p-3 text-right font-semibold">วัน</th>
                <th class="p-3 text-left font-semibold">สถานะ</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-for="row in rows" :key="row.id" class="hover:bg-gray-50 transition-colors">
                <td class="p-3 font-medium text-gray-900">{{ row.document_number }}</td>
                <td class="p-3">
                  <p class="font-medium text-gray-900">{{ row.user?.name }}</p>
                  <p class="text-xs text-gray-500">{{ row.user?.personnel_type?.name }}</p>
                </td>
                <td class="p-3 text-gray-600">{{ row.user?.department?.name }}</td>
                <td class="p-3 text-gray-600">{{ row.type?.name }}</td>
                <td class="p-3 text-gray-600">{{ date(row.start_date) }} – {{ date(row.end_date) }}</td>
                <td class="p-3 text-right font-medium">{{ row.total_days }}</td>
                <td class="p-3">
                  <template v-if="row.is_deleted">
                    <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700">ลบแล้ว</span>
                    <span class="block text-xs text-gray-400">เดิม: {{ status[row.status] || row.status }}</span>
                  </template>
                  <span v-else class="text-xs font-medium">{{ status[row.status] || row.status }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="truncated" class="border-t border-amber-100 bg-amber-50 p-3 text-center text-xs text-amber-800">
          แสดง {{ rows.length }} รายการแรกจากทั้งหมด {{ total }} รายการ — กด “ส่งออก CSV” เพื่อดูข้อมูลทั้งหมด
        </p>
      </section>
    </div>

    <!-- ========================================== -->
    <!-- TAB 2: สรุปเวลาปฏิบัติงานและ OT (ATTENDANCE) -->
    <!-- ========================================== -->
    <div v-show="activeTab === 'attendance'" class="space-y-6">
      <div class="bg-white rounded-2xl border border-gray-100 p-5 shadow-sm">
        <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-3 mb-4">
          <div>
            <h2 class="text-lg font-bold text-gray-900">สรุปเวลาปฏิบัติราชการและการปฏิบัติงานนอกเวลา (OT)</h2>
            <p class="text-xs text-gray-500">ตรวจสอบบันทึกเวลาเข้า-ออกงาน การมาสาย ขาดงาน และชั่วโมงการปฏิบัติงาน OT รายเดือน</p>
          </div>
          <div class="flex gap-2">
            <router-link
              to="/admin/attendances"
              class="px-4 py-2 bg-[#800000] hover:bg-[#660000] text-white text-xs font-bold rounded-xl transition-colors shadow-2xs inline-flex items-center gap-1.5"
            >
              <span>เปิดหน้าลงเวลาฉบับเต็ม</span>
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
            </router-link>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-2">
          <div class="p-4 bg-emerald-50 rounded-xl border border-emerald-100">
            <p class="text-xs text-emerald-700 font-medium">บันทึกเวลาปฏิบัติงานปกติ</p>
            <p class="text-xl font-bold text-emerald-900 mt-1">สมบูรณ์</p>
            <p class="text-xs text-emerald-600 mt-0.5">เชื่อมโยงเครื่องสแกนนิ้ว/GPS และการซิงค์ใบลา</p>
          </div>
          <div class="p-4 bg-amber-50 rounded-xl border border-amber-100">
            <p class="text-xs text-amber-700 font-medium">คำสั่งและบันทึกเวลา OT</p>
            <p class="text-xl font-bold text-amber-900 mt-1">ระบบคำนวณเบิกจ่าย</p>
            <p class="text-xs text-amber-600 mt-0.5">คิดชั่วโมงปฏิบัติงานตามระเบียบกระทรวงการคลัง</p>
          </div>
          <div class="p-4 bg-blue-50 rounded-xl border border-blue-100">
            <p class="text-xs text-blue-700 font-medium">การส่งออกรายงาน</p>
            <p class="text-xl font-bold text-blue-900 mt-1">PDF & Word</p>
            <p class="text-xs text-blue-600 mt-0.5">พิมพ์ใบลงเวลารายเดือนเสนอผู้บริหารลงนาม</p>
          </div>
        </div>
      </div>
    </div>

    <!-- ========================================== -->
    <!-- TAB 3: การประเมิน ก.ค.ศ. 23 วัน และ ว PA (VOCATIONAL) -->
    <!-- ========================================== -->
    <div v-show="activeTab === 'vocational'" class="space-y-6">
      <div class="bg-white rounded-2xl border border-gray-100 p-5 shadow-sm">
        <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-3 mb-4">
          <div>
            <h2 class="text-lg font-bold text-gray-900">เกณฑ์วันลาประเมินผลการปฏิบัติราชการ (ก.ค.ศ.) & ว PA</h2>
            <p class="text-xs text-gray-500">เพดานวันลาป่วยและลากิจสะสมไม่เกิน ๒๓ วันทำการในรอบการประเมิน ๖ เดือน และแฟ้มหลักฐาน ว PA</p>
          </div>
          <router-link
            to="/reports/vocational-evaluation"
            class="px-4 py-2 bg-[#800000] hover:bg-[#660000] text-white text-xs font-bold rounded-xl transition-colors shadow-2xs inline-flex items-center gap-1.5"
          >
            <span>เปิดรายงาน ก.ค.ศ. ฉบับเต็ม</span>
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
          </router-link>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 pt-2">
          <div class="p-4 rounded-xl border border-gray-100 bg-gray-50">
            <h3 class="text-xs font-bold text-gray-500 uppercase">รอบที่ ๑ (๑ ต.ค. – ๓๑ มี.ค.)</h3>
            <p class="text-sm font-semibold text-gray-800 mt-1">ประเมินเลื่อนขั้นเงินเดือนรอบ ๑ เมษายน</p>
            <p class="text-xs text-gray-500 mt-1">รวมวันลาป่วย ลากิจส่วนตัว ไม่เกิน ๒๓ วันทำการ</p>
          </div>
          <div class="p-4 rounded-xl border border-gray-100 bg-gray-50">
            <h3 class="text-xs font-bold text-gray-500 uppercase">รอบที่ ๒ (๑ เม.ย. – ๓๐ ก.ย.)</h3>
            <p class="text-sm font-semibold text-gray-800 mt-1">ประเมินเลื่อนขั้นเงินเดือนรอบ ๑ ตุลาคม</p>
            <p class="text-xs text-gray-500 mt-1">รวมวันลาป่วย ลากิจส่วนตัว ไม่เกิน ๒๓ วันทำการ</p>
          </div>
          <div class="p-4 rounded-xl border border-purple-100 bg-purple-50">
            <h3 class="text-xs font-bold text-purple-700 uppercase">แฟ้มหลักฐาน ว PA</h3>
            <p class="text-sm font-semibold text-purple-900 mt-1">ออกเอกสารรับรองเวลาอัตโนมัติ</p>
            <p class="text-xs text-purple-600 mt-1">ประกอบการประเมินวิทยฐานะและการปฏิบัติราชการ</p>
          </div>
        </div>
      </div>
    </div>

    <!-- ========================================== -->
    <!-- TAB 4: รายงานสารบรรณ 4 ฝ่าย สอศ. (SARABAN) -->
    <!-- ========================================== -->
    <div v-show="activeTab === 'saraban'" class="space-y-6">
      <div class="bg-white rounded-2xl border border-gray-100 p-5 shadow-sm">
        <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-3 mb-4">
          <div>
            <h2 class="text-lg font-bold text-gray-900">สมุดทะเบียนและรายงานสารบรรณอิเล็กทรอนิกส์ (๔ ฝ่าย สอศ.)</h2>
            <p class="text-xs text-gray-500">ทะเบียนรับ ทะเบียนส่ง หนังสือเวียน และบันทึกข้อความภายในตามโครงสร้างสถานศึกษาอาชีวศึกษา</p>
          </div>
          <router-link
            to="/saraban/reports/register"
            class="px-4 py-2 bg-[#800000] hover:bg-[#660000] text-white text-xs font-bold rounded-xl transition-colors shadow-2xs inline-flex items-center gap-1.5"
          >
            <span>เปิดสมุดทะเบียนสารบรรณ</span>
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
          </router-link>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 pt-2">
          <!-- 1. ฝ่ายบริหารทรัพยากร -->
          <router-link to="/saraban/reports/register?division=resource" class="p-4 rounded-xl border border-gray-100 bg-white hover:border-[#800000] shadow-2xs transition-all group">
            <h3 class="text-xs font-bold text-[#800000] uppercase mb-1">๑. ฝ่ายบริหารทรัพยากร</h3>
            <p class="text-sm font-semibold text-gray-800 group-hover:text-[#800000]">งานสารบรรณ/การเงิน/พัสดุ/บุคลากร</p>
            <p class="text-xs text-gray-400 mt-2">ดูสมุดทะเบียน &rarr;</p>
          </router-link>

          <!-- 2. ฝ่ายแผนงานและความร่วมมือ -->
          <router-link to="/saraban/reports/register?division=planning" class="p-4 rounded-xl border border-gray-100 bg-white hover:border-[#800000] shadow-2xs transition-all group">
            <h3 class="text-xs font-bold text-[#800000] uppercase mb-1">๒. ฝ่ายแผนงานและความร่วมมือ</h3>
            <p class="text-sm font-semibold text-gray-800 group-hover:text-[#800000]">งานวางแผน/งบประมาณ/วิจัย/ประกันคุณภาพ</p>
            <p class="text-xs text-gray-400 mt-2">ดูสมุดทะเบียน &rarr;</p>
          </router-link>

          <!-- 3. ฝ่ายวิชาการ -->
          <router-link to="/saraban/reports/register?division=academic" class="p-4 rounded-xl border border-gray-100 bg-white hover:border-[#800000] shadow-2xs transition-all group">
            <h3 class="text-xs font-bold text-[#800000] uppercase mb-1">๓. ฝ่ายวิชาการ</h3>
            <p class="text-sm font-semibold text-gray-800 group-hover:text-[#800000]">งานพัฒนาหลักสูตร/แผนกวิชา/วิทยบริการ</p>
            <p class="text-xs text-gray-400 mt-2">ดูสมุดทะเบียน &rarr;</p>
          </router-link>

          <!-- 4. ฝ่ายพัฒนากิจการนักเรียน นักศึกษา -->
          <router-link to="/saraban/reports/register?division=student_affairs" class="p-4 rounded-xl border border-gray-100 bg-white hover:border-[#800000] shadow-2xs transition-all group">
            <h3 class="text-xs font-bold text-[#800000] uppercase mb-1">๔. ฝ่ายพัฒนากิจการฯ</h3>
            <p class="text-sm font-semibold text-gray-800 group-hover:text-[#800000]">งานกิจกรรม/ปกครอง/แนะแนว/สวัสดิการ</p>
            <p class="text-xs text-gray-400 mt-2">ดูสมุดทะเบียน &rarr;</p>
          </router-link>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../axios'
import { useAlertStore } from '../stores/alert'
import { buildFilename, filenameFromHeaders } from '../utils/downloadFilename'
import { getErrorMessage } from '../utils/errorHandler'
import { ICONS } from '../utils/icons'
import LoadingState from '../components/ui/LoadingState.vue'
import EmptyState from '../components/ui/EmptyState.vue'
import ErrorState from '../components/ui/ErrorState.vue'

const alerts = useAlertStore()
const activeTab = ref('leaves')
const loading = ref(false)
const loadError = ref('')
const rows = ref([])
const total = ref(0)
const truncated = computed(() => total.value > rows.value.length)
const summary = ref([])
const departments = ref([])
const personnelTypes = ref([])

const today = new Date()
const start = new Date(today.getFullYear(), 0, 1)
const iso = (d) => d.toISOString().slice(0, 10)

const filters = reactive({
  from: iso(start),
  to: iso(today),
  org_unit_id: '',
  personnel_type_id: ''
})

const status = {
  Pending: 'รออนุมัติ',
  Approved: 'อนุมัติ',
  Rejected: 'ไม่อนุมัติ',
  Cancelled: 'ยกเลิก',
  Voided: 'ยกเลิกเอกสาร'
}

const params = () => Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''))

const loadSummary = async () => {
  try {
    const { data } = await api.get('/reports/government-leave-summary', { params: params() })
    summary.value = data
  } catch (e) {
    console.warn('Failed to load summary', e)
  }
}

const loadRegister = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const [{ data }] = await Promise.all([
      api.get('/reports/government-leave-register', { params: params() }),
      loadSummary(),
    ])
    rows.value = data.data || data
    total.value = data.total ?? rows.value.length
  } catch (e) {
    console.error(e)
    loadError.value = getErrorMessage(e, 'ไม่สามารถโหลดข้อมูลรายงานได้')
    alerts.error(loadError.value)
  } finally {
    loading.value = false
  }
}

const exportCsv = async () => {
  try {
    const r = await api.get('/reports/government-leave-register.csv', { params: params(), responseType: 'blob' })
    const u = URL.createObjectURL(r.data)
    const a = document.createElement('a')
    a.href = u
    a.download = filenameFromHeaders(r.headers)
      || buildFilename(['ทะเบียนคุมการลา', filters.from, 'ถึง', filters.to], 'csv')
    a.click()
    URL.revokeObjectURL(u)
    alerts.success('ส่งออกรายงานเรียบร้อยแล้ว')
  } catch (e) {
    alerts.error(getErrorMessage(e, 'ส่งออกรายงานไม่สำเร็จ'))
  }
}

const date = (v) => new Intl.DateTimeFormat('th-TH', { dateStyle: 'medium' }).format(new Date(v))

onMounted(async () => {
  try {
    const [d, p] = await Promise.all([
      api.get('/org-units'),
      api.get('/personnel-types')
    ])
    departments.value = d.data.data || d.data
    personnelTypes.value = p.data
  } catch (e) {
    console.warn('Failed to load filter metadata', e)
  }
  await loadRegister()
})
</script>
