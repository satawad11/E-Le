#!/usr/bin/env node
/**
 * ตรวจข้อความภาษาอังกฤษที่ผู้ใช้เห็นในแม่แบบ Vue (REDESIGN-2569 · มาตรฐานภาษาไทย ข้อ 4)
 *
 * อ่าน text node และ attribute ที่ผู้ใช้เห็น (placeholder, title, aria-label, label, alt) ของทุกไฟล์ .vue
 * แล้วรายงานคำอักษรละตินที่ไม่อยู่ในรายการยกเว้น (thai-allowlist.json)
 *
 * รายการที่มีอยู่แล้วเก็บใน thai-baseline.json — CI ล้มเฉพาะเมื่อพบรายการใหม่ เพื่อทยอยแก้ของเดิมได้
 *   node scripts/check-thai.mjs                 ตรวจเทียบ baseline (ใช้ใน CI)
 *   node scripts/check-thai.mjs --update-baseline   บันทึกรายการปัจจุบันเป็น baseline (หลังแก้ของเดิมแล้ว)
 */
import { readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs'
import { join, relative, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const root = join(here, '..')
const srcDir = join(root, 'src')
const allowlist = new Set(JSON.parse(readFileSync(join(here, 'thai-allowlist.json'), 'utf8')).map((w) => w.toLowerCase()))
const baselinePath = join(here, 'thai-baseline.json')
const VISIBLE_ATTRS = ['placeholder', 'title', 'aria-label', 'label', 'alt']

function walk(dir) {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    return statSync(path).isDirectory() ? walk(path) : path.endsWith('.vue') ? [path] : []
  })
}

function templateOf(source) {
  // แม่แบบระดับบนสุด: <template> ต้นบรรทัดแรก ถึง </template> ต้นบรรทัดสุดท้าย (ไม่หลุดเข้า <script>)
  const start = source.search(/^<template/m)
  const ends = [...source.matchAll(/^<\/template>/gm)]
  return start === -1 || !ends.length ? '' : source.slice(start, ends[ends.length - 1].index)
}

function englishWords(text) {
  // นิพจน์ของ Vue ที่มีเครื่องหมาย > (เช่น v-if="a > 0 && b") ไม่ใช่ข้อความ
  if (/&&|\|\||=>/.test(text)) return []
  text = text.replace(/&[a-z#0-9]+;/gi, ' ')
  return (text.match(/[A-Za-z][A-Za-z'-]+/g) || []).filter((w) => !allowlist.has(w.toLowerCase()))
}

function lineOf(source, index) {
  return source.slice(0, index).split('\n').length
}

function findings(file) {
  const source = readFileSync(file, 'utf8')
  const template = templateOf(source)
  const offset = source.indexOf(template)
  // ตัดคอมเมนต์และ {{ นิพจน์ }} ออก แต่คงความยาวไว้เพื่อให้เลขบรรทัดถูก
  const blank = (m) => m.replace(/[^\n]/g, ' ')
  const cleaned = template.replace(/<!--[\s\S]*?-->/g, blank).replace(/\{\{[\s\S]*?\}\}/g, blank)
  const out = []

  for (const m of cleaned.matchAll(/>([^<>]+)</g)) {
    const text = m[1].trim()
    if (text && englishWords(text).length) out.push({ line: lineOf(source, offset + m.index), text })
  }
  for (const attr of VISIBLE_ATTRS) {
    for (const m of cleaned.matchAll(new RegExp(`\\s${attr}="([^"]*)"`, 'g'))) {
      const text = m[1].trim()
      if (text && englishWords(text).length) out.push({ line: lineOf(source, offset + m.index), text: `${attr}="${text}"` })
    }
  }
  return out
}

const current = {}
for (const file of walk(srcDir).sort()) {
  const rel = relative(root, file).replaceAll('\\', '/')
  const items = findings(file)
  if (items.length) current[rel] = items
}

if (process.argv.includes('--update-baseline')) {
  const baseline = Object.fromEntries(Object.entries(current).map(([f, items]) => [f, [...new Set(items.map((i) => i.text))].sort()]))
  writeFileSync(baselinePath, JSON.stringify(baseline, null, 2) + '\n')
  console.log(`บันทึก baseline แล้ว ${Object.values(baseline).flat().length} รายการ`)
  process.exit(0)
}

const baseline = JSON.parse(readFileSync(baselinePath, 'utf8'))
const fresh = []
for (const [file, items] of Object.entries(current)) {
  const known = new Set(baseline[file] || [])
  for (const item of items) if (!known.has(item.text)) fresh.push(`${file}:${item.line}  ${item.text}`)
}

const total = Object.values(current).flat().length
if (fresh.length) {
  console.error('พบข้อความภาษาอังกฤษใหม่ในหน้าจอ (มาตรฐานภาษาไทย 100%):')
  for (const line of fresh) console.error('  ' + line)
  console.error('\nแปลเป็นภาษาไทย หรือถ้าเป็นชื่อเฉพาะให้เพิ่มใน scripts/thai-allowlist.json')
  process.exit(1)
}
console.log(`ไม่พบข้อความภาษาอังกฤษใหม่ (ยังค้างจากของเดิม ${total} รายการใน thai-baseline.json)`)
