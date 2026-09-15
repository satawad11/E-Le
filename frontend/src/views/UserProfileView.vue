<template>
  <div>
    <div class="mb-8 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">โปรไฟล์ของฉัน</h1>
        <p class="text-gray-500 mt-1">ดูและแก้ไขข้อมูลส่วนตัวของคุณ</p>
      </div>
      <!-- สิทธิของเจ้าของข้อมูลตาม ม.30 พ.ร.บ.คุ้มครองข้อมูลส่วนบุคคล -->
      <div class="flex flex-wrap gap-2">
        <router-link
          to="/profile/access-history"
          class="rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
          title="ดูว่าใครเปิดดูข้อมูลส่วนบุคคลของท่านบ้าง"
        >
          ประวัติการเข้าถึงข้อมูล
        </router-link>
        <button
          type="button"
          @click="downloadPersonalData"
          class="rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
          title="ดาวน์โหลดสำเนาข้อมูลส่วนบุคคลทั้งหมดที่ระบบเก็บไว้เกี่ยวกับท่าน"
        >
          ขอสำเนาข้อมูลส่วนบุคคล
        </button>
      </div>
    </div>

    <div v-if="loading" class="flex justify-center py-16">
      <div class="animate-spin h-10 w-10 rounded-full border-4 border-[#800000] border-t-transparent"></div>
    </div>

    <div v-else class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 max-w-4xl">
      <form @submit.prevent="submitProfile">
        <div class="flex flex-col md:flex-row gap-8 mb-8">
          <!-- Profile Picture -->
          <div class="w-full md:w-1/3 flex flex-col items-center">
            <div
              class="w-40 h-40 bg-gray-200 rounded-full flex items-center justify-center mb-4 overflow-hidden border-4 border-white shadow cursor-pointer group relative focus-visible:ring-2 focus-visible:ring-[#800000] focus:outline-none"
              role="button"
              tabindex="0"
              aria-label="คลิกหรือกด Enter เพื่อเปลี่ยนรูปภาพประจำตัว"
              @click="$refs.imageInput.click()"
              @keydown.enter.prevent="$refs.imageInput.click()"
              @keydown.space.prevent="$refs.imageInput.click()"
            >
              <img v-if="imagePreview" :src="imagePreview" alt="ตัวอย่างรูปโปรไฟล์ใหม่" class="w-full h-full object-cover" />
              <img v-else-if="form.profile.profile_photo_path" v-secure-src="form.profile.profile_photo_path" alt="รูปโปรไฟล์ปัจจุบัน" class="w-full h-full object-cover" />
              <svg v-else class="w-20 h-20 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="ICONS.user"/>
              </svg>
              
              <div class="absolute inset-0 bg-black/40 flex flex-col items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity" aria-hidden="true">
                <svg class="w-7 h-7 text-white mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.camera"/>
                </svg>
                <span class="text-white text-sm">อัปโหลดรูป</span>
              </div>
            </div>
            <input type="file" ref="imageInput" @change="onImageSelected" accept="image/jpeg, image/png, image/jpg" class="hidden" aria-hidden="true" />
            <div class="text-center">
              <h2 class="text-lg font-bold text-gray-900">{{ form.personnel?.full_name || form.name }}</h2>
              <p class="text-gray-500 text-sm">{{ form.current_assignment?.position || form.position || form.profile?.position || form.role?.name }}</p>
              <p class="text-gray-500 text-sm">{{ form.current_assignment?.org_unit?.name || form.org_unit?.name || form.department?.name }}</p>
            </div>
          </div>

          <!-- Basic Info -->
          <div class="w-full md:w-2/3 grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="md:col-span-2">
              <h3 class="text-lg font-bold text-red-700 mb-2 border-b pb-2">ข้อมูลเบื้องต้น (แก้ไขไม่ได้)</h3>
            </div>
            <div>
              <label class="block text-xs text-gray-700 mb-1">เลขประจำตัวประชาชน</label>
              <input :value="form.profile.id_card_number" type="text" class="w-full px-3 py-2 border border-gray-200 bg-gray-50 rounded text-sm text-gray-500" readonly />
            </div>
            <div>
              <label class="block text-xs text-gray-700 mb-1">อีเมล</label>
              <input :value="form.email" type="text" class="w-full px-3 py-2 border border-gray-200 bg-gray-50 rounded text-sm text-gray-500" readonly />
            </div>
            <div>
              <label class="block text-xs text-gray-700 mb-1">วันที่เริ่มงาน</label>
              <input :value="form.profile.start_date" type="date" class="w-full px-3 py-2 border border-gray-200 bg-gray-50 rounded text-sm text-gray-500" readonly />
            </div>
          </div>
        </div>

        <!-- Editable Info -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="md:col-span-2">
            <h3 class="text-lg font-bold text-red-700 mb-2 border-b pb-2 mt-4">ข้อมูลติดต่อและการสื่อสาร</h3>
          </div>
          <div>
            <label class="block text-xs text-gray-700 mb-1">เบอร์โทรศัพท์มือถือ</label>
            <input v-model="form.phone" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
          </div>
          <div>
            <label class="block text-xs text-gray-700 mb-1">รหัสห้องแชท Telegram (Chat ID)</label>
            <input v-model="form.telegram_chat_id" type="text" placeholder="เช่น 123456789" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
          </div>
          <div>
            <label class="block text-xs text-gray-700 mb-1">LINE User ID / Account</label>
            <input v-model="form.line_user_id" type="text" placeholder="เช่น U1234567890abcdef..." class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
          </div>
          <div>
            <label class="block text-xs text-gray-700 mb-1">ช่องทางการแจ้งเตือนหลัก</label>
            <select v-model="form.notification_channel" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500">
              <option value="both">ทั้งสองช่องทาง (LINE & Telegram)</option>
              <option value="line">LINE เท่านั้น</option>
              <option value="telegram">Telegram เท่านั้น</option>
              <option value="none">ปิดการแจ้งเตือน (None)</option>
            </select>
          </div>
          <div class="md:col-span-2">
            <h3 class="text-lg font-bold text-red-700 mb-2 border-b pb-2 mt-4">ข้อมูลที่อยู่</h3>
          </div>
          <div>
            <label class="block text-xs text-gray-700 mb-1">บ้านเลขที่</label>
            <input v-model="form.profile.house_number" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
          </div>
          <div>
            <label class="block text-xs text-gray-700 mb-1">หมู่ที่</label>
            <input v-model="form.profile.moo" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
          </div>
          <div class="md:col-span-2">
            <label class="block text-xs text-gray-700 mb-1">ถนน / ซอย</label>
            <input v-model="form.profile.road" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
          </div>
          <div>
            <label class="block text-xs text-gray-700 mb-1">ตำบล / แขวง</label>
            <input v-model="form.profile.sub_district" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
          </div>
          <div>
            <label class="block text-xs text-gray-700 mb-1">อำเภอ / เขต</label>
            <input v-model="form.profile.district" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
          </div>
          <div>
            <label class="block text-xs text-gray-700 mb-1">จังหวัด</label>
            <input v-model="form.profile.province" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
          </div>
          <div>
            <label class="block text-xs text-gray-700 mb-1">รหัสไปรษณีย์</label>
            <input v-model="form.profile.zipcode" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
          </div>

          <!-- Signature Section -->
          <div class="md:col-span-2">
            <h3 class="text-lg font-bold text-red-700 mb-2 border-b pb-2 mt-4">ลายเซ็นของฉัน</h3>
          </div>
          <div class="md:col-span-2 flex flex-col md:flex-row gap-4 items-stretch">
            <div class="flex-1 border border-gray-200 p-4 rounded-xl bg-gray-50 flex flex-col items-center justify-center">
              <p class="text-sm font-medium text-gray-700 mb-3">อัปโหลดไฟล์ลายเซ็น (แนะนำ .png โปร่งใส)</p>
              <div class="w-full bg-white border border-gray-200 rounded-lg h-24 flex items-center justify-center mb-3">
                <img v-if="sigPreview" :src="sigPreview" class="max-h-full max-w-full object-contain p-1" />
                <img v-else-if="form.profile.signature_path" v-secure-src="form.profile.signature_path" class="max-h-full max-w-full object-contain p-1" />
                <span v-else class="text-gray-400 text-sm">ยังไม่มีลายเซ็น</span>
              </div>
              <input type="file" ref="sigInput" @change="onSigFileSelected" accept="image/png, image/jpeg, image/jpg" class="text-xs w-full text-gray-500 file:mr-2 file:py-1 file:px-3 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-red-50 file:text-red-700 hover:file:bg-red-100" />
            </div>

            <div class="flex-1 border border-gray-200 p-4 rounded-xl bg-gray-50 flex flex-col items-center">
              <p class="text-sm font-medium text-gray-700 mb-3">หรือวาดลายเซ็นสด</p>
              <canvas ref="sigCanvas" width="300" height="120" class="border border-gray-300 bg-white rounded shadow-inner cursor-crosshair touch-none"
                @mousedown="startDraw" @mousemove="draw" @mouseup="endDraw" @mouseout="endDraw"
                @touchstart="startDrawTouch" @touchmove="drawTouch" @touchend="endDraw"></canvas>
              <div class="mt-3 flex gap-2">
                <button type="button" @click="clearCanvas" class="px-3 py-1.5 text-xs font-semibold bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg transition-colors">ล้างลายเซ็น</button>
                <button type="button" @click="saveCanvas" class="px-3 py-1.5 text-xs font-semibold bg-gray-800 hover:bg-gray-900 text-white rounded-lg transition-colors">ใช้ลายเซ็นที่วาด</button>
              </div>
              <p v-if="canvasSaved" class="flex items-center gap-1 text-xs text-green-600 mt-2 font-medium">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.check"/>
                </svg>
                ภาพวาดพร้อมบันทึก (อย่าลืมกดปุ่มบันทึกด้านล่าง)
              </p>
            </div>
          </div>

          <!-- Delegation Section -->
          <div v-if="authStore.isManager || authStore.isSuperAdmin" class="md:col-span-2">
            <h3 class="text-lg font-bold text-red-700 mb-2 border-b pb-2 mt-4">มอบหมายผู้อนุมัติชั่วคราว</h3>
            <p class="text-xs text-gray-500 mb-4">ในกรณีที่คุณลางานหรือไม่สามารถอนุมัติใบลาได้ คุณสามารถมอบหมายให้ผู้ใช้อื่นอนุมัติแทนชั่วคราวได้</p>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="md:col-span-2">
                <label class="block text-xs text-gray-700 mb-1">เลือกผู้รับมอบหมาย</label>
                <select v-model="delegationForm.delegate_id" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500">
                  <option :value="null">-- ไม่มีการมอบหมาย --</option>
                  <option v-for="u in usersList" :key="u.id" :value="u.id">{{ u.personnel?.full_name || u.name }} ({{ u.current_assignment?.position || u.position || u.profile?.position || u.role?.name }})</option>
                </select>
              </div>
              <div v-if="delegationForm.delegate_id">
                <label class="block text-xs text-gray-700 mb-1">วันที่เริ่ม</label>
                <input v-model="delegationForm.start_date" type="date" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
              </div>
              <div v-if="delegationForm.delegate_id">
                <label class="block text-xs text-gray-700 mb-1">วันที่สิ้นสุด</label>
                <input v-model="delegationForm.end_date" type="date" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
              </div>
            </div>
            
            <div v-if="delegationForm.delegate_id" class="mt-3">
              <button type="button" @click="saveDelegation" :disabled="savingDelegation" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl text-xs transition-all shadow-sm flex items-center gap-2">
                <span v-if="savingDelegation" class="animate-spin h-3 w-3 border-2 border-white border-t-transparent rounded-full"></span>
                บันทึกการมอบหมาย
              </button>
            </div>
          </div>
        </div>

        <div class="mt-8 flex justify-end">
          <button type="submit" :disabled="submitting" class="px-6 py-2.5 bg-red-700 hover:bg-red-800 text-white font-semibold rounded-xl text-sm transition-all shadow-md flex items-center gap-2">
            <span v-if="submitting" class="animate-spin h-4 w-4 border-2 border-white border-t-transparent rounded-full"></span>
            <svg v-else class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.save"/>
            </svg>
            บันทึกการเปลี่ยนแปลง
          </button>
        </div>
      </form>
    </div>

    <!--
      เปลี่ยนรหัสผ่าน (SEC-024)
      แยกออกจากฟอร์มโปรไฟล์โดยตั้งใจ — การแก้เบอร์โทรหรือที่อยู่ต้องไม่ต้องกรอกรหัสผ่าน
      และการเปลี่ยนรหัสผ่านต้องยืนยันตัวตนด้วยรหัสผ่านปัจจุบันเสมอ
    -->
    <section class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 max-w-4xl mt-6">
      <!--
        บัญชีที่ยังใช้รหัสผ่านชั่วคราวถูกพามาที่นี่จากทุกหน้า — ต้องบอกให้ชัดว่าทำไม
        ไม่งั้นผู้ใช้จะงงว่าเข้าหน้าอื่นไม่ได้เพราะอะไร
      -->
      <div v-if="mustChangePassword" class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <strong>กรุณาตั้งรหัสผ่านใหม่ก่อนใช้งานระบบ</strong> —
        รหัสผ่านปัจจุบันเป็นรหัสชั่วคราวที่ผู้ดูแลตั้งให้ ซึ่งผู้ดูแลก็ทราบรหัสนี้เช่นกัน
        เมื่อตั้งรหัสใหม่แล้วจะใช้งานหน้าอื่นได้ตามปกติ
      </div>
      <h2 class="text-lg font-bold text-red-700 mb-1">เปลี่ยนรหัสผ่าน</h2>
      <p class="text-sm text-gray-500 mb-4">
        ต้องกรอกรหัสผ่านปัจจุบันเพื่อยืนยันตัวตน · เมื่อเปลี่ยนสำเร็จ ระบบจะออกจากระบบในอุปกรณ์อื่นทั้งหมด
      </p>
      <form class="grid grid-cols-1 md:grid-cols-2 gap-4" @submit.prevent="submitPasswordChange">
        <div class="md:col-span-2">
          <label for="current-password" class="block text-xs text-gray-700 mb-1">รหัสผ่านปัจจุบัน</label>
          <input
            id="current-password"
            v-model="passwordForm.current_password"
            type="password"
            autocomplete="current-password"
            required
            aria-describedby="password-help"
            class="w-full md:w-1/2 px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500"
          />
        </div>
        <div>
          <label for="new-password" class="block text-xs text-gray-700 mb-1">รหัสผ่านใหม่</label>
          <input
            id="new-password"
            v-model="passwordForm.password"
            type="password"
            autocomplete="new-password"
            minlength="12"
            required
            aria-describedby="password-help"
            class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500"
          />
        </div>
        <div>
          <label for="confirm-password" class="block text-xs text-gray-700 mb-1">ยืนยันรหัสผ่านใหม่</label>
          <input
            id="confirm-password"
            v-model="passwordForm.password_confirmation"
            type="password"
            autocomplete="new-password"
            minlength="12"
            required
            aria-describedby="password-help"
            class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500"
          />
        </div>
        <p id="password-help" class="md:col-span-2 text-xs text-gray-500">
          รหัสผ่านใหม่ต้องมีความยาวอย่างน้อย 12 ตัวอักษร และต้องไม่ซ้ำกับรหัสผ่านเดิม
        </p>
        <div class="md:col-span-2">
          <button
            type="submit"
            :disabled="changingPassword"
            class="px-5 py-2.5 bg-red-700 text-white rounded-lg text-sm font-semibold hover:bg-red-800 disabled:opacity-60 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2"
          >
            {{ changingPassword ? 'กำลังเปลี่ยนรหัสผ่าน…' : 'เปลี่ยนรหัสผ่าน' }}
          </button>
        </div>
      </form>
    </section>

    <!--
      การยืนยันตัวตนสองชั้นด้วยแอป Authenticator (SoT §24)

      ใช้ยืนยันตัวตนซ้ำก่อนลงนามหนังสือราชการแทนการกรอกรหัสผ่าน · ต้องกรอกรหัสผ่าน
      ปัจจุบันเพื่อขอรหัสลับ และยืนยันด้วยรหัส 6 หลักจากแอปจริงจึงจะผูกสำเร็จ
    -->
    <section class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 max-w-4xl mt-6">
      <h2 class="text-lg font-bold text-gray-900 mb-1">แอปยืนยันตัวตน (OTP / MFA)</h2>
      <p class="text-sm text-gray-500 mb-4">
        เมื่อผูกแอป Authenticator (เช่น Google Authenticator, Microsoft Authenticator) แล้ว
        ท่านจะยืนยันตัวตนก่อนลงนามด้วยรหัส 6 หลักจากแอปแทนการกรอกรหัสผ่านได้
      </p>

      <div v-if="!totpSecret" class="space-y-3">
        <div>
          <label for="totp-password" class="block text-xs text-gray-700 mb-1">กรอกรหัสผ่านปัจจุบันเพื่อเริ่มผูกแอป</label>
          <input
            id="totp-password"
            v-model="totpPassword"
            type="password"
            autocomplete="current-password"
            class="w-full md:w-1/2 px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-[#800000]"
          />
        </div>
        <button
          type="button"
          :disabled="totpBusy || !totpPassword"
          class="px-5 py-2.5 bg-[#800000] text-white rounded-lg text-sm font-semibold hover:bg-[#600000] disabled:opacity-60"
          @click="startTotpSetup"
        >
          {{ totpBusy ? 'กำลังสร้างรหัสลับ…' : 'เริ่มผูกแอป Authenticator' }}
        </button>
      </div>

      <div v-else class="space-y-3">
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
          <p class="font-semibold mb-1">เพิ่มบัญชีในแอป Authenticator ด้วยรหัสลับนี้</p>
          <p class="font-mono text-base tracking-widest break-all">{{ totpSecret }}</p>
          <p class="text-xs mt-1 break-all text-amber-700">{{ totpUri }}</p>
        </div>
        <div>
          <label for="totp-confirm-code" class="block text-xs text-gray-700 mb-1">กรอกรหัส 6 หลักจากแอปเพื่อยืนยัน</label>
          <input
            id="totp-confirm-code"
            v-model="totpCode"
            type="text"
            inputmode="numeric"
            maxlength="6"
            placeholder="123456"
            class="w-40 px-3 py-2 border border-gray-300 rounded text-sm tracking-widest text-center focus:outline-none focus:border-[#800000]"
          />
        </div>
        <div class="flex items-center gap-2">
          <button
            type="button"
            :disabled="totpBusy || totpCode.trim().length < 6"
            class="px-5 py-2.5 bg-[#800000] text-white rounded-lg text-sm font-semibold hover:bg-[#600000] disabled:opacity-60"
            @click="confirmTotpSetup"
          >
            {{ totpBusy ? 'กำลังยืนยัน…' : 'ยืนยันและผูกแอป' }}
          </button>
          <button type="button" class="px-4 py-2.5 text-sm text-gray-600 hover:text-gray-900" @click="cancelTotpSetup">
            ยกเลิก
          </button>
        </div>
      </div>
    </section>

    <!--
      การแจ้งเตือนของฉัน (CLEANUP-02)
      Notification Center รองรับ preference รายบุคคลมาตั้งแต่ LEGACY-10 แต่ไม่มีหน้าจอให้ตั้งค่า
    -->
    <section class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
      <header class="px-6 py-4 border-b border-gray-100">
        <h2 class="font-bold text-gray-900">การแจ้งเตือนของฉัน</h2>
        <p class="text-sm text-gray-500 mt-0.5">
          เลือกช่องทางที่ต้องการรับแจ้งเตือนของแต่ละเหตุการณ์ · การแจ้งเตือนในระบบของเหตุการณ์บังคับปิดไม่ได้
        </p>
      </header>

      <!-- INT-14 — เปิด-ปิดการแจ้งเตือนผ่านเบราว์เซอร์บนเครื่องนี้ -->
      <WebPushToggle />

      <div v-if="notificationTypes.length === 0" class="px-6 py-8 text-center text-sm text-gray-500">
        ยังไม่มีประเภทการแจ้งเตือนในระบบ
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm min-w-[560px]">
          <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
              <th class="text-left px-6 py-3 font-semibold">เหตุการณ์</th>
              <th v-for="channel in notificationChannels" :key="channel" class="px-4 py-3 font-semibold text-center">
                {{ channelLabel(channel) }}
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-for="type in notificationTypes" :key="type.id" class="hover:bg-gray-50/60">
              <td class="px-6 py-3">
                <p class="font-medium text-gray-800">{{ type.name }}</p>
                <p class="text-xs text-gray-400 font-mono">{{ type.code }}</p>
              </td>
              <td v-for="channel in notificationChannels" :key="channel" class="px-4 py-3 text-center">
                <input
                  type="checkbox"
                  class="rounded text-[#800000] focus:ring-[#800000]"
                  :aria-label="`รับ${type.name}ทาง${channelLabel(channel)}`"
                  :checked="isPreferred(type, channel)"
                  :disabled="isLockedChannel(type, channel) || savingPreference"
                  @change="savePreference(type, channel, $event.target.checked)"
                >
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, ref, reactive, onMounted } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useAlertStore } from '../stores/alert'
import api from '../axios'
import { ICONS } from '../utils/icons'
import WebPushToggle from '../components/notifications/WebPushToggle.vue'

const authStore = useAuthStore()
const alertStore = useAlertStore()

const loading = ref(true)
const submitting = ref(false)

// รหัสผ่านชั่วคราวที่ผู้ดูแลตั้งให้ — ฝั่งเซิร์ฟเวอร์เป็นผู้ตัดสิน หน้าจอแค่บอกเหตุผล
const mustChangePassword = computed(() => authStore.user?.must_change_password === true)

const downloadPersonalData = async () => {
  try {
    const res = await api.get('/profile/personal-data', { responseType: 'blob' })
    const url = URL.createObjectURL(res.data)
    const a = document.createElement('a')
    a.href = url
    a.download = `personal-data-${new Date().toISOString().slice(0, 10)}.json`
    a.click()
    URL.revokeObjectURL(url)
  } catch {
    alertStore.error('ดาวน์โหลดสำเนาข้อมูลส่วนบุคคลไม่สำเร็จ')
  }
}
const savingDelegation = ref(false)
const usersList = ref([])

const imageInput = ref(null)
const imageFile = ref(null)
const imagePreview = ref(null)

const sigInput = ref(null)
const sigFile = ref(null)
const sigPreview = ref(null)
const sigBase64 = ref(null)

// Canvas references
const sigCanvas = ref(null)
let isDrawing = false
let ctx = null
const canvasSaved = ref(false)

const form = reactive({
  name: '',
  email: '',
  phone: '',
  telegram_chat_id: '',
  line_user_id: '',
  notification_channel: 'both',
  role: null,
  department: null,
  profile: {}
})

/* SEC-024 — ฟอร์มเปลี่ยนรหัสผ่านแยกจากฟอร์มโปรไฟล์ */
const passwordForm = reactive({
  current_password: '',
  password: '',
  password_confirmation: ''
})
const changingPassword = ref(false)

/* SoT §24 — การผูกแอป Authenticator (TOTP) สองขั้น: ขอรหัสลับ แล้วยืนยันด้วยรหัสจากแอป */
const totpPassword = ref('')
const totpCode = ref('')
const totpSecret = ref('')
const totpUri = ref('')
const totpBusy = ref(false)

const startTotpSetup = async () => {
  totpBusy.value = true
  try {
    const { data } = await api.post('/auth/totp-setup', { password: totpPassword.value })
    totpSecret.value = data.secret
    totpUri.value = data.qr_uri
  } catch (e) {
    alertStore.error(e.response?.data?.message || 'เริ่มผูกแอป Authenticator ไม่สำเร็จ')
  } finally {
    totpBusy.value = false
  }
}

const confirmTotpSetup = async () => {
  totpBusy.value = true
  try {
    await api.post('/auth/totp-setup', { password: totpPassword.value, totp_code: totpCode.value.trim() })
    alertStore.success('ผูกแอป Authenticator เรียบร้อยแล้ว')
    cancelTotpSetup()
  } catch (e) {
    alertStore.error(e.response?.data?.message || 'ยืนยันรหัส OTP ไม่สำเร็จ')
  } finally {
    totpBusy.value = false
  }
}

const cancelTotpSetup = () => {
  totpPassword.value = ''
  totpCode.value = ''
  totpSecret.value = ''
  totpUri.value = ''
}

const delegationForm = reactive({
  delegate_id: null,
  start_date: '',
  end_date: '',
  is_active: true
})

/*
 * การแจ้งเตือนรายบุคคล (CLEANUP-02)
 *
 * Notification Center เก็บ preference ต่อเหตุการณ์ต่อช่องทางมาตั้งแต่ LEGACY-10
 * และ `NotificationCenter::emit()` อ่านค่านี้จริงตอนส่ง แต่ไม่เคยมีหน้าจอให้ตั้งค่า
 * ผู้ใช้จึงได้แต่ค่าเริ่มต้นของแต่ละเหตุการณ์เท่านั้น
 */
const notificationChannels = ref([])
const notificationTypes = ref([])
const notificationPreferences = ref([])
const savingPreference = ref(false)

const CHANNEL_LABELS = {
  in_app: 'ในระบบ',
  email: 'อีเมล',
  line_oa: 'LINE',
  telegram: 'Telegram',
  // INT-14 — ช่องทางใหม่ (SoT §16)
  web_push: 'เบราว์เซอร์',
}

const channelLabel = (channel) => CHANNEL_LABELS[channel] || channel

const loadNotificationPreferences = async () => {
  try {
    const { data } = await api.get('/notification-preferences')
    notificationChannels.value = data.channels || []
    notificationTypes.value = data.types || []
    notificationPreferences.value = data.preferences || []
  } catch (e) {
    console.error(e)
  }
}

/*
 * ไม่มีแถว preference = ใช้ค่าเริ่มต้นของเหตุการณ์นั้น จึงต้องอ่านจาก default_channels
 * ไม่ใช่ถือว่าปิด — ไม่อย่างนั้นช่องที่เปิดอยู่จริงจะแสดงเป็นปิดทั้งหมด
 */
const isPreferred = (type, channel) => {
  const preference = notificationPreferences.value.find(
    (p) => p.channel === channel && (p.notification_type_id === type.id || p.notification_type_id === null),
  )
  if (preference) return !!preference.enabled

  return (type.default_channels || []).includes(channel)
}

const isLockedChannel = (type, channel) => type.is_mandatory && channel === 'in_app'

const savePreference = async (type, channel, enabled) => {
  savingPreference.value = true
  try {
    await api.put('/notification-preferences', { type_code: type.code, channel, enabled })
    await loadNotificationPreferences()
    alertStore.success('บันทึกการตั้งค่าการแจ้งเตือนแล้ว')
  } catch (e) {
    alertStore.error(e.response?.data?.message || 'บันทึกการตั้งค่าไม่สำเร็จ')
    await loadNotificationPreferences()
  } finally {
    savingPreference.value = false
  }
}

onMounted(async () => {
  try {
    const res = await api.get('/profile')
    const user = res.data
    form.name = user.name
    form.email = user.email
    form.phone = user.phone || ''
    form.telegram_chat_id = user.telegram_chat_id || ''
    form.line_user_id = user.line_user_id || ''
    form.notification_channel = user.notification_channel || 'both'
    form.role = user.role
    form.department = user.department
    form.profile = user.profile || {}

    // Load active delegation
    const delRes = await api.get('/profile/delegations')
    if (delRes.data && delRes.data.length > 0) {
      // Find active or future delegation
      const del = delRes.data.find(d => d.is_active)
      if (del) {
        delegationForm.delegate_id = del.delegate_id
        delegationForm.start_date = del.start_date.substring(0, 10)
        delegationForm.end_date = del.end_date.substring(0, 10)
        delegationForm.is_active = del.is_active
      }
    }

    /*
     * รายชื่อผู้รับมอบหมาย (SEC-030)
     *
     * เดิมหน้านี้ดึง `/admin/users` ซึ่งเป็นทะเบียนประวัติบุคลากรทั้งหมด
     * ทั้งที่ต้องการเพียง "ชื่อ" ไปใส่ในช่องเลือกผู้ปฏิบัติงานแทน
     * `/users/list` คืนเฉพาะ id · uuid · ชื่อ · หน่วยงาน ซึ่งพอดีกับที่หน้าจอใช้จริง
     */
    if (authStore.isManager || authStore.isSuperAdmin) {
      const uRes = await api.get('/users/list')
      usersList.value = uRes.data.filter(u => u.id !== authStore.user.id)
    }

    await loadNotificationPreferences()

  } catch (e) {
    console.error(e)
    alertStore.error('ไม่สามารถโหลดข้อมูลโปรไฟล์ได้')
  } finally {
    loading.value = false
    // Init canvas context after render
    setTimeout(() => {
      if (sigCanvas.value) {
        ctx = sigCanvas.value.getContext('2d')
        ctx.lineWidth = 2
        ctx.lineCap = 'round'
        ctx.strokeStyle = '#000000'
      }
    }, 100)
  }
})

// Canvas Methods
const getCanvasPos = (evt) => {
  const rect = sigCanvas.value.getBoundingClientRect()
  const x = (evt.clientX || evt.touches?.[0]?.clientX) - rect.left
  const y = (evt.clientY || evt.touches?.[0]?.clientY) - rect.top
  return { x, y }
}

const startDraw = (e) => {
  isDrawing = true
  canvasSaved.value = false
  sigBase64.value = null // reset if they draw new
  const { x, y } = getCanvasPos(e)
  ctx.beginPath()
  ctx.moveTo(x, y)
}

const startDrawTouch = (e) => {
  e.preventDefault()
  startDraw(e)
}

const draw = (e) => {
  if (!isDrawing) return
  const { x, y } = getCanvasPos(e)
  ctx.lineTo(x, y)
  ctx.stroke()
}

const drawTouch = (e) => {
  e.preventDefault()
  draw(e)
}

const endDraw = () => {
  if (isDrawing) {
    ctx.closePath()
    isDrawing = false
  }
}

const clearCanvas = () => {
  if (ctx && sigCanvas.value) {
    ctx.clearRect(0, 0, sigCanvas.value.width, sigCanvas.value.height)
    canvasSaved.value = false
    sigBase64.value = null
  }
}

const saveCanvas = () => {
  if (sigCanvas.value) {
    // Save to base64
    sigBase64.value = sigCanvas.value.toDataURL('image/png')
    sigFile.value = null // clear file if they use canvas
    sigPreview.value = sigBase64.value
    canvasSaved.value = true
  }
}

const onSigFileSelected = (e) => {
  const file = e.target.files[0]
  if (!file) return
  
  sigFile.value = file
  sigBase64.value = null // clear canvas base64
  canvasSaved.value = false
  clearCanvas()
  
  const reader = new FileReader()
  reader.onload = (evt) => {
    sigPreview.value = evt.target.result
  }
  reader.readAsDataURL(file)
}

const onImageSelected = (e) => {
  const file = e.target.files[0]
  if (!file) return
  
  imageFile.value = file
  
  const reader = new FileReader()
  reader.onload = (e) => {
    imagePreview.value = e.target.result
  }
  reader.readAsDataURL(file)
}

const submitProfile = async () => {
  submitting.value = true
  try {
    const formData = new FormData()
    formData.append('phone', form.phone || '')
    formData.append('telegram_chat_id', form.telegram_chat_id || '')
    formData.append('line_user_id', form.line_user_id || '')
    formData.append('notification_channel', form.notification_channel || 'both')
    
    // Add profile data as individual fields (Server expects an array)
    for (const key in form.profile) {
      if (form.profile[key] !== null && form.profile[key] !== undefined) {
        formData.append(`profile[${key}]`, form.profile[key])
      }
    }


    if (imageFile.value) {
      formData.append('profile_photo', imageFile.value)
    }

    if (sigFile.value) {
      formData.append('signature_file', sigFile.value)
    } else if (sigBase64.value) {
      formData.append('signature_base64', sigBase64.value)
    }

    const res = await api.post('/profile', formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })
    
    alertStore.success(res.data.message || 'อัปเดตโปรไฟล์เรียบร้อยแล้ว')
    // Update local auth user state if needed
    authStore.fetchUser()
  } catch (e) {
    console.error(e)
    const errors = e.response?.data?.errors
    if (errors) {
      alertStore.error(Object.values(errors).flat().join(' | '))
    } else {
      alertStore.error(e.response?.data?.message || 'เกิดข้อผิดพลาดในการอัปเดต')
    }
  } finally {
    submitting.value = false
  }
}

/**
 * เปลี่ยนรหัสผ่าน (SEC-024)
 *
 * ตรวจความตรงกันฝั่งหน้าเว็บก่อนเพื่อ UX เท่านั้น — ฝั่ง server ตรวจซ้ำเสมอ
 */
const submitPasswordChange = async () => {
  if (passwordForm.password !== passwordForm.password_confirmation) {
    alertStore.error('การยืนยันรหัสผ่านใหม่ไม่ตรงกับรหัสผ่านใหม่')
    return
  }

  changingPassword.value = true
  try {
    const res = await api.post('/profile/password', {
      current_password: passwordForm.current_password,
      password: passwordForm.password,
      password_confirmation: passwordForm.password_confirmation
    })
    passwordForm.current_password = ''
    passwordForm.password = ''
    passwordForm.password_confirmation = ''
    // การเปลี่ยนรหัสผ่านเพิกถอน token ทุกใบ — รับใบใหม่ของอุปกรณ์นี้มาใช้ต่อทันที
    if (res.data.access_token) {
      authStore.adoptToken(res.data.access_token, res.data.expires_in)
    }
    // ธงบังคับเปลี่ยนรหัสถูกปลดที่ฝั่งเซิร์ฟเวอร์แล้ว — โหลดผู้ใช้ใหม่ให้หน้าจอตรงกัน
    if (mustChangePassword.value) {
      await authStore.fetchUser()
    }
    alertStore.success(res.data.message || 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว')
  } catch (e) {
    const errors = e.response?.data?.errors
    if (errors) {
      alertStore.error(Object.values(errors).flat().join(' | '))
    } else {
      alertStore.error(e.thaiMessage || e.response?.data?.message || 'เปลี่ยนรหัสผ่านไม่สำเร็จ')
    }
  } finally {
    changingPassword.value = false
  }
}

const saveDelegation = async () => {
  savingDelegation.value = true
  try {
    if (!delegationForm.start_date || !delegationForm.end_date) {
      alertStore.error('กรุณาระบุวันที่เริ่มต้นและสิ้นสุด')
      return
    }
    
    await api.post('/profile/delegations', {
      delegate_id: delegationForm.delegate_id,
      start_date: delegationForm.start_date,
      end_date: delegationForm.end_date,
      is_active: true
    })
    
    alertStore.success('บันทึกการมอบหมายผู้อนุมัติเรียบร้อยแล้ว')
  } catch (e) {
    console.error(e)
    alertStore.error(e.response?.data?.message || 'เกิดข้อผิดพลาดในการบันทึกการมอบหมาย')
  } finally {
    savingDelegation.value = false
  }
}
</script>
