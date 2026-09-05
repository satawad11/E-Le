import axios from 'axios';
import { getErrorMessage } from './utils/errorHandler';

/**
 * HTTP client กลางของหน้าเว็บ
 *
 * SEC-025 — เดิม interceptor นี้เคยเป็นทางเดียวที่จับ 401 แล้วเคลียร์ token ซึ่งถูกแล้ว
 * แต่ฝั่ง store กลับเรียก `logout()` เมื่อคำขอ `/user` ล้ม "ด้วยเหตุใดก็ตาม"
 * ผู้ใช้จึงถูกเตะออกจากระบบเพราะ 429 (คำขอถี่) หรือ 5xx (ระบบขัดข้องชั่วคราว)
 * ทั้งที่โทเคนยังใช้ได้อยู่
 *
 * กฎที่ยึดตั้งแต่นี้: **มีเพียง HTTP 401 เท่านั้นที่แปลว่าการยืนยันตัวตนหมดอายุ**
 * 429 / 5xx / network error ทั้งหมดเป็นความล้มเหลวชั่วคราว ห้ามล้าง token
 */

/**
 * ค่าตั้งของการลองใหม่เมื่อเจอ 429
 *
 * ลองใหม่แบบ exponential backoff และจำกัดจำนวนครั้ง เพื่อไม่ให้กลายเป็น
 * request storm ที่ไปซ้ำเติมถังที่เต็มอยู่แล้ว · export ไว้ให้เทสต์ปรับได้
 */
export const httpRetryConfig = {
  maxRetries: 2,
  baseDelayMs: 1000,
  maxDelayMs: 8000,
};

/** คำขอที่ลองใหม่ได้อย่างปลอดภัย — เฉพาะ method ที่ไม่เปลี่ยนสถานะข้อมูล */
const SAFE_METHODS = ['get', 'head', 'options'];

/**
 * เส้นทางที่หลายหน้าจอเรียกซ้ำระหว่างสลับหน้า
 *
 * รวมคำขอที่ "กำลังวิ่งอยู่" ของเส้นทางเหล่านี้ให้เหลือใบเดียว เพื่อลดจำนวน
 * request ที่หน้าเว็บสร้างจริง แทนการไปผ่อนเพดาน rate limit ฝั่ง server
 */
const DEDUPLICATED_PATHS = ['/user', '/user/menus', '/settings', '/notifications'];

const inFlightGets = new Map();

export const isAuthenticationExpired = (error) => error?.response?.status === 401;

/**
 * หน้าที่เปิดให้คนทั่วไปเข้าถึงได้โดยไม่ต้องเข้าสู่ระบบ
 *
 * **ที่มา (UI QA · 2026-09-05):** app shell เรียก `/api/settings` ตอนบูตทุกครั้ง
 * ซึ่งเป็น endpoint ที่ต้องยืนยันตัวตน · ผู้ที่ยังไม่ได้เข้าสู่ระบบจึงได้ 401
 * แล้ว interceptor นี้พาไปหน้าเข้าสู่ระบบ — **รวมถึงตอนที่เปิดหน้าตรวจสอบเอกสาร
 * จาก QR ด้วย**
 *
 * ผลคือหน้าตรวจสอบทั้งของใบลาและของหนังสือราชการ **เปิดไม่ได้เลยสำหรับผู้ที่
 * ถือกระดาษอยู่ในมือ** ซึ่งเป็นคนกลุ่มเดียวที่หน้านั้นมีไว้ให้ (SoT §12)
 *
 * ต้องตรงกับ route ที่ประกาศ `meta.public` ใน `router/index.js` — มีเทสต์
 * `publicRouteAccess` ตรึงไว้ไม่ให้สองที่นี้เดินหนีกัน
 *
 * @type {string[]}
 */
export const PUBLIC_PATH_PREFIXES = ['/login', '/verify-leave/', '/verify-saraban/'];

/** เส้นทางนี้เปิดสาธารณะหรือไม่ */
export const isPublicPath = (pathname) =>
  typeof pathname === 'string' && PUBLIC_PATH_PREFIXES.some((prefix) => pathname.startsWith(prefix));

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** หน่วงก่อนลองใหม่ — ให้เกียรติ `Retry-After` ของ server ก่อนเสมอ */
const retryDelayMs = (error, attempt) => {
  const header = error?.response?.headers?.['retry-after'] ?? error?.response?.headers?.['Retry-After'];
  const fromHeader = Number(header);
  if (Number.isFinite(fromHeader) && fromHeader > 0) {
    return Math.min(fromHeader * 1000, httpRetryConfig.maxDelayMs);
  }

  return Math.min(httpRetryConfig.baseDelayMs * 2 ** (attempt - 1), httpRetryConfig.maxDelayMs);
};

const clearStoredSession = () => {
  localStorage.removeItem('token');
  localStorage.removeItem('token_expires_at');
};

const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  timeout: 60000, // 60 วินาที รองรับ Docker Desktop on Windows ที่อ่านไฟล์ช้า
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
});

// Request interceptor: แนบ token ทุก request
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const status = error.response?.status;
    const config = error.config;

    /*
     * 401 เท่านั้นที่แปลว่า "ยืนยันตัวตนไม่ผ่านแล้ว" — ล้าง token แล้วกลับหน้าเข้าสู่ระบบ
     * ใช้ full reload เพื่อล้าง state ของ Pinia ไปด้วย
     */
    if (status === 401) {
      clearStoredSession();
      if (typeof window !== 'undefined' && !isPublicPath(window.location?.pathname)) {
        window.location.href = '/login';
      }
    }

    /*
     * 429 — ถังคำขอเต็มชั่วคราว ไม่ใช่ปัญหาการยืนยันตัวตน
     * ลองใหม่ให้อัตโนมัติเฉพาะคำขออ่านข้อมูล และจำกัดจำนวนครั้ง
     */
    if (status === 429 && config && SAFE_METHODS.includes((config.method || 'get').toLowerCase())) {
      const attempt = (config.__retryCount || 0) + 1;
      if (attempt <= httpRetryConfig.maxRetries) {
        config.__retryCount = attempt;
        await sleep(retryDelayMs(error, attempt));

        return api(config);
      }
    }

    // ข้อความไทยพร้อมใช้ ผ่านด่านกรองข้อความอ่อนไหวชุดเดียวกับทั้งระบบแล้ว
    error.thaiMessage = getErrorMessage(error);
    error.isAuthenticationExpired = status === 401;

    /*
     * UI-01 — ข้อความของโครงสร้างพื้นฐานต้องเป็นภาษาไทยเสมอ
     *
     * 429 และ 5xx ตอบด้วยข้อความของเฟรมเวิร์ก เช่น "Too Many Attempts."
     * ซึ่งไปโผล่บนหน้าจอผู้ใช้ตรง ๆ ทุกจุดที่อ่าน `error.response.data.message`
     * (มีอยู่หลายสิบจุดทั่วระบบ) แทนที่ตรงนี้ที่เดียวจึงครอบได้ทั้งหมด
     *
     * แทนเฉพาะเมื่อข้อความนั้นไม่มีอักษรไทย — ข้อความไทยที่ระบบตั้งใจส่งมาเอง
     * (เช่น "บัญชีถูกล็อกชั่วคราว...") ต้องผ่านไปตามเดิม
     */
    const infrastructureFailure = status === 429 || (typeof status === 'number' && status >= 500);
    const serverMessage = error.response?.data?.message;
    if (infrastructureFailure && error.response?.data && !/[฀-๿]/.test(String(serverMessage ?? ''))) {
      error.response.data.message = error.thaiMessage;
    }

    return Promise.reject(error);
  }
);

/*
 * รวมคำขออ่านข้อมูลที่ซ้ำกันและกำลังวิ่งอยู่ให้เหลือใบเดียว
 *
 * ครอบ `api.get` แทนการทำใน interceptor เพราะ interceptor ของ axios ไม่มีทาง
 * "ยกเลิก" คำขอแล้วคืนผลของคำขออื่นได้โดยไม่หลอก type ของ response
 */
const originalGet = api.get.bind(api);
api.get = (url, config) => {
  const path = typeof url === 'string' ? url.split('?')[0] : '';
  if (!DEDUPLICATED_PATHS.includes(path)) {
    return originalGet(url, config);
  }

  const key = `${url}|${JSON.stringify(config?.params ?? null)}`;
  if (inFlightGets.has(key)) {
    return inFlightGets.get(key);
  }

  const pending = originalGet(url, config).finally(() => inFlightGets.delete(key));
  inFlightGets.set(key, pending);

  return pending;
};

export default api;
