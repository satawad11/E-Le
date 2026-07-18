import { defineStore } from 'pinia'
import api from '../axios'

export const useSarabanDocumentTypeStore = defineStore('sarabanDocumentType', {
  state: () => ({
    types: [],
    loading: false,
    error: null
  }),

  actions: {
    async fetchTypes() {
      this.loading = true
      this.error = null
      try {
        const response = await api.get('/saraban/types')
        this.types = response.data
      } catch (err) {
        this.error = err.response?.data?.message || err.message
      } finally {
        this.loading = false
      }
    },

    async createType(data) {
      this.loading = true
      this.error = null
      try {
        const response = await api.post('/saraban/types', data)
        this.types.push(response.data)
        return response.data
      } catch (err) {
        this.error = err.response?.data?.message || err.message
        throw err
      } finally {
        this.loading = false
      }
    },

    async updateType(id, data) {
      this.loading = true
      this.error = null
      try {
        const response = await api.put(`/saraban/types/${id}`, data)
        const index = this.types.findIndex(t => t.id === id)
        if (index !== -1) {
          this.types[index] = response.data
        }
        return response.data
      } catch (err) {
        this.error = err.response?.data?.message || err.message
        throw err
      } finally {
        this.loading = false
      }
    },

    async deleteType(id) {
      this.loading = true
      this.error = null
      try {
        await api.delete(`/saraban/types/${id}`)
        this.types = this.types.filter(t => t.id !== id)
      } catch (err) {
        this.error = err.response?.data?.message || err.message
        throw err
      } finally {
        this.loading = false
      }
    }
  }
})
