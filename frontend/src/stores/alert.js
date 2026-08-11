import { defineStore } from 'pinia'

export const useAlertStore = defineStore('alert', {
  state: () => ({
    alerts: []
  }),
  actions: {
    addAlert(message, type = 'success', duration = 3000) {
      const id = Date.now() + Math.random()
      this.alerts.push({ id, message, type })
      setTimeout(() => {
        this.removeAlert(id)
      }, duration)
    },
    removeAlert(id) {
      this.alerts = this.alerts.filter(alert => alert.id !== id)
    },
    success(message, duration = 3000) {
      this.addAlert(message, 'success', duration)
    },
    error(message, duration = 4000) {
      this.addAlert(message, 'error', duration)
    },
    info(message, duration = 3000) {
      this.addAlert(message, 'info', duration)
    },
    // Aliases for compatibility
    showSuccess(message, duration = 3000) {
      this.success(message, duration)
    },
    showError(message, duration = 4000) {
      this.error(message, duration)
    },
    showInfo(message, duration = 3000) {
      this.info(message, duration)
    }
  }
})
