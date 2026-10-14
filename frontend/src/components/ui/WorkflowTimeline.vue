<template>
  <div class="workflow-timeline font-sans">
    <!-- 1. Rejection / Cancellation / Return Alert Banner -->
    <div v-if="rejectionInfo" class="mb-4 p-4 rounded-xl bg-red-50 border border-red-200 text-red-900 flex items-start gap-3 shadow-sm animate-fade-in">
      <div class="p-2 bg-red-100 rounded-lg text-red-600 flex-shrink-0 mt-0.5" aria-hidden="true">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
      </div>
      <div class="flex-1 min-w-0">
        <div class="flex items-center justify-between gap-2 flex-wrap mb-1">
          <h4 class="font-bold text-sm text-red-800 flex items-center gap-1.5">
            <span>{{ rejectionInfo.title }}</span>
          </h4>
          <span v-if="rejectionInfo.date" class="text-xs text-red-600 font-medium">
            {{ formatThaiTime(rejectionInfo.date) }}
          </span>
        </div>
        <p class="text-xs text-red-700 font-medium mb-1">
          <span class="text-red-500">โดย:</span> {{ rejectionInfo.by }}
        </p>
        <p class="text-xs text-red-800 bg-white/80 p-2.5 rounded-lg border border-red-200 mt-1.5 italic">
          "{{ rejectionInfo.reason || 'ไม่ได้ระบุเหตุผล' }}"
        </p>
      </div>
    </div>

    <!-- 2. Next Step / Current Status Summary Badge Bar -->
    <div class="mb-4 px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 flex flex-wrap items-center justify-between gap-3 text-xs">
      <div class="flex items-center gap-2">
        <span class="font-semibold text-slate-500">สถานะปัจจุบัน:</span>
        <span :class="['px-2.5 py-1 rounded-full font-bold inline-flex items-center gap-1', currentStatusBadgeClass]">
          <span class="w-1.5 h-1.5 rounded-full" :class="currentStatusDotClass"></span>
          {{ currentStatusLabel }}
        </span>
      </div>
      <div v-if="nextStepInfo" class="flex items-center gap-2 text-slate-600">
        <span class="font-semibold text-slate-400">ขั้นตอนถัดไป:</span>
        <span class="font-medium text-slate-800 bg-white px-2 py-0.5 rounded border border-slate-200">
          {{ nextStepInfo }}
        </span>
      </div>
    </div>

    <!-- 3. Dynamic Workflow Steps Timeline (Mobile-First: Vertical on mobile) -->
    <div class="relative pl-6 sm:pl-8 space-y-6 before:absolute before:left-3 sm:before:left-4 before:top-3 before:bottom-3 before:w-0.5 before:bg-slate-200">
      <div
        v-for="(step, idx) in workflowSteps"
        :key="idx"
        class="relative flex items-start gap-3 sm:gap-4 group"
      >
        <!-- Step Icon Node -->
        <div
          class="absolute -left-6 sm:-left-8 w-6 h-6 sm:w-8 sm:h-8 rounded-full flex items-center justify-center text-xs font-bold transition-all shadow-sm z-10"
          :class="getStepNodeClass(step)"
        >
          <!-- Completed Icon -->
          <svg v-if="step.state === 'completed'" class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
          </svg>
          <!-- Rejected Icon -->
          <svg v-else-if="step.state === 'rejected'" class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
          </svg>
          <!-- Active Current Pulsating Dot -->
          <span v-else-if="step.state === 'current'" class="w-2.5 h-2.5 rounded-full bg-white animate-ping"></span>
          <!-- Step Order Number -->
          <span v-else class="text-xs">{{ idx + 1 }}</span>
        </div>

        <!-- Step Content Card -->
        <div class="flex-1 bg-white p-3.5 rounded-xl border transition-all text-xs" :class="getStepCardClass(step)">
          <div class="flex flex-wrap items-center justify-between gap-1.5 mb-1">
            <div class="flex items-center gap-2 flex-wrap">
              <h5 class="font-bold text-sm" :class="step.state === 'current' ? 'text-[#800000]' : 'text-slate-800'">
                {{ step.title }}
              </h5>
              <span :class="['px-2 py-0.5 rounded-full text-[11px] font-semibold', getStepBadgeClass(step)]">
                {{ step.statusText }}
              </span>
              <span v-if="step.isDelegated" class="px-2 py-0.5 rounded-full text-[10px] font-medium bg-purple-50 text-purple-700 border border-purple-200">
                ปฏิบัติหน้าที่แทน
              </span>
              <span v-if="step.isSelfApproval" class="px-2 py-0.5 rounded-full text-[10px] font-medium bg-amber-50 text-amber-800 border border-amber-200">
                อนุมัติด้วยตนเอง
              </span>
            </div>
            <span v-if="step.actedAt" class="text-slate-400 text-[11px]">
              {{ formatThaiTime(step.actedAt) }}
            </span>
          </div>

          <!-- Actor & Position -->
          <div v-if="step.actorName" class="text-slate-600 text-xs mt-1 flex items-center gap-1.5 flex-wrap">
            <svg class="w-3.5 h-3.5 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
            </svg>
            <span class="font-medium text-slate-800">{{ step.actorName }}</span>
            <span v-if="step.actorPosition" class="text-slate-400">({{ step.actorPosition }})</span>
          </div>

          <!-- Comment / Reason quote -->
          <div v-if="step.comment" class="mt-2 p-2.5 rounded-lg bg-slate-50 border border-slate-150 text-slate-700 italic text-[11px]">
            <span class="font-semibold not-italic text-slate-500">ความเห็น:</span> "{{ step.comment }}"
          </div>
        </div>
      </div>
    </div>

    <!-- 4. Expandable Action & Audit Logs -->
    <div v-if="showLogs && hasLogs" class="mt-5 pt-3 border-t border-slate-100">
      <button
        type="button"
        @click="isLogsExpanded = !isLogsExpanded"
        class="text-xs text-slate-500 hover:text-slate-800 font-semibold flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#800000] rounded p-1"
        :aria-expanded="isLogsExpanded"
      >
        <svg
          class="w-3.5 h-3.5 transition-transform duration-200"
          :class="isLogsExpanded ? 'rotate-90' : ''"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
          aria-hidden="true"
        >
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
        <span>ประวัติการทำรายการอย่างละเอียด (Audit Trail {{ leave.action_logs?.length || 0 }} รายการ)</span>
      </button>

      <transition name="slide-down">
        <div v-if="isLogsExpanded" class="mt-3 space-y-2 pl-4 border-l-2 border-slate-200 text-xs">
          <div
            v-for="log in leave.action_logs"
            :key="log.id"
            class="p-2.5 rounded-lg bg-slate-50 border border-slate-100 text-slate-600 flex flex-col gap-1"
          >
            <div class="flex items-center justify-between gap-2 flex-wrap">
              <span class="font-bold text-slate-800">{{ log.action }}</span>
              <span class="text-slate-400 text-[11px]">{{ formatThaiTime(log.created_at) }}</span>
            </div>
            <div class="text-[11px] text-slate-500 flex items-center gap-1.5">
              <span>ผู้ดำเนินการ:</span>
              <span class="font-medium text-slate-700">{{ log.user?.name || 'ระบบอัตโนมัติ' }}</span>
            </div>
            <p v-if="log.details?.comment || log.details?.reason" class="text-[11px] text-slate-600 italic bg-white p-1.5 rounded border border-slate-100">
              "{{ log.details.comment || log.details.reason }}"
            </p>
          </div>
        </div>
      </transition>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { ThaiDateFormatter } from '../../utils/ThaiDateFormatter';

const props = defineProps({
  leave: {
    type: Object,
    required: true
  },
  showLogs: {
    type: Boolean,
    default: true
  }
});

const isLogsExpanded = ref(false);

function formatThaiTime(dateStr) {
  if (!dateStr) return '';
  return ThaiDateFormatter.toThaiSlash(dateStr);
}

const hasLogs = computed(() => {
  return props.leave?.action_logs && props.leave.action_logs.length > 0;
});

const rejectionInfo = computed(() => {
  const l = props.leave;
  if (!l) return null;

  if (l.status === 'Rejected') {
    const lastRejectedApproval = [...(l.approvals || [])].reverse().find(a => a.status === 'Rejected');
    return {
      title: 'ใบลาไม่ได้รับการอนุมัติ (Rejected)',
      by: lastRejectedApproval?.approver_name || lastRejectedApproval?.approver?.name || 'ผู้มีอำนาจพิจารณา',
      reason: lastRejectedApproval?.comment || l.meta_data?.rejection_reason || l.void_reason,
      date: lastRejectedApproval?.acted_at || lastRejectedApproval?.approved_at || l.updated_at
    };
  }

  if (l.cancellation_status === 'Rejected') {
    return {
      title: 'คำขอยกเลิกใบลาถูกปฏิเสธ',
      by: 'ผู้มีอำนาจพิจารณา',
      reason: l.cancellation_reason,
      date: l.cancellation_date
    };
  }

  if (l.status === 'Cancelled' || l.cancellation_status === 'Approved') {
    return {
      title: 'ใบลานี้ถูกยกเลิกแล้ว (Cancelled)',
      by: l.cancellation_reason ? 'ผู้ขอยกเลิก' : 'ระบบ',
      reason: l.cancellation_reason || 'ยกเลิกตามคำขอของผู้ยื่น',
      date: l.cancellation_date || l.updated_at
    };
  }

  return null;
});

const currentStatusLabel = computed(() => {
  const l = props.leave;
  if (!l) return '-';
  if (l.cancellation_status === 'Pending') return 'รออนุมัติยกเลิก';
  if (l.cancellation_status === 'Approved' || l.status === 'Cancelled') return 'ยกเลิกแล้ว';
  if (l.status === 'Approved') return 'อนุมัติเรียบร้อยแล้ว';
  if (l.status === 'Rejected') return 'ไม่อนุมัติ';
  
  if (l.substitute_id && l.substitute_status === 'Pending') return 'รอผู้ปฏิบัติหน้าที่แทนรับทราบ';

  // Dynamic status based on snapshot approvals
  const approvals = l.approvals || [];
  const currentStep = approvals.find(a => a.step_order === l.approval_level);
  if (currentStep) {
    return `รอ${currentStep.step_name}พิจารณา`;
  }

  if (l.approval_level === 1 || !l.approval_level) return 'รอหัวหน้างาน/แผนกพิจารณา';
  if (l.approval_level === 2) return 'รอฝ่ายบุคคล/รองผู้อำนวยการ';
  if (l.approval_level === 3) return 'รอผู้อำนวยการลงนามอนุมัติ';
  return 'รอพิจารณา';
});

const currentStatusBadgeClass = computed(() => {
  const l = props.leave;
  if (!l) return 'bg-slate-100 text-slate-700';
  if (l.status === 'Approved') return 'bg-emerald-50 text-emerald-700 border border-emerald-200';
  if (l.status === 'Rejected') return 'bg-rose-50 text-rose-700 border border-rose-200';
  if (l.status === 'Cancelled' || l.cancellation_status === 'Approved') return 'bg-slate-100 text-slate-700 border border-slate-200';
  return 'bg-amber-50 text-amber-800 border border-amber-200';
});

const currentStatusDotClass = computed(() => {
  const l = props.leave;
  if (!l) return 'bg-slate-400';
  if (l.status === 'Approved') return 'bg-emerald-500';
  if (l.status === 'Rejected') return 'bg-rose-500';
  if (l.status === 'Cancelled') return 'bg-slate-400';
  return 'bg-amber-500 animate-pulse';
});

const nextStepInfo = computed(() => {
  const l = props.leave;
  if (!l || l.status === 'Approved' || l.status === 'Rejected' || l.status === 'Cancelled') {
    return null;
  }
  if (l.substitute_id && l.substitute_status === 'Pending') {
    return 'รอคุณ ' + (l.substitute?.name || 'ผู้ปฏิบัติหน้าที่แทน') + ' กดยืนยันรับทราบ';
  }

  const approvals = l.approvals || [];
  const currentStep = approvals.find(a => a.step_order === l.approval_level);
  if (currentStep) {
    return `${currentStep.step_name} ตรวจสอบและพิจารณาตามสายงาน`;
  }

  if (l.approval_level === 1 || !l.approval_level) {
    return 'หัวหน้ากลุ่มงาน/หัวหน้าแผนก ตรวจสอบและลงความเห็น';
  }
  if (l.approval_level === 2) {
    return 'งานบุคลากร / รองผู้อำนวยการ ตรวจสอบความถูกต้อง';
  }
  if (l.approval_level === 3) {
    return 'ผู้อำนวยการสถานศึกษา ลงนามอนุมัติขั้นสุดท้าย';
  }
  return 'เจ้าหน้าที่ดำเนินการตามลำดับ';
});

const workflowSteps = computed(() => {
  const l = props.leave;
  if (!l) return [];
  const steps = [];

  // Step 0: Submitter
  steps.push({
    title: 'ยื่นคำขอลา',
    actorName: l.user?.personnel?.full_name || l.user?.name || 'ผู้ยื่นคำขอ',
    actorPosition: l.user?.position || l.user?.profile?.position || 'บุคลากร',
    actedAt: l.submitted_at || l.created_at,
    state: 'completed',
    statusText: 'ส่งคำขอสำเร็จ',
    comment: null,
    isDelegated: false
  });

  // Step 1 (optional): Substitute
  if (l.substitute_id || l.substitute) {
    let subState = 'pending';
    let subText = 'รอรับทราบ';
    if (l.substitute_status === 'Accepted') {
      subState = 'completed';
      subText = 'รับทราบแล้ว';
    } else if (l.substitute_status === 'Rejected') {
      subState = 'rejected';
      subText = 'ปฏิเสธ';
    } else if (l.status === 'Rejected' || l.status === 'Cancelled') {
      subState = 'cancelled';
      subText = 'สิ้นสุด';
    } else {
      subState = 'current';
    }

    steps.push({
      title: 'ผู้ปฏิบัติหน้าที่แทน',
      actorName: l.substitute?.personnel?.full_name || l.substitute?.name || 'ผู้ปฏิบัติหน้าที่แทน',
      actorPosition: l.substitute?.position || l.substitute?.profile?.position || '',
      actedAt: null,
      state: subState,
      statusText: subText,
      comment: l.substitute_reason || null,
      isDelegated: false
    });
  }

  // Dynamic Approval Steps from Snapshot
  const approvals = [...(l.approvals || [])].sort((a, b) => (a.step_order || 0) - (b.step_order || 0));

  if (approvals.length > 0) {
    approvals.forEach((app) => {
      let state = 'pending';
      let statusText = 'รอตามลำดับ';

      if (app.status === 'Approved') {
        state = 'completed';
        statusText = 'อนุมัติเรียบร้อย';
      } else if (app.status === 'Rejected') {
        state = 'rejected';
        statusText = 'ไม่อนุมัติ';
      } else if (l.status === 'Approved') {
        state = 'completed';
        statusText = 'ผ่านการพิจารณา';
      } else if (l.status === 'Rejected' || l.status === 'Cancelled') {
        state = 'cancelled';
        statusText = 'ยุติกระบวนการ';
      } else if (l.status === 'Pending' && (l.approval_level === app.step_order || (!l.approval_level && app.step_order === 1))) {
        state = 'current';
        statusText = 'กำลังพิจารณา';
      }

      steps.push({
        title: app.step_name || `ขั้นตอนที่ ${app.step_order}`,
        actorName: app.approver_name || app.approver?.personnel?.full_name || app.approver?.name || null,
        actorPosition: app.approver_position || (app.required_role?.name ? app.required_role.name : null),
        actedAt: app.acted_at || app.approved_at || null,
        state,
        statusText,
        comment: app.comment || null,
        isDelegated: Boolean(app.delegated_from_user_id),
        isSelfApproval: Boolean(app.is_self_approval)
      });
    });
  } else {
    // Fallback if no snapshot approvals present
    const defaultTitles = ['หัวหน้าแผนก / หัวหน้างาน', 'ฝ่ายบุคคล / รองผู้อำนวยการ', 'ผู้อำนวยการสถานศึกษา'];
    defaultTitles.forEach((t, i) => {
      const stepOrder = i + 1;
      let state = 'pending';
      let statusText = 'รอพิจารณา';

      if (l.status === 'Approved') {
        state = 'completed';
        statusText = 'ผ่านการพิจารณา';
      } else if (l.status === 'Rejected') {
        state = (l.approval_level === stepOrder) ? 'rejected' : 'cancelled';
        statusText = (l.approval_level === stepOrder) ? 'ไม่อนุมัติ' : 'ยุติ';
      } else if (l.approval_level === stepOrder) {
        state = 'current';
        statusText = 'กำลังพิจารณา';
      }

      steps.push({
        title: t,
        actorName: null,
        actorPosition: null,
        actedAt: null,
        state,
        statusText,
        comment: null,
        isDelegated: false
      });
    });
  }

  return steps;
});

function getStepNodeClass(step) {
  if (step.state === 'completed') return 'bg-emerald-600 text-white ring-4 ring-emerald-100';
  if (step.state === 'rejected') return 'bg-rose-600 text-white ring-4 ring-rose-100';
  if (step.state === 'current') return 'bg-[#800000] text-white ring-4 ring-amber-100 animate-pulse';
  if (step.state === 'cancelled') return 'bg-slate-300 text-slate-600 ring-2 ring-slate-100';
  return 'bg-slate-200 text-slate-600 border border-slate-300';
}

function getStepCardClass(step) {
  if (step.state === 'completed') return 'border-emerald-150 bg-emerald-50/20';
  if (step.state === 'rejected') return 'border-rose-200 bg-rose-50/30';
  if (step.state === 'current') return 'border-[#800000]/30 bg-amber-50/20 ring-1 ring-[#800000]/20 shadow-sm';
  if (step.state === 'cancelled') return 'border-slate-200 bg-slate-50/50 opacity-60';
  return 'border-slate-200 bg-white opacity-80';
}

function getStepBadgeClass(step) {
  if (step.state === 'completed') return 'bg-emerald-100 text-emerald-800';
  if (step.state === 'rejected') return 'bg-rose-100 text-rose-800';
  if (step.state === 'current') return 'bg-amber-100 text-amber-800 animate-pulse';
  return 'bg-slate-100 text-slate-600';
}
</script>

<style scoped>
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(-4px); }
  to { opacity: 1; transform: translateY(0); }
}
.animate-fade-in {
  animation: fadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
.slide-down-enter-active,
.slide-down-leave-active {
  transition: all 0.2s ease-out;
}
.slide-down-enter-from,
.slide-down-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}
</style>