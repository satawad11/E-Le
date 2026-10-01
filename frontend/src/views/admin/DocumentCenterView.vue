<template>
  <div>
    <!-- Header -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">ศูนย์จัดการเอกสาร</h1>
        <p class="text-gray-500 mt-1 text-sm">
          จัดการแบบฟอร์มราชการ เวอร์ชัน ตำแหน่งฟิลด์ และเอกสารที่ระบบออกให้ — ใช้ได้กับทุกโมดูล ไม่เฉพาะระบบการลา
        </p>
        <!--
          รายงานย้ายไปอยู่ใต้เมนู "รายงาน" แล้ว หน้านี้เหลือเฉพาะเรื่องแบบฟอร์มและเอกสาร
          เพื่อให้การตั้งค่าอยู่ในหมวดตั้งค่า และการใช้งานรายงานอยู่ในหมวดรายงาน
        -->
        <p class="text-gray-400 mt-1 text-xs">
          ต้องการดูหรือออกแบบรายงาน?
          <router-link :to="{ name: 'custom-reports' }" class="text-[#800000] font-semibold hover:underline">
            ไปที่ รายงาน › รายงานที่ออกแบบเอง →
          </router-link>
        </p>
      </div>
      <button
        @click="loadAll"
        :disabled="loading"
        class="flex items-center gap-1.5 px-4 py-2.5 bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 font-semibold rounded-xl text-sm shadow-sm disabled:opacity-50 min-h-[44px]"
      >
        <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        รีเฟรช
      </button>
    </div>

    <!-- สถานะ Engine -->
    <div
      v-if="overview"
      class="mb-6 rounded-2xl border p-4 text-sm flex flex-wrap items-center gap-x-6 gap-y-2"
      :class="overview.engine_enabled ? 'bg-green-50 border-green-200 text-green-900' : 'bg-amber-50 border-amber-200 text-amber-900'"
    >
      <span class="font-bold">
        {{ overview.engine_enabled ? 'ระบบจัดทำเอกสารอัตโนมัติ (Document Engine) เปิดใช้งานอยู่' : 'ระบบจัดทำเอกสารอัตโนมัติ (Document Engine) ปิดอยู่ — ระบบใช้เส้นทางพิมพ์เอกสารเดิม' }}
      </span>
      <span v-if="overview.engine_enabled">
        ประเภทการลาที่เปิด: {{ overview.enabled_leave_types.length ? overview.enabled_leave_types.join(', ') : 'ยังไม่ได้ระบุ' }}
      </span>
      <span v-else class="text-amber-800">
        เปิดได้ที่ไฟล์ตั้งค่า <code class="bg-white/70 px-1.5 py-0.5 rounded">DOCUMENT_ENGINE_ENABLED=true</code>
        และระบุประเภทการลาที่ <code class="bg-white/70 px-1.5 py-0.5 rounded">DOCUMENT_ENGINE_LEAVE_TYPES</code>
      </span>
    </div>

    <div
      v-if="failedSections.length"
      role="alert"
      class="mb-6 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"
    >
      โหลดข้อมูลบางส่วนไม่สำเร็จ: {{ failedSections.join(', ') }} — ส่วนอื่นใช้งานได้ตามปกติ กด "รีเฟรช" เพื่อลองใหม่
    </div>

    <!-- ตัวเลขภาพรวม -->
    <div v-if="overview" class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
      <div v-for="card in cards" :key="card.label" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
        <p class="text-xs font-semibold text-gray-500 uppercase">{{ card.label }}</p>
        <p class="text-2xl font-bold text-gray-900 mt-1">{{ card.value }}</p>
        <p v-if="card.hint" class="text-xs text-gray-400 mt-1">{{ card.hint }}</p>
      </div>
    </div>

    <!-- แท็บ -->
    <div class="mb-6 flex flex-wrap gap-2 border-b border-gray-200">
      <button
        v-for="tab in tabs"
        :key="tab.value"
        @click="activeTab = tab.value"
        class="px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px transition-colors min-h-[44px]"
        :class="activeTab === tab.value ? 'border-[#800000] text-[#800000]' : 'border-transparent text-gray-500 hover:text-gray-800'"
      >
        {{ tab.label }}
      </button>
    </div>

    <div v-if="loading" class="flex justify-center py-16">
      <div class="animate-spin h-10 w-10 rounded-full border-4 border-[#800000] border-t-transparent"></div>
    </div>

    <template v-else>
      <!-- แบบฟอร์มเอกสาร -->
      <section v-if="activeTab === 'templates'" class="space-y-4">
        <div v-if="canManage" class="flex flex-wrap justify-end gap-2">
          <!--
            CLEANUP-01 (D2) — ปุ่มชุดแม่แบบตั้งต้นย้ายมาจากหน้า "จัดการแม่แบบเอกสาร"
            ที่ถูกยุบเข้ามาที่นี่ เพื่อให้เหลือทางเข้าเดียว
          -->
          <button
            type="button"
            :disabled="seeding"
            class="px-4 py-2.5 border border-gray-200 text-gray-700 hover:bg-gray-50 font-semibold rounded-xl text-sm min-h-[44px] disabled:opacity-50"
            @click="seedDefaults(false)"
          >
            {{ seeding ? 'กำลังซิงค์…' : 'ซิงค์แม่แบบตั้งต้น' }}
          </button>
          <button
            type="button"
            :disabled="seeding"
            class="px-4 py-2.5 border border-amber-200 text-amber-700 hover:bg-amber-50 font-semibold rounded-xl text-sm min-h-[44px] disabled:opacity-50"
            @click="restoreDefaults"
          >
            คืนค่าแบบฟอร์มตั้งต้น
          </button>
          <!-- นำเข้าชุดแบบฟอร์มที่ส่งออกจากเครื่องอื่น (DESIGNER-04) -->
          <button
            type="button"
            :disabled="importing"
            class="px-4 py-2.5 border border-gray-200 text-gray-700 hover:bg-gray-50 font-semibold rounded-xl text-sm min-h-[44px] disabled:opacity-50"
            title="ไฟล์ .zip ที่ส่งออกจากหน้าออกแบบแบบฟอร์ม — ระบบจะสร้างเป็นแม่แบบใหม่พร้อมฉบับร่าง ไม่ทับของเดิม"
            @click="packageInput?.click()"
          >
            {{ importing ? 'กำลังนำเข้า…' : 'นำเข้าชุดแบบฟอร์ม' }}
          </button>
          <input ref="packageInput" type="file" accept=".zip,application/zip" class="hidden" @change="importPackage" />

          <button
            @click="showCreate = true"
            class="px-5 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm shadow-md min-h-[44px]"
          >
            + สร้างแม่แบบใหม่
          </button>
        </div>

        <!-- แถบหมวดหมู่และการค้นหาแม่แบบเอกสาร -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-white p-4 rounded-2xl border border-gray-100 shadow-xs">
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="cat in templateCategories"
              :key="cat.value"
              type="button"
              class="px-3.5 py-1.5 rounded-xl text-xs font-semibold transition-colors"
              :class="templateCategoryFilter === cat.value ? 'bg-[#800000] text-white shadow-xs' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
              @click="templateCategoryFilter = cat.value"
            >
              {{ cat.label }}
            </button>
          </div>
          <div class="relative w-full sm:w-64">
            <input
              v-model="templateSearchQuery"
              type="text"
              placeholder="ค้นหาชื่อ, รหัสแม่แบบ..."
              aria-label="ค้นหาแม่แบบเอกสาร"
              class="w-full pl-9 pr-4 py-2 text-xs rounded-xl border border-gray-200 focus:outline-none focus:ring-2 focus:ring-[#800000]/20 focus:border-[#800000]"
            />
            <span class="absolute left-3 top-2.5 text-gray-400 text-xs">🔍</span>
          </div>
        </div>

        <div v-if="filteredTemplates.length === 0" class="bg-white rounded-2xl border border-gray-100 p-12 text-center text-gray-500">
          {{ templates.length === 0 ? 'ยังไม่มีแม่แบบเอกสารในระบบ' : 'ไม่พบแม่แบบเอกสารที่ตรงกับเงื่อนไขการค้นหา' }}
        </div>

        <div v-else class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-x-auto">
          <table class="w-full text-sm min-w-[860px]">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
              <tr>
                <th class="text-left px-5 py-3">ชื่อแม่แบบ</th>
                <th class="text-left px-5 py-3">รหัส</th>
                <th class="text-left px-5 py-3">โมดูล</th>
                <th class="text-left px-5 py-3">ชนิด</th>
                <th class="text-left px-5 py-3">วิธีเรนเดอร์</th>
                <th class="text-left px-5 py-3">เวอร์ชันที่ใช้</th>
                <th class="text-left px-5 py-3">ผูกไว้</th>
                <th class="text-right px-5 py-3">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="template in filteredTemplates" :key="template.id" class="border-t border-gray-50 hover:bg-gray-50/60">
                <td class="px-5 py-3 font-semibold text-gray-900">{{ template.name }}</td>
                <td class="px-5 py-3 font-mono text-xs text-gray-500">{{ template.code }}</td>
                <td class="px-5 py-3">{{ template.module }}</td>
                <td class="px-5 py-3">{{ typeLabel(template.document_type) }}</td>
                <td class="px-5 py-3">
                  <span
                    class="px-2 py-0.5 rounded-full text-xs font-semibold"
                    :class="template.render_mode === 'pdf_overlay' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-700'"
                  >
                    {{ template.render_mode === 'pdf_overlay' ? 'วางฟิลด์บน PDF' : 'HTML เดิม' }}
                  </span>
                </td>
                <td class="px-5 py-3">
                  <span v-if="template.active_version">
                    v{{ template.active_version.version }}
                    <span class="text-xs text-gray-400">({{ statusLabel(template.active_version.status) }})</span>
                  </span>
                  <span v-else class="text-gray-400">—</span>
                </td>
                <td class="px-5 py-3">{{ template.bound_entities }} รายการ</td>
                <td class="px-5 py-3 text-right space-x-1 whitespace-nowrap">
                  <button
                    v-if="canManage && template.render_mode !== 'pdf_overlay'"
                    type="button"
                    class="px-3 py-1.5 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-100 text-xs font-semibold"
                    @click="openContentEditor(template)"
                  >
                    แก้เนื้อหา
                  </button>
                  <button
                    @click="openTemplate(template)"
                    class="px-3 py-1.5 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-100 text-xs font-semibold"
                  >
                    จัดการเวอร์ชัน
                  </button>
                  <button
                    v-if="canManage"
                    type="button"
                    class="px-3 py-1.5 rounded-lg border border-red-200 text-red-700 hover:bg-red-50 text-xs font-semibold"
                    @click="deleteTemplate(template)"
                  >
                    ลบ
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- สารบัญตัวแปรเอกสาร (Field Dictionary) -->
      <section v-else-if="activeTab === 'fields'" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
        <p class="text-sm text-gray-500 mb-4">
          ตัวแปรทั้งหมดที่แบบฟอร์มและรายงานอ้างถึงได้ ค่าเหล่านี้มาจากแหล่งข้อมูลที่ระบบลงทะเบียนไว้ ไม่ได้มาจากการเขียน SQL เอง
          — รายการอัปเดตอัตโนมัติตามช่องเฉพาะของประเภทการลา ขั้นอนุมัติ และประเภทการลาที่มีอยู่จริง
          แก้ได้เฉพาะป้ายชื่อ กลุ่ม และรูปแบบค่า ส่วนรหัสและชนิดข้อมูลเป็นของระบบ
        </p>

        <!-- แก้ป้ายชื่อ/กลุ่ม/รูปแบบของตัวแปร (DESIGNER-03) -->
        <div v-if="fieldForm" class="mb-5 rounded-2xl border border-[#800000]/30 bg-[#800000]/5 p-4 space-y-3">
          <div class="flex items-center justify-between">
            <h3 class="font-bold text-gray-900 text-sm">แก้ไขตัวแปร</h3>
            <span class="font-mono text-[11px] text-gray-500">{{ fieldForm.code }}</span>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <label class="block">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">ป้ายชื่อ</span>
              <input v-model="fieldForm.label_th" type="text" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm" />
            </label>
            <label class="block">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">กลุ่ม</span>
              <input v-model="fieldForm.group_th" type="text" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm" />
            </label>
            <label class="block md:col-span-2">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">คำอธิบาย</span>
              <input v-model="fieldForm.description" type="text" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm" />
            </label>
          </div>

          <div class="flex flex-wrap gap-2">
            <button @click="saveField" :disabled="savingField"
              class="px-4 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm min-h-[44px] disabled:opacity-50">บันทึก</button>
            <button @click="deactivateField" :disabled="savingField"
              class="px-4 py-2.5 rounded-xl border border-red-200 text-red-600 text-sm font-semibold min-h-[44px]"
              title="ซ่อนจากรายการตัวแปร — แบบฟอร์มเดิมที่ใช้อยู่ยังพิมพ์ได้เหมือนเดิม">ปิดการใช้งาน</button>
            <button @click="fieldForm = null" class="px-4 py-2.5 rounded-xl border border-gray-200 text-gray-700 text-sm min-h-[44px]">ยกเลิก</button>
          </div>
        </div>
        <div v-for="(fields, group) in groupedFields" :key="group" class="mb-5">
          <h3 class="font-bold text-gray-800 text-sm mb-2">{{ group }}</h3>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
            <div v-for="field in fields" :key="field.id" class="flex items-start justify-between gap-3 text-xs border border-gray-100 rounded-xl px-3 py-2">
              <div>
                <p class="font-semibold text-gray-800">{{ field.label_th }}</p>
                <p class="font-mono text-[11px] text-gray-400">{{ field.code }}</p>
                <p v-if="field.description" class="text-[11px] text-gray-400">{{ field.description }}</p>
              </div>
              <div class="flex items-center gap-2 flex-shrink-0">
                <span class="text-[10px] font-semibold text-gray-500 bg-gray-100 rounded px-1.5 py-0.5">{{ field.data_type }}</span>
                <button v-if="canManage" @click="editField(field)" class="text-[11px] font-semibold text-[#800000] hover:underline">แก้ไข</button>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- แหล่งข้อมูล -->
      <section v-else-if="activeTab === 'sources'" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 space-y-3">
        <p class="text-sm text-gray-500">
          แหล่งข้อมูลที่ระบบอนุญาต — เพิ่มได้จากโค้ดเท่านั้น เพื่อไม่ให้มีการดึงข้อมูลนอกขอบเขตสิทธิ์ผ่านหน้าจอ
        </p>
        <div v-for="source in dataSources" :key="source.id" class="border border-gray-100 rounded-xl px-4 py-3 flex flex-wrap items-center justify-between gap-3">
          <div>
            <p class="font-semibold text-gray-800 text-sm">{{ source.name_th }}</p>
            <p class="font-mono text-xs text-gray-400">{{ source.code }}</p>
          </div>
          <div class="flex items-center gap-2 text-xs">
            <span class="bg-gray-100 rounded px-2 py-0.5">{{ source.module }}</span>
            <span class="bg-blue-50 text-blue-700 rounded px-2 py-0.5">{{ source.kind === 'DATASET' ? 'หลายรายการ (รายงาน)' : 'รายการเดียว (แบบฟอร์ม)' }}</span>
          </div>
        </div>
      </section>

      <!-- การผูกแบบฟอร์ม -->
      <section v-else-if="activeTab === 'bindings'" class="space-y-4">
        <div v-if="unboundLeaveTypes.length" class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
          <p class="font-bold mb-1">ประเภทการลาที่ยังไม่มีแบบฟอร์มเฉพาะ ({{ unboundLeaveTypes.length }} ประเภท)</p>
          <p class="mb-2 text-amber-800">
            ประเภทเหล่านี้ยังพิมพ์เอกสารได้ตามปกติด้วยแบบฟอร์มเดิมของระบบ — อัปโหลดแบบฟอร์มเมื่อพร้อมแล้วค่อยผูกได้ภายหลัง
          </p>
          <div class="flex flex-wrap gap-2">
            <span v-for="type in unboundLeaveTypes" :key="type.id" class="px-2.5 py-1 rounded-full bg-white/70 border border-amber-200 text-xs font-medium">
              {{ type.name }}
            </span>
          </div>
        </div>

        <!-- เพิ่ม/แก้กติกาผูกแบบฟอร์ม -->
        <div v-if="canManage" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <h3 class="font-bold text-gray-800 text-sm mb-3">
            {{ bindingForm.id ? 'แก้กติกาการผูกแบบฟอร์ม' : 'ผูกแบบฟอร์มกับประเภทการลา' }}
          </h3>

          <div class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
            <label class="block">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">ใช้กับ</span>
              <select v-model="bindingForm.entity_type" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white">
                <option value="LEAVE_TYPE">ประเภทการลา</option>
                <option value="LEAVE_CANCELLATION">คำขอยกเลิกวันลา</option>
              </select>
            </label>

            <label class="block">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">รายการ</span>
              <select v-model="bindingForm.entity_id" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white">
                <option :value="null">ทุกรายการ</option>
                <option v-for="type in leaveTypeOptions" :key="type.id" :value="type.id">{{ type.name }}</option>
              </select>
            </label>

            <label class="block md:col-span-2">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">แบบฟอร์ม</span>
              <select v-model="bindingForm.document_template_id" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white">
                <option :value="null">เลือกแบบฟอร์ม</option>
                <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}</option>
              </select>
            </label>

            <div class="flex gap-2">
              <button
                @click="saveBinding"
                :disabled="!bindingForm.document_template_id"
                class="flex-1 px-4 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm min-h-[44px] disabled:opacity-50"
              >
                {{ bindingForm.id ? 'บันทึก' : 'ผูกแบบฟอร์ม' }}
              </button>
              <button
                v-if="bindingForm.id"
                @click="resetBindingForm"
                class="px-4 py-2.5 rounded-xl border border-gray-200 text-gray-700 text-sm min-h-[44px]"
              >
                ยกเลิก
              </button>
            </div>
          </div>

          <p class="text-[11px] text-gray-400 mt-2">
            หนึ่งรายการควรใช้แบบฟอร์มเดียว — ถ้าผูกซ้ำ ระบบจะเลือกกติกาที่ลำดับน้อยกว่า
            · กติกาที่เคยมีผลแล้วจะไม่ถูกแก้ทับหรือลบ ระบบปิดช่วงของกติกาเดิมไว้เป็นประวัติแล้วออกกติกาใหม่แทน
          </p>
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-x-auto">
        <table class="w-full text-sm min-w-[760px]">
          <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
              <th class="text-left px-5 py-3">โมดูล</th>
              <th class="text-left px-5 py-3">ใช้กับ</th>
              <th class="text-left px-5 py-3">รายการ</th>
              <th class="text-left px-5 py-3">แบบฟอร์ม</th>
              <th class="text-left px-5 py-3">ลำดับ</th>
              <th class="text-right px-5 py-3">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="rule in bindings" :key="rule.id" class="border-t border-gray-50">
              <td class="px-5 py-3">{{ rule.module }}</td>
              <td class="px-5 py-3">{{ entityTypeLabel(rule.entity_type) }}</td>
              <td class="px-5 py-3">{{ entityName(rule) }}</td>
              <td class="px-5 py-3 font-semibold text-gray-800">{{ rule.template?.name || '—' }}</td>
              <td class="px-5 py-3">{{ rule.priority }}</td>
              <td class="px-5 py-3 text-right">
                <template v-if="canManage">
                  <button @click="editBinding(rule)" class="px-3 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-100 text-xs font-semibold mr-2">แก้ไข</button>
                  <button @click="removeBinding(rule)" class="px-3 py-1.5 rounded-lg text-red-600 hover:bg-red-50 text-xs font-semibold">ลบ</button>
                </template>
              </td>
            </tr>
            <tr v-if="bindings.length === 0">
              <td colspan="6" class="px-5 py-10 text-center text-gray-400">ยังไม่มีการผูกแบบฟอร์ม</td>
            </tr>
          </tbody>
          </table>
        </div>

        <!--
          เปิดใช้แบบฟอร์ม PDF รายประเภทการลา (DESIGNER-03)
          เดิมตั้งได้จาก `.env` เท่านั้น ผู้ดูแลจึงทยอยเปิดใช้เองไม่ได้และไม่มีบันทึกว่าใครเปิดเมื่อใด
        -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 space-y-3">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-bold text-gray-900">เปิดใช้แบบฟอร์ม PDF รายประเภทการลา</h3>
            <span v-if="!overview.engine_enabled" class="text-xs px-2 py-1 rounded-lg bg-amber-50 border border-amber-200 text-amber-800">
              ระบบจัดทำเอกสารอัตโนมัติ (Document Engine) ปิดอยู่ระดับระบบ — ค่าที่บันทึกจะยังไม่มีผล
            </span>
          </div>

          <p class="text-xs text-gray-500">
            ประเภทที่ไม่ได้เปิดจะพิมพ์ด้วยเส้นทางเดิมเหมือนก่อน — เปิดทีละประเภทหลังตรวจแบบฟอร์มแล้ว
            ใบลาที่ออกไปแล้วไม่เปลี่ยนแบบฟอร์มตามการตั้งค่านี้ เพราะยึดเวอร์ชันที่ pin ไว้ตอนยื่น
          </p>

          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
            <label v-for="type in leaveTypeForms" :key="type.leave_type_id"
              class="flex items-start gap-2 p-3 rounded-xl border border-gray-100 hover:border-gray-200">
              <input type="checkbox" :value="type.leave_type_id" v-model="enabledLeaveTypes" class="mt-1 rounded" />
              <span>
                <span class="block text-sm font-semibold text-gray-800">{{ type.leave_type_name }}</span>
                <span class="block text-[11px] text-gray-400">
                  {{ type.template_name || 'ยังไม่ได้ผูกแบบฟอร์ม' }}
                  <template v-if="type.version"> · เวอร์ชัน {{ type.version }} · {{ type.field_count }} ฟิลด์</template>
                </span>
                <span v-if="type.render_mode === 'pdf_overlay' && !type.has_source_file" class="block text-[11px] text-amber-700 font-semibold">
                  ยังไม่มีไฟล์ต้นฉบับ
                </span>
              </span>
            </label>
          </div>

          <button v-if="canManage" @click="saveEnabledLeaveTypes" :disabled="savingEntities"
            class="px-5 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm min-h-[44px] disabled:opacity-50">
            {{ savingEntities ? 'กำลังบันทึก...' : 'บันทึกประเภทที่เปิดใช้' }}
          </button>
        </div>
      </section>

      <!-- เอกสารที่สร้างแล้ว -->
      <section v-else-if="activeTab === 'generated'" class="space-y-4">
        <form
          class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 grid grid-cols-1 md:grid-cols-5 gap-3 items-end"
          @submit.prevent="applyGeneratedFilters"
        >
          <label class="block md:col-span-2">
            <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">ค้นหา</span>
            <input
              v-model="generatedFilters.q"
              type="search"
              placeholder="เลขที่ใบลา หรือชื่อแม่แบบ"
              class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm"
            />
          </label>
          <label class="block">
            <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">สถานะ</span>
            <select v-model="generatedFilters.status" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white">
              <option value="">ทุกสถานะ</option>
              <option value="GENERATED">ออกแล้ว</option>
              <option value="FINALIZED">ฉบับสมบูรณ์</option>
              <option value="SUPERSEDED">ถูกแทนที่</option>
            </select>
          </label>
          <label class="block">
            <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">ตั้งแต่วันที่</span>
            <input v-model="generatedFilters.date_from" type="date" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm" />
          </label>
          <label class="block">
            <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">ถึงวันที่</span>
            <input v-model="generatedFilters.date_to" type="date" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm" />
          </label>
          <div class="md:col-span-5 flex justify-end gap-2">
            <button type="button" class="px-4 py-2.5 rounded-xl border border-gray-200 text-gray-700 text-sm min-h-[44px]" @click="resetGeneratedFilters">ล้างตัวกรอง</button>
            <button type="submit" class="px-5 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm min-h-[44px]">ค้นหา</button>
          </div>
        </form>

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-x-auto">
        <table class="w-full text-sm min-w-[820px]" :class="{ 'opacity-50': generatedLoading }">
          <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
              <th class="text-left px-5 py-3">เอกสาร</th>
              <th class="text-left px-5 py-3">แม่แบบ</th>
              <th class="text-left px-5 py-3">ฉบับที่</th>
              <th class="text-left px-5 py-3">สถานะ</th>
              <th class="text-left px-5 py-3">สร้างเมื่อ</th>
              <th class="text-right px-5 py-3">ไฟล์</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="file in generated" :key="file.id" class="border-t border-gray-50">
              <td class="px-5 py-3">{{ file.source_label || `${file.source_type} #${file.source_id}` }}</td>
              <td class="px-5 py-3">{{ file.template?.name || '—' }}</td>
              <td class="px-5 py-3">{{ file.revision }}</td>
              <td class="px-5 py-3">
                <span class="px-2 py-0.5 rounded-full text-xs font-semibold" :class="fileStatusClass(file.status)">
                  {{ fileStatusLabel(file.status) }}
                </span>
              </td>
              <td class="px-5 py-3 text-xs text-gray-500">{{ formatDate(file.generated_at) }}</td>
              <td class="px-5 py-3 text-right">
                <button v-if="canDownload" @click="downloadGenerated(file)" class="px-3 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-100 text-xs font-semibold">ดาวน์โหลด</button>
              </td>
            </tr>
            <tr v-if="generated.length === 0">
              <td colspan="6" class="px-5 py-10 text-center text-gray-400">ไม่มีเอกสารที่ตรงกับเงื่อนไข หรือที่คุณมีสิทธิ์เปิด</td>
            </tr>
          </tbody>
        </table>
        </div>

        <AppPagination
          :page="generatedPage"
          :total="generatedTotal"
          :per-page="GENERATED_PER_PAGE"
          @update:page="loadGenerated"
        />
      </section>

      <!-- รายงาน -->
      <!-- ค่าชดเชยการพิมพ์ -->
      <section v-else-if="activeTab === 'calibration'" class="space-y-4">
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 text-sm text-amber-900">
          ใช้เมื่อเครื่องพิมพ์วางข้อความเยื้องจากตำแหน่งจริง — ปรับที่นี่แทนการขยับตำแหน่งฟิลด์ในแบบฟอร์ม
          เพราะเครื่องพิมพ์แต่ละเครื่องเพี้ยนไม่เท่ากัน ค่าที่เจาะจงกว่าจะอยู่ทับ (ทั้งระบบ → แม่แบบ → ผู้ใช้)
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
            <div>
              <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">ระดับ</label>
              <select aria-label="ระดับ" v-model="calibrationForm.scope" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white">
                <option value="GLOBAL">ทั้งระบบ</option>
                <option value="TEMPLATE">เฉพาะแม่แบบ</option>
              </select>
            </div>
            <div v-if="calibrationForm.scope === 'TEMPLATE'">
              <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">แม่แบบ</label>
              <select aria-label="แม่แบบ" v-model="calibrationForm.document_template_id" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white">
                <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}</option>
              </select>
            </div>
            <div>
              <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">เลื่อนแนวนอน (pt)</label>
              <input aria-label="เลื่อนแนวนอน (pt)" v-model.number="calibrationForm.x_offset" type="number" step="0.5" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm" />
            </div>
            <div>
              <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">เลื่อนแนวตั้ง (pt)</label>
              <input aria-label="เลื่อนแนวตั้ง (pt)" v-model.number="calibrationForm.y_offset" type="number" step="0.5" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm" />
            </div>
            <button
              v-if="canManage"
              @click="saveCalibration"
              class="px-5 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm min-h-[44px]"
            >
              บันทึกค่าชดเชย
            </button>
          </div>
        </div>

        <div v-if="calibrations.length" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 space-y-2">
          <div v-for="row in calibrations" :key="row.id" class="flex items-center justify-between text-sm border-b border-gray-50 last:border-0 py-2">
            <span class="font-semibold text-gray-700">{{ scopeLabel(row.scope) }}</span>
            <span class="text-gray-500 text-xs">แนวนอน {{ row.x_offset }} pt · แนวตั้ง {{ row.y_offset }} pt · สเกล {{ row.scale }}</span>
          </div>
        </div>
      </section>
    </template>

    <!-- Modal สร้างแม่แบบ -->
    <div v-if="showCreate" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4 overflow-y-auto" @click.self="showCreate = false">
      <div class="bg-white rounded-2xl max-w-lg w-full p-6 space-y-4 shadow-2xl max-h-[90vh] overflow-y-auto my-auto">
        <h3 class="text-lg font-bold text-gray-900">สร้างแม่แบบเอกสารใหม่</h3>
        <div class="space-y-3">
          <div>
            <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">ชื่อแม่แบบ *</label>
            <input aria-label="ชื่อแม่แบบ" v-model="createForm.name" type="text" placeholder="เช่น แบบใบขอลาพักผ่อน" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm" />
          </div>
          <div>
            <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">รหัส (ภาษาอังกฤษ) *</label>
            <input aria-label="รหัส (ภาษาอังกฤษ)" v-model="createForm.code" type="text" placeholder="form_leave_vacation" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-mono" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">โมดูล</label>
              <select aria-label="โมดูล" v-model="createForm.module" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm bg-white">
                <option value="leave">ระบบการลา</option>
                <option value="hr">งานบุคคล</option>
                <option value="finance">การเงิน</option>
                <option value="procurement">พัสดุ</option>
                <option value="core">ส่วนกลาง</option>
              </select>
            </div>
            <div>
              <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">ชนิดเอกสาร</label>
              <select aria-label="ชนิดเอกสาร" v-model="createForm.document_type" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm bg-white">
                <option v-for="type in documentTypes" :key="type.value" :value="type.value">{{ type.label }}</option>
              </select>
            </div>
          </div>
          <div>
            <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">วิธีเรนเดอร์</label>
            <select aria-label="วิธีเรนเดอร์" v-model="createForm.render_mode" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm bg-white">
              <option value="pdf_overlay">วางฟิลด์บนแบบฟอร์ม PDF (แนะนำสำหรับแบบฟอร์มราชการ)</option>
              <option value="html">แม่แบบ HTML แบบเดิม</option>
            </select>
          </div>
        </div>
        <div class="flex justify-end gap-3 pt-2">
          <button @click="showCreate = false" class="px-4 py-2.5 rounded-xl border border-gray-200 text-gray-700 text-sm font-medium min-h-[44px]">ยกเลิก</button>
          <button @click="createTemplate" :disabled="saving" class="px-5 py-2.5 rounded-xl bg-[#800000] text-white text-sm font-semibold disabled:opacity-50 min-h-[44px]">
            {{ saving ? 'กำลังสร้าง...' : 'สร้างแม่แบบ' }}
          </button>
        </div>
      </div>
    </div>

    <!--
      แก้เนื้อหาแม่แบบชนิด HTML (CLEANUP-01 · D2)
      ย้ายมาจากหน้า "จัดการแม่แบบเอกสาร" ที่ถูกยุบเข้าศูนย์จัดการเอกสารแล้ว
    -->
    <div v-if="contentEditor" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="closeContentEditor">
      <div class="bg-white rounded-2xl shadow-2xl w-full max-w-6xl max-h-[92vh] flex flex-col">
        <header class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
          <div>
            <h3 class="font-bold text-gray-900">แก้เนื้อหาแม่แบบ — {{ contentEditor.name }}</h3>
            <p class="text-xs text-gray-500 font-mono">{{ contentEditor.code }}</p>
          </div>
          <button type="button" class="p-2 rounded-lg text-gray-400 hover:bg-gray-100" aria-label="ปิด" @click="closeContentEditor">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </header>

        <div class="flex-1 overflow-y-auto px-6 py-5 grid grid-cols-1 lg:grid-cols-3 gap-5">
          <div class="lg:col-span-2 space-y-4">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
              <label class="text-xs font-semibold text-gray-600 col-span-2">
                ชื่อแม่แบบ
                <input v-model="contentForm.name" type="text" class="mt-1 w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]">
              </label>
              <label class="text-xs font-semibold text-gray-600">
                ขนาดกระดาษ
                <select v-model="contentForm.paper_size" class="mt-1 w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]">
                  <option value="A4">A4</option>
                  <option value="Letter">Letter (8.5 × 11 นิ้ว)</option>
                  <option value="Legal">Legal (8.5 × 14 นิ้ว)</option>
                </select>
              </label>
              <label class="text-xs font-semibold text-gray-600">
                แนวกระดาษ
                <select v-model="contentForm.orientation" class="mt-1 w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]">
                  <option value="portrait">แนวตั้ง</option>
                  <option value="landscape">แนวนอน</option>
                </select>
              </label>
            </div>

            <label class="block text-xs font-semibold text-gray-600">
              เนื้อหาแม่แบบ (HTML + ตัวแปร Blade)
              <textarea
                v-model="contentForm.content_html"
                rows="20"
                spellcheck="false"
                class="mt-1 w-full border-gray-300 rounded-lg font-mono text-xs leading-relaxed focus:ring-[#800000] focus:border-[#800000]"
              ></textarea>
            </label>
          </div>

          <aside class="space-y-3">
            <p class="text-xs text-gray-500">คลิกตัวแปรเพื่อแทรกลงท้ายเนื้อหา</p>
            <div v-for="(fields, group) in contentFieldGroups" :key="group">
              <h4 class="text-xs font-bold text-gray-700 mb-1">{{ group }}</h4>
              <div class="flex flex-wrap gap-1">
                <button
                  v-for="field in fields"
                  :key="field.name"
                  type="button"
                  :title="field.label"
                  class="text-[11px] font-mono bg-gray-100 hover:bg-gray-200 text-gray-700 px-2 py-0.5 rounded"
                  @click="insertTag(`{{ $${field.name} }}`)"
                >
                  {{ field.name }}
                </button>
              </div>
            </div>
          </aside>
        </div>

        <footer class="px-6 py-4 border-t border-gray-100 flex flex-wrap justify-end gap-3">
          <button
            type="button"
            :disabled="previewing"
            class="px-4 py-2.5 rounded-xl border border-gray-200 text-gray-700 text-sm font-medium min-h-[44px] disabled:opacity-50"
            @click="previewContent"
          >
            {{ previewing ? 'กำลังสร้างพรีวิว…' : 'พรีวิวด้วยข้อมูลตัวอย่าง' }}
          </button>
          <button type="button" class="px-4 py-2.5 rounded-xl border border-gray-200 text-gray-700 text-sm font-medium min-h-[44px]" @click="closeContentEditor">ยกเลิก</button>
          <button
            type="button"
            :disabled="saving"
            class="px-5 py-2.5 rounded-xl bg-[#800000] text-white text-sm font-semibold disabled:opacity-50 min-h-[44px]"
            @click="saveContent"
          >
            {{ saving ? 'กำลังบันทึก…' : 'บันทึกเนื้อหา' }}
          </button>
        </footer>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { useAuthStore } from '../../stores/auth'
import { useConfirm } from '../../composables/useConfirm'
import AppPagination from '../../components/ui/AppPagination.vue'

const router = useRouter()
const alertStore = useAlertStore()
const authStore = useAuthStore()
const { confirmAction, confirmDanger } = useConfirm()

/*
 * ปุ่มแสดงตามสิทธิ์ที่ API ใช้ตัดสินจริง — ผู้ดูอย่างเดียวไม่ต้องเห็นปุ่มที่กดแล้วได้ 403
 * ด่านจริงยังอยู่ที่ backend เสมอ
 */
const canManage = computed(() => authStore.hasAnyPermission(['document.template.manage', 'system.setting.manage']))
const canDownload = computed(() => authStore.hasAnyPermission(['document.final.download', 'system.setting.manage']))

const loading = ref(false)
const saving = ref(false)
const activeTab = ref('templates')
const showCreate = ref(false)

const overview = ref(null)
const templates = ref([])
const fields = ref([])
const dataSources = ref([])
const bindings = ref([])
const bindingEntities = ref({})
const generated = ref([])
const generatedPage = ref(1)
const generatedTotal = ref(0)
const generatedLoading = ref(false)
const GENERATED_PER_PAGE = 30
const generatedFilters = ref({ q: '', status: '', date_from: '', date_to: '' })
const failedSections = ref([])
const calibrations = ref([])
const leaveTypeForms = ref([])
const fieldForm = ref(null)
const savingField = ref(false)
const packageInput = ref(null)
const importing = ref(false)
const enabledLeaveTypes = ref([])
const savingEntities = ref(false)
const unboundLeaveTypes = ref([])
const bindingForm = ref({ id: null, module: 'LEAVE', entity_type: 'LEAVE_TYPE', entity_id: null, document_template_id: null })

const tabs = [
  { value: 'templates', label: 'แบบฟอร์มเอกสาร' },
  { value: 'bindings', label: 'การผูกแบบฟอร์ม' },
  { value: 'fields', label: 'สารบัญตัวแปรเอกสาร' },
  { value: 'sources', label: 'แหล่งข้อมูล' },
  { value: 'generated', label: 'เอกสารที่สร้างแล้ว' },
  { value: 'calibration', label: 'ตั้งค่าการพิมพ์' },
]

const documentTypes = [
  { value: 'FORM', label: 'แบบฟอร์ม (1 รายการ)' },
  { value: 'REPORT', label: 'รายงาน (หลายรายการ)' },
  { value: 'CERTIFICATE', label: 'หนังสือรับรอง' },
  { value: 'LETTER', label: 'หนังสือราชการ' },
  { value: 'LABEL', label: 'ป้าย/สลาก' },
  { value: 'OTHER', label: 'อื่น ๆ' },
]

const templateCategoryFilter = ref('ALL')
const templateSearchQuery = ref('')

const templateCategories = [
  { value: 'ALL', label: 'ทั้งหมด' },
  { value: 'LEAVE_FORM', label: 'แบบฟอร์มการลา' },
  { value: 'ADMIN_CUSTOM', label: 'แบบฟอร์มทั่วไป / อื่น ๆ' },
]

/*
 * DC-05 — หมวดแยกตามโมดูลที่แม่แบบสังกัดเท่านั้น ไม่ปนกับชนิดเอกสาร
 * เดิมแม่แบบชนิด FORM ของโมดูลอื่นไหลเข้าหมวดการลา · ค่า module มีทั้ง 'leave' และ 'LEAVE' จึงเทียบแบบไม่สนตัวพิมพ์
 */
const isLeaveTemplate = (template) => String(template.module || '').toLowerCase() === 'leave'

const filteredTemplates = computed(() => {
  let list = templates.value || []
  if (templateCategoryFilter.value === 'LEAVE_FORM') {
    list = list.filter(isLeaveTemplate)
  } else if (templateCategoryFilter.value === 'ADMIN_CUSTOM') {
    list = list.filter(t => !isLeaveTemplate(t))
  }

  if (templateSearchQuery.value.trim()) {
    const q = templateSearchQuery.value.trim().toLowerCase()
    list = list.filter(t =>
      (t.name && t.name.toLowerCase().includes(q)) ||
      (t.code && t.code.toLowerCase().includes(q)) ||
      (t.description && t.description.toLowerCase().includes(q))
    )
  }
  return list
})

const createForm = ref({ name: '', code: '', module: 'leave', document_type: 'FORM', render_mode: 'pdf_overlay' })
const calibrationForm = ref({ scope: 'GLOBAL', document_template_id: null, x_offset: 0, y_offset: 0, scale: 1 })

const cards = computed(() => overview.value ? [
  { label: 'แม่แบบทั้งหมด', value: overview.value.templates, hint: `วางฟิลด์บน PDF ${overview.value.pdf_templates} แบบ` },
  { label: 'เวอร์ชันที่ใช้งาน', value: overview.value.active_versions, hint: `ทั้งหมด ${overview.value.versions} เวอร์ชัน` },
  { label: 'ตัวแปรที่ใช้ได้', value: overview.value.field_definitions, hint: `จาก ${overview.value.data_sources} แหล่งข้อมูล` },
  { label: 'เอกสารที่ออกแล้ว', value: overview.value.generated_files, hint: `ฉบับสมบูรณ์ ${overview.value.finalized_files} ฉบับ` },
] : [])

const groupedFields = computed(() => {
  const groups = {}
  for (const field of fields.value) {
    const key = field.group_th || 'อื่น ๆ'
    groups[key] = groups[key] || []
    groups[key].push(field)
  }
  return groups
})

const typeLabel = (value) => documentTypes.find(t => t.value === value)?.label || value
const statusLabel = (status) => ({ DRAFT: 'ฉบับร่าง', ACTIVE: 'ใช้งานอยู่', RETIRED: 'เลิกใช้แล้ว', ARCHIVED: 'เก็บถาวร' })[status] || status
const scopeLabel = (scope) => ({ GLOBAL: 'ทั้งระบบ', TEMPLATE: 'เฉพาะแม่แบบ', USER: 'เฉพาะผู้ใช้' })[scope] || scope
const entityTypeLabel = (type) => ({ LEAVE_TYPE: 'ประเภทการลา', LEAVE_CANCELLATION: 'คำขอยกเลิกวันลา' })[type] || type
const fileStatusLabel = (status) => ({ GENERATED: 'ออกแล้ว', FINALIZED: 'ฉบับสมบูรณ์', SUPERSEDED: 'ถูกแทนที่' })[status] || status
const fileStatusClass = (status) => ({
  GENERATED: 'bg-gray-100 text-gray-700',
  FINALIZED: 'bg-green-100 text-green-800',
  SUPERSEDED: 'bg-amber-100 text-amber-800',
})[status] || 'bg-gray-100 text-gray-700'

const entityName = (rule) => {
  if (!rule.entity_id) return 'ทุกรายการ'
  const list = bindingEntities.value?.[rule.module]?.[rule.entity_type] || []
  return list.find(item => item.id === rule.entity_id)?.name || `#${rule.entity_id}`
}

const formatDate = (value) => value ? new Date(value).toLocaleString('th-TH', { dateStyle: 'short', timeStyle: 'short' }) : '—'

/*
 * โหลดแต่ละส่วนแยกกัน — เดิมใช้ Promise.all ถ้าคำขอเดียวล้ม ทั้งหน้าจะไม่อัปเดตเลย
 * ตอนนี้ส่วนที่โหลดได้แสดงตามปกติ และบอกชื่อส่วนที่โหลดไม่สำเร็จให้กดรีเฟรชใหม่
 */
const sections = {
  overview: { label: 'ภาพรวม', url: '/admin/document-engine/overview', apply: (data) => { overview.value = data } },
  templates: { label: 'แบบฟอร์มเอกสาร', url: '/admin/document-engine/templates', apply: (data) => { templates.value = data } },
  fields: { label: 'สารบัญตัวแปร', url: '/admin/document-engine/fields', apply: (data) => { fields.value = data } },
  sources: { label: 'แหล่งข้อมูล', url: '/admin/document-engine/data-sources', apply: (data) => { dataSources.value = data } },
  bindings: {
    label: 'การผูกแบบฟอร์ม',
    url: '/admin/document-engine/bindings',
    apply: (data) => {
      bindings.value = data.rules
      bindingEntities.value = data.entities
      unboundLeaveTypes.value = data.unbound_leave_types || []
    },
  },
  calibrations: { label: 'ตั้งค่าการพิมพ์', url: '/admin/document-engine/calibrations', apply: (data) => { calibrations.value = data } },
  forms: { label: 'แบบฟอร์มรายประเภทการลา', url: '/admin/document-engine/leave-type-forms', apply: (data) => { leaveTypeForms.value = data.rows } },
}

const loadAll = async () => {
  loading.value = true
  const keys = Object.keys(sections)
  const results = await Promise.allSettled(keys.map(key => api.get(sections[key].url)))

  failedSections.value = []
  results.forEach((result, index) => {
    const section = sections[keys[index]]
    if (result.status === 'fulfilled') {
      section.apply(result.value.data)
    } else {
      failedSections.value.push(section.label)
    }
  })

  if (overview.value && leaveTypeForms.value.length) {
    // `['*']` = เปิดทุกประเภท — แสดงเป็นการติ๊กครบทุกช่องเพื่อไม่ให้ผู้ดูแลเห็นสถานะที่ขัดกับความจริง
    enabledLeaveTypes.value = overview.value.enabled_leave_types.includes('*')
      ? leaveTypeForms.value.map(row => row.leave_type_id)
      : leaveTypeForms.value.filter(row => row.engine_enabled).map(row => row.leave_type_id)
  }

  loading.value = false
  await loadGenerated(generatedPage.value)
}

/* DC-04 — เอกสารที่ออกแล้วแบ่งหน้าที่เซิร์ฟเวอร์ (หน้าละ 30) พร้อมตัวกรอง */
const loadGenerated = async (page = 1) => {
  generatedLoading.value = true
  try {
    const params = { page }
    for (const [key, value] of Object.entries(generatedFilters.value)) {
      if (value) params[key] = value
    }
    const { data } = await api.get('/admin/document-engine/generated', { params })
    generated.value = data.data || []
    generatedTotal.value = data.total ?? generated.value.length
    generatedPage.value = data.current_page ?? page
    failedSections.value = failedSections.value.filter(label => label !== 'เอกสารที่สร้างแล้ว')
  } catch (error) {
    if (!failedSections.value.includes('เอกสารที่สร้างแล้ว')) failedSections.value.push('เอกสารที่สร้างแล้ว')
    if (error.response?.status === 422) {
      alertStore.error(Object.values(error.response.data?.errors || {}).flat()[0] || 'ตัวกรองไม่ถูกต้อง')
    }
  } finally {
    generatedLoading.value = false
  }
}

const applyGeneratedFilters = () => loadGenerated(1)

const resetGeneratedFilters = () => {
  generatedFilters.value = { q: '', status: '', date_from: '', date_to: '' }
  loadGenerated(1)
}

const importPackage = async (event) => {
  const file = event.target.files?.[0]
  if (!file) return

  importing.value = true
  try {
    const form = new FormData()
    form.append('file', file)
    const res = await api.post('/admin/document-engine/templates/import', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    alertStore.success(res.data.message)
    await loadAll()
    router.push({ name: 'document-designer', params: { templateId: res.data.template_id } })
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'นำเข้าชุดแบบฟอร์มไม่สำเร็จ')
  } finally {
    importing.value = false
    event.target.value = ''
  }
}

const editField = (field) => {
  fieldForm.value = {
    id: field.id,
    code: field.code,
    label_th: field.label_th,
    group_th: field.group_th || '',
    description: field.description || '',
  }
}

const saveField = async () => {
  savingField.value = true
  try {
    const res = await api.put(`/admin/document-engine/fields/${fieldForm.value.id}`, {
      label_th: fieldForm.value.label_th,
      group_th: fieldForm.value.group_th || null,
      description: fieldForm.value.description || null,
    })
    alertStore.success(res.data.message)
    fieldForm.value = null
    await loadAll()
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'บันทึกตัวแปรไม่สำเร็จ')
  } finally {
    savingField.value = false
  }
}

const deactivateField = async () => {
  const confirmed = await confirmAction({
    title: 'ปิดการใช้งานตัวแปร',
    message: 'ปิดการใช้งานตัวแปรนี้?',
    detail: 'จะไม่แสดงให้เลือกในหน้าออกแบบอีก แบบฟอร์มเดิมที่ใช้อยู่ยังพิมพ์ได้เหมือนเดิม',
    confirmText: 'ปิดการใช้งาน',
  })

  if (!confirmed) return
  savingField.value = true
  try {
    const res = await api.delete(`/admin/document-engine/fields/${fieldForm.value.id}`)
    alertStore.success(res.data.message)
    fieldForm.value = null
    await loadAll()
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'ปิดการใช้งานตัวแปรไม่สำเร็จ')
  } finally {
    savingField.value = false
  }
}

const saveEnabledLeaveTypes = async () => {
  savingEntities.value = true
  try {
    const res = await api.post('/admin/document-engine/enabled-entities', {
      leave_type_ids: enabledLeaveTypes.value,
    })
    alertStore.success(res.data.message)
    await loadAll()
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'บันทึกประเภทที่เปิดใช้ไม่สำเร็จ')
  } finally {
    savingEntities.value = false
  }
}

const createTemplate = async () => {
  if (!createForm.value.name.trim() || !createForm.value.code.trim()) {
    alertStore.error('กรุณากรอกชื่อและรหัสแม่แบบ')
    return
  }
  saving.value = true
  try {
    const res = await api.post('/admin/document-engine/templates', createForm.value)
    alertStore.success(res.data.message)
    showCreate.value = false
    createForm.value = { name: '', code: '', module: 'leave', document_type: 'FORM', render_mode: 'pdf_overlay' }
    await loadAll()
  } catch (error) {
    alertStore.error(error.response?.data?.message || Object.values(error.response?.data?.errors || {})[0]?.[0] || 'สร้างแม่แบบไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

const openTemplate = (template) => {
  router.push({ name: 'document-designer', params: { templateId: template.id } })
}

/*
 * ─────────── แม่แบบชนิด HTML (CLEANUP-01 · D2) ───────────
 *
 * ย้ายมาจากหน้า "จัดการแม่แบบเอกสาร" (`/admin/document-templates`) ที่เป็นเมนูแยก
 * ทั้งที่อ่านเขียนตารางเดียวกับหน้านี้ ตอนนี้เหลือทางเข้าเดียวคือศูนย์จัดการเอกสาร
 */
const contentEditor = ref(null)
const contentForm = ref({ name: '', paper_size: 'A4', orientation: 'portrait', content_html: '' })
const contentFields = ref([])
const previewing = ref(false)
const seeding = ref(false)

// สารบัญตัวแปรมาเป็นรายการแบน { group, name, label } — จัดกลุ่มให้อ่านง่ายตอนแสดง
const contentFieldGroups = computed(() =>
  contentFields.value.reduce((groups, field) => {
    (groups[field.group] ||= []).push(field)
    return groups
  }, {}),
)

const openContentEditor = async (template) => {
  try {
    const { data } = await api.get(`/admin/document-engine/templates/${template.id}`)
    contentEditor.value = template
    contentForm.value = {
      name: data.template.name,
      description: data.template.description,
      paper_size: data.template.paper_size || 'A4',
      orientation: data.template.orientation || 'portrait',
      margin_top: data.template.margin_top,
      margin_bottom: data.template.margin_bottom,
      margin_left: data.template.margin_left,
      margin_right: data.template.margin_right,
      content_html: data.template.content_html || '',
      is_active: data.template.is_active,
    }

    if (!contentFields.value.length) {
      const fieldsResponse = await api.get('/admin/document-engine/templates/content-fields')
      contentFields.value = fieldsResponse.data
    }
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'เปิดแม่แบบไม่สำเร็จ')
  }
}

const closeContentEditor = () => {
  contentEditor.value = null
}

const insertTag = (tag) => {
  contentForm.value.content_html += ` ${tag}`
}

const saveContent = async () => {
  saving.value = true
  try {
    const { data } = await api.put(`/admin/document-engine/templates/${contentEditor.value.id}/content`, contentForm.value)
    alertStore.success(data.message)
    closeContentEditor()
    await loadAll()
  } catch (error) {
    alertStore.error(
      Object.values(error.response?.data?.errors || {}).flat()[0]
        || error.response?.data?.message
        || 'บันทึกเนื้อหาแม่แบบไม่สำเร็จ',
    )
  } finally {
    saving.value = false
  }
}

/* พรีวิวเรนเดอร์ผ่าน dompdf ตัวเดียวกับตอนพิมพ์จริง จึงเห็นผลตรงกับเอกสารที่จะออกมา */
const previewContent = async () => {
  previewing.value = true
  try {
    // DC-06 — ส่งระยะขอบที่กำลังแก้ไปด้วย พรีวิวจึงใช้สไตล์หน้ากระดาษชุดเดียวกับตอนพิมพ์จริง
    const response = await api.post('/admin/document-engine/templates/preview', {
      content_html: contentForm.value.content_html,
      paper_size: contentForm.value.paper_size,
      orientation: contentForm.value.orientation,
      margin_top: contentForm.value.margin_top,
      margin_right: contentForm.value.margin_right,
      margin_bottom: contentForm.value.margin_bottom,
      margin_left: contentForm.value.margin_left,
    }, { responseType: 'blob' })
    const url = URL.createObjectURL(new Blob([response.data], { type: 'application/pdf' }))
    window.open(url, '_blank')
    setTimeout(() => URL.revokeObjectURL(url), 60000)
  } catch (error) {
    let detail = error.response?.data?.message
    if (error.response?.data instanceof Blob) {
      try {
        detail = JSON.parse(await error.response.data.text())?.message
      } catch {
        detail = null
      }
    }
    alertStore.error(detail || 'สร้างพรีวิวไม่สำเร็จ')
  } finally {
    previewing.value = false
  }
}

const deleteTemplate = async (template) => {
  const confirmed = await confirmDanger({
    title: 'ลบแม่แบบเอกสาร',
    message: `ต้องการลบแม่แบบ "${template.name}" ใช่หรือไม่?`,
    confirmText: 'ลบแม่แบบ',
  })

  if (!confirmed) return
  try {
    const { data } = await api.delete(`/admin/document-engine/templates/${template.id}`)
    alertStore.success(data.message)
    await loadAll()
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'ลบแม่แบบไม่สำเร็จ')
  }
}

const seedDefaults = async (overwrite) => {
  seeding.value = true
  try {
    const { data } = await api.post('/admin/document-engine/templates/seed-defaults', { overwrite })
    alertStore.success(data.message)
    await loadAll()
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'ซิงค์แม่แบบตั้งต้นไม่สำเร็จ')
  } finally {
    seeding.value = false
  }
}

const restoreDefaults = async () => {
  const confirmed = await confirmDanger({
    title: 'คืนค่าแบบฟอร์มตั้งต้น',
    message: 'คืนค่าแบบฟอร์มตั้งต้นทั้งหมด? เนื้อหาที่แก้ไว้จะถูกแทนที่',
    detail: 'เวอร์ชันเดิมยังถูกเก็บไว้ และใบลาเก่ายังพิมพ์ได้เหมือนเดิม',
    confirmText: 'คืนค่าตั้งต้น',
  })

  if (confirmed) await seedDefaults(true)
}

const leaveTypeOptions = computed(() => bindingEntities.value?.LEAVE?.[bindingForm.value.entity_type] || [])

const resetBindingForm = () => {
  bindingForm.value = { id: null, module: 'LEAVE', entity_type: 'LEAVE_TYPE', entity_id: null, document_template_id: null }
}

const editBinding = (rule) => {
  bindingForm.value = {
    id: rule.id,
    module: rule.module,
    entity_type: rule.entity_type,
    entity_id: rule.entity_id,
    document_template_id: rule.document_template_id,
  }
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

const saveBinding = async () => {
  try {
    const res = await api.post('/admin/document-engine/bindings', bindingForm.value)
    alertStore.success(res.data.message)
    resetBindingForm()
    await loadAll()
  } catch (error) {
    alertStore.error(error.response?.data?.message || Object.values(error.response?.data?.errors || {})[0]?.[0] || 'ผูกแบบฟอร์มไม่สำเร็จ')
  }
}

const removeBinding = async (rule) => {
  const confirmed = await confirmDanger({
    title: 'ลบกติกาการผูกแบบฟอร์ม',
    message: 'ลบกติกาการผูกแบบฟอร์มนี้หรือไม่?',
    detail: 'เอกสารที่เข้าเงื่อนไขนี้จะกลับไปใช้แบบฟอร์มตั้งต้น',
    confirmText: 'ลบกติกา',
  })

  if (!confirmed) return
  try {
    await api.delete(`/admin/document-engine/bindings/${rule.id}`)
    alertStore.success('ลบกติกาเรียบร้อยแล้ว')
    await loadAll()
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'ลบไม่สำเร็จ')
  }
}

const downloadGenerated = async (file) => {
  try {
    const res = await api.get(`/admin/document-engine/generated/${file.id}/download`, { responseType: 'blob' })
    const url = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
    window.open(url, '_blank')
  } catch {
    alertStore.error('ดาวน์โหลดเอกสารไม่สำเร็จ')
  }
}

const saveCalibration = async () => {
  try {
    const res = await api.post('/admin/document-engine/calibrations', {
      ...calibrationForm.value,
      scale: calibrationForm.value.scale || 1,
    })
    alertStore.success(res.data.message)
    await loadAll()
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'บันทึกค่าชดเชยไม่สำเร็จ')
  }
}

onMounted(loadAll)
</script>
