<?php

namespace App\Authz\Menu;

use App\Authz\Grant;
use App\Authz\Manifest\ModuleRegistry;
use App\Authz\PermissionEngine;
use App\Models\OrgUnit;
use App\Models\PersonnelChangeRequest;
use App\Models\User;

/**
 * เมนูของผู้ใช้จาก manifest กรองตามสิทธิ์ (PLAN §8.1 · R04-6)
 *
 * 1. เมนูแสดงเมื่อมี permission ที่กำหนดอย่างน้อยหนึ่ง scope (ถ้าเมนูระบุ scopes ต้องมี scope ในชุดนั้น)
 * 2. กลุ่มแสดงเมื่อมีเมนูที่มองเห็นอย่างน้อยหนึ่งรายการ
 * 4. ชื่อปรับตาม scope: ถ้าแคบกว่า all และยึดหน่วยเดียว แสดงชื่อหน่วย ("บุคลากรแผนกวิชาช่างยนต์")
 * 5. badge นับเฉพาะรายการในขอบเขต
 * 6. โมดูลที่ปิดไม่แสดงเมนูใดเลย
 * 7. หน้ารายละเอียด (`in_menu: false`) ไม่อยู่ในเมนู
 */
class MenuBuilder
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly PermissionEngine $engine,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function forUser(User $user): array
    {
        $effective = $this->engine->effective($user)->map(fn (Grant $g) => $g->permission)->unique()->values()->all();

        $visible = [];
        foreach ($this->registry->menus() as $menu) {
            if (! $menu['in_menu'] || ! $this->registry->isEnabled($menu['module'])) {
                continue;
            }
            $item = $this->resolve($user, $menu, $effective);
            if ($item !== null) {
                $visible[$menu['code']] = $item;
            }
        }

        // เมนูย่อยแสดงเมื่อเมนูแม่แสดง
        foreach ($visible as $code => $item) {
            if ($item['parent'] !== null) {
                if (isset($visible[$item['parent']])) {
                    $visible[$item['parent']]['children'][] = $this->publicItem($item);
                }
                unset($visible[$code]);
            }
        }

        $groups = [];
        foreach ($this->registry->menuGroups() as $group) {
            $items = array_values(array_filter($visible, fn ($i) => $i['group'] === $group['code']));
            if ($items === []) {
                continue;
            }
            usort($items, fn ($a, $b) => $a['order'] <=> $b['order']);
            $groups[] = [
                'code' => $group['code'],
                'name' => $group['name'],
                'icon' => $group['icon'],
                'order' => $group['order'],
                'items' => array_map(fn ($i) => $this->publicItem($i), $items),
            ];
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $menu
     * @param  array<int, string>  $effective
     * @return array<string, mixed>|null
     */
    private function resolve(User $user, array $menu, array $effective): ?array
    {
        $scope = null;
        $permissionUsed = null;

        if ($menu['permission_pattern'] !== null) {
            $matched = collect($effective)->first(fn ($p) => collect((array) $menu['permission_pattern'])->contains(fn ($pattern) => fnmatch($pattern, $p)));
            if ($matched === null) {
                return null;
            }
        } elseif ($menu['permission'] !== null) {
            foreach ((array) $menu['permission'] as $permission) {
                $scopes = $this->engine->scopesOf($user, $permission);
                $allowed = $menu['scopes'] === null ? $scopes : array_values(array_intersect($scopes, (array) $menu['scopes']));
                if ($allowed !== []) {
                    $permissionUsed = $permission;
                    $scope = in_array('all', $allowed, true) ? 'all' : $allowed[0];
                    break;
                }
            }
            if ($permissionUsed === null) {
                return null;
            }
        }

        return [
            'code' => $menu['code'],
            'group' => $menu['group'],
            'parent' => $menu['parent'],
            'order' => $menu['order'],
            'name' => $this->name($user, $menu, $permissionUsed, $scope),
            'route' => $menu['route'],
            'scope' => $scope,
            'badge' => $menu['badge'] !== null ? $this->badge($user, $menu['badge']) : null,
            'children' => [],
        ];
    }

    /** @param  array<string, mixed>  $menu */
    private function name(User $user, array $menu, ?string $permission, ?string $scope): string
    {
        if ($menu['scoped_name'] === null || $permission === null || $scope === 'all' || $scope === 'own') {
            return $menu['name'];
        }

        $units = $this->engine->anchorUnitIds($user, $permission);
        if (count($units) === 1) {
            return str_replace('{unit}', (string) OrgUnit::whereKey($units[0])->value('name'), $menu['scoped_name']);
        }

        return $menu['name'].' ('.count($units).' หน่วย)';
    }

    private function badge(User $user, string $provider): ?int
    {
        return match ($provider) {
            'personnel.change_requests.pending' => $this->engine
                ->scopeQuery($user, 'personnel.change_request.review', PersonnelChangeRequest::where('status', 'pending'))
                ->where('requested_by', '!=', $user->id)
                ->count(),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function publicItem(array $item): array
    {
        return array_intersect_key($item, array_flip(['code', 'name', 'route', 'scope', 'badge', 'children']));
    }
}
