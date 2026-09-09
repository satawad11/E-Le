<template>
  <div>
    <!-- Header -->
    <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-6">
      <div>
        <button @click="$router.back()" class="text-sm text-gray-500 hover:text-gray-800 mb-1">← กลับไปศูนย์จัดการเอกสาร</button>
        <h1 class="text-2xl font-bold text-gray-900">{{ template?.name || 'ออกแบบแบบฟอร์ม' }}</h1>
        <p class="text-gray-500 text-sm mt-1">
          ลากตัวแปรจากรายการด้านซ้ายมาวางบนแบบฟอร์ม เลือกหลายฟิลด์ด้วย Shift แล้วจัดแนวได้ — ทุกอย่างบันทึกที่ "ฉบับร่าง" เท่านั้น
        </p>
      </div>

      <div class="flex items-center gap-2 flex-wrap">
        <span v-if="isDirty" class="px-3 py-1.5 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-xs font-semibold">
          ยังไม่ได้บันทึก
        </span>

        <select
          v-model="selectedVersionId"
          @change="loadVersion"
          class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm bg-white min-h-[44px]"
        >
          <option v-for="version in versions" :key="version.id" :value="version.id">
            เวอร์ชัน {{ version.version }} — {{ statusLabel(version.status) }}
          </option>
        </select>

        <button @click="createVersion" :disabled="saving" class="px-4 py-2.5 bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 font-semibold rounded-xl text-sm min-h-[44px] disabled:opacity-50">
          สร้างเวอร์ชันใหม่
        </button>

        <button v-if="isDraft" @click="triggerUpload" :disabled="saving" class="px-4 py-2.5 bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 font-semibold rounded-xl text-sm min-h-[44px] disabled:opacity-50">
          อัปโหลดไฟล์ต้นฉบับ
        </button>
        <input ref="fileInput" type="file" accept="application/pdf" class="hidden" @change="uploadPdf" />

        <div class="flex items-center gap-1">
          <select v-model="previewMode" aria-label="โหมดพรีวิว" class="px-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white min-h-[44px]">
            <option value="sample">พรีวิวด้วยข้อความตัวอย่าง</option>
            <option value="blank">พรีวิวเฉพาะกรอบช่อง</option>
            <option value="leave">พรีวิวจากใบลาจริง</option>
          </select>
          <input
            v-if="previewMode === 'leave'"
            v-model.number="previewLeaveId"
            type="number" min="1" placeholder="เลขที่ใบลาในระบบ" aria-label="เลขที่ใบลาที่ใช้พรีวิว"
            class="w-40 px-3 py-2.5 rounded-xl border border-gray-200 text-sm min-h-[44px]"
          />
          <button @click="preview" :disabled="!hasPdf" class="px-4 py-2.5 bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 font-semibold rounded-xl text-sm min-h-[44px] disabled:opacity-50">
            พรีวิว PDF
          </button>
        </div>

        <button v-if="isDraft" @click="save" :disabled="saving" class="px-5 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm shadow-md min-h-[44px] disabled:opacity-50">
          {{ saving ? 'กำลังบันทึก...' : 'บันทึกตำแหน่งฟิลด์' }}
        </button>

        <button v-if="isDraft" @click="publish" :disabled="saving" class="px-5 py-2.5 bg-green-700 hover:bg-green-800 text-white font-semibold rounded-xl text-sm shadow-md min-h-[44px] disabled:opacity-50">
          เผยแพร่เวอร์ชันนี้
        </button>

        <button
          v-if="isDraft && versions.length > 1"
          @click="removeDraft"
          :disabled="saving"
          class="px-4 py-2.5 bg-white border border-red-200 hover:bg-red-50 text-red-600 font-semibold rounded-xl text-sm min-h-[44px] disabled:opacity-50"
          title="ลบฉบับร่างนี้ทิ้ง"
        >
          ลบฉบับร่าง
        </button>

        <!--
          CLEANUP-02 — เลิกใช้เวอร์ชันที่เผยแพร่แล้ว
          endpoint `retire` มีมาตั้งแต่ DOC-ENGINE แต่ไม่มีปุ่มใดเรียก
          ผู้ดูแลจึงหยุดใช้แบบฟอร์มที่เผยแพร่ไปแล้วไม่ได้ นอกจากเผยแพร่ทับด้วยเวอร์ชันใหม่
        -->
        <button
          v-if="!isDraft && version?.status === 'ACTIVE'"
          :disabled="saving"
          class="px-4 py-2.5 bg-white border border-amber-200 hover:bg-amber-50 text-amber-700 font-semibold rounded-xl text-sm min-h-[44px] disabled:opacity-50"
          title="หยุดใช้เวอร์ชันนี้กับเอกสารที่ออกใหม่"
          @click="retire"
        >
          เลิกใช้เวอร์ชันนี้
        </button>
      </div>
    </div>

    <div v-if="!isDraft" class="mb-4 rounded-2xl bg-amber-50 border border-amber-200 p-4 text-sm text-amber-900">
      เวอร์ชันนี้เผยแพร่แล้ว จึงแก้ตำแหน่งฟิลด์ไม่ได้ — เอกสารที่ออกไปแล้วอ้างอิงเวอร์ชันนี้อยู่
      กด "สร้างเวอร์ชันใหม่" เพื่อคัดลอกไปแก้ต่อ
    </div>

    <div v-if="loading" class="flex justify-center py-16">
      <div class="animate-spin h-10 w-10 rounded-full border-4 border-[#800000] border-t-transparent"></div>
    </div>

    <div v-else class="grid grid-cols-1 xl:grid-cols-4 gap-6">
      <!-- รายการตัวแปร -->
      <aside class="xl:col-span-1 space-y-4">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
          <h3 class="font-bold text-gray-800 text-sm mb-2">ตัวแปรที่วางได้</h3>
          <input v-model="fieldSearch" type="text" placeholder="ค้นหาตัวแปร..." class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm mb-3" />

          <div class="max-h-[420px] overflow-y-auto space-y-3 pr-1">
            <div v-for="(groupFields, group) in groupedFields" :key="group">
              <p class="text-[11px] font-bold text-gray-400 uppercase mb-1">{{ group }}</p>
              <button
                v-for="field in groupFields"
                :key="field.id"
                @click="addField(field)"
                :disabled="!isDraft"
                class="w-full text-left px-3 py-2 rounded-xl border border-gray-100 hover:border-[#800000]/40 hover:bg-[#800000]/5 mb-1 disabled:opacity-40 disabled:cursor-not-allowed"
              >
                <span class="block text-xs font-semibold text-gray-800">{{ field.label_th }}</span>
                <span class="block font-mono text-[10px] text-gray-400">{{ field.code }}</span>
              </button>
            </div>
          </div>
        </div>

        <!-- เครื่องมือจัดแนวเมื่อเลือกหลายฟิลด์ -->
        <div v-if="selection.length > 1" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 space-y-3">
          <h3 class="font-bold text-gray-800 text-sm">เลือกอยู่ {{ selection.length }} ฟิลด์</h3>

          <div>
            <p class="text-[11px] font-bold text-gray-500 uppercase mb-1">จัดแนว</p>
            <div class="grid grid-cols-3 gap-1.5">
              <button v-for="tool in alignTools" :key="tool.mode" @click="align(tool.mode)" :disabled="!isDraft"
                class="px-2 py-2 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-40">
                {{ tool.label }}
              </button>
            </div>
          </div>

          <div>
            <p class="text-[11px] font-bold text-gray-500 uppercase mb-1">กระจายระยะเท่ากัน</p>
            <div class="grid grid-cols-2 gap-1.5">
              <button @click="distribute('horizontal')" :disabled="!isDraft || selection.length < 3"
                class="px-2 py-2 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-40">แนวนอน</button>
              <button @click="distribute('vertical')" :disabled="!isDraft || selection.length < 3"
                class="px-2 py-2 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-40">แนวตั้ง</button>
            </div>
          </div>

          <button @click="removeSelected" :disabled="!isDraft" class="w-full px-3 py-2 rounded-lg border border-red-200 text-red-600 text-xs font-semibold hover:bg-red-50 disabled:opacity-40">
            ลบฟิลด์ที่เลือกทั้งหมด
          </button>
        </div>

        <!-- คุณสมบัติของฟิลด์ที่เลือก -->
        <div v-if="selected" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 space-y-3">
          <div class="flex items-center justify-between">
            <h3 class="font-bold text-gray-800 text-sm">คุณสมบัติฟิลด์</h3>
            <button @click="removeSelected" :disabled="!isDraft" class="text-xs font-semibold text-red-600 hover:underline disabled:opacity-40">ลบฟิลด์</button>
          </div>
          <p class="font-mono text-[11px] text-gray-400 break-all">{{ selected.field_code }}</p>

          <div class="grid grid-cols-2 gap-2">
            <label class="block">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">แนวนอน (pt)</span>
              <input v-model.number="selected.x" @focus="beginChange" @change="commit" type="number" step="1" :disabled="!isDraft" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm" />
            </label>
            <label class="block">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">แนวตั้ง (pt)</span>
              <input v-model.number="selected.y" @focus="beginChange" @change="commit" type="number" step="1" :disabled="!isDraft" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm" />
            </label>
            <label class="block">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">กว้าง</span>
              <input v-model.number="selected.width" @focus="beginChange" @change="commit" type="number" step="1" :disabled="!isDraft" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm" />
            </label>
            <label class="block">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">สูง</span>
              <input v-model.number="selected.height" @focus="beginChange" @change="commit" type="number" step="1" :disabled="!isDraft" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm" />
            </label>
            <label class="block">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">ขนาดอักษร</span>
              <input v-model.number="selected.font_size" @focus="beginChange" @change="commit" type="number" step="0.5" :disabled="!isDraft" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm" />
            </label>
            <label class="block">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">จัดวาง</span>
              <select v-model="selected.align" @focus="beginChange" @change="commit" :disabled="!isDraft" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm bg-white">
                <option value="left">ชิดซ้าย</option>
                <option value="center">กึ่งกลาง</option>
                <option value="right">ชิดขวา</option>
              </select>
            </label>
            <label class="block col-span-2">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">ชนิดฟิลด์</span>
              <select v-model="selected.field_type" @focus="beginChange" @change="commit" :disabled="!isDraft" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm bg-white">
                <option v-for="type in fieldTypes" :key="type" :value="type">{{ type }}</option>
              </select>
            </label>
            <label class="block col-span-2">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">หน้า</span>
              <input v-model.number="selected.page" @focus="beginChange" @change="commit" type="number" min="1" :max="pageCount" :disabled="!isDraft" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm" />
            </label>

            <label class="block">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">น้ำหนักอักษร</span>
              <select v-model="selected.font" @focus="beginChange" @change="commit" :disabled="!isDraft" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm bg-white">
                <option :value="null">ปกติ</option>
                <option value="THSarabunNewB">ตัวหนา</option>
              </select>
            </label>

            <label class="block">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">ข้อความยาวเกินช่อง</span>
              <select v-model="selected.overflow" @focus="beginChange" @change="commit" :disabled="!isDraft" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm bg-white">
                <option value="shrink">ย่อขนาดให้พอดี</option>
                <option value="wrap">ตัดขึ้นบรรทัดใหม่</option>
                <option value="clip">ตัดส่วนที่เกินทิ้ง</option>
              </select>
            </label>

            <label class="block col-span-2">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">รูปแบบค่า</span>
              <select v-model="selected.format" @focus="beginChange" @change="commit" :disabled="!isDraft" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm bg-white">
                <option :value="null">ตามค่าที่ได้รับ</option>
                <option v-for="(label, code) in formats" :key="code" :value="code">{{ label }}</option>
              </select>
            </label>

            <label class="block col-span-2">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">ข้อความคงที่ (ใช้แทนค่าจากข้อมูล)</span>
              <input v-model="selected.static_text" @focus="beginChange" @change="commit" type="text" maxlength="500" :disabled="!isDraft" placeholder="เว้นว่างไว้เพื่อใช้ค่าจากข้อมูล" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm" />
            </label>

            <label class="flex items-center gap-2 col-span-2 text-sm text-gray-700">
              <input type="checkbox" :checked="optionOf('underline')" @change="setOption('underline', $event.target.checked)" :disabled="!isDraft" class="rounded" />
              ขีดเส้นใต้
            </label>

            <label class="block">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">ระยะห่างตัวอักษร (pt)</span>
              <input
                type="number" min="0" max="20" step="0.5" :disabled="!isDraft"
                :value="optionOf('letter_spacing') ?? 0"
                @change="setOption('letter_spacing', Number($event.target.value) || null)"
                title="เหมาะกับช่องตัวเลขหรือภาษาอังกฤษที่แบ่งเป็นกล่องทีละตัว — ข้อความไทยที่มีสระบนล่างจะถูกถ่างตามไปด้วย"
                class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm"
              />
            </label>

            <label class="block">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">หมุนข้อความ</span>
              <select :value="optionOf('rotate') ?? 0" @change="setOption('rotate', Number($event.target.value) || null)" :disabled="!isDraft" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm bg-white">
                <option :value="0">ไม่หมุน</option>
                <option :value="90">90°</option>
                <option :value="180">180°</option>
                <option :value="270">270°</option>
              </select>
            </label>

            <template v-if="selected.field_type === 'CHECKBOX'">
              <label class="block">
                <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">เครื่องหมายที่ใช้ติ๊ก</span>
                <input
                  type="text" maxlength="4" :disabled="!isDraft"
                  :value="optionOf('mark') ?? ''"
                  @change="setOption('mark', $event.target.value || null)"
                  placeholder="/"
                  class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm"
                />
              </label>
              <label class="block">
                <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">ติ๊กเมื่อค่าตรงกับ</span>
                <input
                  type="text" :disabled="!isDraft"
                  :value="(optionOf('checked_values') || []).join(', ')"
                  @change="setOption('checked_values', splitValues($event.target.value))"
                  placeholder="เว้นว่าง = ติ๊กเมื่อค่าเป็นจริง"
                  title="คั่นหลายค่าด้วยเครื่องหมายจุลภาค"
                  class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm"
                />
              </label>
            </template>
          </div>

          <div class="pt-2 border-t border-gray-100 space-y-2">
            <span class="block text-[11px] font-bold text-gray-500 uppercase">พิมพ์ฟิลด์นี้เมื่อ</span>
            <select :value="condition.field" @change="setCondition('field', $event.target.value)" :disabled="!isDraft" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm bg-white">
              <option value="">พิมพ์เสมอ</option>
              <option v-for="field in fields" :key="field.id" :value="field.code">{{ field.label_th }}</option>
            </select>
            <div v-if="condition.field" class="grid grid-cols-2 gap-2">
              <select :value="condition.operator" @change="setCondition('operator', $event.target.value)" :disabled="!isDraft" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm bg-white">
                <option value="equals">มีค่าเท่ากับ</option>
                <option value="not_equals">มีค่าไม่เท่ากับ</option>
                <option value="filled">มีค่า</option>
                <option value="empty">ไม่มีค่า</option>
              </select>
              <input
                v-if="['equals', 'not_equals'].includes(condition.operator)"
                type="text" :value="condition.value" @change="setCondition('value', $event.target.value)"
                :disabled="!isDraft" placeholder="ค่าที่ต้องตรงกัน"
                class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm"
              />
            </div>
          </div>

          <div v-if="pageCount > 1 && isDraft" class="pt-2 border-t border-gray-100">
            <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">ทำซ้ำฟิลด์ที่เลือกไปหน้า</span>
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="page in pageCount"
                :key="page"
                @click="duplicateToPage(page)"
                :disabled="page === currentPage"
                class="w-9 h-9 rounded-lg border border-gray-200 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-30"
              >{{ page }}</button>
            </div>
          </div>
        </div>

        <!-- เครื่องมือก่อนเผยแพร่ (DESIGNER-04) -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 space-y-3">
          <h3 class="font-bold text-gray-800 text-sm">ตรวจก่อนเผยแพร่</h3>

          <button @click="runPreflight" :disabled="checking"
            class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50 min-h-[44px]">
            {{ checking ? 'กำลังตรวจ...' : 'ตรวจแบบฟอร์มนี้' }}
          </button>

          <div v-if="preflight" class="space-y-2 text-xs">
            <div v-if="preflight.errors.length" class="rounded-xl bg-red-50 border border-red-200 p-3">
              <p class="font-bold text-red-800 mb-1">ข้อผิดพลาด {{ preflight.errors.length }} ข้อ (เผยแพร่ไม่ได้)</p>
              <ul class="list-disc list-inside space-y-0.5 text-red-700">
                <li v-for="(message, index) in preflight.errors" :key="'e' + index">{{ message }}</li>
              </ul>
            </div>
            <div v-if="preflight.warnings.length" class="rounded-xl bg-amber-50 border border-amber-200 p-3">
              <p class="font-bold text-amber-900 mb-1">ข้อควรระวัง {{ preflight.warnings.length }} ข้อ</p>
              <ul class="list-disc list-inside space-y-0.5 text-amber-800">
                <li v-for="(message, index) in preflight.warnings" :key="'w' + index">{{ message }}</li>
              </ul>
            </div>
            <p v-if="!preflight.errors.length && !preflight.warnings.length" class="text-green-700 font-semibold">ไม่พบปัญหา</p>
          </div>

          <div class="pt-2 border-t border-gray-100 space-y-2">
            <span class="block text-[11px] font-bold text-gray-500 uppercase">เทียบกับเวอร์ชัน</span>
            <select v-model="diffAgainstId" @change="loadDiff" aria-label="เวอร์ชันที่จะนำมาเทียบ"
              class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm bg-white">
              <option :value="null">ไม่เทียบ</option>
              <option v-for="other in versions.filter(v => v.id !== version?.id)" :key="other.id" :value="other.id">
                เวอร์ชัน {{ other.version }} — {{ statusLabel(other.status) }}
              </option>
            </select>

            <div v-if="diff" class="text-xs text-gray-600 space-y-1">
              <p><span class="font-semibold text-green-700">เพิ่ม {{ diff.added.length }}</span> ·
                <span class="font-semibold text-red-700">ลบ {{ diff.removed.length }}</span> ·
                <span class="font-semibold text-amber-700">แก้ {{ diff.changed.length }}</span></p>
              <ul class="list-disc list-inside text-[11px] text-gray-500 max-h-32 overflow-y-auto">
                <li v-for="code in diff.added" :key="'a' + code">เพิ่ม {{ code }}</li>
                <li v-for="code in diff.removed" :key="'r' + code">ลบ {{ code }}</li>
                <li v-for="row in diff.changed" :key="'c' + row.field_code + row.page">
                  แก้ {{ row.field_code }} (หน้า {{ row.page }}): {{ Object.keys(row.changes).join(', ') }}
                </li>
              </ul>
            </div>
          </div>

          <div class="pt-2 border-t border-gray-100 space-y-2">
            <span class="block text-[11px] font-bold text-gray-500 uppercase">สอบเทียบเครื่องพิมพ์</span>
            <button @click="openCalibrationSheet" :disabled="!hasPdf"
              class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-40">
              พิมพ์ใบสอบเทียบ (ขนาดจริง 100%)
            </button>

            <div class="grid grid-cols-2 gap-2">
              <label class="block">
                <span class="block text-[10px] text-gray-500 mb-1">ขอบซ้ายถึง A (มม.)</span>
                <input v-model.number="measurement.measured_a_x_mm" type="number" step="0.5" class="w-full px-2 py-1.5 rounded-lg border border-gray-200 text-xs" />
              </label>
              <label class="block">
                <span class="block text-[10px] text-gray-500 mb-1">ขอบบนถึง A (มม.)</span>
                <input v-model.number="measurement.measured_a_y_mm" type="number" step="0.5" class="w-full px-2 py-1.5 rounded-lg border border-gray-200 text-xs" />
              </label>
              <label class="block">
                <span class="block text-[10px] text-gray-500 mb-1">A ถึง B (มม.)</span>
                <input v-model.number="measurement.measured_ab_mm" type="number" step="0.5" class="w-full px-2 py-1.5 rounded-lg border border-gray-200 text-xs" />
              </label>
              <label class="block">
                <span class="block text-[10px] text-gray-500 mb-1">A ถึง C (มม.)</span>
                <input v-model.number="measurement.measured_ac_mm" type="number" step="0.5" class="w-full px-2 py-1.5 rounded-lg border border-gray-200 text-xs" />
              </label>
            </div>

            <button @click="solveCalibration" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-50">
              คำนวณค่าชดเชย
            </button>

            <div v-if="calibration" class="text-xs text-gray-600 space-y-1">
              <p>เลื่อนแนวนอน {{ calibration.x_offset }} pt · แนวตั้ง {{ calibration.y_offset }} pt · สเกล {{ calibration.scale }}</p>
              <button @click="saveCalibration" class="w-full px-3 py-2 rounded-xl bg-[#800000] hover:bg-[#600000] text-white text-xs font-semibold">
                ใช้ค่านี้กับแม่แบบนี้
              </button>
            </div>
          </div>

          <div class="pt-2 border-t border-gray-100">
            <button @click="exportPackage" :disabled="!hasPdf"
              class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-40"
              title="ได้ไฟล์เดียวที่มีทั้งแบบฟอร์มต้นฉบับและตำแหน่งฟิลด์ นำไปติดตั้งที่เครื่องอื่นได้">
              ส่งออกชุดแบบฟอร์ม (.zip)
            </button>
          </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 text-[11px] text-gray-500 leading-relaxed">
          <p class="font-bold text-gray-700 text-xs mb-1">ปุ่มลัด</p>
          ลูกศร = ขยับ 1 pt · Shift+ลูกศร = 10 pt · Delete = ลบ ·
          Ctrl+C / Ctrl+V = คัดลอก/วาง · Ctrl+D = ทำซ้ำ · Ctrl+Z / Ctrl+Y = เลิกทำ/ทำซ้ำ · Shift+คลิก = เลือกหลายฟิลด์
        </div>
      </aside>

      <!-- พื้นที่ออกแบบ -->
      <section class="xl:col-span-3 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-2 text-sm">
            <span class="text-gray-500">หน้า</span>
            <button
              v-for="page in pageCount"
              :key="page"
              @click="currentPage = page"
              class="w-9 h-9 rounded-lg text-sm font-semibold"
              :class="currentPage === page ? 'bg-[#800000] text-white' : 'bg-white border border-gray-200 text-gray-600'"
            >{{ page }}</button>

            <span class="ml-2 text-xs text-gray-400">
              {{ Math.round(pageBox.width) }}×{{ Math.round(pageBox.height) }} pt
            </span>
          </div>

          <div class="flex items-center gap-2 text-sm">
            <button @click="undo" :disabled="!canUndo" class="px-3 h-9 rounded-lg bg-white border border-gray-200 text-xs font-semibold text-gray-700 disabled:opacity-40" title="เลิกทำ (Ctrl+Z)">เลิกทำ</button>
            <button @click="redo" :disabled="!canRedo" class="px-3 h-9 rounded-lg bg-white border border-gray-200 text-xs font-semibold text-gray-700 disabled:opacity-40" title="ทำซ้ำ (Ctrl+Y)">ทำซ้ำ</button>

            <span class="text-gray-500 ml-2">ย่อ/ขยาย</span>
            <button @click="zoom = Math.max(0.5, Math.round((zoom - 0.1) * 10) / 10)" class="w-9 h-9 rounded-lg bg-white border border-gray-200">−</button>
            <span class="w-14 text-center">{{ Math.round(zoom * 100) }}%</span>
            <button @click="zoom = Math.min(2, Math.round((zoom + 0.1) * 10) / 10)" class="w-9 h-9 rounded-lg bg-white border border-gray-200">+</button>
          </div>
        </div>

        <div class="flex flex-wrap items-center gap-4 text-xs text-gray-600">
          <label class="flex items-center gap-1.5">
            <input v-model="showBackground" type="checkbox" class="rounded" />
            แสดงแบบฟอร์มต้นฉบับ
          </label>
          <label class="flex items-center gap-1.5">
            <input v-model="showGrid" type="checkbox" class="rounded" />
            แสดงเส้นตาราง
          </label>
          <label class="flex items-center gap-1.5">
            <input v-model="snapEnabled" type="checkbox" class="rounded" />
            ดูดเข้าเส้นตาราง
          </label>
          <label class="flex items-center gap-1.5">
            ระยะตาราง (pt)
            <input v-model.number="gridSize" type="number" min="1" max="50" step="1" class="w-16 px-2 py-1 rounded-lg border border-gray-200" />
          </label>
        </div>

        <div v-if="!hasPdf" class="bg-white rounded-2xl border border-dashed border-gray-300 p-12 text-center text-gray-500">
          เวอร์ชันนี้ยังไม่มีไฟล์แบบฟอร์มต้นฉบับ — กด "อัปโหลดไฟล์ต้นฉบับ" เพื่อเริ่มวางฟิลด์
        </div>

        <!--
          กระดาษ: วาดหน้า PDF จริงลง <canvas> ด้วย pdf.js
          ระบบพิกัดของพื้นที่วางฟิลด์จึงเป็นขนาดหน้าจริงของไฟล์ ไม่ใช่ A4 ที่เดาไว้ล่วงหน้า
        -->
        <div v-else class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 overflow-auto">
          <div class="inline-block">
            <!-- ไม้บรรทัดแนวนอน -->
            <div class="flex">
              <div class="w-6 h-6 shrink-0"></div>
              <div class="relative h-6 border-b border-gray-200" :style="{ width: pageBox.width * zoom + 'px' }">
                <div v-for="tick in horizontalTicks" :key="'h' + tick" class="absolute top-0 h-full border-l border-gray-200"
                  :style="{ left: tick * zoom + 'px' }">
                  <span class="absolute top-0 left-0.5 text-[9px] text-gray-400">{{ tick }}</span>
                </div>
              </div>
            </div>

            <div class="flex">
              <!-- ไม้บรรทัดแนวตั้ง -->
              <div class="relative w-6 border-r border-gray-200 shrink-0" :style="{ height: pageBox.height * zoom + 'px' }">
                <div v-for="tick in verticalTicks" :key="'v' + tick" class="absolute left-0 w-full border-t border-gray-200"
                  :style="{ top: tick * zoom + 'px' }">
                  <span class="absolute top-0.5 left-0.5 text-[9px] text-gray-400">{{ tick }}</span>
                </div>
              </div>

              <div
                ref="canvasArea"
                class="relative border border-gray-200 bg-white"
                :style="{ width: pageBox.width * zoom + 'px', height: pageBox.height * zoom + 'px' }"
                @mousedown.self="clearSelection"
              >
                <canvas ref="pdfCanvas" class="absolute inset-0 pointer-events-none" :class="{ 'opacity-0': !showBackground }"></canvas>

                <div v-if="showGrid" class="absolute inset-0 pointer-events-none" :style="gridStyle"></div>

                <div
                  v-for="mapping in pageMappings"
                  :key="mapping._key"
                  class="absolute border-2 rounded cursor-move flex items-center px-1 overflow-hidden select-none"
                  :class="isSelected(mapping)
                    ? 'border-[#800000] bg-[#800000]/15'
                    : 'border-blue-500/70 bg-blue-500/10 hover:bg-blue-500/20'"
                  :style="boxStyle(mapping)"
                  @mousedown.stop="startDrag($event, mapping)"
                >
                  <span class="text-[10px] font-semibold text-gray-800 truncate pointer-events-none">
                    {{ labelFor(mapping.field_code) }}
                  </span>
                  <span
                    v-if="isDraft"
                    class="absolute bottom-0 right-0 w-3 h-3 bg-[#800000] cursor-se-resize"
                    @mousedown.stop="startResize($event, mapping)"
                  ></span>
                </div>
              </div>
            </div>
          </div>

          <p class="text-xs text-gray-400 mt-3 text-center">
            พิกัดเป็นหน่วย PDF point วัดจากมุมซ้ายบนของหน้า — ค่าเดียวกับที่ระบบใช้ตอนพิมพ์จริง
          </p>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import { useRoute, onBeforeRouteLeave } from 'vue-router'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import {
  snapValue, clampBox, clampSize, alignBoxes, distributeBoxes, nudgeBox, pageSize,
} from '../../utils/designerGeometry'

const route = useRoute()
const alertStore = useAlertStore()

const loading = ref(false)
const saving = ref(false)
const template = ref(null)
const versions = ref([])
const selectedVersionId = ref(null)
const version = ref(null)
const mappings = ref([])
const fields = ref([])
const selection = ref([])
const currentPage = ref(1)
const zoom = ref(1)
const showBackground = ref(true)
const showGrid = ref(false)
const snapEnabled = ref(true)
const gridSize = ref(5)
const fieldSearch = ref('')
const fileInput = ref(null)
const canvasArea = ref(null)
const pdfCanvas = ref(null)

/*
 * ขนาดหน้าจริงของไฟล์ต้นฉบับ (PDF point)
 *
 * เดิมค่านี้ถูกตรึงไว้ที่ 595×842 ทำให้แบบฟอร์มขนาดอื่นวางบนจอกับพิมพ์จริงไม่ตรงกัน
 * ตอนนี้อ่านจากไฟล์ด้วย pdf.js และเทียบกับที่ backend คำนวณจากไฟล์เดียวกัน
 */
const pages = ref([])

/*
 * ชนิดฟิลด์และรูปแบบค่ามาจากฝั่งเซิร์ฟเวอร์
 *
 * เดิมหน้าจอมีรายการของตัวเอง จึงให้เลือก BARCODE ได้ทั้งที่ตัวเรนเดอร์วาดไม่ได้
 * และพิมพ์ออกมาเป็นข้อความธรรมดาโดยไม่มีคำเตือน
 */
const fieldTypes = ref(['TEXT'])
const formats = ref({})

// เครื่องมือก่อนเผยแพร่ (DESIGNER-04)
const previewMode = ref('sample')
const previewLeaveId = ref(null)
const preflight = ref(null)
const checking = ref(false)
const diffAgainstId = ref(null)
const diff = ref(null)
const measurement = ref({ measured_a_x_mm: 25.4, measured_a_y_mm: 25.4, measured_ab_mm: 127, measured_ac_mm: 127 })
const calibration = ref(null)

const alignTools = [
  { mode: 'left', label: 'ชิดซ้าย' },
  { mode: 'center', label: 'กึ่งกลางแนวตั้ง' },
  { mode: 'right', label: 'ชิดขวา' },
  { mode: 'top', label: 'ชิดบน' },
  { mode: 'middle', label: 'กึ่งกลางแนวนอน' },
  { mode: 'bottom', label: 'ชิดล่าง' },
]

const isDraft = computed(() => version.value?.status === 'DRAFT')
const hasPdf = computed(() => !!version.value?.pdf_file_path)
const pageCount = computed(() => pages.value.length || version.value?.page_count || 1)
const pageBox = computed(() => pageSize(pages.value, currentPage.value))
const pageMappings = computed(() => mappings.value.filter(m => Number(m.page) === currentPage.value))
const selected = computed(() => (selection.value.length === 1 ? selection.value[0] : null))

const groupedFields = computed(() => {
  const query = fieldSearch.value.trim().toLowerCase()
  const groups = {}
  for (const field of fields.value) {
    if (query && !field.code.toLowerCase().includes(query) && !field.label_th.toLowerCase().includes(query)) continue
    const key = field.group_th || 'อื่น ๆ'
    groups[key] = groups[key] || []
    groups[key].push(field)
  }
  return groups
})

const tickStep = computed(() => (zoom.value >= 1.5 ? 25 : 50))

const ticksFor = (length) => {
  const result = []
  for (let value = 0; value <= length; value += tickStep.value) result.push(value)
  return result
}

const horizontalTicks = computed(() => ticksFor(pageBox.value.width))
const verticalTicks = computed(() => ticksFor(pageBox.value.height))

const gridStyle = computed(() => {
  const step = Math.max(1, gridSize.value) * zoom.value
  return {
    backgroundImage:
      'linear-gradient(to right, rgba(128,0,0,0.12) 1px, transparent 1px),' +
      'linear-gradient(to bottom, rgba(128,0,0,0.12) 1px, transparent 1px)',
    backgroundSize: `${step}px ${step}px`,
  }
})

const statusLabel = (status) => ({ DRAFT: 'ฉบับร่าง', ACTIVE: 'ใช้งานอยู่', RETIRED: 'เลิกใช้แล้ว', ARCHIVED: 'เก็บถาวร' })[status] || status
const labelFor = (code) => fields.value.find(f => f.code === code)?.label_th || code
const isSelected = (mapping) => selection.value.includes(mapping)

const boxStyle = (mapping) => ({
  left: mapping.x * zoom.value + 'px',
  top: mapping.y * zoom.value + 'px',
  width: mapping.width * zoom.value + 'px',
  height: mapping.height * zoom.value + 'px',
})

// ───────────────────────── ประวัติการแก้ไข (undo/redo) ─────────────────────────

const serialize = (rows) => JSON.stringify(rows.map(({ _key, ...rest }) => rest))

const history = ref([])
const future = ref([])
const savedSnapshot = ref('[]')

const canUndo = computed(() => history.value.length > 0)
const canRedo = computed(() => future.value.length > 0)
const isDirty = computed(() => serialize(mappings.value) !== savedSnapshot.value)

/** บันทึกสถานะ "ก่อนแก้" ลงประวัติ — เรียกก่อนทุกการกระทำที่เปลี่ยนตำแหน่งฟิลด์ */
let pending = null
const beginChange = () => { pending = clone(mappings.value) }

const commit = () => {
  const before = pending ?? clone(mappings.value)
  pending = null

  if (JSON.stringify(before) === JSON.stringify(mappings.value)) return

  history.value.push(before)
  if (history.value.length > 50) history.value.shift()
  future.value = []
}

const clone = (rows) => rows.map(row => ({ ...row }))

const restore = (rows) => {
  mappings.value = clone(rows)
  selection.value = []
}

const undo = () => {
  if (!canUndo.value) return
  future.value.push(clone(mappings.value))
  restore(history.value.pop())
}

const redo = () => {
  if (!canRedo.value) return
  history.value.push(clone(mappings.value))
  restore(future.value.pop())
}

// ───────────────────────────── โหลดข้อมูล ─────────────────────────────

const loadTemplate = async () => {
  loading.value = true
  try {
    const [tpl, fld] = await Promise.all([
      api.get(`/admin/document-engine/templates/${route.params.templateId}`),
      api.get('/admin/document-engine/fields'),
    ])
    template.value = tpl.data.template
    versions.value = tpl.data.versions
    fields.value = fld.data

    // เปิดฉบับร่างล่าสุดให้ก่อน เพราะเป็นเวอร์ชันเดียวที่แก้ได้
    const draft = versions.value.find(v => v.status === 'DRAFT')
    selectedVersionId.value = draft?.id || versions.value[0]?.id
    await loadVersion()
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'โหลดแม่แบบไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

const loadVersion = async () => {
  if (!selectedVersionId.value) return
  try {
    const res = await api.get(`/admin/document-engine/versions/${selectedVersionId.value}/mappings`)
    version.value = res.data.version
    fieldTypes.value = res.data.field_types || fieldTypes.value
    formats.value = res.data.formats || {}
    mappings.value = res.data.mappings.map((m, index) => ({
      ...m,
      _key: `${m.id}-${index}`,
      x: Number(m.x), y: Number(m.y), width: Number(m.width), height: Number(m.height),
      font_size: Number(m.font_size),
    }))
    savedSnapshot.value = serialize(mappings.value)
    history.value = []
    future.value = []
    selection.value = []
    currentPage.value = 1
    pages.value = []
    await loadGeometry()
    await loadPdf()
  } catch {
    alertStore.error('โหลดตำแหน่งฟิลด์ไม่สำเร็จ')
  }
}

/** ขนาดหน้าที่ backend อ่านจากไฟล์เดียวกัน — ใช้ได้แม้เบราว์เซอร์ยังเรนเดอร์ PDF ไม่เสร็จ */
const loadGeometry = async () => {
  if (!version.value?.pdf_file_path) return
  try {
    const res = await api.get(`/admin/document-engine/versions/${version.value.id}/geometry`)
    pages.value = res.data.pages.map(p => ({ width: Number(p.width), height: Number(p.height) }))
  } catch {
    pages.value = []
  }
}

// ───────────────────────────── การวาดหน้า PDF ─────────────────────────────

let pdfDocument = null
let renderTask = null
let pdfObjectUrl = ''

const releasePdf = () => {
  renderTask?.cancel()
  renderTask = null
  pdfDocument?.destroy()
  pdfDocument = null
  if (pdfObjectUrl) {
    URL.revokeObjectURL(pdfObjectUrl)
    pdfObjectUrl = ''
  }
}

const loadPdf = async () => {
  releasePdf()
  if (!version.value?.pdf_file_path) return

  try {
    const res = await api.get(`/admin/document-engine/versions/${version.value.id}/pdf`, { responseType: 'blob' })
    pdfObjectUrl = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))

    const pdfjs = await import('pdfjs-dist')
    pdfjs.GlobalWorkerOptions.workerSrc = (
      await import('pdfjs-dist/build/pdf.worker.min.mjs?url')
    ).default

    // pdf.js 6 รับเฉพาะรูปแบบพารามิเตอร์ (`{url}` / `{data}`) การส่ง URL เปล่าจะโยน error ทันที
    pdfDocument = await pdfjs.getDocument({ url: pdfObjectUrl }).promise

    // ขนาดจริงรายหน้าจากไฟล์ — เป็นระบบพิกัดของพื้นที่วางฟิลด์
    const sizes = []
    for (let number = 1; number <= pdfDocument.numPages; number++) {
      const viewport = (await pdfDocument.getPage(number)).getViewport({ scale: 1 })
      sizes.push({ width: viewport.width, height: viewport.height })
    }
    pages.value = sizes

    await renderPage()
  } catch {
    // วางฟิลด์ต่อได้แม้แสดงพื้นหลังไม่ขึ้น — ขนาดหน้ายังใช้ค่าที่ backend อ่านไว้
    showBackground.value = false
  }
}

const renderPage = async () => {
  if (!pdfDocument || !pdfCanvas.value) return

  renderTask?.cancel()
  renderTask = null

  const pdfPage = await pdfDocument.getPage(Math.min(currentPage.value, pdfDocument.numPages))
  const viewport = pdfPage.getViewport({ scale: zoom.value })
  const context = pdfCanvas.value.getContext('2d')

  pdfCanvas.value.width = viewport.width
  pdfCanvas.value.height = viewport.height

  renderTask = pdfPage.render({ canvasContext: context, viewport })

  try {
    await renderTask.promise
  } catch {
    // ยกเลิกการวาดเมื่อเปลี่ยนหน้า/ซูมระหว่างทาง — ไม่ใช่ข้อผิดพลาด
  } finally {
    renderTask = null
  }
}

/*
 * วาดหน้า PDF ใหม่เมื่อเปลี่ยนหน้า/ซูม และ **เมื่อพื้นที่วาดเพิ่งปรากฏบนหน้าจอ**
 *
 * `loading` ยังเป็น true ตลอดช่วงโหลดครั้งแรก แคนวาสจึงยังไม่อยู่ใน DOM ตอนที่
 * `loadPdf()` ทำงานเสร็จ ถ้าเฝ้าเฉพาะหน้า/ซูม ภาพแบบฟอร์มจะไม่ขึ้นเลยจนกว่าผู้ใช้จะกดซูม
 * (เจอตอนซ้อมเปิดใช้งานจริง — ผู้ดูแลเห็นแต่กล่องฟิลด์ลอยบนพื้นขาว)
 */
watch([currentPage, zoom, loading, hasPdf], async () => {
  await nextTick()
  await renderPage()
})

// ───────────────────────────── การเลือกและแก้ไขฟิลด์ ─────────────────────────────

const clearSelection = () => { selection.value = [] }

const select = (mapping, additive) => {
  if (!additive) {
    selection.value = [mapping]
    return
  }
  selection.value = selection.value.includes(mapping)
    ? selection.value.filter(m => m !== mapping)
    : [...selection.value, mapping]
}

const addField = (field) => {
  if (!isDraft.value) return
  beginChange()

  const mapping = clampBox({
    _key: `new-${Date.now()}-${Math.random()}`,
    field_code: field.code,
    // ตัวแปรที่นิยามชนิดไว้แต่ตัวเรนเดอร์ยังวาดไม่ได้ ให้เริ่มเป็นข้อความธรรมดาแทนการวางชนิดที่พิมพ์ไม่ออก
    field_type: fieldTypes.value.includes(field.data_type) ? field.data_type : 'TEXT',
    page: currentPage.value,
    x: 60, y: 60, width: 200, height: 20,
    font: null, font_size: 14, align: 'left', overflow: 'shrink',
    format: null, static_text: null, options: null,
  }, pageBox.value)

  mappings.value.push(mapping)
  selection.value = [mapping]
  commit()
}

const removeSelected = () => {
  if (!isDraft.value || selection.value.length === 0) return
  beginChange()
  mappings.value = mappings.value.filter(m => !selection.value.includes(m))
  selection.value = []
  commit()
}

const align = (mode) => {
  if (!isDraft.value || selection.value.length < 2) return
  beginChange()
  applyToSelection(alignBoxes(selection.value.map(box => ({ ...box })), mode))
  commit()
}

const distribute = (axis) => {
  if (!isDraft.value || selection.value.length < 3) return
  beginChange()
  applyToSelection(distributeBoxes(selection.value.map(box => ({ ...box })), axis))
  commit()
}

/** เขียนค่าที่คำนวณแล้วกลับเข้าฟิลด์ที่เลือก โดยไม่ให้หลุดออกนอกหน้ากระดาษ */
const applyToSelection = (updated) => {
  selection.value.forEach((mapping, index) => {
    const box = clampBox(updated[index], sizeOf(mapping))
    mapping.x = round(box.x)
    mapping.y = round(box.y)
  })
}

const duplicateToPage = (targetPage) => {
  if (!isDraft.value || selection.value.length === 0 || targetPage === currentPage.value) return
  beginChange()

  const copies = selection.value.map(mapping => clampBox({
    ...mapping,
    _key: `copy-${Date.now()}-${Math.random()}`,
    id: undefined,
    page: targetPage,
  }, pageSize(pages.value, targetPage)))

  mappings.value.push(...copies)
  commit()
  alertStore.success(`ทำซ้ำ ${copies.length} ฟิลด์ไปหน้า ${targetPage} แล้ว`)
}

/*
 * ตัวเลือกเฉพาะชนิดฟิลด์ (`options`)
 *
 * เก็บเฉพาะคีย์ที่มีค่าจริง และลบทั้งก้อนเมื่อไม่เหลืออะไร เพื่อไม่ให้ฐานข้อมูลสะสม
 * ตัวเลือกเปล่าที่ไม่มีความหมาย และให้ค่าที่ส่งไปตรงกับ whitelist ของฝั่งเซิร์ฟเวอร์
 */
const optionOf = (key) => selected.value?.options?.[key] ?? null

const setOption = (key, value) => {
  if (!selected.value || !isDraft.value) return
  beginChange()

  const options = { ...(selected.value.options || {}) }

  if (value === null || value === '' || value === false || (Array.isArray(value) && value.length === 0)) {
    delete options[key]
  } else {
    options[key] = value
  }

  selected.value.options = Object.keys(options).length ? options : null
  commit()
}

const splitValues = (text) => String(text || '').split(',').map(value => value.trim()).filter(Boolean)

/** เงื่อนไขแสดง/ซ่อนฟิลด์ — เก็บไว้ใน `options.visible_when` ชุดเดียวกับที่ตัวเรนเดอร์อ่าน */
const condition = computed(() => ({
  field: optionOf('visible_when')?.field || '',
  operator: optionOf('visible_when')?.operator || 'equals',
  value: optionOf('visible_when')?.value || '',
}))

const setCondition = (key, value) => {
  const next = { ...condition.value, [key]: value }

  if (!next.field) {
    setOption('visible_when', null)
    return
  }

  // ตัวดำเนินการที่ไม่ต้องใช้ค่าเทียบ ต้องไม่ทิ้งค่าเก่าค้างไว้ให้เข้าใจผิด
  if (['filled', 'empty'].includes(next.operator)) {
    delete next.value
  }

  setOption('visible_when', next)
}

const sizeOf = (mapping) => pageSize(pages.value, Number(mapping.page) || 1)
const round = (value) => Math.round(value * 100) / 100

// ── ลาก/ปรับขนาดกล่องฟิลด์ ──
let dragState = null

const startDrag = (event, mapping) => {
  select(mapping, event.shiftKey || event.ctrlKey || event.metaKey)
  if (!isDraft.value) return

  beginChange()
  dragState = {
    mode: 'move',
    startX: event.clientX,
    startY: event.clientY,
    boxes: selection.value.map(box => ({ box, originX: box.x, originY: box.y })),
  }
  window.addEventListener('mousemove', onDrag)
  window.addEventListener('mouseup', endDrag)
}

const startResize = (event, mapping) => {
  if (!isDraft.value) return
  select(mapping, false)

  beginChange()
  dragState = {
    mode: 'resize',
    mapping,
    startX: event.clientX,
    startY: event.clientY,
    originW: mapping.width,
    originH: mapping.height,
  }
  window.addEventListener('mousemove', onDrag)
  window.addEventListener('mouseup', endDrag)
}

const onDrag = (event) => {
  if (!dragState) return
  const grid = snapEnabled.value ? gridSize.value : 0
  const dx = (event.clientX - dragState.startX) / zoom.value
  const dy = (event.clientY - dragState.startY) / zoom.value

  if (dragState.mode === 'move') {
    for (const { box, originX, originY } of dragState.boxes) {
      const moved = clampBox({
        ...box,
        x: snapValue(originX + dx, grid),
        y: snapValue(originY + dy, grid),
      }, sizeOf(box))
      box.x = round(moved.x)
      box.y = round(moved.y)
    }
    return
  }

  const resized = clampSize({
    ...dragState.mapping,
    width: snapValue(dragState.originW + dx, grid),
    height: snapValue(dragState.originH + dy, grid),
  }, sizeOf(dragState.mapping))

  dragState.mapping.width = round(resized.width)
  dragState.mapping.height = round(resized.height)
}

const endDrag = () => {
  if (dragState) commit()
  dragState = null
  window.removeEventListener('mousemove', onDrag)
  window.removeEventListener('mouseup', endDrag)
}

// ───────────────────────────── ปุ่มลัด ─────────────────────────────

let clipboard = []

const onKeydown = (event) => {
  const tag = event.target?.tagName
  if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA') return
  if (!isDraft.value) return

  const ctrl = event.ctrlKey || event.metaKey

  if (ctrl && event.key.toLowerCase() === 'z') {
    event.preventDefault()
    undo()
    return
  }

  if (ctrl && (event.key.toLowerCase() === 'y' || (event.shiftKey && event.key.toLowerCase() === 'z'))) {
    event.preventDefault()
    redo()
    return
  }

  if (ctrl && event.key.toLowerCase() === 'c') {
    clipboard = selection.value.map(box => ({ ...box }))
    return
  }

  if (ctrl && event.key.toLowerCase() === 'v') {
    event.preventDefault()
    paste(clipboard)
    return
  }

  if (ctrl && event.key.toLowerCase() === 'd') {
    event.preventDefault()
    paste(selection.value)
    return
  }

  if ((event.key === 'Delete' || event.key === 'Backspace') && selection.value.length) {
    event.preventDefault()
    removeSelected()
    return
  }

  if (event.key.startsWith('Arrow') && selection.value.length) {
    event.preventDefault()
    beginChange()
    for (const box of selection.value) {
      const moved = nudgeBox(box, event.key, sizeOf(box), event.shiftKey)
      box.x = round(moved.x)
      box.y = round(moved.y)
    }
    commit()
  }
}

/** วางสำเนาเยื้องลงมาเล็กน้อยให้เห็นว่าเป็นคนละกล่อง */
const paste = (source) => {
  if (!isDraft.value || source.length === 0) return
  beginChange()

  const copies = source.map(box => clampBox({
    ...box,
    _key: `paste-${Date.now()}-${Math.random()}`,
    id: undefined,
    page: currentPage.value,
    x: box.x + 10,
    y: box.y + 10,
  }, pageBox.value))

  mappings.value.push(...copies)
  selection.value = copies
  commit()
}

// ───────────────────────── การกระทำกับเวอร์ชัน ─────────────────────────

const triggerUpload = () => fileInput.value?.click()

const uploadPdf = async (event) => {
  const file = event.target.files?.[0]
  if (!file) return
  saving.value = true
  try {
    const form = new FormData()
    form.append('file', file)
    const res = await api.post(`/admin/document-engine/versions/${version.value.id}/pdf`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    alertStore.success(res.data.message)
    version.value = res.data.version
    await loadGeometry()
    await loadPdf()
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'อัปโหลดไฟล์ไม่สำเร็จ')
  } finally {
    saving.value = false
    event.target.value = ''
  }
}

const save = async () => {
  saving.value = true
  try {
    const res = await api.put(`/admin/document-engine/versions/${version.value.id}/mappings`, {
      mappings: mappings.value.map(m => ({
        field_code: m.field_code,
        field_type: m.field_type,
        page: Number(m.page) || 1,
        x: Number(m.x), y: Number(m.y),
        width: Number(m.width), height: Number(m.height),
        font: m.font || null,
        font_size: Number(m.font_size) || 14,
        align: m.align || 'left',
        overflow: m.overflow || 'shrink',
        format: m.format || null,
        static_text: m.static_text || null,
        options: m.options || null,
      })),
    })
    savedSnapshot.value = serialize(mappings.value)
    alertStore.success(res.data.message)
    return true
  } catch (error) {
    alertStore.error(
      error.response?.data?.errors?.mappings?.[0]
      || error.response?.data?.message
      || Object.values(error.response?.data?.errors || {})[0]?.[0]
      || 'บันทึกไม่สำเร็จ',
    )
    return false
  } finally {
    saving.value = false
  }
}

const preview = async () => {
  if (previewMode.value === 'leave' && !previewLeaveId.value) {
    alertStore.error('กรอกเลขที่ใบลาที่จะใช้เป็นตัวอย่างก่อน')
    return
  }

  try {
    const res = await api.get(`/admin/document-engine/versions/${version.value.id}/preview`, {
      params: { mode: previewMode.value, leave_id: previewMode.value === 'leave' ? previewLeaveId.value : undefined },
      responseType: 'blob',
    })
    window.open(URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' })), '_blank')
  } catch (error) {
    // คำตอบเป็น blob ข้อความผิดพลาดจึงต้องอ่านออกมาก่อน มิฉะนั้นผู้ดูแลจะเห็นแต่ข้อความกลาง ๆ
    const message = await readBlobMessage(error)
    alertStore.error(message || 'สร้างตัวอย่างเอกสารไม่สำเร็จ — ตรวจสอบว่าอัปโหลดแบบฟอร์มต้นฉบับแล้วหรือยัง')
  }
}

/** อ่านข้อความผิดพลาดจากคำตอบที่เป็น blob (endpoint ที่ปกติคืนไฟล์) */
const readBlobMessage = async (error) => {
  const data = error?.response?.data
  if (!(data instanceof Blob)) return error?.response?.data?.message || ''

  try {
    return JSON.parse(await data.text())?.message || ''
  } catch {
    return ''
  }
}

// ───────────────────── ตรวจก่อนเผยแพร่ · เทียบเวอร์ชัน · สอบเทียบ · แพ็กเกจ ─────────────────────

const runPreflight = async () => {
  checking.value = true
  try {
    const res = await api.get(`/admin/document-engine/versions/${version.value.id}/preflight`)
    preflight.value = res.data
    if (!res.data.errors.length && !res.data.warnings.length) {
      alertStore.success('ตรวจแล้วไม่พบปัญหา')
    }
    return res.data
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'ตรวจแบบฟอร์มไม่สำเร็จ')
    return null
  } finally {
    checking.value = false
  }
}

const loadDiff = async () => {
  if (!diffAgainstId.value) {
    diff.value = null
    return
  }
  try {
    const res = await api.get(`/admin/document-engine/versions/${version.value.id}/diff`, {
      params: { against: diffAgainstId.value },
    })
    diff.value = res.data
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'เทียบเวอร์ชันไม่สำเร็จ')
  }
}

const openCalibrationSheet = async () => {
  try {
    const res = await api.get(`/admin/document-engine/versions/${version.value.id}/calibration-sheet`, { responseType: 'blob' })
    window.open(URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' })), '_blank')
  } catch (error) {
    alertStore.error((await readBlobMessage(error)) || 'สร้างใบสอบเทียบไม่สำเร็จ')
  }
}

const solveCalibration = async () => {
  try {
    const res = await api.post('/admin/document-engine/calibrations/solve', measurement.value)
    calibration.value = res.data.calibration
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'คำนวณค่าชดเชยไม่สำเร็จ')
  }
}

/** บันทึกค่าชดเชยให้แม่แบบนี้โดยเฉพาะ (ไม่แตะค่าของทั้งระบบหรือของผู้ใช้อื่น) */
const saveCalibration = async () => {
  try {
    const res = await api.post('/admin/document-engine/calibrations', {
      scope: 'TEMPLATE',
      document_template_id: template.value.id,
      ...calibration.value,
    })
    alertStore.success(res.data.message)
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'บันทึกค่าชดเชยไม่สำเร็จ')
  }
}

const exportPackage = async () => {
  try {
    const res = await api.get(`/admin/document-engine/versions/${version.value.id}/package`, { responseType: 'blob' })
    const url = URL.createObjectURL(new Blob([res.data], { type: 'application/zip' }))
    const link = document.createElement('a')
    link.href = url
    link.download = `${template.value.code}-v${version.value.version}.zip`
    link.click()
    URL.revokeObjectURL(url)
  } catch (error) {
    alertStore.error((await readBlobMessage(error)) || 'ส่งออกแบบฟอร์มไม่สำเร็จ')
  }
}

const createVersion = async () => {
  if (!(await confirmDiscard())) return
  saving.value = true
  try {
    const res = await api.post(`/admin/document-engine/templates/${template.value.id}/versions`, {
      from_version_id: version.value?.id,
    })
    alertStore.success(res.data.message)
    await loadTemplate()
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'สร้างเวอร์ชันใหม่ไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

const removeDraft = async () => {
  if (!window.confirm('ลบฉบับร่างนี้ทิ้ง? ตำแหน่งฟิลด์ที่วางไว้ในฉบับร่างนี้จะหายไปด้วย (เวอร์ชันที่เผยแพร่แล้วไม่ถูกแตะ)')) return

  saving.value = true
  try {
    const res = await api.delete(`/admin/document-engine/versions/${version.value.id}`)
    alertStore.success(res.data.message)
    savedSnapshot.value = serialize(mappings.value)
    selectedVersionId.value = null
    await loadTemplate()
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'ลบฉบับร่างไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

const publish = async () => {
  // บันทึกก่อนเสมอ และหยุดทันทีเมื่อบันทึกไม่ผ่าน มิฉะนั้นจะเผยแพร่ตำแหน่งชุดเก่าโดยไม่รู้ตัว
  if (!(await save())) return

  /*
   * ตรวจก่อนถามยืนยัน — ผู้ดูแลจะได้เห็นปัญหาก่อนตัดสินใจ ไม่ใช่หลังกดยืนยันแล้วถูกปฏิเสธ
   */
  const result = await runPreflight()

  if (result === null) return

  if (result.errors.length) {
    alertStore.error('เผยแพร่ไม่ได้ — แก้ข้อผิดพลาดที่แสดงในกล่อง "ผลตรวจก่อนเผยแพร่" ก่อน')
    return
  }

  const warning = result.warnings.length
    ? `\n\nมีข้อควรระวัง ${result.warnings.length} ข้อ:\n- ${result.warnings.join('\n- ')}`
    : ''

  if (!window.confirm(`เผยแพร่เวอร์ชันนี้? เอกสารที่ออกใหม่จะใช้เวอร์ชันนี้ และเวอร์ชันเดิมจะถูกปลดเป็น "เลิกใช้แล้ว" (ไม่ถูกลบ)${warning}`)) return

  saving.value = true
  try {
    const res = await api.post(`/admin/document-engine/versions/${version.value.id}/publish`)
    alertStore.success(res.data.message)
    await loadTemplate()
  } catch (error) {
    alertStore.error(
      error.response?.data?.errors?.mappings?.[0]
      || error.response?.data?.message
      || 'เผยแพร่ไม่สำเร็จ',
    )
  } finally {
    saving.value = false
  }
}

/*
 * เลิกใช้เวอร์ชันที่เผยแพร่แล้ว (CLEANUP-02)
 *
 * เอกสารที่ออกไปแล้วยังอ้างอิงเวอร์ชันนี้ได้ตามเดิม — การเลิกใช้มีผลกับเอกสารที่ออก "ใหม่" เท่านั้น
 */
const retire = async () => {
  if (! window.confirm('เลิกใช้เวอร์ชันนี้? เอกสารที่ออกใหม่จะไม่ใช้เวอร์ชันนี้อีก (เอกสารเก่าที่อ้างอิงไว้ยังพิมพ์ซ้ำได้เหมือนเดิม)')) return
  saving.value = true
  try {
    const res = await api.post(`/admin/document-engine/versions/${version.value.id}/retire`)
    alertStore.success(res.data.message)
    await loadTemplate()
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'เลิกใช้เวอร์ชันไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

// ───────────────────── กันงานหายเมื่อยังไม่ได้บันทึก ─────────────────────

const confirmDiscard = async () => {
  if (!isDirty.value) return true
  return window.confirm('ยังมีตำแหน่งฟิลด์ที่แก้ไว้แต่ยังไม่ได้บันทึก — ออกจากหน้านี้แล้วงานที่แก้จะหายไป ยืนยันหรือไม่?')
}

const warnOnUnload = (event) => {
  if (!isDirty.value) return
  event.preventDefault()
  event.returnValue = ''
}

onBeforeRouteLeave(async () => await confirmDiscard())

onMounted(() => {
  loadTemplate()
  window.addEventListener('keydown', onKeydown)
  window.addEventListener('beforeunload', warnOnUnload)
})

onBeforeUnmount(() => {
  releasePdf()
  endDrag()
  window.removeEventListener('keydown', onKeydown)
  window.removeEventListener('beforeunload', warnOnUnload)
})
</script>
