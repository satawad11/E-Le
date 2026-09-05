/**
 * Error Handler Utility
 * Sanitizes server responses and converts HTTP errors into safe, user-friendly Thai copy.
 * Strictly prevents raw exceptions, SQL errors, and stack traces from leaking to the UI.
 */

export function getErrorMessage(error, defaultMessage = 'เกิดข้อผิดพลาดในการประมวลผล') {
  if (!error) return defaultMessage;

  // 1. Network / Connection Error
  if (error.code === 'ERR_NETWORK' || error.message === 'Network Error' || (!error.response && error.request)) {
    return 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้ กรุณาตรวจสอบการเชื่อมต่ออินเทอร์เน็ตแล้วลองใหม่อีกครั้ง';
  }

  // 2. Timeout Error
  if (error.code === 'ECONNABORTED' || error.message?.includes('timeout')) {
    return 'การเชื่อมต่อใช้เวลานานเกินกำหนด กรุณาลองใหม่อีกครั้ง';
  }

  const status = error.response?.status;
  const data = error.response?.data;

  // 3. Validation Errors (422)
  if (status === 422) {
    if (data?.errors && typeof data.errors === 'object') {
      const messages = Object.values(data.errors).flat().filter(Boolean);
      if (messages.length > 0) {
        return messages.join(' | ');
      }
    }
    if (data?.message) {
      return sanitizeErrorMessage(data.message);
    }
    return 'ข้อมูลที่ระบุไม่ถูกต้องหรือไม่ครบถ้วน';
  }

  // 4. Standard HTTP Status Errors
  switch (status) {
    case 400:
      return sanitizeErrorMessage(data?.message) || 'คำขอไม่ถูกต้อง กรุณาตรวจสอบข้อมูล';
    case 401:
      return 'เซสชันการใช้งานหมดอายุ กรุณาเข้าสู่ระบบใหม่อีกครั้ง';
    case 403:
      return sanitizeErrorMessage(data?.message) || 'คุณไม่มีสิทธิ์ในการเข้าถึงหรือดำเนินการในส่วนนี้';
    case 404:
      return sanitizeErrorMessage(data?.message) || 'ไม่พบหน้าที่ต้องการ หรือข้อมูลอาจถูกลบไปแล้ว';
    case 419:
      return 'เซสชันหมดอายุ กรุณารีเฟรชหน้าจอแล้วลองใหม่อีกครั้ง';
    case 429:
      return 'มีการส่งคำขอถี่เกินไป กรุณารอสักครู่แล้วลองใหม่อีกครั้ง';
    case 500:
      return 'เกิดข้อผิดพลาดภายในระบบ กรุณาลองใหม่อีกครั้ง หรือติดต่อผู้ดูแลระบบ';
    case 502:
    case 503:
    case 504:
      return 'ระบบกำลังปิดปรับปรุงชั่วคราวหรือไม่สามารถให้บริการได้ในขณะนี้';
    default:
      break;
  }

  // 5xx อื่น ๆ ที่ไม่ได้ระบุไว้ข้างบน — ต้องไม่หลุดเป็นข้อความดิบจาก server
  if (typeof status === 'number' && status >= 500) {
    return 'เกิดข้อผิดพลาดภายในระบบ กรุณาลองใหม่อีกครั้ง หรือติดต่อผู้ดูแลระบบ';
  }

  if (typeof error === 'string') {
    return sanitizeErrorMessage(error);
  }

  if (data?.message) {
    return sanitizeErrorMessage(data.message);
  }

  return defaultMessage;
}

/**
 * Filter out sensitive keywords, SQL errors, file paths, and stack traces
 */
export function sanitizeErrorMessage(message) {
  if (!message || typeof message !== 'string') return '';

  // Check for SQL errors / raw PHP exceptions
  const sensitivePatterns = [
    /SQLSTATE/i,
    /syntax error/i,
    /database query/i,
    /stack trace/i,
    /at \/var\/www/i,
    /at [A-Za-z]:\\/i,
    /Illuminate\\/i,
    /PDOException/i,
    /QueryException/i,
    /ErrorException/i,
    /UnhandledPromiseRejection/i,
  ];

  for (const pattern of sensitivePatterns) {
    if (pattern.test(message)) {
      return 'เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่อีกครั้ง หรือติดต่อผู้ดูแลระบบ';
    }
  }

  return message;
}

export function isNetworkError(error) {
  return error?.code === 'ERR_NETWORK' || error?.message === 'Network Error' || (!error?.response && error?.request);
}

export function isForbidden(error) {
  return error?.response?.status === 403;
}

export function isNotFound(error) {
  return error?.response?.status === 404;
}

export function isUnauthorized(error) {
  return error?.response?.status === 401;
}
