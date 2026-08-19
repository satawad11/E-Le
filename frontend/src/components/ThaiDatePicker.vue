<template>
  <div class="relative w-full" ref="containerRef">
    <!-- Trigger input -->
    <div
      class="date-trigger focus-visible:ring-2 focus-visible:ring-[#800000] focus:outline-none"
      :class="{ 'date-trigger--open': isOpen, 'date-trigger--filled': !!displayDate }"
      @click="toggleOpen"
      role="button"
      :aria-expanded="isOpen"
      aria-haspopup="dialog"
      aria-label="เลือกวันที่"
      tabindex="0"
      @keydown.enter.prevent="toggleOpen"
      @keydown.space.prevent="toggleOpen"
      @keydown.escape="isOpen = false"
    >
      <svg class="trigger-cal-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
          d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
      </svg>
      <span class="trigger-text" :class="displayDate ? 'text-filled' : 'text-placeholder'">
        {{ displayDate || 'เลือกวันที่' }}
      </span>
      <button v-if="displayDate" type="button" class="clear-btn focus-visible:ring-2 focus-visible:ring-[#800000]" @click.stop="clearDate" title="ล้างวันที่" aria-label="ล้างวันที่">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
      <svg v-else class="chevron-icon" :class="{ 'rotate-180': isOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
      </svg>
    </div>

    <!-- Calendar popover (teleport to body to avoid overflow:hidden clipping) -->
    <teleport to="body">
      <transition name="cal-pop">
      <div
        v-if="isOpen"
        role="dialog"
        aria-modal="true"
        aria-label="ปฏิทินเลือกวันที่"
        class="cal-popover focus:outline-none"
        :style="popoverStyle"
        tabindex="-1"
        @keydown.escape="isOpen = false"
        @click.stop
      >
        <!-- Header: Month/Year navigation -->
        <div class="cal-header">
          <button type="button" class="nav-btn focus-visible:ring-2 focus-visible:ring-[#800000]" @click.stop="prevMonth" title="เดือนก่อนหน้า" aria-label="เดือนก่อนหน้า">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
          </button>

          <!-- Month/Year selector row -->
          <div class="header-selectors">
            <select v-model="currentMonth" class="header-select" aria-label="เลือกเดือน" @change.stop @click.stop>
              <option v-for="(m, i) in monthNames" :key="i" :value="i">{{ m }}</option>
            </select>
            <select v-model="currentYear" class="header-select" aria-label="เลือกปี พ.ศ." @change.stop @click.stop>
              <option v-for="y in yearRange" :key="y" :value="y">{{ y + 543 }}</option>
            </select>
          </div>

          <button type="button" class="nav-btn focus-visible:ring-2 focus-visible:ring-[#800000]" @click.stop="nextMonth" title="เดือนถัดไป" aria-label="เดือนถัดไป">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
          </button>
        </div>

        <!-- "Today" shortcut -->
        <div class="today-bar">
          <button type="button" class="today-btn focus-visible:ring-2 focus-visible:ring-[#800000]" @click.stop="selectToday" aria-label="เลือกวันปัจจุบัน">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.calendar"/>
            </svg>
            วันนี้ ({{ todayDisplay }})
          </button>
        </div>

        <!-- Day-of-week headers -->
        <div class="weekday-grid" role="row">
          <div v-for="d in weekDays" :key="d" class="weekday-cell" role="columnheader" :class="{ 'text-red-500': d === 'อา', 'text-blue-500': d === 'ส' }">
            {{ d }}
          </div>
        </div>

        <!-- Days grid -->
        <div class="days-grid" role="grid" aria-label="ตารางวันในเดือน">
          <!-- Blank cells for first day offset -->
          <div v-for="b in blankDays" :key="'b-'+b" aria-hidden="true"></div>

          <!-- Day cells -->
          <button
            v-for="day in daysInMonth"
            :key="'d-'+day"
            type="button"
            @click.stop="selectDate(day)"
            class="day-btn focus-visible:ring-2 focus-visible:ring-[#800000]"
            :class="getDayClass(day)"
            :disabled="isDisabled(day)"
            :aria-label="`${day} ${monthNames[currentMonth]} ${currentYear + 543}`"
          >
            {{ day }}
          </button>
        </div>

        <!-- Footer info -->
        <div v-if="selectedDate" class="cal-footer">
          <svg class="w-3.5 h-3.5 text-[#800000]" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
          </svg>
          <span>{{ displayDate }}</span>
        </div>
      </div>
    </transition>
    </teleport>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { ICONS } from '../utils/icons';

const props = defineProps({
  modelValue: {
    type: String,
    default: ''
  },
  minDate: {
    type: String,
    default: ''
  },
  maxDate: {
    type: String,
    default: ''
  }
});

const emit = defineEmits(['update:modelValue']);

const isOpen = ref(false);
const containerRef = ref(null);
const popoverStyle = ref({});

const today = new Date();
const currentMonth = ref(today.getMonth());
const currentYear  = ref(today.getFullYear());
const selectedDate = ref(null);

const monthNames = [
  'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
  'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'
];
const monthNamesShort = [
  'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
  'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'
];
const weekDays = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];

// Year range: ±100 years to support birthdates and founding dates
const yearRange = computed(() => {
  const base = today.getFullYear();
  let minYear = base - 100;
  let maxYear = base + 10;
  
  if (currentYear.value < minYear) minYear = currentYear.value;
  if (currentYear.value > maxYear) maxYear = currentYear.value;
  
  const arr = [];
  for (let y = minYear; y <= maxYear; y++) arr.push(y);
  return arr;
});

const daysInMonth = computed(() =>
  new Date(currentYear.value, currentMonth.value + 1, 0).getDate()
);
const blankDays = computed(() =>
  new Date(currentYear.value, currentMonth.value, 1).getDay()
);

// Today's display string
const todayDisplay = computed(() => {
  const d = today.getDate();
  const m = monthNamesShort[today.getMonth()];
  const y = today.getFullYear() + 543;
  return `${d} ${m} ${y}`;
});

// Formatted display
const displayDate = computed(() => {
  if (!selectedDate.value) return '';
  const d = selectedDate.value.getDate();
  const m = monthNamesShort[selectedDate.value.getMonth()];
  const y = selectedDate.value.getFullYear() + 543;
  return `${d} ${m} ${y}`;
});

// Sync from prop
watch(() => props.modelValue, (val) => {
  if (val && typeof val === 'string' && val.length >= 10) {
    const datePart = val.substring(0, 10);
    const [y, mo, d] = datePart.split('-').map(Number);
    if (!isNaN(y) && !isNaN(mo) && !isNaN(d)) {
      selectedDate.value = new Date(y, mo - 1, d);
      currentMonth.value = mo - 1;
      currentYear.value  = y;
      return;
    }
  }
  selectedDate.value = null;
}, { immediate: true });

// Day class logic
const getDayClass = (day) => {
  const date = new Date(currentYear.value, currentMonth.value, day);
  const dow = date.getDay(); // 0=Sun, 6=Sat
  const isSelected = isSameDate(day);
  const isToday    = isCurrentDay(day);
  const disabled   = isDisabled(day);

  return {
    'day-btn--selected': isSelected,
    'day-btn--today': isToday && !isSelected,
    'day-btn--sunday': dow === 0 && !isSelected,
    'day-btn--saturday': dow === 6 && !isSelected,
    'day-btn--disabled': disabled,
  };
};

const isSameDate = (day) => {
  if (!selectedDate.value) return false;
  return (
    selectedDate.value.getDate()     === day &&
    selectedDate.value.getMonth()    === currentMonth.value &&
    selectedDate.value.getFullYear() === currentYear.value
  );
};

const isCurrentDay = (day) => {
  return (
    today.getDate()     === day &&
    today.getMonth()    === currentMonth.value &&
    today.getFullYear() === currentYear.value
  );
};

const isDisabled = (day) => {
  if (!props.minDate && !props.maxDate) return false;
  const date = new Date(currentYear.value, currentMonth.value, day);
  if (props.minDate) {
    const [my, mm, md] = props.minDate.split('-').map(Number);
    if (date < new Date(my, mm - 1, md)) return true;
  }
  if (props.maxDate) {
    const [xy, xm, xd] = props.maxDate.split('-').map(Number);
    if (date > new Date(xy, xm - 1, xd)) return true;
  }
  return false;
};

const updatePopoverPosition = () => {
  if (!containerRef.value) return;
  const rect = containerRef.value.getBoundingClientRect();
  const popoverHeight = 360;
  const popoverWidth = 300;
  const spaceBelow = window.innerHeight - rect.bottom;
  const spaceAbove = rect.top;

  let top, bottom;
  if (spaceBelow < popoverHeight && spaceAbove > spaceBelow) {
    // Open upward
    bottom = window.innerHeight - rect.top + 8;
    top = 'auto';
  } else {
    // Open downward
    top = rect.bottom + 8;
    bottom = 'auto';
  }

  // Prevent going off right edge
  let left = rect.left;
  if (left + popoverWidth > window.innerWidth - 8) {
    left = window.innerWidth - popoverWidth - 8;
  }
  if (left < 8) left = 8;

  popoverStyle.value = {
    position: 'fixed',
    top: top === 'auto' ? 'auto' : `${top}px`,
    bottom: bottom === 'auto' ? 'auto' : `${bottom}px`,
    left: `${left}px`,
    zIndex: 9999,
    width: `${popoverWidth}px`,
  };
};

const toggleOpen = () => {
  isOpen.value = !isOpen.value;
  if (isOpen.value) {
    updatePopoverPosition();
  }
};

const selectDate = (day) => {
  if (isDisabled(day)) return;
  const d = new Date(currentYear.value, currentMonth.value, day);
  selectedDate.value = d;
  const yy = d.getFullYear();
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  emit('update:modelValue', `${yy}-${mm}-${dd}`);
  isOpen.value = false;
};

const selectToday = () => {
  currentMonth.value = today.getMonth();
  currentYear.value  = today.getFullYear();
  selectDate(today.getDate());
};

const clearDate = () => {
  selectedDate.value = null;
  emit('update:modelValue', '');
};

const prevMonth = () => {
  if (currentMonth.value === 0) {
    currentMonth.value = 11;
    currentYear.value--;
  } else {
    currentMonth.value--;
  }
};
const nextMonth = () => {
  if (currentMonth.value === 11) {
    currentMonth.value = 0;
    currentYear.value++;
  } else {
    currentMonth.value++;
  }
};

const handleClickOutside = (e) => {
  // Close if click is outside the trigger container
  // The popover is teleported to body, so we check by class name too
  if (
    containerRef.value &&
    !containerRef.value.contains(e.target) &&
    !e.target.closest('.cal-popover')
  ) {
    isOpen.value = false;
  }
};

const handleScroll = () => {
  if (isOpen.value) {
    updatePopoverPosition();
  }
};

onMounted(() => {
  document.addEventListener('mousedown', handleClickOutside);
  window.addEventListener('scroll', handleScroll, true);
  window.addEventListener('resize', updatePopoverPosition);
});
onUnmounted(() => {
  document.removeEventListener('mousedown', handleClickOutside);
  window.removeEventListener('scroll', handleScroll, true);
  window.removeEventListener('resize', updatePopoverPosition);
});
</script>

<style scoped>
/* ====== Trigger ====== */
.date-trigger {
  width: 100%;
  padding: 0.75rem 1rem;
  border-radius: 14px;
  border: 2px solid #e5e7eb;
  background: #fff;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  transition: border-color 0.2s, box-shadow 0.2s;
  user-select: none;
  outline: none;
}
.date-trigger:hover,
.date-trigger--open {
  border-color: #800000;
  box-shadow: 0 0 0 4px rgba(128,0,0,0.08);
}
.date-trigger--filled { border-color: #800000; }

.trigger-cal-icon {
  width: 1.1rem;
  height: 1.1rem;
  color: #800000;
  flex-shrink: 0;
}
.trigger-text { flex: 1; font-size: 0.9375rem; }
.text-filled { color: #111827; font-weight: 500; }
.text-placeholder { color: #9ca3af; }

.clear-btn {
  padding: 0.25rem;
  border-radius: 50%;
  background: #f3f4f6;
  border: none;
  cursor: pointer;
  color: #6b7280;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s, color 0.15s;
  flex-shrink: 0;
}
.clear-btn:hover { background: #fee2e2; color: #dc2626; }

.chevron-icon {
  width: 1rem; height: 1rem;
  color: #9ca3af;
  transition: transform 0.2s;
  flex-shrink: 0;
}
.chevron-icon.rotate-180 { transform: rotate(180deg); }


/* ====== Transition (scoped fallback, actual transitions in global block) ====== */
.cal-pop-enter-active,
.cal-pop-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.cal-pop-enter-from,
.cal-pop-leave-to {
  opacity: 0;
  transform: translateY(-6px) scale(0.97);
}
</style>

<!-- Global styles for teleported popover (not scoped) -->
<style>
/* ====== Popover (global, teleported to body) ====== */
.cal-popover {
  background: #fff;
  border-radius: 18px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.15), 0 4px 12px rgba(0,0,0,0.08);
  border: 1px solid #f0f0f0;
  overflow: hidden;
}

/* ====== Header ====== */
.cal-popover .cal-header {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.875rem 1rem;
  background: linear-gradient(135deg, #800000, #5a0000);
}

.cal-popover .nav-btn {
  width: 2rem; height: 2rem;
  border-radius: 8px;
  border: 1px solid rgba(255,255,255,0.2);
  background: rgba(255,255,255,0.1);
  color: white;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  transition: background 0.15s;
}
.cal-popover .nav-btn:hover { background: rgba(255,255,255,0.25); }

.cal-popover .header-selectors {
  flex: 1;
  display: flex;
  gap: 0.375rem;
}
.cal-popover .header-select {
  flex: 1;
  background: rgba(255,255,255,0.15);
  border: 1px solid rgba(255,255,255,0.2);
  color: white;
  border-radius: 8px;
  padding: 0.3rem 0.4rem;
  font-size: 0.8125rem;
  font-weight: 600;
  outline: none;
  cursor: pointer;
  appearance: none;
  text-align: center;
}
.cal-popover .header-select option { background: #5a0000; color: white; }

/* ====== Today bar ====== */
.cal-popover .today-bar {
  padding: 0.5rem 1rem;
  background: #fff8f8;
  border-bottom: 1px solid #fee2e2;
}
.cal-popover .today-btn {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.375rem;
  padding: 0.375rem 0.75rem;
  background: #fff;
  border: 1.5px solid #fca5a5;
  border-radius: 8px;
  color: #800000;
  font-size: 0.8125rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s;
}
.cal-popover .today-btn:hover { background: #fef2f2; border-color: #800000; }

/* ====== Weekday headers ====== */
.cal-popover .weekday-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  padding: 0.5rem 0.75rem 0.25rem;
}
.cal-popover .weekday-cell {
  text-align: center;
  font-size: 0.75rem;
  font-weight: 700;
  color: #6b7280;
  padding: 0.25rem 0;
}

/* ====== Days grid ====== */
.cal-popover .days-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 2px;
  padding: 0.25rem 0.75rem 0.75rem;
}

.cal-popover .day-btn {
  width: 100%;
  aspect-ratio: 1;
  border-radius: 50%;
  border: none;
  background: transparent;
  font-size: 0.8125rem;
  color: #374151;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.1s, color 0.1s, transform 0.1s;
  font-weight: 500;
}
.cal-popover .day-btn:hover:not(:disabled):not(.day-btn--selected) {
  background: #fee2e2;
  color: #800000;
  transform: scale(1.1);
}
.cal-popover .day-btn--selected {
  background: #800000 !important;
  color: #fff !important;
  font-weight: 700;
  box-shadow: 0 2px 8px rgba(128,0,0,0.4);
}
.cal-popover .day-btn--today {
  border: 2px solid #800000;
  color: #800000;
  font-weight: 700;
}
.cal-popover .day-btn--sunday { color: #ef4444; }
.cal-popover .day-btn--saturday { color: #3b82f6; }
.cal-popover .day-btn--disabled {
  opacity: 0.3;
  cursor: not-allowed;
}

/* ====== Footer ====== */
.cal-popover .cal-footer {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.5rem 1rem 0.75rem;
  font-size: 0.8rem;
  font-weight: 600;
  color: #800000;
  border-top: 1px solid #f3f4f6;
}

/* ====== Transition ====== */
.cal-pop-enter-active,
.cal-pop-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.cal-pop-enter-from,
.cal-pop-leave-to {
  opacity: 0;
  transform: translateY(-6px) scale(0.97);
}
</style>
