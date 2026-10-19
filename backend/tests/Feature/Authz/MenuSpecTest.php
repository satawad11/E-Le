<?php

namespace Tests\Feature\Authz;

use App\Authz\Manifest\ModuleRegistry;
use Tests\TestCase;

/**
 * manifest ของ core ต้องประกาศเมนูตรงตาม PLAN §8.2 ก., ข., ช., ซ. ทุกแถว (route · สิทธิ์ · scope · ลำดับ · อยู่ในเมนูหรือไม่)
 */
class MenuSpecTest extends TestCase
{
    /** [group, route, permission(s) หรือ pattern, scopes|null, in_menu] ตามตาราง PLAN §8.2 */
    private const SPEC = [
        // ก. ส่วนบุคคล (core, order 0)
        ['core', '/', null, null, true],
        ['core', '/inbox', ['*.*.approve', '*.*.review'], null, true],
        ['core', '/me', 'personnel.profile.view', ['own'], true],
        ['core', '/me/access-history', 'personnel.profile.view', ['own'], true],
        ['core', '/notifications', null, null, true],
        // ข. ข้อมูลบุคลากร (personnel, order 10)
        ['personnel', '/personnel', 'personnel.profile.view', ['unit', 'subtree', 'all', 'assigned'], true],
        ['personnel', '/personnel/:uuid', 'personnel.profile.view', null, false],
        ['personnel', '/personnel/change-requests', 'personnel.change_request.review', null, true],
        ['personnel', '/org', ['org.unit.view', 'org.unit.manage'], null, true],
        ['personnel', '/org/positions', 'org.position.manage', null, true],
        ['personnel', '/org/assignments', 'personnel.assignment.manage', null, true],
        ['personnel', '/personnel/reports', 'personnel.report.view', ['subtree', 'all'], true],
        // ช. บัญชีและสิทธิ์ (access, order 90)
        ['access', '/access/accounts', 'access.account.view', null, true],
        ['access', '/access/accounts/:uuid', 'access.account.view', null, false],
        ['access', '/access/roles', 'access.role.view', null, true],
        ['access', '/access/modules', 'access.template.manage', null, true],
        ['access', '/access/change-requests', 'access.change.approve', null, true],
        ['access', '/access/reviews', ['access.review.confirm', 'access.review.manage'], null, true],
        ['access', '/access/audit', 'audit.log.view', null, true],
        // ซ. ตั้งค่าระบบ (system, order 99)
        ['system', '/system/modules', 'system.module.manage', null, true],
        ['system', '/system/workflows', 'workflow.definition.manage', null, true],
        ['system', '/system/documents', 'document.template.manage', null, true],
        ['system', '/system/notifications', 'notification.channel.manage', null, true],
        ['system', '/system/settings', 'system.setting.manage', null, true],
    ];

    public function test_every_menu_row_of_plan_section_8_2_is_declared(): void
    {
        $menus = collect(app(ModuleRegistry::class)->menus())->keyBy('route');

        foreach (self::SPEC as [$group, $route, $permission, $scopes, $inMenu]) {
            $this->assertTrue($menus->has($route), "ไม่มีเมนู {$route}");
            $menu = $menus[$route];
            $this->assertSame($group, $menu['group'], "{$route}: กลุ่ม");
            $this->assertSame($inMenu, $menu['in_menu'], "{$route}: in_menu");
            $declared = $menu['permission_pattern'] ?? $menu['permission'];
            $this->assertEquals($permission, $declared, "{$route}: สิทธิ์");
            $this->assertEquals($scopes, $menu['scopes'], "{$route}: scope");
        }

        $coreGroups = ['core', 'personnel', 'access', 'system'];
        $this->assertCount(
            count(self::SPEC),
            $menus->filter(fn ($m) => in_array($m['group'], $coreGroups, true)),
            'มีเมนูในกลุ่ม core ที่ไม่อยู่ในผัง PLAN §8.2'
        );
    }

    public function test_group_order_and_item_order_follow_the_plan(): void
    {
        $registry = app(ModuleRegistry::class);

        $this->assertSame(['core' => 0, 'personnel' => 10, 'access' => 90, 'system' => 99],
            collect($registry->menuGroups())->pluck('order', 'code')->all());

        $order = fn (string $group) => collect($registry->menus())->where('group', $group)->where('in_menu', true)->whereNull('parent')
            ->sortBy('order')->pluck('route')->values()->all();

        $this->assertSame(['/', '/inbox', '/me', '/notifications'], $order('core'));
        $this->assertSame(['/personnel', '/personnel/change-requests', '/org', '/org/positions', '/org/assignments', '/personnel/reports'], $order('personnel'));
        $this->assertSame(['/access/accounts', '/access/roles', '/access/modules', '/access/change-requests', '/access/reviews', '/access/audit'], $order('access'));
        $this->assertSame(['/system/modules', '/system/workflows', '/system/documents', '/system/notifications', '/system/settings'], $order('system'));
    }
}
