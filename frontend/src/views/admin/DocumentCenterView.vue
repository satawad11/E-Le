<template>
  <div>
    <!-- Header -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">ศูนย์จัดการเอกสารและรายงาน</h1>
        <p class="text-gray-500 mt-1 text-sm">
          จัดการแบบฟอร์มราชการ เวอร์ชัน ตำแหน่งฟิลด์ และเอกสารที่ระบบออกให้ — ใช้ได้กับทุกโมดูล ไม่เฉพาะระบบการลา
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
        {{ overview.engine_enabled ? 'Document Engine เปิดใช้งานอยู่' : 'Document Engine ปิดอยู่ (ระบบใช้เส้นทางพิมพ์เอกสารเดิม)' }}
      </span>
      <span v-if="overview.engine_enabled">
        ประเภทการลาที่เปิด: {{ overview.enabled_leave_types.length ? overview.enabled_leave_types.join(', ') : 'ยังไม่ได้ระบุ' }}
      </span>
      <span v-else class="text-amber-800">
        เปิดได้ที่ไฟล์ตั้งค่า <code class="bg-white/70 px-1.5 py-0.5 rounded">DOCUMENT_ENGINE_ENABLED=true</code>
        และระบุประเภทการลาที่ <code class="bg-white/70 px-1.5 py-0.5 rounded">DOCUMENT_ENGINE_LEAVE_TYPES</code>
      </span>
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
        <div class="flex justify-end">
          <button
            @click="showCreate = true"
            class="px-5 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm shadow-md min-h-[44px]"
          >
            + สร้างแม่แบบใหม่
          </button>
        </div>

        <div v-if="templates.length === 0" class="bg-white rounded-2xl border border-gray-100 p-12 text-center text-gray-500">
          ยังไม่มีแม่แบบเอกสารในระบบ
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
              <tr v-for="template in templates" :key="template.id" class="border-t border-gray-50 hover:bg-gray-50/60">
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
                <td class="px-5 py-3 text-right">
                  <button
                    @click="openTemplate(template)"
                    class="px-3 py-1.5 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-100 text-xs font-semibold"
                  >
                    จัดการเวอร์ชัน
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Field Dictionary -->
      <section v-else-if="activeTab === 'fields'" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
        <p class="text-sm text-gray-500 mb-4">
          ตัวแปรทั้งหมดที่แบบฟอร์มและรายงานอ้างถึงได้ ค่าเหล่านี้มาจากแหล่งข้อมูลที่ระบบลงทะเบียนไว้ ไม่ได้มาจากการเขียน SQL เอง
        </p>
        <div v-for="(fields, group) in groupedFields" :key="group" class="mb-5">
          <h3 class="font-bold text-gray-800 text-sm mb-2">{{ group }}</h3>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
            <div v-for="field in fields" :key="field.id" class="flex items-start justify-between gap-3 text-xs border border-gray-100 rounded-xl px-3 py-2">
              <div>
                <p class="font-semibold text-gray-800">{{ field.label_th }}</p>
                <p class="font-mono text-[11px] text-gray-400">{{ field.code }}</p>
              </div>
              <span class="text-[10px] font-semibold text-gray-500 bg-gray-100 rounded px-1.5 py-0.5 flex-shrink-0">{{ field.data_type }}</span>
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
                <button @click="removeBinding(rule)" class="px-3 py-1.5 rounded-lg text-red-600 hover:bg-red-50 text-xs font-semibold">ลบ</button>
              </td>
            </tr>
            <tr v-if="bindings.length === 0">
              <td colspan="6" class="px-5 py-10 text-center text-gray-400">ยังไม่มีการผูกแบบฟอร์ม</td>
            </tr>
          </tbody>
          </table>
        </div>
      </section>

      <!-- เอกสารที่สร้างแล้ว -->
      <section v-else-if="activeTab === 'generated'" class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-x-auto">
        <table class="w-full text-sm min-w-[820px]">
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
              <td class="px-5 py-3 font-mono text-xs">{{ file.source_type }} #{{ file.source_id }}</td>
              <td class="px-5 py-3">{{ file.template?.name || '—' }}</td>
              <td class="px-5 py-3">{{ file.revision }}</td>
              <td class="px-5 py-3">
                <span class="px-2 py-0.5 rounded-full text-xs font-semibold" :class="fileStatusClass(file.status)">
                  {{ fileStatusLabel(file.status) }}
                </span>
              </td>
              <td class="px-5 py-3 text-xs text-gray-500">{{ formatDate(file.generated_at) }}</td>
              <td class="px-5 py-3 text-right">
                <button @click="downloadGenerated(file)" class="px-3 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-100 text-xs font-semibold">ดาวน์โหลด</button>
              </td>
            </tr>
            <tr v-if="generated.length === 0">
              <td colspan="6" class="px-5 py-10 text-center text-gray-400">ยังไม่มีเอกสารที่ระบบออกให้</td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- รายงาน -->
      <section v-else-if="activeTab === 'reports'" class="space-y-4">
        <div class="flex justify-end">
          <router-link
            :to="{ name: 'report-designer' }"
            class="px-5 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm shadow-md min-h-[44px] inline-flex items-center"
          >
            + ออกแบบรายงาน
          </router-link>
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-x-auto">
          <table class="w-full text-sm min-w-[720px]">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
              <tr>
                <th class="text-left px-5 py-3">ชื่อรายงาน</th>
                <th class="text-left px-5 py-3">แหล่งข้อมูล</th>
                <th class="text-left px-5 py-3">จัดกลุ่มตาม</th>
                <th class="text-right px-5 py-3">ออกรายงาน</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="report in reports" :key="report.id" class="border-t border-gray-50">
                <td class="px-5 py-3 font-semibold text-gray-900">{{ report.name_th }}</td>
                <td class="px-5 py-3 font-mono text-xs text-gray-500">{{ report.data_source_code }}</td>
                <td class="px-5 py-3">{{ report.grouping?.column || '—' }}</td>
                <td class="px-5 py-3 text-right space-x-2 whitespace-nowrap">
                  <button @click="runReport(report)" class="px-3 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-100 text-xs font-semibold">ดูผล</button>
                  <button @click="exportReport(report, 'pdf')" class="px-3 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-100 text-xs font-semibold">PDF</button>
                  <button @click="exportReport(report, 'xlsx')" class="px-3 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-100 text-xs font-semibold">Excel</button>
                </td>
              </tr>
              <tr v-if="reports.length === 0">
                <td colspan="4" class="px-5 py-10 text-center text-gray-400">ยังไม่มีรายงานในระบบ</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- ผลรายงาน -->
        <div v-if="reportResult" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 overflow-x-auto">
          <h3 class="font-bold text-gray-900 mb-3">{{ reportResult.definition.name_th }}</h3>
          <table class="w-full text-sm min-w-[720px]">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
              <tr>
                <th class="text-left px-3 py-2">ลำดับ</th>
                <th v-for="(label, key) in reportResult.columns" :key="key" class="text-left px-3 py-2">{{ label }}</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="group in reportResult.groups" :key="group.key">
                <tr v-if="group.label" class="bg-gray-50">
                  <td :colspan="Object.keys(reportResult.columns).length + 1" class="px-3 py-2 font-bold text-gray-700">{{ group.label }}</td>
                </tr>
                <tr v-for="(row, index) in group.rows" :key="group.key + '-' + index" class="border-t border-gray-50">
                  <td class="px-3 py-2 text-gray-400">{{ index + 1 }}</td>
                  <td v-for="(label, key) in reportResult.columns" :key="key" class="px-3 py-2">{{ row[key] }}</td>
                </tr>
                <tr v-if="group.label" class="border-t border-gray-100 bg-gray-50/60">
                  <td class="px-3 py-2"></td>
                  <td v-for="(label, key, i) in reportResult.columns" :key="key" class="px-3 py-2 font-semibold">
                    {{ i === 0 ? 'รวม ' + group.label : (group.subtotal?.[key] ?? '') }}
                  </td>
                </tr>
              </template>
              <tr class="border-t-2 border-gray-200 bg-gray-100">
                <td class="px-3 py-2"></td>
                <td v-for="(label, key, i) in reportResult.columns" :key="key" class="px-3 py-2 font-bold">
                  {{ i === 0 ? 'รวมทั้งสิ้น' : (reportResult.totals?.[key] ?? '') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

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
              <select v-model="calibrationForm.scope" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white">
                <option value="GLOBAL">ทั้งระบบ</option>
                <option value="TEMPLATE">เฉพาะแม่แบบ</option>
              </select>
            </div>
            <div v-if="calibrationForm.scope === 'TEMPLATE'">
              <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">แม่แบบ</label>
              <select v-model="calibrationForm.document_template_id" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white">
                <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}</option>
              </select>
            </div>
            <div>
              <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">เลื่อนแนวนอน (pt)</label>
              <input v-model.number="calibrationForm.x_offset" type="number" step="0.5" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm" />
            </div>
            <div>
              <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">เลื่อนแนวตั้ง (pt)</label>
              <input v-model.number="calibrationForm.y_offset" type="number" step="0.5" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm" />
            </div>
            <button
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
    <div v-if="showCreate" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showCreate = false">
      <div class="bg-white rounded-2xl max-w-lg w-full p-6 space-y-4 shadow-2xl">
        <h3 class="text-lg font-bold text-gray-900">สร้างแม่แบบเอกสารใหม่</h3>
        <div class="space-y-3">
          <div>
            <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">ชื่อแม่แบบ *</label>
            <input v-model="createForm.name" type="text" placeholder="เช่น แบบใบขอลาพักผ่อน" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm" />
          </div>
          <div>
            <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">รหัส (ภาษาอังกฤษ) *</label>
            <input v-model="createForm.code" type="text" placeholder="form_leave_vacation" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-mono" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">โมดูล</label>
              <select v-model="createForm.module" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm bg-white">
                <option value="leave">ระบบการลา</option>
                <option value="hr">งานบุคคล</option>
                <option value="finance">การเงิน</option>
                <option value="procurement">พัสดุ</option>
                <option value="core">ส่วนกลาง</option>
              </select>
            </div>
            <div>
              <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">ชนิดเอกสาร</label>
              <select v-model="createForm.document_type" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm bg-white">
                <option v-for="type in documentTypes" :key="type.value" :value="type.value">{{ type.label }}</option>
              </select>
            </div>
          </div>
          <div>
            <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">วิธีเรนเดอร์</label>
            <select v-model="createForm.render_mode" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm bg-white">
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
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'

const router = useRouter()
const alertStore = useAlertStore()

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
const calibrations = ref([])
const reports = ref([])
const reportResult = ref(null)
const unboundLeaveTypes = ref([])

const tabs = [
  { value: 'templates', label: 'แบบฟอร์มเอกสาร' },
  { value: 'bindings', label: 'การผูกแบบฟอร์ม' },
  { value: 'fields', label: 'Field Dictionary' },
  { value: 'sources', label: 'แหล่งข้อมูล' },
  { value: 'reports', label: 'รายงาน' },
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

const runReport = async (report) => {
  try {
    const res = await api.get(`/admin/document-reports/${report.id}/run`)
    reportResult.value = res.data
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'ประมวลผลรายงานไม่สำเร็จ')
  }
}

const exportReport = async (report, format) => {
  try {
    const res = await api.get(`/admin/document-reports/${report.id}/export/${format}`, { responseType: 'blob' })
    const mime = format === 'pdf' ? 'application/pdf' : 'application/octet-stream'
    const url = URL.createObjectURL(new Blob([res.data], { type: mime }))

    if (format === 'pdf') {
      window.open(url, '_blank')
      return
    }

    const link = document.createElement('a')
    link.href = url
    link.download = `${report.code}.${format}`
    link.click()
    URL.revokeObjectURL(url)
  } catch (error) {
    alertStore.error('ส่งออกรายงานไม่สำเร็จ')
  }
}

const loadAll = async () => {
  loading.value = true
  try {
    const [ov, tpl, fld, src, bnd, gen, cal, rep] = await Promise.all([
      api.get('/admin/document-engine/overview'),
      api.get('/admin/document-engine/templates'),
      api.get('/admin/document-engine/fields'),
      api.get('/admin/document-engine/data-sources'),
      api.get('/admin/document-engine/bindings'),
      api.get('/admin/document-engine/generated'),
      api.get('/admin/document-engine/calibrations'),
      api.get('/admin/document-reports'),
    ])
    overview.value = ov.data
    templates.value = tpl.data
    fields.value = fld.data
    dataSources.value = src.data
    bindings.value = bnd.data.rules
    bindingEntities.value = bnd.data.entities
    unboundLeaveTypes.value = bnd.data.unbound_leave_types || []
    generated.value = gen.data.data || gen.data
    calibrations.value = cal.data
    reports.value = rep.data
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'โหลดข้อมูลศูนย์จัดการเอกสารไม่สำเร็จ')
  } finally {
    loading.value = false
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

const removeBinding = async (rule) => {
  if (!window.confirm('ลบกติกาการผูกแบบฟอร์มนี้หรือไม่?')) return
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
  } catch (error) {
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
