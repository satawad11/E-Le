#!/usr/bin/env bash
# REBUILD-2569 — ตรวจรูปแบบที่ห้ามใช้ในการตรวจสิทธิ์ (CODEX-RUNBOOK §2.3 · AGENTS.md hard gates)
# รันจากโฟลเดอร์ backend: bash scripts/check-authz-patterns.sh
set -u
cd "$(dirname "$0")/.." || exit 2
fail=0

forbidden=$(grep -rnE 'hasRole\(|Gate::before|Spatie\Permission|User::permission\(' app bootstrap config routes database/migrations database/seeders database/factories || true)
if [ -n "$forbidden" ]; then
  echo "::error::พบรูปแบบตรวจสิทธิ์ที่ห้ามใช้ (hasRole / Gate::before / spatie):"
  echo "$forbidden"
  fail=1
fi

# ชื่อ role/ตำแหน่งเป็น literal ในตรรกะ — manifest เป็นข้อมูลประกาศ จึงไม่นับ
literal_files=$(grep -rlE "'(Super Admin|SuperAdmin|Director|Deputy Director|Head of Department|HR Head|ผู้อำนวยการ|รองผู้อำนวยการ|หัวหน้)" app --include=*.php | grep -v '^app/Modules/.*/manifest\.php$' | sort || true)
allow=$(grep -vE '^\s*(#|$)' scripts/role-literal-allowlist.txt | sort)
new_files=$(comm -23 <(echo "$literal_files" | sed '/^$/d') <(echo "$allow" | sed '/^$/d'))
if [ -n "$new_files" ]; then
  echo "::error::พบชื่อ role/ตำแหน่งเป็น literal ในไฟล์ที่ไม่อยู่ใน allowlist (โค้ดใหม่ต้องใช้ PermissionEngine/manifest):"
  echo "$new_files"
  fail=1
fi
stale=$(comm -13 <(echo "$literal_files" | sed '/^$/d') <(echo "$allow" | sed '/^$/d'))
if [ -n "$stale" ]; then
  echo "::warning::ไฟล์ใน allowlist ไม่มี literal แล้ว ให้ลบออกจาก scripts/role-literal-allowlist.txt:"
  echo "$stale"
fi

[ "$fail" -eq 0 ] && echo "authz pattern check: ผ่าน"
exit "$fail"
