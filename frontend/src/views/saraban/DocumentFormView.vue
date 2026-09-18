<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <!-- Breadcrumb Navigation -->
    <AppBreadcrumb
      :items="[
        { title: 'งานสารบรรณ', to: '/saraban' },
        { title: 'ทะเบียนหนังสือ', to: '/saraban/documents' },
        { title: pageTitle }
      ]"
      :backTo="'/saraban/documents'"
    />

    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">{{ pageTitle }}</h1>
        <p class="text-sm text-gray-500 mt-1">
          หนังสือที่เสนอเข้าสู่กระบวนการแล้วจะแก้ไขไม่ได้ การแนบไฟล์ใหม่จะบันทึกเป็นฉบับใหม่เสมอ
        </p>
      </div>

      <!-- Local Draft Alert Banner (for new documents) -->
      <transition name="fade">
        <div v-if="hasDraft && !isEdit" class="w-full p-4 rounded-2xl bg-amber-50 border border-amber-200 text-amber-900 flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-xs">
          <div class="flex items-center gap-2.5 text-sm">
            <span class="text-lg">📝</span>
            <div>
              <p class="font-bold">พบเนื้อหาร่างหนังสือที่บันทึกไว้ในเครื่อง</p>
              <p class="text-xs text-amber-700">บันทึกอัตโนมัติล่าสุดเมื่อ {{ lastSavedAt }}</p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button type="button" @click="handleRestoreDraft" class="px-3.5 py-2 bg-[#800000] hover:bg-[#600000] text-white text-xs font-bold rounded-xl shadow-xs transition-colors flex items-center gap-1">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
              กู้คืนเนื้อหาร่าง
            </button>
            <button type="button" @click="clearDraft" class="px-3.5 py-2 bg-white hover:bg-amber-100 text-amber-800 border border-amber-200 text-xs font-semibold rounded-xl transition-colors">
              ล้างทิ้ง
            </button>
          </div>
        </div>
      </transition>
      <div class="flex flex-wrap gap-2">
        <!-- เกษียนหนังสือ (สำหรับผู้บริหาร / ผอ. - รอง ผอ. ๔ ฝ่าย / ผู้ตรวจทาน) -->
        <AppButton
          v-if="isEdit && !documentRecord?.finalized_at && versions.length"
          v-can="'saraban.endorse.create'"
          variant="secondary"
          @click="openEndorse"
        >
          เกษียนหนังสือ
        </AppButton>
        <!--
          ลงนามเป็นหน้าเต็ม (ปิด RISK-30) — ผู้ลงนามต้องเห็นเอกสารและลากวาง
          ลายเซ็นบนตำแหน่งจริง ซึ่งทำใน modal ไม่ได้
        -->
        <AppButton
          v-if="isEdit && !documentRecord?.finalized_at && versions.length"
          v-can="'saraban.signature.sign'"
          :variant="isSignatureStep ? 'primary' : 'secondary'"
          @click="openSigning"
        >
          ลงนาม{{ isSignatureStep ? ' (ถึงขั้นตอนแล้ว)' : '' }}
        </AppButton>
        <!-- INT-13 — เวียนหนังสือถึงผู้รับ -->
        <AppButton
          v-if="isEdit && (documentRecord?.status === 'signed' || documentRecord?.status === 'registered')"
          v-can="'saraban.distribution.send'"
          variant="secondary"
          @click="distributing = true"
        >
          เวียนหนังสือ
        </AppButton>
        <!-- ส่งหนังสือข้ามสถานศึกษา (Inter-Agency Exchange) -->
        <AppButton
          v-if="isEdit && (documentRecord?.status === 'signed' || documentRecord?.status === 'registered' || documentRecord?.finalized_at)"
          v-can="'saraban.distribution.track'"
          variant="secondary"
          @click="showInterAgencyModal = true"
        >
          🌐 ส่งข้ามสถานศึกษา
        </AppButton>
        <!-- INT-10 — พื้นที่ประทับตราของหนังสือฉบับนี้ (เฉพาะหนังสือรับเข้า) -->
        <AppButton
          v-if="isEdit && (documentRecord?.direction === 'incoming' || documentRecord?.incoming_registration)"
          variant="outline"
          @click="openWorkspace"
        >
          ประทับตรา
        </AppButton>
        <AppButton variant="ghost" @click="backToList">กลับสู่ทะเบียน</AppButton>
      </div>

      <DistributeDocumentModal
        v-if="isEdit && distributing"
        :document-id="route.params.id"
        :confidentiality="form.confidentiality"
        @close="distributing = false"
        @sent="onDistributed"
      />

      <InterAgencyDispatchModal
        v-if="isEdit && showInterAgencyModal"
        :is-open="showInterAgencyModal"
        :document="documentRecord"
        @close="showInterAgencyModal = false"
        @success="onInterAgencyDispatched"
      />
    </div>

    <!-- แผงการเดินเรื่องและอนุมัติด่วน (Workflow Quick Actions ด้านบน) -->
    <section
      v-if="workflow.started && workflow.transitions.length"
      class="bg-gradient-to-r from-amber-50 to-orange-50 border border-amber-200 rounded-2xl p-5 shadow-xs space-y-3"
    >
      <div class="flex flex-wrap items-center justify-between gap-2 border-b border-amber-200/70 pb-3">
        <div>
          <div class="flex items-center gap-2">
            <span class="inline-flex items-center justify-center w-2.5 h-2.5 rounded-full bg-amber-500 animate-ping" />
            <h2 class="font-bold text-amber-900 text-sm">การเดินเรื่อง — ขั้นตอนที่รอท่านดำเนินการ</h2>
          </div>
          <p class="text-xs text-amber-800 mt-0.5">
            ขั้นปัจจุบัน: <strong>{{ workflow.current_step?.name || '—' }}</strong>
          </p>
        </div>
        <AppBadge :variant="workflowVariant">{{ workflowStatusLabel }}</AppBadge>
      </div>

      <div class="space-y-3">
        <AppTextarea v-model="workflowComment" label="ความเห็นประกอบการพิจารณา" :rows="2" placeholder="ระบุความเห็นหรือคำสั่งการ..." />
        <div class="flex flex-wrap gap-2">
          <AppButton
            v-for="transition in workflow.transitions"
            :key="transition.action"
            :variant="transition.action === 'approve' ? 'primary' : 'outline'"
            :loading="actingOn === transition.action"
            @click="performWorkflow(transition)"
          >
            {{ transition.name }}
          </AppButton>
        </div>
      </div>
    </section>

    <LoadingState v-if="loading" type="skeleton-form" />
    <ErrorState v-else-if="loadError" :message="loadError" @retry="boot" />

    <form v-else class="space-y-6" @submit.prevent="save">
      <!-- แม่แบบหนังสือราชการมาตรฐานสำหรับสร้างหนังสือใหม่ (๔ รูปแบบตามระเบียบสำนักนายกฯ ๒๕๖๔) -->
      <section v-if="!isEdit" class="bg-gradient-to-r from-primary-50/70 to-blue-50/70 border border-primary-100 rounded-2xl p-5 shadow-xs space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div class="flex items-center gap-2">
            <span class="text-base">📋</span>
            <h2 class="font-semibold text-gray-800 text-sm">แม่แบบหนังสือราชการมาตรฐาน (ระเบียบสำนักนายกฯ ๒๕๖๔)</h2>
          </div>
          <span class="text-xs text-primary-700 font-medium">คลิกเลือกแม่แบบเพื่อใส่โครงสร้างเอกสารและตำแหน่งตราครุฑอัตโนมัติ</span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
          <button
            v-for="tpl in availableTemplates"
            :key="tpl.id"
            type="button"
            class="flex flex-col text-left p-3.5 rounded-xl border transition-all bg-white hover:border-primary-400 hover:shadow-xs group relative"
            :class="selectedTemplateId === tpl.id ? 'border-primary-500 ring-2 ring-primary-200 bg-primary-50/20' : 'border-gray-200'"
            @click="applyTemplate(tpl.id)"
          >
            <div class="flex items-center justify-between gap-1 mb-1">
              <span class="font-bold text-xs text-gray-800 line-clamp-1 group-hover:text-primary-800">
                {{ tpl.name }}
              </span>
              <span
                class="text-[10px] px-1.5 py-0.5 rounded-full font-medium whitespace-nowrap"
                :class="tpl.garuda?.position === 'left' ? 'bg-primary-100 text-primary-800' : 'bg-blue-100 text-blue-800'"
              >
                {{ tpl.garuda?.position === 'left' ? 'ครุฑซ้าย ๑.๕ ซม.' : 'ครุฑกลาง ๓ ซม.' }}
              </span>
            </div>
            <span class="text-[11px] text-gray-500 line-clamp-2 leading-snug">
              {{ tpl.description }}
            </span>
          </button>
        </div>

        <!-- ชุดข้อความสั่งการ/เกษียนความเห็นด่วน ๔ ฝ่ายอาชีวศึกษา -->
        <div v-if="endorsementPresets.length" class="flex flex-wrap items-center gap-2 pt-1 text-xs">
          <span class="text-gray-600 font-medium flex items-center gap-1">
            <span>⚡</span> ข้อความสั่งการด่วน ๔ ฝ่าย:
          </span>
          <button
            v-for="preset in endorsementPresets"
            :key="preset.id"
            type="button"
            class="px-2.5 py-1 rounded-lg border border-gray-200 bg-white hover:border-primary-300 hover:bg-primary-50/50 text-gray-700 text-xs transition-colors"
            @click="insertPreset(preset)"
          >
            {{ preset.label }}
          </button>
        </div>

        <!-- ข้อความปฏิเสธความรับผิดสำหรับแนบท้ายอีเมลหนังสือภายนอก (ภาคผนวก ๗) -->
        <div v-if="externalDisclaimer && (selectedTemplateId === 'external_letter' || selectedTemplateId === 'external')" class="rounded-xl bg-white/80 border border-primary-100 p-3">
          <p class="text-xs font-semibold text-gray-700 mb-1">ข้อความปฏิเสธความรับผิดสำหรับแนบท้ายอีเมล (๒ ภาษา)</p>
          <pre class="text-[11px] text-gray-500 whitespace-pre-wrap font-sans leading-relaxed">{{ externalDisclaimer }}</pre>
        </div>
      </section>

      <!-- แผงตัวอย่างหนังสือแบบเรียลไทม์ (Live Document Preview) -->
      <section class="border border-gray-200 rounded-2xl overflow-hidden bg-white shadow-xs">
        <header class="px-5 py-3.5 bg-gray-50/90 border-b border-gray-200 flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-2">
            <span class="text-lg">📄</span>
            <div>
              <h2 class="font-semibold text-sm text-gray-800">ตัวอย่างการจัดวางหนังสือราชการ (Live Preview)</h2>
              <p class="text-xs text-gray-500">แสดงผลตราครุฑ ส่วนหัวหนังสือ และการจัดหน้ากระดาษแบบเรียลไทม์</p>
            </div>
          </div>
          <AppButton size="sm" variant="outline" @click="showLivePreview = !showLivePreview">
            {{ showLivePreview ? 'ซ่อนตัวอย่าง ▲' : 'แสดงตัวอย่าง ▼' }}
          </AppButton>
        </header>

        <div v-if="showLivePreview" class="p-4 bg-gray-100/50 border-t border-gray-100">
          <LiveDocumentPreview
            :template-id="selectedTemplateId || 'internal_memo'"
            :subject="form.subject"
            :summary="form.summary"
            :agency="draftSections.agency || ownerOrgUnitName"
            :salutation="draftSections.salutation"
            :reference="draftSections.reference"
            :enclosures="draftSections.enclosures"
            :contact="draftSections.contact"
            :signatory-name="draftSections.signatory_name"
            :signatory-title="draftSections.signatory_title"
            :urgency="form.urgency"
            :confidentiality="form.confidentiality"
            :document-number="documentRecord?.document_number"
          />
        </div>
      </section>

      <!-- ฟอร์มรายละเอียดหนังสือและการยกร่าง -->
      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <header class="px-5 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 class="font-semibold text-gray-800">รายละเอียดหนังสือ</h2>
            <p class="text-xs text-gray-500 mt-0.5">กรอกข้อมูลและเนื้อหาร่างหนังสือตามระเบียบงานสารบรรณ</p>
          </div>
          <div v-if="!readOnly" class="flex items-center gap-1.5 bg-gray-100 p-1 rounded-xl text-xs">
            <button
              type="button"
              class="px-2.5 py-1 rounded-lg font-medium transition-all"
              :class="draftMode === 'structured' ? 'bg-white shadow-xs text-primary-800' : 'text-gray-600 hover:text-gray-900'"
              @click="draftMode = 'structured'"
            >
              ร่างแบบแยกส่วน (แนะนำ)
            </button>
            <button
              type="button"
              class="px-2.5 py-1 rounded-lg font-medium transition-all"
              :class="draftMode === 'freeform' ? 'bg-white shadow-xs text-primary-800' : 'text-gray-600 hover:text-gray-900'"
              @click="draftMode = 'freeform'"
            >
              เนื้อหาเต็มอิสระ
            </button>
          </div>
        </header>

        <div class="p-5 space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <AppSelect
              v-model="form.document_type_id"
              label="ประเภทหนังสือ"
              required
              :disabled="readOnly"
              :error="errors.document_type_id"
            >
              <option value="">เลือกประเภท</option>
              <option v-for="type in options.document_types" :key="type.id" :value="type.id">
                {{ type.name }}
              </option>
            </AppSelect>

            <AppSelect
              v-model="form.direction"
              label="ทิศทางของหนังสือ"
              required
              :disabled="readOnly"
              :error="errors.direction"
            >
              <option v-for="d in DIRECTIONS" :key="d.value" :value="d.value">{{ d.label }}</option>
            </AppSelect>

            <div class="sm:col-span-2">
              <AppInput v-model="form.subject" label="เรื่อง" required :disabled="readOnly" :error="errors.subject" placeholder="ระบุชื่อเรื่องหนังสือ..." />
            </div>

            <AppSelect
              v-model="form.confidentiality"
              label="ชั้นความลับ"
              required
              :disabled="readOnly"
              :error="errors.confidentiality"
            >
              <option v-for="c in CONFIDENTIALITY" :key="c.value" :value="c.value">{{ c.label }}</option>
            </AppSelect>

            <AppSelect
              v-model="form.urgency"
              label="ชั้นความเร็ว"
              required
              :disabled="readOnly"
              :error="errors.urgency"
            >
              <option v-for="u in URGENCY" :key="u.value" :value="u.value">{{ u.label }}</option>
            </AppSelect>

            <div class="sm:col-span-2">
              <AppSelect
                v-model="form.owner_org_unit_id"
                label="หน่วยงานเจ้าของเรื่อง"
                required
                :disabled="readOnly"
                :error="errors.owner_org_unit_id"
              >
                <option value="">เลือกหน่วยงาน</option>
                <option v-for="unit in orgUnits" :key="unit.id" :value="unit.id">{{ unit.name }}</option>
              </AppSelect>
            </div>
          </div>

          <!-- โหมดร่างแบบแยกส่วน (Structured Drafting) -->
          <div v-if="draftMode === 'structured' && !readOnly" class="border-t border-gray-100 pt-4 space-y-4">
            <h3 class="text-sm font-bold text-gray-800 flex items-center gap-1.5">
              <span>✍️</span> ส่วนประกอบข้อความหนังสือ (จะประกอบรวมเป็นเนื้อหาเอกสารอัตโนมัติ)
            </h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <AppInput
                v-model="draftSections.agency"
                label="ส่วนราชการ / หน่วยงาน"
                placeholder="เช่น ฝ่ายบริหารทรัพยากร งานสารบรรณ โทร. ๐-๒xxx-xxxx"
                @input="syncSummaryFromSections"
              />
              <AppInput
                v-model="draftSections.salutation"
                label="คำขึ้นต้น (เรียน)"
                placeholder="เช่น ผู้อำนวยการวิทยาลัย / ตำแหน่งผู้รับ"
                @input="syncSummaryFromSections"
              />

              <!-- กรณีหนังสือภายนอก -->
              <template v-if="selectedTemplateId === 'external_letter' || selectedTemplateId === 'external'">
                <AppInput
                  v-model="draftSections.reference"
                  label="อ้างถึง (ถ้ามี)"
                  placeholder="หนังสือที่เคยติดต่อกัน..."
                  @input="syncSummaryFromSections"
                />
                <AppInput
                  v-model="draftSections.enclosures"
                  label="สิ่งที่ส่งมาด้วย (ถ้ามี)"
                  placeholder="เอกสารประกอบ..."
                  @input="syncSummaryFromSections"
                />
              </template>
            </div>

            <div class="space-y-3">
              <AppTextarea
                v-model="draftSections.background"
                label="๑. ข้อความตอนแรก / เหตุผลความเป็นมา (ด้วย...)"
                :rows="3"
                placeholder="ระบุเหตุผล ความเป็นมา หรือข้อเท็จจริง..."
                @input="syncSummaryFromSections"
              />
              <AppTextarea
                v-model="draftSections.objective"
                label="๒. ข้อความตอนกลาง / รายละเอียดการดำเนินการ (ในการนี้...)"
                :rows="3"
                placeholder="ระบุวัตถุประสงค์ รายละเอียด หรือสิ่งที่ขอความร่วมมือ..."
                @input="syncSummaryFromSections"
              />
              <AppInput
                v-model="draftSections.conclusion"
                label="๓. ข้อความตอนท้าย / ข้อสรุปเพื่อโปรดพิจารณา (จึงเรียนมาเพื่อ...)"
                placeholder="เช่น จึงเรียนมาเพื่อโปรดพิจารณาอนุมัติ"
                @input="syncSummaryFromSections"
              />
            </div>
          </div>

          <!-- โหมดเนื้อหาเต็ม (Freeform Summary) -->
          <div class="border-t border-gray-100 pt-4">
            <AppTextarea
              v-model="form.summary"
              label="เนื้อความหนังสือฉบับเต็ม / สาระสำคัญ"
              :rows="7"
              :disabled="readOnly"
              :error="errors.summary"
              placeholder="พิมพ์เนื้อหาหนังสือฉบับเต็ม หรือใช้การร่างแบบแยกส่วนด้านบน..."
            />
          </div>
        </div>
      </section>

      <!-- ไฟล์หนังสือและประวัติเวอร์ชัน -->
      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <header class="px-5 py-4 border-b border-gray-100">
          <h2 class="font-semibold text-gray-800">ไฟล์หนังสือ</h2>
          <p class="text-sm text-gray-500 mt-0.5">
            รับไฟล์ PDF, JPG หรือ PNG · ทุกไฟล์ผ่านการตรวจความปลอดภัยก่อนจัดเก็บ
          </p>
        </header>

        <div class="p-5 space-y-4">
          <div v-if="!readOnly">
            <label for="document-file" class="block text-sm font-semibold text-gray-700 mb-1.5">
              แนบไฟล์{{ isEdit ? 'ฉบับใหม่' : 'ต้นฉบับ' }}
            </label>
            <input
              id="document-file"
              type="file"
              accept=".pdf,.jpg,.jpeg,.png"
              class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-xl file:border-0 file:bg-[#800000] file:px-4 file:py-2 file:text-white file:text-sm file:font-semibold hover:file:bg-[#600000]"
              @change="onFileChange"
            />
            <p v-if="errors.file" class="mt-1 text-xs text-red-600" role="alert">{{ errors.file }}</p>
            <p class="mt-1.5 text-xs text-gray-400">
              ข้อแนะนำตามระเบียบงานสารบรรณ (ภาคผนวก ๗ ข้อ ๔.๓): การตั้งชื่อไฟล์ควรใช้รูปแบบ "เลขหนังสือ_ปีพ.ศ._ชื่อเรื่องย่อ.pdf" เช่น ศธ04001-125_2569_เชิญประชุม.pdf เพื่อความเป็นระเบียบและค้นหาง่าย
            </p>
          </div>

          <div v-if="versions.length" class="overflow-x-auto">
            <table class="w-full text-sm min-w-[480px]">
              <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                <tr>
                  <th class="text-left px-4 py-2.5 font-semibold">ฉบับที่</th>
                  <th class="text-left px-4 py-2.5 font-semibold">ขั้น</th>
                  <th class="text-left px-4 py-2.5 font-semibold">บันทึกเมื่อ</th>
                  <th class="text-right px-4 py-2.5 font-semibold">การกระทำ</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-50">
                <tr v-for="version in versions" :key="version.id">
                  <td class="px-4 py-2.5 font-medium text-gray-900">{{ version.version_no }}</td>
                  <td class="px-4 py-2.5 text-gray-600">{{ stageLabel(version.stage) }}</td>
                  <td class="px-4 py-2.5 text-gray-500">{{ version.created_at }}</td>
                  <td class="px-4 py-2.5 text-right whitespace-nowrap">
                    <AppButton size="sm" variant="ghost" @click="preview(version)">ดูเอกสาร</AppButton>
                    <AppButton size="sm" variant="ghost" :loading="downloading" @click="downloadOfficial">ดาวน์โหลด</AppButton>
                  </td>
                </tr>
              </tbody>
            </table>
            <p class="px-4 py-2 text-xs text-gray-400">ต้นฉบับถูกเก็บไว้ทุกฉบับและไม่ถูกเขียนทับ</p>
          </div>

          <!-- ตัวอย่างเอกสารไฟล์แนบ -->
          <div v-if="previewError" role="alert" class="text-sm text-red-700">{{ previewError }}</div>

          <div v-if="previewUrl" class="border border-gray-200 rounded-xl overflow-hidden">
            <div class="flex items-center justify-between px-4 py-2 bg-gray-50 border-b border-gray-200">
              <span class="text-sm text-gray-700">ตัวอย่างฉบับที่ {{ previewVersion?.version_no }}</span>
              <AppButton size="sm" variant="ghost" @click="closePreview">ปิดตัวอย่าง</AppButton>
            </div>
            <iframe
              :src="previewUrl"
              title="ตัวอย่างเอกสาร"
              class="w-full h-[32rem] bg-white"
            />
          </div>
        </div>
      </section>

      <!-- การเดินเรื่อง (INT-08) -->
      <section
        v-if="workflow.started"
        class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden"
      >
        <header class="px-5 py-4 border-b border-gray-100 flex flex-wrap items-center gap-3">
          <div class="flex-1">
            <h2 class="font-semibold text-gray-800">การเดินเรื่อง</h2>
            <p class="text-sm text-gray-500 mt-0.5">
              ขั้นปัจจุบัน: {{ workflow.current_step?.name || 'จบกระบวนการแล้ว' }}
            </p>
          </div>
          <AppBadge :variant="workflowVariant">{{ workflowStatusLabel }}</AppBadge>
        </header>

        <div class="p-5 space-y-4">
          <div v-if="workflow.transitions.length" class="space-y-3">
            <AppTextarea v-model="workflowComment" label="ความเห็น" />
            <div class="flex flex-wrap gap-2">
              <AppButton
                v-for="transition in workflow.transitions"
                :key="transition.action"
                :variant="transition.action === 'approve' ? 'primary' : 'outline'"
                :loading="actingOn === transition.action"
                @click="performWorkflow(transition)"
              >
                {{ transition.name }}
              </AppButton>
            </div>
          </div>
          <p v-else class="text-sm text-gray-500">
            ขณะนี้ท่านไม่มีขั้นตอนที่ต้องดำเนินการกับหนังสือฉบับนี้
          </p>

          <div v-if="workflow.history.length" class="border-t border-gray-100 pt-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-2">ประวัติการดำเนินการ</h3>
            <ul class="space-y-1.5">
              <li v-for="(entry, index) in workflow.history" :key="index" class="text-sm text-gray-600">
                <span class="font-medium text-gray-800">{{ actionLabel(entry.action) }}</span>
                <span v-if="entry.actor"> โดย {{ entry.actor }}</span>
                <span class="text-gray-400"> · {{ entry.at }}</span>
                <span v-if="entry.comment" class="block text-xs text-gray-500">{{ entry.comment }}</span>
              </li>
            </ul>
          </div>
        </div>
      </section>

      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-2">
        <div v-if="!isEdit" class="flex items-center gap-2 text-xs text-gray-500">
          <span
            class="w-2 h-2 rounded-full"
            :class="isSaving ? 'bg-amber-400 animate-ping' : lastSavedAt ? 'bg-emerald-500' : 'bg-gray-300'"
          ></span>
          <span>
            {{ isSaving ? 'กำลังบันทึกร่างลงในเครื่อง...' : lastSavedAt ? `บันทึกร่างอัตโนมัติแล้วเมื่อ ${lastSavedAt}` : 'ระบบบันทึกร่างอัตโนมัติในเครื่อง' }}
          </span>
          <button
            v-if="lastSavedAt"
            type="button"
            @click="clearDraft"
            class="text-gray-400 hover:text-red-600 transition-colors ml-2"
          >
            ล้างร่างในเครื่อง
          </button>
        </div>
        <div v-else></div>

        <div class="flex flex-wrap justify-end gap-3">
          <AppButton v-if="!readOnly" type="submit" :loading="saving">
            {{ isEdit ? 'บันทึกการแก้ไข' : 'บันทึกฉบับร่าง' }}
          </AppButton>
          <AppButton v-if="canSubmit" variant="secondary" :loading="submitting" @click="submitDocument">
            เสนอเรื่อง
          </AppButton>
        </div>
      </div>
    </form>
    <DocumentTools v-if="documentRecord && !loading" :key="documentRecord.id" :document="documentRecord" @changed="boot" />
    <RetentionPanel v-if="documentRecord && !loading" :key="`retention-${documentRecord.id}`" :document-id="documentRecord.id" />
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../../axios'
import { getErrorMessage } from '../../utils/errorHandler'
import { fetchObjectUrl } from '../../utils/secureFile'
import { useAlertStore } from '../../stores/alert'
import AppBreadcrumb from '../../components/ui/AppBreadcrumb.vue'
import AppButton from '../../components/ui/AppButton.vue'
import AppInput from '../../components/ui/AppInput.vue'
import AppSelect from '../../components/ui/AppSelect.vue'
import AppTextarea from '../../components/ui/AppTextarea.vue'
import AppBadge from '../../components/ui/AppBadge.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import LoadingState from '../../components/ui/LoadingState.vue'
import DistributeDocumentModal from '../../components/saraban/DistributeDocumentModal.vue'
import InterAgencyDispatchModal from '../../components/saraban/InterAgencyDispatchModal.vue'
import DocumentTools from '../../components/saraban/DocumentTools.vue'
import RetentionPanel from '../../components/saraban/RetentionPanel.vue'
import LiveDocumentPreview from '../../components/saraban/LiveDocumentPreview.vue'
import { useFormAutoSave } from '../../composables/useFormAutoSave'

const DIRECTIONS = [
  { value: 'incoming', label: 'หนังสือรับ' },
  { value: 'outgoing', label: 'หนังสือส่ง' },
  { value: 'internal', label: 'หนังสือภายใน' },
]
const CONFIDENTIALITY = [
  { value: 'normal', label: 'ปกติ' },
  { value: 'confidential', label: 'ลับ' },
  { value: 'secret', label: 'ลับมาก' },
  { value: 'top_secret', label: 'ลับที่สุด' },
]
const URGENCY = [
  { value: 'normal', label: 'ปกติ' },
  { value: 'urgent', label: 'ด่วน' },
  { value: 'very_urgent', label: 'ด่วนมาก' },
  { value: 'most_urgent', label: 'ด่วนที่สุด' },
]
const STAGES = {
  original: 'ต้นฉบับ',
  revised: 'ฉบับแก้ไข',
  reviewed: 'ผ่านการตรวจ',
  endorsed: 'เกษียนแล้ว',
  signed: 'ลงนามแล้ว',
  final: 'ฉบับสมบูรณ์',
}

const route = useRoute()
const router = useRouter()
const alertStore = useAlertStore()

const loading = ref(true)
const loadError = ref('')
const saving = ref(false)
const submitting = ref(false)
const documentRecord = ref(null)
const options = ref({ document_types: [] })
const orgUnits = ref([])
const errors = reactive({})
const selectedFile = ref(null)

const availableTemplates = ref([])
const endorsementPresets = ref([])
const selectedTemplateId = ref('internal_memo')
const showLivePreview = ref(true)
const draftMode = ref('structured')

const draftSections = reactive({
  agency: '',
  salutation: 'ผู้อำนวยการ',
  reference: '',
  enclosures: '',
  background: 'ด้วย มีความจำเป็นต้องดำเนินงาน...',
  objective: 'ในการนี้ จึงขออนุมัติการดำเนินงาน...',
  conclusion: 'จึงเรียนมาเพื่อโปรดพิจารณาอนุมัติ',
  signatory_name: '',
  signatory_title: 'ผู้อำนวยการวิทยาลัย',
  contact: '',
})

const ownerOrgUnitName = computed(() => {
  const found = orgUnits.value.find((u) => String(u.id) === String(form.owner_org_unit_id))
  return found?.name || ''
})

const syncSummaryFromSections = () => {
  const parts = []
  if (draftSections.salutation) {
    parts.push(`เรียน  ${draftSections.salutation}`)
  }
  if (draftSections.reference) {
    parts.push(`อ้างถึง  ${draftSections.reference}`)
  }
  if (draftSections.enclosures) {
    parts.push(`สิ่งที่ส่งมาด้วย  ${draftSections.enclosures}`)
  }
  if (draftSections.background) {
    parts.push(`\n${draftSections.background}`)
  }
  if (draftSections.objective) {
    parts.push(`\n${draftSections.objective}`)
  }
  if (draftSections.conclusion) {
    parts.push(`\n${draftSections.conclusion}`)
  }

  form.summary = parts.join('\n').trim()
}

const applyTemplate = (typeId) => {
  if (form.summary && !window.confirm('เนื้อเรื่องที่พิมพ์ไว้จะถูกแทนที่ด้วยแม่แบบ ต้องการดำเนินการต่อหรือไม่?')) {
    return
  }

  selectedTemplateId.value = typeId
  const tpl = availableTemplates.value.find((t) => t.id === typeId)

  if (tpl) {
    form.direction = tpl.direction || 'internal'
    form.confidentiality = tpl.default_confidentiality || 'normal'
    form.urgency = tpl.default_urgency || 'normal'

    if (tpl.sections) {
      draftSections.agency = tpl.sections.agency || draftSections.agency
      draftSections.salutation = tpl.sections.salutation?.replace(/^เรียน\s+/, '') || draftSections.salutation
      draftSections.background = tpl.sections.background || draftSections.background
      draftSections.objective = tpl.sections.objective || draftSections.objective
      draftSections.conclusion = tpl.sections.conclusion || draftSections.conclusion
      draftSections.reference = tpl.sections.reference?.replace(/^อ้างถึง\s+/, '') || ''
      draftSections.enclosures = tpl.sections.enclosures?.replace(/^สิ่งที่ส่งมาด้วย\s+/, '') || ''
      draftSections.contact = tpl.sections.contact || ''
      draftSections.signatory_title = tpl.sections.signatory_title || 'ผู้อำนวยการวิทยาลัย'
    }

    if (typeId === 'internal_memo' || typeId === 'memo') {
      if (!form.subject) form.subject = 'ขออนุมัติ...'
    } else if (typeId === 'external_letter' || typeId === 'external') {
      if (!form.subject) form.subject = 'ขอความอนุเคราะห์...'
      loadExternalDisclaimer()
    } else if (typeId === 'college_order' || typeId === 'order') {
      if (!form.subject) form.subject = 'แต่งตั้งคณะกรรมการ...'
    } else if (typeId === 'college_announcement' || typeId === 'announcement') {
      if (!form.subject) form.subject = 'ประกาศรับสมัคร/จัดกิจกรรม...'
    }

    syncSummaryFromSections()
  }
}

const insertPreset = (preset) => {
  if (preset.text) {
    if (draftSections.conclusion) {
      draftSections.conclusion += ` (${preset.text})`
    } else {
      draftSections.conclusion = preset.text
    }
    syncSummaryFromSections()
    alertStore.success(`แทรกข้อความสั่งการ "${preset.label}" แล้ว`)
  }
}

const externalDisclaimer = ref('')

const loadExternalDisclaimer = async () => {
  if (externalDisclaimer.value) return
  try {
    const { data } = await api.get('/saraban/templates/disclaimer')
    externalDisclaimer.value = data.disclaimer?.formatted || ''
  } catch {
    // ไม่รบกวนผู้ใช้ — เป็นข้อมูลประกอบ
  }
}

const form = reactive({
  document_type_id: '',
  direction: 'internal',
  subject: '',
  summary: '',
  confidentiality: 'normal',
  urgency: 'normal',
  owner_org_unit_id: '',
})

const autoSaveDraftState = computed({
  get: () => ({
    form: { ...form },
    draftSections: { ...draftSections },
    selectedTemplateId: selectedTemplateId.value,
    draftMode: draftMode.value,
  }),
  set: (val) => {
    if (val.form) Object.assign(form, val.form)
    if (val.draftSections) Object.assign(draftSections, val.draftSections)
    if (val.selectedTemplateId) selectedTemplateId.value = val.selectedTemplateId
    if (val.draftMode) draftMode.value = val.draftMode
  }
})

const autoSaveKey = computed(() => `ela_draft_saraban_doc_${route.params.id || 'new'}`)
const {
  hasDraft,
  lastSavedAt,
  isSaving,
  restoreDraft,
  clearDraft,
} = useFormAutoSave(autoSaveKey.value, autoSaveDraftState, {
  debounceMs: 1500,
  isFormPopulated: (d) => !!(d?.form?.subject || d?.draftSections?.background || d?.draftSections?.agency),
})

const handleRestoreDraft = () => {
  restoreDraft()
  syncSummaryFromSections()
}

const isEdit = computed(() => Boolean(route.params.id))
const readOnly = computed(() => Boolean(documentRecord.value) && documentRecord.value.status !== 'draft')
const versions = computed(() => documentRecord.value?.versions || [])
const canSubmit = computed(
  () => isEdit.value && documentRecord.value?.status === 'draft' && versions.value.length > 0,
)
const pageTitle = computed(() => {
  if (!isEdit.value) return 'สร้างหนังสือ'

  return readOnly.value ? 'รายละเอียดหนังสือ' : 'แก้ไขหนังสือฉบับร่าง'
})

const stageLabel = (stage) => STAGES[stage] || stage

// ── ตัวอย่างเอกสารไฟล์แนบ (NAV §4.3) ────────────────────────────────────
const previewUrl = ref('')
const previewVersion = ref(null)
const previewError = ref('')

function closePreview() {
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  previewUrl.value = ''
  previewVersion.value = null
}

async function preview(version) {
  const path = version.pdf_file_path || version.original_file_path

  if (!path) {
    previewError.value = 'ฉบับนี้ไม่มีไฟล์ให้แสดง'
    return
  }

  previewError.value = ''
  closePreview()

  try {
    previewUrl.value = await fetchObjectUrl(`/storage/${path}`)
    previewVersion.value = version
  } catch (e) {
    previewError.value = getErrorMessage(e, 'เปิดตัวอย่างเอกสารไม่สำเร็จ')
  }
}

const downloading = ref(false)

async function downloadOfficial() {
  downloading.value = true
  try {
    const res = await api.get(`/saraban/documents/${route.params.id}/download`, { responseType: 'blob' })
    const disposition = res.headers['content-disposition'] || ''
    const match = disposition.match(/filename\*?=(?:UTF-8''|")?([^";]+)/i)
    const filename = match ? decodeURIComponent(match[1].replace(/"$/, '')) : `document-${route.params.id}.pdf`
    const url = URL.createObjectURL(res.data)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
  } catch (e) {
    previewError.value = getErrorMessage(e, 'ดาวน์โหลดหนังสือไม่สำเร็จ')
  } finally {
    downloading.value = false
  }
}

onBeforeUnmount(closePreview)

const onFileChange = (event) => {
  selectedFile.value = event.target.files?.[0] || null
}

const clearErrors = () => Object.keys(errors).forEach((key) => delete errors[key])

const applyValidationErrors = (err) => {
  const bag = err.response?.data?.errors
  if (!bag) return false

  Object.entries(bag).forEach(([field, messages]) => {
    errors[field] = messages[0]
  })

  return true
}

const buildPayload = () => {
  const payload = new FormData()
  Object.entries(form).forEach(([key, value]) => payload.append(key, value ?? ''))
  if (selectedFile.value) payload.append('file', selectedFile.value)

  return payload
}

const boot = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const [refRes, tplRes] = await Promise.all([
      api.get('/saraban/documents/options'),
      api.get('/saraban/templates').catch(() => ({ data: { templates: [], endorsement_presets: [] } })),
    ])

    const reference = refRes.data
    options.value = reference
    orgUnits.value = reference.org_units || []

    availableTemplates.value = tplRes.data?.templates || []
    endorsementPresets.value = tplRes.data?.endorsement_presets || []

    if (!form.owner_org_unit_id && orgUnits.value.length) {
      form.owner_org_unit_id = orgUnits.value[0].id
    }

    if (isEdit.value) {
      const { data: existing } = await api.get(`/saraban/documents/${route.params.id}`)
      documentRecord.value = existing
      Object.keys(form).forEach((key) => {
        if (existing[key] !== undefined && existing[key] !== null) form[key] = existing[key]
      })
    } else if (route.query.source_type) {
      try {
        const { data: crossData } = await api.get('/saraban/templates/cross-module-draft', {
          params: {
            source_type: route.query.source_type,
            source_id: route.query.source_id,
          },
        })
        if (crossData?.draft) {
          const d = crossData.draft
          if (d.subject) form.subject = d.subject
          if (d.template_id) selectedTemplateId.value = d.template_id
          if (d.sections) {
            if (d.sections.preamble) draftSections.background = d.sections.preamble
            if (d.sections.body) draftSections.objective = d.sections.body
            if (d.sections.effective_clause) draftSections.conclusion = d.sections.effective_clause
            if (d.sections.subject) draftSections.reference = d.sections.subject
          }
          syncSummaryFromSections()
          alertStore.info('แปลงข้อมูลตั้งต้นจากโมดูลอื่นเรียบร้อยแล้ว')
        }
      } catch {
        if (availableTemplates.value.length) {
          applyTemplate(availableTemplates.value[0].id)
        } else {
          syncSummaryFromSections()
        }
      }
    } else {
      // ค่าเริ่มต้นสำหรับสร้างหนังสือใหม่
      if (availableTemplates.value.length) {
        applyTemplate(availableTemplates.value[0].id)
      } else {
        syncSummaryFromSections()
      }
    }
  } catch (err) {
    loadError.value = getErrorMessage(err, 'โหลดข้อมูลหนังสือไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

const save = async () => {
  saving.value = true
  clearErrors()
  try {
    const url = isEdit.value ? `/saraban/documents/${route.params.id}` : '/saraban/documents'
    const { data } = await api.post(url, buildPayload(), {
      headers: { 'Content-Type': 'multipart/form-data' },
    })

    alertStore.success(isEdit.value ? 'บันทึกการแก้ไขแล้ว' : 'บันทึกฉบับร่างแล้ว')
    clearDraft()

    if (!isEdit.value) {
      router.push({ name: 'saraban-document-detail', params: { id: data.id } })
      return
    }

    documentRecord.value = data
    selectedFile.value = null
  } catch (err) {
    if (!applyValidationErrors(err)) {
      alertStore.error(getErrorMessage(err, 'บันทึกไม่สำเร็จ'))
    }
  } finally {
    saving.value = false
  }
}

const submitDocument = async () => {
  submitting.value = true
  try {
    const { data } = await api.post(`/saraban/documents/${route.params.id}/submit`)
    documentRecord.value = { ...documentRecord.value, ...data }
    alertStore.success('เสนอเรื่องเรียบร้อยแล้ว')
  } catch (err) {
    alertStore.error(getErrorMessage(err, 'เสนอเรื่องไม่สำเร็จ'))
  } finally {
    submitting.value = false
  }
}

// ── การเดินเรื่อง (INT-08) ──────────────────────────────────────────────
const WORKFLOW_STATUS = {
  running: { label: 'กำลังดำเนินการ', variant: 'info' },
  completed: { label: 'ลงนามแล้ว', variant: 'success' },
  rejected: { label: 'ไม่เห็นชอบ', variant: 'danger' },
  returned: { label: 'ส่งกลับแก้ไข', variant: 'warning' },
  cancelled: { label: 'ยกเลิก', variant: 'gray' },
}
const ACTIONS = {
  started: 'เริ่มกระบวนการ',
  approve: 'เห็นชอบ',
  reject: 'ไม่เห็นชอบ',
  return: 'ส่งกลับแก้ไข',
  cancel: 'ยกเลิก',
}

const workflow = ref({ started: false, status: null, current_step: null, transitions: [], history: [] })
const workflowComment = ref('')
const distributing = ref(false)
const showInterAgencyModal = ref(false)

const onDistributed = async () => {
  distributing.value = false
  await boot()
}

const onInterAgencyDispatched = async () => {
  showInterAgencyModal.value = false
  await boot()
}

const actingOn = ref(null)

const workflowStatusLabel = computed(() => WORKFLOW_STATUS[workflow.value.status]?.label || '—')
const workflowVariant = computed(() => WORKFLOW_STATUS[workflow.value.status]?.variant || 'gray')
const actionLabel = (action) => ACTIONS[action] || action

const loadWorkflow = async () => {
  if (!isEdit.value) return

  try {
    const { data } = await api.get(`/saraban/documents/${route.params.id}/workflow`)
    workflow.value = data
  } catch {
    workflow.value = { started: false, status: null, current_step: null, transitions: [], history: [] }
  }
}

const performWorkflow = async (transition) => {
  if (transition.requires_comment && !workflowComment.value.trim()) {
    alertStore.error('การดำเนินการนี้ต้องระบุความเห็นประกอบ')
    return
  }

  actingOn.value = transition.action
  try {
    const { data } = await api.post(`/saraban/documents/${route.params.id}/workflow`, {
      action: transition.action,
      comment: workflowComment.value || undefined,
    })

    workflow.value = data
    workflowComment.value = ''
    alertStore.success('บันทึกการดำเนินการแล้ว')

    await boot()
    await loadWorkflow()
  } catch (err) {
    alertStore.error(getErrorMessage(err, 'ดำเนินการไม่สำเร็จ'))
  } finally {
    actingOn.value = null
  }
}

const isSignatureStep = computed(
  () =>
    workflow.value?.current_step?.code === 'signature' ||
    workflow.value?.current_step?.assignee_permission === 'saraban.signature.sign',
)

const openEndorse = () =>
  router.push({ name: 'saraban-document-endorse', params: { id: route.params.id } })

const openWorkspace = () =>
  router.push({ name: 'saraban-document-workspace', params: { id: route.params.id } })

const openSigning = () =>
  router.push({ name: 'saraban-document-sign', params: { id: route.params.id } })

const backToList = () => router.push({ name: 'saraban-documents' })

onMounted(async () => {
  await boot()
  await loadWorkflow()
})
</script>
