<?php

namespace App\Authz\Manifest;

/**
 * ตรวจ manifest ทั้งชุดก่อนใช้งาน — ผิดข้อใดโยน ManifestException พร้อมรายการทั้งหมด
 *
 * - รหัสโมดูล/feature/action เป็น snake_case · scope อยู่ในชุดที่รองรับ · risk และ rules รู้จัก
 * - `requires` และ permission ใน template/เมนูต้องมีอยู่จริง · scope ใน template อยู่ใน allowed_scopes
 * - template ระบุ `denies` ด้วย pattern `module.*` หรือชื่อเต็ม · เพดานเป็น scope ที่รองรับหรือ null
 * - defaults อ้าง template แบบ positional ที่มีอยู่ ระดับ 1–5 · เมนูอ้างกลุ่มและ parent ที่มีอยู่ รหัสไม่ซ้ำ
 */
class ManifestValidator
{
    /** @param  array<string, array<string, mixed>>  $modules */
    public function validate(array $modules): void
    {
        $errors = [];
        $permissions = [];
        foreach ($modules as $module) {
            foreach ($module['features'] as $feature) {
                foreach ($feature['actions'] as $action) {
                    $permissions[$action['permission']] = $action;
                }
            }
        }
        $templates = [];
        foreach ($modules as $module) {
            $templates += $module['templates'];
        }
        $groups = [];
        $menuCodes = [];
        foreach ($modules as $module) {
            foreach ($module['menu_groups'] as $g) {
                $groups[$g['code']] = true;
            }
            foreach ($module['menus'] as $m) {
                if (isset($menuCodes[$m['code']])) {
                    $errors[] = "เมนู {$m['code']} ประกาศซ้ำ";
                }
                $menuCodes[$m['code']] = true;
            }
        }

        foreach ($modules as $code => $module) {
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $code)) {
                $errors[] = "รหัสโมดูล {$code} ต้องเป็น snake_case";
            }
            foreach ($module['features'] as $feature) {
                foreach ($feature['actions'] as $action) {
                    $name = $action['permission'];
                    if (! preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $name)) {
                        $errors[] = "ชื่อ permission {$name} ต้องเป็น module.feature.action";
                    }
                    if ($action['scopes'] === [] || array_diff($action['scopes'], ModuleRegistry::SCOPES)) {
                        $errors[] = "{$name}: scope ไม่ถูกต้อง";
                    }
                    if (! in_array($action['risk'], ModuleRegistry::RISKS, true)) {
                        $errors[] = "{$name}: risk ไม่ถูกต้อง";
                    }
                    foreach (array_diff($action['rules'], ModuleRegistry::RULES) as $rule) {
                        $errors[] = "{$name}: ไม่รู้จักกฎ {$rule}";
                    }
                    foreach ($action['requires'] as $required) {
                        if (! isset($permissions[$required])) {
                            $errors[] = "{$name}: requires {$required} ไม่มีอยู่";
                        }
                    }
                }
            }

            foreach ($module['templates'] as $template) {
                if (! in_array($template['kind'], ['positional', 'functional'], true)) {
                    $errors[] = "template {$template['code']}: kind ต้องเป็น positional หรือ functional";
                }
                foreach ($template['permissions'] as $permission => $scope) {
                    if (! isset($permissions[$permission])) {
                        $errors[] = "template {$template['code']}: ไม่มี permission {$permission}";
                    } elseif (! in_array($scope, $permissions[$permission]['scopes'], true)) {
                        $errors[] = "template {$template['code']}: {$permission} ไม่รองรับ scope {$scope}";
                    }
                }
                foreach ($template['denies'] as $pattern => $ceiling) {
                    if (! str_ends_with($pattern, '.*') && ! isset($permissions[$pattern])) {
                        $errors[] = "template {$template['code']}: denies {$pattern} ไม่ใช่ pattern module.* หรือ permission ที่มีอยู่";
                    }
                    if ($ceiling !== null && ! in_array($ceiling, ModuleRegistry::SCOPES, true)) {
                        $errors[] = "template {$template['code']}: เพดาน {$ceiling} ไม่ถูกต้อง";
                    }
                }
            }

            foreach ($module['defaults'] as $level => $codes) {
                if ($level < 1 || $level > 5) {
                    $errors[] = "{$code}: defaults ระดับ L{$level} ต้องอยู่ระหว่าง L1–L5";
                }
                foreach ($codes as $templateCode) {
                    if (($templates[$templateCode]['kind'] ?? null) !== 'positional') {
                        $errors[] = "{$code}: defaults L{$level} อ้าง {$templateCode} ที่ไม่ใช่ template แบบ positional";
                    }
                }
            }

            foreach ($module['menus'] as $menu) {
                if (! isset($groups[$menu['group']])) {
                    $errors[] = "เมนู {$menu['code']}: ไม่มีกลุ่ม {$menu['group']}";
                }
                if ($menu['parent'] !== null && ! isset($menuCodes[$menu['parent']])) {
                    $errors[] = "เมนู {$menu['code']}: ไม่มี parent {$menu['parent']}";
                }
                foreach ((array) $menu['permission'] as $permission) {
                    if (! isset($permissions[$permission])) {
                        $errors[] = "เมนู {$menu['code']}: ไม่มี permission {$permission}";
                    }
                }
                foreach ((array) ($menu['scopes'] ?? []) as $scope) {
                    if (! in_array($scope, ModuleRegistry::SCOPES, true)) {
                        $errors[] = "เมนู {$menu['code']}: scope {$scope} ไม่ถูกต้อง";
                    }
                }
            }
        }

        if ($errors !== []) {
            throw new ManifestException("manifest ไม่ผ่านการตรวจ:\n- ".implode("\n- ", $errors));
        }
    }
}
