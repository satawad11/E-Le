import { useAuthStore } from '../stores/auth';

/**
 * Vue 3 Directive for Fine-Grained Permission Control
 * 
 * Usage in templates:
 *  v-can="'leave.approve'"
 *  v-can="['attendance.view_self', 'leave.view']"
 */
export const canDirective = {
  mounted(el, binding) {
    checkPermission(el, binding);
  },
  updated(el, binding) {
    checkPermission(el, binding);
  }
};

function checkPermission(el, binding) {
  const authStore = useAuthStore();
  const value = binding.value;

  if (!value) return;

  let hasAccess = false;

  if (Array.isArray(value)) {
    hasAccess = authStore.hasAnyPermission(value);
  } else if (typeof value === 'string') {
    hasAccess = authStore.hasPermission(value);
  }

  if (!hasAccess) {
    el.parentNode && el.parentNode.removeChild(el);
  }
}

export default canDirective;
