<template>
  <div class="max-w-7xl mx-auto space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6"><div><h1 class="text-2xl font-bold text-gray-900">นโยบายสิทธิการลา</h1><p class="text-sm text-gray-500">กำหนดสิทธิ์แยกตามประเภทบุคลากรและช่วงวันที่มีผล</p></div><button @click="openCreate" class="px-4 py-2 rounded-xl bg-[#800000] text-white">เพิ่มนโยบาย</button></div>
    <div v-if="notice" class="mb-4 flex items-start gap-3 px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-sm text-emerald-800"><span class="flex-1">{{notice}}</span><button @click="notice=''" class="text-emerald-600 font-bold">×</button></div>

    <!-- ตั้งค่าทั่วไปของระบบลา ย้ายมาจากหน้าข้อมูลสถานศึกษาเพื่อให้อยู่รวมกับนโยบายสิทธิ -->
    <form @submit.prevent="saveGeneral" class="bg-white rounded-2xl border p-4 space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="font-bold text-gray-900">ตั้งค่าทั่วไปของระบบลา</h2>
          <p class="text-sm text-gray-500">มีผลกับใบลาทุกประเภทและทุกประเภทบุคลากร</p>
        </div>
        <button :disabled="isSavingGeneral" class="px-4 py-2 rounded-xl bg-[#800000] text-white text-sm disabled:opacity-50">
          {{ isSavingGeneral ? 'กำลังบันทึก...' : 'บันทึกการตั้งค่า' }}
        </button>
      </div>
      <div class="grid sm:grid-cols-2 gap-4">
        <label class="text-sm text-gray-700">
          ยกเลิกใบลาได้ภายใน (วัน)
          <input v-model.number="general.cancel_allowed_days" type="number" min="0" max="365" class="input">
          <span class="text-xs text-gray-500 mt-1 block">นับจากวันเริ่มลา หากเกินกำหนดผู้ใช้จะยื่นยกเลิกเองไม่ได้</span>
        </label>
        <label class="text-sm text-gray-700">
          เก็บหลักฐานใบลา (วัน)
          <input v-model.number="general.leave_evidence_retention_days" type="number" min="1" max="36500" class="input">
          <span class="text-xs text-gray-500 mt-1 block">ระยะเวลาเก็บไฟล์หลักฐานก่อนถูกลบตามนโยบายคุ้มครองข้อมูลส่วนบุคคล</span>
        </label>
      </div>
    </form>
    <div class="bg-white rounded-2xl border overflow-hidden"><div class="p-4 border-b"><select v-model="filterType" @change="load" class="border rounded-lg px-3 py-2"><option value="">ทุกประเภทบุคลากร</option><option v-for="p in personnel" :key="p.id" :value="p.id">{{p.name}}</option></select></div>
      <div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-gray-50"><tr><th class="p-3 text-left">บุคลากร</th><th class="p-3 text-left">ประเภทลา</th><th class="p-3">โควตา</th><th class="p-3">รับเงินเดือน</th><th class="p-3">หลักฐานเมื่อ</th><th class="p-3">ช่วงมีผล</th><th class="p-3"></th></tr></thead><tbody><tr v-for="p in policies" :key="p.id" class="border-t"><td class="p-3">{{p.personnel_type?.name}}</td><td class="p-3">{{p.leave_type?.name}}</td><td class="p-3 text-center">{{p.annual_quota ?? 'ไม่จำกัด'}}</td><td class="p-3 text-center">{{p.max_days_with_pay ?? 'ทุกวัน'}}</td><td class="p-3 text-center">{{p.attachment_required_after_days ? `${p.attachment_required_after_days} วัน` : '-'}}</td><td class="p-3 text-center">{{date(p.effective_from)}} – {{p.effective_to?date(p.effective_to):'ไม่มีกำหนด'}}</td><td class="p-3"><button @click="edit(p)" class="text-[#800000] font-semibold">แก้ไข</button></td></tr></tbody></table></div></div>
    <div v-if="show" class="fixed inset-0 bg-black/40 z-50 grid place-items-center p-4"><form @submit.prevent="save" class="bg-white rounded-2xl p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto"><h2 class="text-xl font-bold mb-4">{{form.id?'แก้ไข':'เพิ่ม'}}นโยบาย</h2><div class="grid sm:grid-cols-2 gap-4">
      <label>ประเภทบุคลากร<select v-model="form.personnel_type_id" required class="input"><option v-for="p in personnel" :value="p.id">{{p.name}}</option></select></label><label>ประเภทลา<select v-model="form.leave_type_id" required class="input"><option v-for="t in leaveTypes" :value="t.id">{{t.name}}</option></select></label>
      <label>มีผลตั้งแต่<input v-model="form.effective_from" type="date" required class="input"></label><label>ถึงวันที่<input v-model="form.effective_to" type="date" class="input"></label><label>โควตาต่อปี (รวม)<input v-model.number="form.annual_quota" type="number" step="0.5" class="input"></label><label>ในจำนวนนี้ ได้รับเงินเดือน (วัน)<input v-model.number="form.max_days_with_pay" type="number" step="0.5" placeholder="เว้นว่าง = ได้รับทุกวัน" class="input"></label><label>ต้องแนบหลักฐานตั้งแต่<input v-model.number="form.attachment_required_after_days" type="number" step="0.5" class="input"></label>
      <label>ฐานรอบสิทธิ์<select v-model="form.period_basis" class="input"><option value="fiscal_year">ปีงบประมาณ</option><option value="calendar_year">ปีปฏิทิน</option></select></label><label>ยื่นล่วงหน้า (วัน)<input v-model.number="form.notice_period_days" type="number" class="input"></label>
    </div><div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-3"><p class="text-sm font-semibold mb-2">เงื่อนไขเฉพาะตามระเบียบ</p><div class="grid sm:grid-cols-2 gap-3"><label class="text-sm flex items-center gap-2"><input v-model="rules.once_in_career" type="checkbox" class="rounded text-[#800000] focus:ring-[#800000] h-4 w-4"> ใช้สิทธิ์ได้ครั้งเดียวตลอดอายุราชการ</label><label class="text-sm">จำกัดเพศผู้มีสิทธิ์<select v-model="rules.gender" class="input"><option value="">ไม่จำกัด</option><option value="female">เฉพาะเพศหญิง</option><option value="male">เฉพาะเพศชาย</option></select></label><label class="text-sm">จำนวนครั้งสูงสุดต่อปี<input v-model.number="rules.max_times_per_year" type="number" min="1" placeholder="ไม่จำกัด" class="input"></label><label class="text-sm">ต้องเว้นระยะจากครั้งก่อน (วัน)<input v-model.number="rules.min_days_between" type="number" min="1" placeholder="ไม่กำหนด" class="input"></label></div></div><div class="grid sm:grid-cols-2 gap-2 mt-4"><label><input v-model="form.allow_past_dates" type="checkbox" class="rounded text-[#800000] focus:ring-[#800000] h-4 w-4"> อนุญาตย้อนหลัง</label><label><input v-model="form.exclude_holidays" type="checkbox" class="rounded text-[#800000] focus:ring-[#800000] h-4 w-4"> ไม่นับวันหยุด</label><label><input v-model="form.allow_half_day" type="checkbox" class="rounded text-[#800000] focus:ring-[#800000] h-4 w-4"> ลาครึ่งวัน</label><label><input v-model="form.requires_substitute" type="checkbox" class="rounded text-[#800000] focus:ring-[#800000] h-4 w-4"> ต้องมีผู้ปฏิบัติงานแทน</label><label><input v-model="form.is_carry_forward" type="checkbox" class="rounded text-[#800000] focus:ring-[#800000] h-4 w-4"> สะสมวันลา</label><label><input v-model="form.is_active" type="checkbox" class="rounded text-[#800000] focus:ring-[#800000] h-4 w-4"> เปิดใช้งาน</label></div><div class="flex justify-end gap-2 mt-6"><button type="button" @click="show=false" :disabled="isSaving" class="px-4 py-2">ยกเลิก</button><button :disabled="isSaving" class="px-5 py-2 rounded-xl bg-[#800000] text-white disabled:opacity-50 flex items-center gap-2"><span v-if="isSaving" class="animate-spin rounded-full h-4 w-4 border-b-2 border-white"></span>{{ isSaving ? 'กำลังบันทึก...' : 'บันทึก' }}</button></div></form></div>
  </div>
</template>
<script setup>
import {ref,onMounted} from 'vue';import api from '../../axios';import {useAlertStore} from '../../stores/alert';
import { ThaiDateFormatter } from '../../utils/ThaiDateFormatter';
const personnel=ref([]),policies=ref([]),leaveTypes=ref([]),filterType=ref(''),show=ref(false),notice=ref(''),isSaving=ref(false);
const alertStore=useAlertStore();
// eligibility_rules เก็บเป็น JSON ที่ใส่เฉพาะข้อที่บังคับ จึงต้องแปลงกลับไปกลับมากับฟอร์ม
const blankRules=()=>({once_in_career:false,gender:'',max_times_per_year:null,min_days_between:null});const rules=ref(blankRules());
const rulesFrom=v=>({...blankRules(),...(v||{}),gender:v?.gender||'',once_in_career:!!v?.once_in_career});
const rulesToPayload=()=>{const r={};if(rules.value.once_in_career)r.once_in_career=true;if(rules.value.gender)r.gender=rules.value.gender;if(rules.value.max_times_per_year)r.max_times_per_year=rules.value.max_times_per_year;if(rules.value.min_days_between)r.min_days_between=rules.value.min_days_between;return Object.keys(r).length?r:null};
const blank=()=>({personnel_type_id:'',leave_type_id:'',effective_from:new Date().toISOString().slice(0,10),effective_to:'',annual_quota:null,max_days_with_pay:null,attachment_required_after_days:null,minimum_service_days:0,notice_period_days:0,allow_past_dates:false,exclude_holidays:true,allow_half_day:true,requires_substitute:false,is_carry_forward:false,max_carry_forward:0,period_basis:'fiscal_year',is_active:true});const form=ref(blank());
const load=async()=>{const [a,b,c]=await Promise.all([api.get('/personnel-types'),api.get('/admin/leave-policies',{params:{personnel_type_id:filterType.value||undefined}}),api.get('/leaves/types')]);personnel.value=a.data;policies.value=b.data;leaveTypes.value=c.data};const openCreate=()=>{form.value=blank();rules.value=blankRules();show.value=true};const edit=p=>{form.value={...blank(),...p,effective_from:p.effective_from?.slice(0,10),effective_to:p.effective_to?.slice(0,10)||''};rules.value=rulesFrom(p.eligibility_rules);show.value=true};
const save=async()=>{
  isSaving.value = true;
  try {
    const payload={...form.value,effective_to:form.value.effective_to||null,eligibility_rules:rulesToPayload()};
    const res=form.value.id?await api.put(`/admin/leave-policies/${form.value.id}`,payload):await api.post('/admin/leave-policies',payload);
    const sync=res.data?.sync;
    notice.value=sync?`บันทึกแล้ว — อัปเดตสิทธิ์ผู้ใช้ ${sync.synced} คน (ปี ${sync.year+543})${sync.over_quota?` • เตือน: มี ${sync.over_quota} คนใช้วันลาเกินโควตาใหม่แล้ว`:''}`:'บันทึกแล้ว';
    alertStore.success('บันทึกนโยบายสิทธิการลาเรียบร้อยแล้ว');
    show.value=false;
    await load();
  } catch (error) {
    const errors = error.response?.data?.errors;
    if (errors) {
      alertStore.error(Object.values(errors).flat().join(' | '));
    } else {
      alertStore.error(error.response?.data?.message || 'เกิดข้อผิดพลาดในการบันทึกนโยบาย');
    }
  } finally {
    isSaving.value = false;
  }
};
// ค่าตั้งทั่วไปของระบบลา เก็บอยู่ใน system settings จึงเรียกคนละ endpoint กับตารางนโยบาย
const general=ref({cancel_allowed_days:5,leave_evidence_retention_days:2555});const isSavingGeneral=ref(false);
const loadGeneral=async()=>{
  try{
    const {data}=await api.get('/admin/settings');
    for(const key of Object.keys(general.value)){
      if(Object.prototype.hasOwnProperty.call(data,key)&&data[key]!==null&&data[key]!=='')general.value[key]=Number(data[key]);
    }
  }catch(error){
    alertStore.error(error.response?.data?.message||'โหลดการตั้งค่าทั่วไปของระบบลาไม่สำเร็จ');
  }
};
const saveGeneral=async()=>{
  isSavingGeneral.value=true;
  try{
    await api.post('/admin/settings',general.value);
    alertStore.success('บันทึกการตั้งค่าทั่วไปของระบบลาเรียบร้อยแล้ว');
  }catch(error){
    const errors=error.response?.data?.errors;
    alertStore.error(errors?Object.values(errors).flat().join(' | '):error.response?.data?.message||'บันทึกการตั้งค่าไม่สำเร็จ');
  }finally{
    isSavingGeneral.value=false;
  }
};
const date=v=>ThaiDateFormatter.toThaiShortText(v);onMounted(()=>{load();loadGeneral()});
</script>
<style scoped>.input{display:block;width:100%;margin-top:.35rem;border:1px solid #d1d5db;border-radius:.6rem;padding:.6rem}</style>
