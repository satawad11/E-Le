import axios from 'axios';

const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  timeout: 60000, // 60 วินาที รองรับ Docker Desktop on Windows ที่อ่านไฟล์ช้า
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
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

// Response interceptor: จับ 401 Unauthorized → clear session แล้ว redirect login ทันที
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      // Token หมดอายุหรือไม่ถูกต้อง — clear แล้วไป login
      localStorage.removeItem('token');
      // ใช้ window.location เพื่อ full reload (clear Pinia state ด้วย)
      if (window.location.pathname !== '/login') {
        window.location.href = '/login';
      }
    }
    return Promise.reject(error);
  }
);

export default api;
