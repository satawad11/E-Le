import { defineStore } from 'pinia'
import api from '../axios'

export const useSarabanDocumentStore = defineStore('sarabanDocument', {
  state: () => ({
    documents: [],
    currentDocument: null,
    loading: false,
    error: null,
    pagination: {
      current_page: 1,
      per_page: 10,
      total: 0,
      last_page: 1
    }
  }),

  actions: {
    async fetchDocuments(page = 1, perPage = 10, filters = {}) {
      this.loading = true
      this.error = null
      try {
        const params = { page, per_page: perPage }
        if (filters.search) params.search = filters.search
        if (filters.tab && filters.tab !== 'all') params.tab = filters.tab
        if (filters.type_id) params.type_id = filters.type_id
        if (filters.direction) params.direction = filters.direction

        const response = await api.get('/saraban/documents', { params })
        this.documents = response.data.data
        this.pagination = {
          current_page: response.data.current_page,
          per_page: response.data.per_page,
          total: response.data.total,
          last_page: response.data.last_page
        }
      } catch (err) {
        this.error = err.response?.data?.message || err.message
        throw err
      } finally {
        this.loading = false
      }
    },


    async fetchDocument(id) {
      this.loading = true
      this.error = null
      try {
        const response = await api.get(`/saraban/documents/${id}`)
        this.currentDocument = response.data
        return response.data
      } catch (err) {
        this.error = err.response?.data?.message || err.message
        throw err
      } finally {
        this.loading = false
      }
    },

    async createDocument(data) {
      // ... existing
      this.loading = true
      this.error = null
      try {
        let payload = data;
        let headers = {};
        
        // If data is not already FormData but has files, convert it
        if (!(data instanceof FormData) && data.files && data.files.length > 0) {
          payload = new FormData();
          for (const key in data) {
            if (key === 'files') {
              data.files.forEach(file => payload.append('files[]', file));
            } else if (key === 'links' || key === 'receivers' || key === 'receiver_departments') {
              if (Array.isArray(data[key])) {
                data[key].forEach((val, i) => {
                  if (typeof val === 'object') {
                    for (const objKey in val) {
                      payload.append(`${key}[${i}][${objKey}]`, val[objKey]);
                    }
                  } else {
                    payload.append(`${key}[]`, val);
                  }
                });
              }
            } else if (data[key] !== null && data[key] !== undefined) {
              payload.append(key, data[key]);
            }
          }
          headers = { 'Content-Type': 'multipart/form-data' };
        }

        const response = await api.post('/saraban/documents', payload, { headers })
        return response.data
      } catch (err) {
        this.error = err.response?.data?.message || err.message
        throw err
      } finally {
        this.loading = false
      }
    },

    async updateDocument(id, data) {
      this.loading = true
      this.error = null
      try {
        let payload = data;
        let headers = {};
        
        // If data has files, we must use FormData and spoof PUT method
        if (!(data instanceof FormData) && data.files && data.files.length > 0) {
          payload = new FormData();
          payload.append('_method', 'PUT'); // Spoof PUT
          for (const key in data) {
            if (key === 'files') {
              data.files.forEach(file => payload.append('files[]', file));
            } else if (key === 'links' || key === 'receivers' || key === 'receiver_departments') {
              if (Array.isArray(data[key])) {
                data[key].forEach((val, i) => {
                  if (typeof val === 'object') {
                    for (const objKey in val) {
                      payload.append(`${key}[${i}][${objKey}]`, val[objKey]);
                    }
                  } else {
                    payload.append(`${key}[]`, val);
                  }
                });
              }
            } else if (data[key] !== null && data[key] !== undefined) {
              payload.append(key, data[key]);
            }
          }
          headers = { 'Content-Type': 'multipart/form-data' };
          const response = await api.post(`/saraban/documents/${id}`, payload, { headers })
          return response.data
        } else {
          const response = await api.put(`/saraban/documents/${id}`, payload)
          return response.data
        }
      } catch (err) {
        this.error = err.response?.data?.message || err.message
        throw err
      } finally {
        this.loading = false
      }
    },

    async deleteDocument(id) {
      this.loading = true
      this.error = null
      try {
        await api.delete(`/saraban/documents/${id}`)
        this.documents = this.documents.filter(doc => doc.id !== id)
      } catch (err) {
        this.error = err.response?.data?.message || err.message
        throw err
      } finally {
        this.loading = false
      }
    },

    async deleteMultipleDocuments(ids) {
      this.loading = true
      this.error = null
      try {
        await api.delete('/saraban/documents/bulk', { data: { ids } })
        this.documents = this.documents.filter(doc => !ids.includes(doc.id))
      } catch (err) {
        this.error = err.response?.data?.message || err.message
        throw err
      } finally {
        this.loading = false
      }
    },

    async updateDocumentState(id, stateData) {
      try {
        const response = await api.post(`/saraban/documents/${id}/state`, stateData)
        // Update local document state if it exists in the list
        const index = this.documents.findIndex(d => d.id === id)
        if (index !== -1) {
          if (stateData.is_hidden) {
            this.documents.splice(index, 1)
          } else {
            this.documents[index] = { ...this.documents[index], ...stateData }
          }
        }
        return response.data
      } catch (err) {
        throw err
      }
    },

    async submitWorkflow(id, comment = '') {
      try {
        const response = await api.post(`/saraban/documents/${id}/workflow/submit`, { comment })
        return response.data
      } catch (err) {
        throw err
      }
    },

    async approveWorkflow(id, comment = '') {
      try {
        const response = await api.post(`/saraban/documents/${id}/workflow/approve`, { comment })
        return response.data
      } catch (err) {
        throw err
      }
    },

    async rejectWorkflow(id, comment) {
      try {
        const response = await api.post(`/saraban/documents/${id}/workflow/reject`, { comment })
        return response.data
      } catch (err) {
        throw err
      }
    },

    async signDocument(id, data) {
      try {
        const response = await api.post(`/saraban/documents/${id}/sign`, data)
        return response.data
      } catch (err) {
        throw err
      }
    },

    async sendCircular(id, userIds, message = '') {
      try {
        const response = await api.post(`/saraban/documents/${id}/circular`, { user_ids: userIds, message })
        return response.data
      } catch (err) {
        throw err
      }
    },

    async acknowledgeCircular(circularId) {
      try {
        const response = await api.post(`/saraban/circulars/${circularId}/acknowledge`)
        return response.data
      } catch (err) {
        throw err
      }
    }
  }
})
