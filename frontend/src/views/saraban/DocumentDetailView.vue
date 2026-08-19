<template>
  <div class="p-6">
    <div class="mb-6 flex justify-between items-center">
      <div>
        <router-link to="/saraban" class="text-gray-500 hover:text-gray-800 mb-2 inline-block">← กลับไปหน้ารายการ</router-link>
        <h1 class="text-2xl font-bold text-gray-800">รายละเอียดเอกสาร</h1>
      </div>
      <div class="flex items-center gap-2">
        <span class="px-3 py-1 rounded-full text-sm font-medium border" :class="statusConfig(document?.status).class">{{ statusConfig(document?.status).label }}</span>
        
        <!-- Workflow Actions -->
        <button v-if="canSubmit" @click="openActionModal('submit')" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">ส่งตรวจสอบ</button>
        <button v-if="canApprove" @click="openActionModal('approve')" class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm hover:bg-green-700">อนุมัติ</button>
        <button v-if="canApprove" @click="openActionModal('reject')" class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700">ไม่อนุมัติ</button>
        <button v-if="canSign" @click="openSignModal" class="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm hover:bg-purple-700">ลงนาม</button>
        <button v-if="canCircular" @click="openCircularModal" class="px-4 py-2 bg-orange-500 text-white rounded-lg text-sm hover:bg-orange-600 flex items-center gap-1">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
          แจ้งเวียน
        </button>
        
        <router-link v-if="canEdit" :to="`/saraban/${route.params.id}/edit`" class="px-4 py-2 bg-[#800000] text-white rounded-lg text-sm hover:bg-red-800 flex items-center gap-1">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
          แก้ไข
        </router-link>
      </div>
    </div>

    <div v-if="loading" class="text-center py-16">
      <svg class="animate-spin h-8 w-8 mx-auto mb-2 text-[#800000]" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
      </svg>
      <p class="text-gray-500">กำลังโหลดข้อมูล...</p>
    </div>
    
    <div v-else-if="document" class="space-y-4">
      <!-- Header Info Card -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <!-- Badges -->
        <div class="flex flex-wrap gap-2 mb-4">
          <span v-if="document.type" class="px-3 py-1 text-sm rounded-full text-white font-medium" :style="{ backgroundColor: document.type.color || '#6b7280' }">
            {{ document.type.name }}
          </span>
          <span class="px-3 py-1 text-sm rounded-full border" :class="secrecyClass(document.secrecy)">
            {{ secrecyLabel(document.secrecy) }}
          </span>
          <span v-if="document.priority !== 'normal'" class="inline-flex items-center gap-1.5 px-3 py-1 text-sm rounded-full bg-orange-100 text-orange-700 border border-orange-200">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.bolt"/>
            </svg>
            {{ priorityLabel(document.priority) }}
          </span>
        </div>

        <!-- เรื่อง -->
        <h2 class="text-xl font-bold text-gray-900 mb-6">{{ document.title }}</h2>

        <!-- Grid ข้อมูลหลัก -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4">
          <div>
            <p class="text-xs text-gray-400 uppercase tracking-wide mb-1">เลขที่เอกสาร</p>
            <p class="font-semibold text-gray-800">{{ document.document_number || 'ยังไม่ออกเลข' }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-400 uppercase tracking-wide mb-1">ลงวันที่</p>
            <p class="font-semibold text-gray-800">{{ document.document_date ? formatThaiDate(document.document_date) : '-' }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-400 uppercase tracking-wide mb-1">เรียน</p>
            <p class="font-semibold text-gray-800">{{ document.to || '-' }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-400 uppercase tracking-wide mb-1">ผู้สร้างเอกสาร</p>
            <p class="font-semibold text-gray-800">{{ document.creator?.name || '-' }}</p>
          </div>
          <div v-if="document.reference">
            <p class="text-xs text-gray-400 uppercase tracking-wide mb-1">อ้างถึง</p>
            <p class="font-semibold text-gray-800">{{ document.reference }}</p>
          </div>
          <div v-if="document.attachments_note">
            <p class="text-xs text-gray-400 uppercase tracking-wide mb-1">สิ่งที่ส่งมาด้วย</p>
            <p class="font-semibold text-gray-800">{{ document.attachments_note }}</p>
          </div>
        </div>

        <!-- หมายเหตุ -->
        <div v-if="document.remark" class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
          <p class="text-xs text-yellow-600 font-medium mb-1">หมายเหตุ</p>
          <p class="text-sm text-gray-700">{{ document.remark }}</p>
        </div>
      </div>

      <!-- หนังสือเข้า (ถ้ามี) -->
      <div v-if="document.incoming_document_number || document.incoming_date" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
          <span class="w-1.5 h-5 bg-blue-500 rounded-full inline-block"></span>
          รายละเอียดหนังสือที่รับเข้า
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <p class="text-xs text-gray-400 uppercase tracking-wide mb-1">เลขที่หนังสือเข้า</p>
            <p class="font-semibold text-gray-800">{{ document.incoming_document_number || '-' }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-400 uppercase tracking-wide mb-1">วันที่รับเข้า</p>
            <p class="font-semibold text-gray-800">{{ document.incoming_date ? formatThaiDate(document.incoming_date) : '-' }}</p>
          </div>
        </div>
      </div>

      <!-- ไฟล์แนบ -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
          <span class="w-1.5 h-5 bg-[#800000] rounded-full inline-block"></span>
          ไฟล์แนบและลิงก์
        </h3>
        <div v-if="document.attachments && document.attachments.length > 0" class="space-y-2">
          <div v-for="att in document.attachments" :key="att.id" class="flex items-center p-3 bg-gray-50 rounded-lg border border-gray-100 hover:bg-gray-100 transition">
            <svg class="w-5 h-5 mr-3 flex-shrink-0" :class="att.type === 'link' ? 'text-blue-500' : 'text-gray-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="att.type === 'link' ? ICONS.link : ICONS.document"/>
            </svg>
            <div class="flex-1 min-w-0">
              <p class="font-medium text-sm truncate">{{ att.name }}</p>
            </div>
            <div v-if="att.type === 'link'" class="ml-3">
              <a :href="att.path" target="_blank" class="text-sm text-blue-600 hover:underline font-medium">เปิดลิงก์ →</a>
            </div>
            <div v-else-if="document.allow_download" class="ml-3">
              <button type="button" @click="downloadAttachment(att)" class="text-sm text-[#800000] hover:underline font-medium">ดาวน์โหลด ↓</button>
            </div>
            <div v-else class="ml-3">
              <span class="inline-flex items-center gap-1 text-sm text-gray-400 cursor-not-allowed" title="ไม่อนุญาตให้ดาวน์โหลด">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.lock"/>
                </svg>
                ไม่อนุญาต
              </span>
            </div>
          </div>
        </div>
        <p v-else class="text-sm text-gray-400 italic">ไม่มีไฟล์แนบ</p>
      </div>

      <!-- สถานะการเปิดอ่าน -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
          <span class="w-1.5 h-5 bg-green-500 rounded-full inline-block"></span>
          สถานะการเปิดอ่านของผู้รับ
        </h3>
        <div v-if="document.receivers && document.receivers.length > 0" class="overflow-hidden bg-white border border-gray-200 rounded-lg">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ชื่อผู้รับ</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">สถานะ</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">เวลาที่อ่าน</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="receiver in document.receivers" :key="receiver.id">
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ receiver.name }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm">
                  <span v-if="receiver.pivot.read_at" class="px-2 inline-flex items-center gap-1 text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                    <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.check"/>
                    </svg>
                    เปิดอ่านแล้ว
                  </span>
                  <span v-else class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-600">ยังไม่ได้อ่าน</span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                  {{ receiver.pivot.read_at ? new Date(receiver.pivot.read_at).toLocaleString('th-TH') : '-' }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else class="text-sm text-gray-400 italic">ไม่มีผู้รับที่ระบุ</p>
      </div>

      <!-- เส้นทางเอกสาร (Workflow Timeline) -->
      <div v-if="document.workflow_steps && document.workflow_steps.length > 0" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
          <span class="w-1.5 h-5 bg-purple-500 rounded-full inline-block"></span>
          เส้นทางเอกสาร (Workflow)
        </h3>
        <div class="space-y-6 border-l-2 border-gray-200 ml-3 pl-6">
          <div v-for="step in document.workflow_steps" :key="step.id" class="relative">
            <span class="absolute -left-[33px] flex items-center justify-center w-6 h-6 bg-white border-2 border-purple-500 rounded-full"></span>
            <div>
              <p class="font-medium text-gray-900">{{ step.user?.name || 'ระบบ' }} - <span class="text-purple-600 font-bold uppercase text-xs">{{ step.action }}</span></p>
              <p class="text-sm text-gray-500">{{ step.action_at ? new Date(step.action_at).toLocaleString('th-TH') : 'ยังไม่ดำเนินการ' }}</p>
              <p v-if="step.comment" class="text-sm text-gray-700 mt-1 italic">"{{ step.comment }}"</p>
            </div>
          </div>
        </div>
      </div>

      <!-- การลงนาม (Signatures) -->
      <div v-if="document.signatures && document.signatures.length > 0" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
          <span class="w-1.5 h-5 bg-pink-500 rounded-full inline-block"></span>
          ลายมือชื่ออิเล็กทรอนิกส์
        </h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
          <div v-for="sig in document.signatures" :key="sig.id" class="border rounded-xl p-4 text-center bg-gray-50 flex flex-col items-center">
            <div class="w-24 h-24 mb-3 bg-white p-1 rounded border">
              <img :src="`/api/saraban/verify/${sig.signature_hash}/qrcode`" alt="QR Code" class="w-full h-full object-cover">
            </div>
            <p class="font-bold text-gray-800">{{ sig.signer?.name }}</p>
            <p class="text-xs text-gray-500">{{ sig.signer?.profile?.position || 'ผู้ลงนาม' }}</p>
            <p class="text-xs text-gray-400 mt-2">{{ new Date(sig.signed_at).toLocaleString('th-TH') }}</p>
            <p class="text-[10px] text-gray-300 mt-1 truncate w-full" :title="sig.signature_hash">{{ sig.signature_hash }}</p>
          </div>
        </div>
      </div>
    </div>

    <div v-else class="text-center py-16 text-gray-500">ไม่พบข้อมูลเอกสาร</div>

    <!-- Action Modal -->
    <div v-if="showActionModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md overflow-hidden">
        <div class="p-6">
          <h3 class="text-lg font-bold mb-4">{{ actionModalTitle }}</h3>
          <textarea v-model="actionComment" class="w-full border-gray-300 rounded-lg shadow-sm focus:border-[#800000] focus:ring-[#800000] p-2" rows="3" placeholder="ระบุความคิดเห็น (ถ้ามี)"></textarea>
        </div>
        <div class="px-6 py-4 bg-gray-50 flex justify-end gap-3 rounded-b-xl border-t">
          <button @click="showActionModal = false" class="px-4 py-2 text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">ยกเลิก</button>
          <button @click="submitAction" class="px-4 py-2 text-white bg-[#800000] rounded-lg hover:bg-red-800 transition-colors" :disabled="isSubmittingAction">
            <span v-if="isSubmittingAction">กำลังดำเนินการ...</span>
            <span v-else>ยืนยัน</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Sign Modal -->
    <div v-if="showSignModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4 overflow-y-auto">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-4xl overflow-hidden my-8">
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <h3 class="text-lg font-bold mb-4">ลงนาม/เกษียนเอกสาร</h3>
            
            <div class="flex flex-wrap gap-3 mb-4">
              <label class="flex items-center cursor-pointer">
                <input type="radio" v-model="signMode" value="esignature" class="text-[#800000] focus:ring-[#800000]">
                <span class="ml-2 text-sm">e-Signature (ใช้รหัสผ่าน)</span>
              </label>
              <label class="flex items-center cursor-pointer">
                <input type="radio" v-model="signMode" value="text" class="text-[#800000] focus:ring-[#800000]">
                <span class="ml-2 text-sm">พิมพ์ข้อความ</span>
              </label>
              <label class="flex items-center cursor-pointer">
                <input type="radio" v-model="signMode" value="draw" class="text-[#800000] focus:ring-[#800000]" @change="() => { if(!signaturePad && canvasRef) { signaturePad = new SignaturePad(canvasRef); } }">
                <span class="ml-2 text-sm">วาดลายเซ็น</span>
              </label>
            </div>

            <!-- Mode: e-Signature -->
            <div v-if="signMode === 'esignature'" class="mb-4">
              <p class="text-sm text-gray-600 mb-2">กรุณาระบุรหัสผ่านของคุณเพื่อยืนยันการลงนามด้วย e-Signature</p>
              <input type="password" v-model="signPassword" class="w-full border-gray-300 rounded-lg shadow-sm focus:border-[#800000] focus:ring-[#800000] p-2" placeholder="รหัสผ่านเข้าสู่ระบบ">
            </div>

            <!-- Mode: Text -->
            <div v-if="signMode === 'text'" class="mb-4">
              <p class="text-sm text-gray-600 mb-2">พิมพ์ข้อความสำหรับเกษียนหนังสือ</p>
              <textarea v-model="signText" class="w-full border-gray-300 rounded-lg shadow-sm focus:border-[#800000] focus:ring-[#800000] p-2" rows="3" placeholder="ข้อความ..."></textarea>
            </div>

            <!-- Mode: Draw -->
            <div v-show="signMode === 'draw'" class="mb-4">
              <p class="text-sm text-gray-600 mb-2">วาดลายเซ็นของคุณลงในกรอบด้านล่าง</p>
              <div class="border-2 border-dashed border-gray-300 rounded-lg bg-gray-50 relative">
                <canvas ref="canvasRef" width="400" height="200" class="w-full cursor-crosshair touch-none"></canvas>
                <button type="button" @click="clearSignature" class="absolute top-2 right-2 text-xs px-2 py-1 bg-white border rounded shadow-sm text-gray-600 hover:bg-gray-100">ล้าง</button>
              </div>
            </div>
            
            <div class="bg-gray-50 p-4 rounded-lg border">
              <h4 class="font-semibold text-sm mb-2">กำหนดตำแหน่งลายเซ็นบน PDF</h4>
              <p class="text-xs text-gray-500 mb-3">คลิกหรือลากวางบนพื้นที่จำลองด้านขวา เพื่อกำหนดตำแหน่ง (X, Y)</p>
              <div class="grid grid-cols-2 gap-2 mb-2">
                <div>
                  <label class="text-xs text-gray-600">แกน X (แนวนอน)</label>
                  <input type="number" v-model="signX" class="w-full border-gray-300 rounded p-1 text-sm">
                </div>
                <div>
                  <label class="text-xs text-gray-600">แกน Y (แนวตั้ง)</label>
                  <input type="number" v-model="signY" class="w-full border-gray-300 rounded p-1 text-sm">
                </div>
              </div>
              <div>
                <label class="text-xs text-gray-600">หน้ากระดาษ (Page)</label>
                <input type="number" v-model="signPage" class="w-full border-gray-300 rounded p-1 text-sm">
              </div>
            </div>
            <p v-if="signError" class="text-sm text-red-500 mt-2">{{ signError }}</p>
          </div>
          
          <!-- PDF Mock Preview Area -->
          <div class="bg-gray-200 border-2 border-dashed border-gray-400 rounded-lg relative h-[400px] overflow-hidden" 
               @click="setSignaturePosition" @mousemove="dragSignature" @mouseup="stopDrag">
            <div class="absolute inset-0 flex items-center justify-center pointer-events-none text-gray-400">
              <p>พื้นที่จำลองหน้ากระดาษ PDF (A4)</p>
            </div>
            
            <!-- Draggable Signature Box -->
            <div class="absolute border-2 border-purple-500 bg-white bg-opacity-70 p-1 flex flex-col items-center justify-center cursor-move shadow-lg"
                 :style="{ left: signX + 'px', top: signY + 'px', width: '120px', height: '60px' }"
                 @mousedown.stop="startDrag">
              <span class="text-xs font-bold text-purple-700">ลายเซ็นของคุณ</span>
              <span class="text-[8px] text-gray-500">ลากเพื่อย้าย</span>
            </div>
          </div>
        </div>
        <div class="px-6 py-4 bg-gray-50 flex justify-end gap-3 rounded-b-xl border-t">
          <button @click="showSignModal = false" class="px-4 py-2 text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">ยกเลิก</button>
          <button @click="submitSign" class="px-4 py-2 text-white bg-purple-600 rounded-lg hover:bg-purple-700 transition-colors" :disabled="isSigning">
            <span v-if="isSigning">กำลังดำเนินการ...</span>
            <span v-else>ลงนาม</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Circular Modal -->
    <div v-if="showCircularModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-lg overflow-hidden flex flex-col">
        <div class="px-6 py-4 border-b flex justify-between items-center bg-[#800000] text-white">
          <h3 class="font-bold text-lg flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
            ส่งหนังสือเวียน
          </h3>
          <button @click="showCircularModal = false" class="text-white/70 hover:text-white">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>
        <div class="p-6 overflow-y-auto max-h-[60vh]">
          <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">เลือกผู้รับหนังสือเวียน</label>
            <div class="border rounded-lg max-h-48 overflow-y-auto p-2 bg-gray-50">
              <label v-for="user in userList" :key="user.id" class="flex items-center gap-2 p-2 hover:bg-gray-100 rounded cursor-pointer">
                <input type="checkbox" :value="user.id" v-model="circularUserIds" class="rounded text-[#800000] focus:ring-[#800000]">
                <span>{{ userDisplayName(user) }} ({{ user.department?.name || 'ไม่มีฝ่าย' }})</span>
              </label>
              <div v-if="userList.length === 0" class="text-sm text-gray-500 p-2">ไม่มีข้อมูลผู้ใช้</div>
            </div>
            <p class="text-xs text-gray-500 mt-1">สามารถเลือกได้มากกว่า 1 คน</p>
          </div>
          <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">ข้อความแนบ (ไม่บังคับ)</label>
            <textarea v-model="circularMessage" rows="3" class="w-full border-gray-300 rounded-lg shadow-sm focus:border-[#800000] focus:ring focus:ring-[#800000]/20" placeholder="ระบุข้อความเพื่อแจ้งให้ทราบ..."></textarea>
          </div>
        </div>
        <div class="px-6 py-4 bg-gray-50 flex justify-end gap-3 border-t">
          <button @click="showCircularModal = false" class="px-4 py-2 text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">ยกเลิก</button>
          <button @click="submitCircular" :disabled="isSendingCircular || circularUserIds.length === 0" class="px-4 py-2 text-white bg-[#800000] rounded-lg hover:bg-red-800 transition-colors disabled:opacity-50">
            <span v-if="isSendingCircular">กำลังส่ง...</span>
            <span v-else>ยืนยันการแจ้งเวียน</span>
          </button>
        </div>
      </div>
import { ICONS } from '../../utils/icons'
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import api from '../../axios'
import { useSarabanDocumentStore } from '../../stores/useSarabanDocumentStore'
import { useAuthStore } from '../../stores/auth'
import { sarabanStatusConfig } from '../../utils/statusHelper'
import { userDisplayName } from '../../utils/userName'
import Swal from 'sweetalert2'
import { ThaiDateFormatter } from '../../utils/ThaiDateFormatter'

const route = useRoute()
const store = useSarabanDocumentStore()
const authStore = useAuthStore()
const loading = ref(true)
const document = ref(null)

// Action states
const showActionModal = ref(false)
const currentAction = ref('')
const actionComment = ref('')
const isSubmittingAction = ref(false)

const showSignModal = ref(false)
const signMode = ref('esignature') // esignature, text, draw
const signPassword = ref('')
const signText = ref('')
const signError = ref('')
const isSigning = ref(false)
const signX = ref(150)
const signY = ref(250)
const signPage = ref(1)

const canvasRef = ref(null)
let signaturePad = null

// Drag and drop state
const isDragging = ref(false)
const dragOffsetX = ref(0)
const dragOffsetY = ref(0)

// Circular state
const showCircularModal = ref(false)
const userList = ref([])
const circularUserIds = ref([])
const circularMessage = ref('')
const isSendingCircular = ref(false)

const canEdit = computed(() => {
  return authStore.isSuperAdmin || authStore.isSarabanOfficer || (document.value?.status === 'draft' && document.value?.created_by === authStore.user?.id)
})

const canSubmit = computed(() => {
  return document.value?.status === 'draft' && 
         (authStore.isSuperAdmin || authStore.isSarabanOfficer || document.value?.created_by === authStore.user?.id)
})

/*
 * LEGACY-04 (ปิดงาน) · Decision 2 — กลั่นกรองและลงนามเป็นสิทธิ์ของตัวเอง
 *
 * เดิมเงื่อนไขนี้อิง `isSarabanOfficer` / `isManager` (ซึ่งดูสิทธิ์อนุมัติ **ใบลา**)
 * ทำให้ปุ่มโผล่กับคนที่ backend ปฏิเสธ ตอนนี้ถามสิทธิ์ตัวเดียวกับที่ route บังคับ
 */
const canApprove = computed(() => {
  return ['pending_review', 'pending_approval'].includes(document.value?.status) &&
         authStore.hasPermission('saraban.document.approve')
})

const canSign = computed(() => {
  return document.value?.status === 'approved_pending_signature' &&
         authStore.hasPermission('saraban.document.sign')
})

const canCircular = computed(() => {
  return ['completed', 'signed', 'dispatched'].includes(document.value?.status) && 
         (authStore.isSuperAdmin || authStore.isSarabanOfficer || document.value?.created_by === authStore.user?.id)
})

const actionModalTitle = computed(() => {
  if (currentAction.value === 'submit') return 'ส่งตรวจสอบเอกสาร'
  if (currentAction.value === 'approve') return 'อนุมัติเอกสาร'
  if (currentAction.value === 'reject') return 'ไม่อนุมัติเอกสาร'
  return 'ดำเนินการ'
})

const formatThaiDate = (dateStr) => {
  return ThaiDateFormatter.toThaiShortText(dateStr)
}

/**
 * ดาวน์โหลดผ่าน endpoint ที่มี auth + policy + audit log
 * (แทนการลิงก์ตรงไปที่ /storage/ ซึ่งเปิดสาธารณะ)
 */
const downloadAttachment = async (att) => {
  try {
    const res = await api.get(`/saraban/attachments/${att.id}/download`, { responseType: 'blob' })
    const url = window.URL.createObjectURL(new Blob([res.data]))
    const link = window.document.createElement('a')
    link.href = url
    link.setAttribute('download', att.name)
    window.document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  } catch (err) {
    Swal.fire('ดาวน์โหลดไม่สำเร็จ', 'คุณอาจไม่มีสิทธิ์ดาวน์โหลดไฟล์นี้', 'error')
  }
}

const secrecyLabel = (val) => {
  const map = { normal: 'ปกติ', secret: 'ลับ', top_secret: 'ลับมาก', most_secret: 'ลับที่สุด' }
  return map[val] || val
}

const secrecyClass = (val) => {
  if (val === 'normal') return 'bg-gray-50 text-gray-600 border-gray-200'
  if (val === 'secret') return 'bg-yellow-50 text-yellow-700 border-yellow-200'
  if (val === 'top_secret') return 'bg-orange-50 text-orange-700 border-orange-200'
  return 'bg-red-50 text-red-700 border-red-200'
}

const priorityLabel = (val) => {
  const map = { urgent: 'ด่วน', very_urgent: 'ด่วนมาก', most_urgent: 'ด่วนที่สุด' }
  return map[val] || val
}

const statusConfig = (status) => sarabanStatusConfig(status)

onMounted(async () => {
  try {
    document.value = await store.fetchDocument(route.params.id)
    
    // Mark as read in background if we are a recipient
    try {
      await api.post(`/saraban/documents/${route.params.id}/read`)
    } catch(e) {}
  } catch (err) {
    console.error(err)
  } finally {
    loading.value = false
  }
})

const openActionModal = (action) => {
  currentAction.value = action
  actionComment.value = ''
  showActionModal.value = true
}

const submitAction = async () => {
  isSubmittingAction.value = true
  try {
    if (currentAction.value === 'submit') {
      await store.submitWorkflow(document.value.id, actionComment.value)
    } else if (currentAction.value === 'approve') {
      await store.approveWorkflow(document.value.id, actionComment.value)
    } else if (currentAction.value === 'reject') {
      await store.rejectWorkflow(document.value.id, actionComment.value)
    }
    showActionModal.value = false
    // Refresh document
    document.value = await store.fetchDocument(route.params.id)
    Swal.fire('สำเร็จ', 'ดำเนินการเรียบร้อยแล้ว', 'success')
  } catch (err) {
    Swal.fire('ข้อผิดพลาด', err.response?.data?.message || 'เกิดข้อผิดพลาด', 'error')
  } finally {
    isSubmittingAction.value = false
  }
}

const openSignModal = async () => {
  signMode.value = 'esignature'
  signPassword.value = ''
  signText.value = ''
  signError.value = ''
  showSignModal.value = true
  
  await nextTick()
  if (canvasRef.value) {
    signaturePad = new SignaturePad(canvasRef.value)
  }
}

const clearSignature = () => {
  if (signaturePad) {
    signaturePad.clear()
  }
}

const submitSign = async () => {
  isSigning.value = true
  signError.value = ''
  try {
    let payload = {
      x: signX.value,
      y: signY.value,
      page: signPage.value,
      mode: signMode.value
    }
    
    if (signMode.value === 'esignature') {
      payload.password = signPassword.value
    } else if (signMode.value === 'text') {
      if (!signText.value.trim()) throw new Error('กรุณาระบุข้อความ')
      payload.text = signText.value
    } else if (signMode.value === 'draw') {
      if (!signaturePad || signaturePad.isEmpty()) throw new Error('กรุณาวาดลายเซ็น')
      payload.signature_image = signaturePad.toDataURL()
    }

    await store.signDocument(document.value.id, payload)
    showSignModal.value = false
    Swal.fire('สำเร็จ', 'ลงนามสำเร็จ', 'success')
    // Refresh document
    document.value = await store.fetchDocument(route.params.id)
  } catch (err) {
    signError.value = err.message || err.response?.data?.message || 'รหัสผ่านไม่ถูกต้อง หรือเกิดข้อผิดพลาด'
  } finally {
    isSigning.value = false
  }
}

const setSignaturePosition = (e) => {
  if (isDragging.value) return;
  const rect = e.currentTarget.getBoundingClientRect()
  signX.value = Math.round(e.clientX - rect.left - 60) // Center the 120px box
  signY.value = Math.round(e.clientY - rect.top - 30) // Center the 60px box
}

const startDrag = (e) => {
  isDragging.value = true
  const rect = e.currentTarget.getBoundingClientRect()
  dragOffsetX.value = e.clientX - rect.left
  dragOffsetY.value = e.clientY - rect.top
}

const dragSignature = (e) => {
  if (!isDragging.value) return;
  const rect = e.currentTarget.getBoundingClientRect()
  signX.value = Math.round(e.clientX - rect.left - dragOffsetX.value)
  signY.value = Math.round(e.clientY - rect.top - dragOffsetY.value)
}

const stopDrag = () => {
  isDragging.value = false
}

// Circular Logic
const openCircularModal = async () => {
  circularUserIds.value = []
  circularMessage.value = ''
  showCircularModal.value = true
  
  if (userList.value.length === 0) {
    try {
      const res = await api.get('/saraban/users-list')
      userList.value = res.data.filter(u => u.id !== authStore.user?.id) // Exclude current user
    } catch (err) {
      console.error('Failed to fetch users', err)
      Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถโหลดรายชื่อผู้รับได้ กรุณาตรวจสอบสิทธิ์การใช้งาน', 'error')
    }
  }
}

const submitCircular = async () => {
  if (circularUserIds.value.length === 0) return
  isSendingCircular.value = true
  try {
    await api.post(`/saraban/documents/${document.value.id}/circular`, {
      user_ids: circularUserIds.value,
      message: circularMessage.value
    })
    showCircularModal.value = false
    Swal.fire('สำเร็จ', 'ส่งหนังสือเวียนเรียบร้อยแล้ว', 'success')
  } catch (err) {
    Swal.fire('ข้อผิดพลาด', err.response?.data?.message || 'เกิดข้อผิดพลาดในการส่งหนังสือเวียน', 'error')
  } finally {
    isSendingCircular.value = false
  }
}
</script>
