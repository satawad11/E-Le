<template>
  <div class="space-y-2">
    <div v-if="label" class="flex items-center justify-between">
      <label class="block text-sm font-semibold text-gray-700">
        {{ label }}
        <span v-if="required" class="text-red-500">*</span>
      </label>
      <span v-if="maxSizeMb" class="text-xs text-gray-400">ขนาดสูงสุด {{ maxSizeMb }} MB</span>
    </div>

    <!-- Drop Zone Box -->
    <div
      class="border-2 border-dashed rounded-2xl p-5 text-center transition-all duration-200 relative cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-[#800000]"
      :class="isDragging
        ? 'border-[#800000] bg-[#800000]/5 scale-[1.01]'
        : errorMsg
          ? 'border-red-300 bg-red-50/50'
          : 'border-gray-200 hover:border-[#800000]/60 bg-gray-50/50 hover:bg-gray-50'"
      role="button"
      tabindex="0"
      :aria-label="label || 'พื้นที่ลากและวางไฟล์แนบ'"
      @dragover.prevent="onDragOver"
      @dragleave.prevent="onDragLeave"
      @drop.prevent="onDrop"
      @click="triggerFileInput"
      @keydown.enter.prevent="triggerFileInput"
      @keydown.space.prevent="triggerFileInput"
    >
      <input
        ref="fileInputRef"
        type="file"
        :accept="accept"
        :multiple="multiple"
        class="hidden"
        aria-hidden="true"
        @change="handleInputChange"
      >

      <!-- Empty State inside Dropzone -->
      <div v-if="!selectedFiles.length" class="flex flex-col items-center justify-center py-2">
        <div class="w-12 h-12 mb-3 rounded-2xl bg-white shadow-sm border border-gray-100 flex items-center justify-center text-[#800000] transition-transform group-hover:scale-110">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
          </svg>
        </div>
        <p class="text-sm font-semibold text-gray-700 mb-1">
          <span class="text-[#800000] hover:underline">คลิกเลือกไฟล์</span> หรือลากไฟล์มาวางที่นี่
        </p>
        <p class="text-xs text-gray-500">
          {{ helperText || `รองรับไฟล์ ${acceptFormatted} (ขนาดไม่เกิน ${maxSizeMb}MB)` }}
        </p>
      </div>

      <!-- File Previews (When files are selected) -->
      <div v-else class="space-y-3 text-left" @click.stop>
        <div
          v-for="(fileItem, index) in selectedFiles"
          :key="index"
          class="flex items-center justify-between p-3 bg-white rounded-xl border border-gray-100 shadow-sm"
        >
          <div class="flex items-center gap-3 min-w-0">
            <!-- Image thumbnail or file type icon -->
            <div class="w-10 h-10 rounded-lg bg-gray-100 border border-gray-200 overflow-hidden flex items-center justify-center flex-shrink-0">
              <img
                v-if="fileItem.previewUrl"
                :src="fileItem.previewUrl"
                alt="รูปตัวอย่างไฟล์"
                class="w-full h-full object-cover"
              >
              <svg v-else-if="fileItem.isPdf" class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
              </svg>
              <svg v-else class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
              </svg>
            </div>

            <!-- File details -->
            <div class="min-w-0 flex-1">
              <p class="text-sm font-semibold text-gray-900 truncate">{{ fileItem.file.name }}</p>
              <p class="text-xs text-gray-400">{{ formatFileSize(fileItem.file.size) }}</p>
            </div>
          </div>

          <!-- Actions -->
          <div class="flex items-center gap-2">
            <button
              type="button"
              class="p-1.5 text-gray-400 hover:text-red-600 rounded-lg hover:bg-red-50 transition-colors focus:outline-none"
              title="ลบไฟล์นี้"
              aria-label="ลบไฟล์นี้"
              @click.stop="removeFile(index)"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
              </svg>
            </button>
          </div>
        </div>

        <div class="flex justify-end pt-1">
          <button
            type="button"
            class="text-xs text-[#800000] font-semibold hover:underline flex items-center gap-1"
            @click.stop="triggerFileInput"
          >
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            เปลี่ยนไฟล์
          </button>
        </div>
      </div>
    </div>

    <!-- Error message display -->
    <p v-if="errorMsg" class="text-xs text-red-600 flex items-center gap-1 mt-1">
      <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      {{ errorMsg }}
    </p>
  </div>
</template>

<script setup>
import { ref, computed, onBeforeUnmount } from 'vue'

const props = defineProps({
  modelValue: { type: [File, Array, null], default: null },
  label: { type: String, default: '' },
  required: { type: Boolean, default: false },
  multiple: { type: Boolean, default: false },
  accept: { type: String, default: '.pdf,.jpg,.jpeg,.png' },
  maxSizeMb: { type: Number, default: 5 },
  helperText: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'change', 'error'])

const fileInputRef = ref(null)
const isDragging = ref(false)
const errorMsg = ref('')
const selectedFiles = ref([])

const acceptFormatted = computed(() => {
  return props.accept.split(',').map((ext) => ext.trim().toUpperCase().replace('.', '')).join(', ')
})

const formatFileSize = (bytes) => {
  if (!bytes || bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

const triggerFileInput = () => {
  if (fileInputRef.value) {
    fileInputRef.value.click()
  }
}

const onDragOver = () => {
  isDragging.value = true
}

const onDragLeave = () => {
  isDragging.value = false
}

const validateFile = (file) => {
  const maxBytes = props.maxSizeMb * 1024 * 1024
  if (file.size > maxBytes) {
    return `ขนาดไฟล์ "${file.name}" เกินกำหนด (${props.maxSizeMb} MB)`
  }

  if (props.accept) {
    const acceptedExtensions = props.accept.split(',').map((ext) => ext.trim().toLowerCase())
    const fileName = file.name.toLowerCase()
    const isAccepted = acceptedExtensions.some((ext) => {
      if (ext.startsWith('.')) {
        return fileName.endsWith(ext)
      }
      return file.type.match(new RegExp(ext.replace('*', '.*')))
    })
    if (!isAccepted) {
      return `ไฟล์ "${file.name}" ไม่ตรงตามประเภทที่อนุญาต (${acceptFormatted.value})`
    }
  }
  return null
}

const processFiles = (rawFileList) => {
  errorMsg.value = ''
  const filesArray = Array.from(rawFileList)
  if (!filesArray.length) return

  const validItems = []
  for (const file of filesArray) {
    const validationError = validateFile(file)
    if (validationError) {
      errorMsg.value = validationError
      emit('error', validationError)
      return
    }

    const isImage = file.type.startsWith('image/')
    const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf')
    let previewUrl = null
    if (isImage) {
      previewUrl = URL.createObjectURL(file)
    }

    validItems.push({
      file,
      previewUrl,
      isPdf,
    })
  }

  // Cleanup old preview URLs
  selectedFiles.value.forEach((item) => {
    if (item.previewUrl) URL.revokeObjectURL(item.previewUrl)
  })

  if (props.multiple) {
    selectedFiles.value = [...selectedFiles.value, ...validItems]
    emit('update:modelValue', selectedFiles.value.map((i) => i.file))
  } else {
    selectedFiles.value = validItems.slice(0, 1)
    emit('update:modelValue', selectedFiles.value[0]?.file || null)
  }
  emit('change', props.multiple ? selectedFiles.value.map((i) => i.file) : selectedFiles.value[0]?.file)
}

const onDrop = (e) => {
  isDragging.value = false
  if (e.dataTransfer && e.dataTransfer.files) {
    processFiles(e.dataTransfer.files)
  }
}

const handleInputChange = (e) => {
  if (e.target && e.target.files) {
    processFiles(e.target.files)
  }
}

const removeFile = (index) => {
  const item = selectedFiles.value[index]
  if (item && item.previewUrl) {
    URL.revokeObjectURL(item.previewUrl)
  }
  selectedFiles.value.splice(index, 1)
  if (fileInputRef.value) {
    fileInputRef.value.value = ''
  }

  if (props.multiple) {
    emit('update:modelValue', selectedFiles.value.map((i) => i.file))
  } else {
    emit('update:modelValue', null)
  }
  emit('change', props.multiple ? selectedFiles.value.map((i) => i.file) : null)
}

onBeforeUnmount(() => {
  selectedFiles.value.forEach((item) => {
    if (item.previewUrl) URL.revokeObjectURL(item.previewUrl)
  })
})
</script>
