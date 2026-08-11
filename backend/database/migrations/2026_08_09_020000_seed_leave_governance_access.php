<?php

use App\Models\Menu;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        $names = ['leave.approval.hr', 'leave.evidence.verify', 'leave.policy.manage', 'leave.audit.view', 'leave.calendar.manage'];
        $permissions = collect($names)->mapWithKeys(fn ($name) => [$name => Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard])]);

        Role::whereHas('permissions', fn ($query) => $query->whereIn('name', ['leave.management.manage', 'manage_leave_types']))
            ->each(fn ($role) => $role->givePermissionTo($permissions->values()));

        $admin = Menu::where('code', 'admin')->first();
        if (!$admin) return;

        $items = [
            ['code' => 'admin.leave_policies', 'title_th' => 'นโยบายและสิทธิการลา', 'title_en' => 'Leave Policies', 'icon' => 'ClipboardList', 'route_name' => 'AdminLeavePolicies', 'route_path' => '/admin/leave-policies', 'sort_order' => 30, 'permission' => 'leave.policy.manage'],
            ['code' => 'admin.personnel_types', 'title_th' => 'ประเภทบุคลากร', 'title_en' => 'Personnel Types', 'icon' => 'UsersRound', 'route_name' => 'AdminPersonnelTypes', 'route_path' => '/admin/personnel-types', 'sort_order' => 31, 'permission' => 'edit_users'],
            ['code' => 'admin.leave_calendar', 'title_th' => 'ปฏิทินวันทำการ', 'title_en' => 'Work Calendar', 'icon' => 'CalendarCog', 'route_name' => 'AdminLeaveCalendar', 'route_path' => '/admin/leave-calendar', 'sort_order' => 32, 'permission' => 'leave.calendar.manage'],
        ];
        foreach ($items as $item) {
            $permission = $item['permission']; unset($item['permission']);
            $menu = Menu::updateOrCreate(['code' => $item['code']], $item + ['module' => 'leave', 'parent_id' => $admin->id, 'is_active' => true, 'is_external' => false]);
            $menu->permissions()->sync([Permission::where('name', $permission)->value('id')]);
        }
    }

    public function down(): void
    {
        Menu::whereIn('code', ['admin.leave_policies', 'admin.personnel_types', 'admin.leave_calendar'])->delete();
    }
};
