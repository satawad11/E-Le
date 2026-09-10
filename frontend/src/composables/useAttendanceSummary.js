import { computed, ref } from 'vue'
import api from '../axios'
import { useAlertStore } from '../stores/alert'
import { openAuthenticatedReport, reportErrorMessage } from '../utils/secureFile'

/**
 * ส่วนที่ใช้ร่วมกันของหน้าสรุปผลการปฏิบัติงาน (CLEANUP-02 · D10)
 *
 * หน้า "สรุปผลการปฏิบัติงาน" กับ "สรุปผลการปฏิบัติงาน (OT)" มีตัวกรอง รายชื่อปีงบประมาณ
 * หน่วยงาน บุคลากร และวิธีดาวน์โหลดรายงานชุดเดียวกันทั้งหมด ต่างกันเฉพาะ endpoint
 * และรูปแบบตาราง — เดิมโค้ดส่วนนี้ถูกคัดลอกไว้ทั้งสองไฟล์ แก้ทีต้องแก้สองที่
 *
 * ตารางของแต่ละหน้ายังแยกกันตามเดิม เพราะเป็นเอกสารราชการคนละแบบ
 *
 * @param {string} basePath เส้นทางฐานของรายงาน เช่น `/admin/attendances/summary`
 */
export function useAttendanceSummary(basePath) {
  const alertStore = useAlertStore()

  const loading = ref(false)
  const errorMessage = ref('')
  const records = ref([])
  const departments = ref([])
  const users = ref([])
  const yearOptions = ref([])

  const now = new Date()
  const filters = ref({
    month: now.getMonth() + 1,
    year: now.getFullYear(),
    org_unit_id: '',
    user_id: '',
  })

  const thaiMonths = [
    'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
    'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม',
  ]

  const daysInMonth = computed(() => new Date(filters.value.year, filters.value.month, 0).getDate())

  const filteredUsers = computed(() => {
    if (!filters.value.org_unit_id) return users.value
    return users.value.filter((u) => u.org_unit_id === filters.value.org_unit_id)
  })

  /*
   * ใช้ `/departments` ไม่ใช่ `/admin/departments`
   *
   * CLEANUP-02 — เส้นทางเดิมต้องมีสิทธิ์ `manage_departments` ผู้ที่ดูรายงานได้แต่ไม่ได้
   * จัดการหน่วยงาน (เช่น หัวหน้างานบุคลากร) จึงได้ 403 และช่องตัวกรองว่างเปล่าเสมอ
   */
  const fetchDepartments = async () => {
    try {
      const res = await api.get('/org-units')
      departments.value = res.data
    } catch (error) {
      console.error(error)
    }
  }

  const fetchUsers = async () => {
    try {
      const res = await api.get('/users/list')
      users.value = res.data
    } catch (error) {
      console.error(error)
    }
  }

  // ปีงบประมาณเก็บเป็น พ.ศ. ส่วนตัวกรองของหน้านี้ใช้ ค.ศ.
  const fetchFiscalYears = async () => {
    const currentYear = new Date().getFullYear()
    try {
      const res = await api.get('/admin/fiscal-years')
      yearOptions.value = res.data
        .map((fy) => (fy.year > 2500 ? fy.year - 543 : fy.year))
        .sort((a, b) => b - a)

      if (!yearOptions.value.includes(currentYear)) {
        yearOptions.value.push(currentYear)
        yearOptions.value.sort((a, b) => b - a)
      }
    } catch (error) {
      console.error(error)
      yearOptions.value = [currentYear + 1, currentYear, currentYear - 1]
    }
  }

  /*
   * SEC-009 — เดิมแนบ Sanctum token ทาง query string แล้วเปิดด้วย window.open
   * ทำให้ token ไปโผล่ใน access log และ browser history
   * ตอนนี้ดึงผ่าน axios ที่แนบ Authorization header ให้แล้ว
   */
  const runReport = async (path) => {
    try {
      await openAuthenticatedReport(path, filters.value)
    } catch (error) {
      alertStore.error(reportErrorMessage(error))
    }
  }

  const previewReport = () => runReport(`${basePath}/preview`)
  const exportPdf = () => runReport(`${basePath}/export/pdf`)
  const exportWord = () => runReport(`${basePath}/export/word`)
  const exportExcel = () => runReport(`${basePath}/export/excel`)

  /**
   * โหลดข้อมูลรายงาน — ผู้เรียกส่ง callback มาแปลงผลลัพธ์ตามรูปแบบของหน้าตัวเอง
   *
   * @param {(data: unknown) => void} [onLoaded]
   */
  const fetchRecords = async (onLoaded = null) => {
    loading.value = true
    errorMessage.value = ''
    try {
      const res = await api.get(basePath, { params: filters.value })
      /*
       * เรียกจาก `@change` ของ <select> ได้ด้วย ซึ่งจะส่ง DOM Event เข้ามาแทน callback
       * จึงต้องตรวจว่าเป็นฟังก์ชันจริงก่อน ไม่ใช่แค่ตรวจว่ามีค่า
       */
      if (typeof onLoaded === 'function') {
        onLoaded(res.data)
      } else {
        records.value = res.data
      }
    } catch (error) {
      if (error.response?.data?.error) {
        errorMessage.value = error.response.data.error
      }
      records.value = []
    } finally {
      loading.value = false
    }
  }

  const loadReferenceData = () => Promise.all([fetchFiscalYears(), fetchDepartments(), fetchUsers()])

  return {
    loading,
    errorMessage,
    records,
    departments,
    users,
    yearOptions,
    filters,
    thaiMonths,
    daysInMonth,
    filteredUsers,
    fetchRecords,
    loadReferenceData,
    previewReport,
    exportPdf,
    exportWord,
    exportExcel,
  }
}
