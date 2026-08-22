import js from '@eslint/js'
import pluginVue from 'eslint-plugin-vue'
import globals from 'globals'

/*
 * QUALITY-002 — โครงการนี้ไม่เคยมี linter มาก่อน
 *
 * ตั้งไว้ที่ระดับ "จับบั๊กจริง" (recommended ของ JS + essential ของ Vue)
 * ไม่ใช่ระดับจัดรูปแบบโค้ด เพื่อให้ทุกข้อที่ฟ้องเป็นข้อที่ควรแก้จริง ๆ
 * ไม่ใช่เสียงรบกวนที่ทำให้ทีมเลิกสนใจผลลัพธ์ของ lint
 */
export default [
  {
    ignores: ['dist/**', 'node_modules/**', 'public/**', 'coverage/**'],
  },

  js.configs.recommended,
  ...pluginVue.configs['flat/essential'],

  {
    files: ['**/*.{js,vue}'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: {
        ...globals.browser,
      },
    },
    rules: {
      // ตัวแปรที่ประกาศแล้วไม่ใช้มักเป็นร่องรอยของโค้ดที่แก้ค้างไว้
      // ยกเว้นชื่อขึ้นต้นด้วย _ ซึ่งเป็นธรรมเนียมของ "ตั้งใจไม่ใช้"
      'no-unused-vars': ['error', {
        argsIgnorePattern: '^_',
        varsIgnorePattern: '^_',
        caughtErrorsIgnorePattern: '^_',
      }],

      // ห้ามหลุด console/debugger ขึ้น production
      'no-console': ['warn', { allow: ['warn', 'error'] }],
      'no-debugger': 'error',

      // ชื่อ component หลายคำเป็นข้อแนะนำด้านสไตล์ ไม่ใช่บั๊ก
      'vue/multi-word-component-names': 'off',
    },
  },

  {
    files: ['**/*.test.js', '**/*.spec.js', 'tests/**/*.js'],
    languageOptions: {
      globals: {
        ...globals.node,
      },
    },
  },

  {
    files: ['vite.config.js', 'vitest.config.js', 'eslint.config.js'],
    languageOptions: {
      globals: {
        ...globals.node,
      },
    },
  },
]
