<?php

use App\Models\Menu;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        $leave = Menu::where('code', 'leave')->first();
        if (! $leave) {
            return;
        }
        $items = [
            ['code' => 'leave.services', 'title_th' => 'บริการและเอกสารใบลา', 'title_en' => 'Leave Services', 'icon' => 'Files', 'route_name' => 'LeaveServices', 'route_path' => '/leave-services', 'sort_order' => 4, 'permission' => null],
            ['code' => 'leave.governance', 'title_th' => 'ศูนย์งานกำกับการลา', 'title_en' => 'Leave Governance', 'icon' => 'ShieldCheck', 'route_name' => 'LeaveGovernance', 'route_path' => '/admin/leave-governance', 'sort_order' => 5, 'permission' => 'leave.approval.hr'],
            ['code' => 'leave.government_reports', 'title_th' => 'รายงานการลาราชการ', 'title_en' => 'Government Leave Reports', 'icon' => 'BarChart3', 'route_name' => 'GovernmentLeaveReports', 'route_path' => '/reports', 'sort_order' => 6, 'permission' => 'leave.report.view'],
        ];
        foreach ($items as $item) {
            $permission = $item['permission'];
            unset($item['permission']);
            $menu = Menu::updateOrCreate(['code' => $item['code']], $item + ['module' => 'leave', 'parent_id' => $leave->id, 'is_active' => true, 'is_external' => false]);
            $permission ? $menu->permissions()->sync([Permission::where('name', $permission)->value('id')]) : $menu->permissions()->detach();
        }
    }

    public function down(): void
    {
        Menu::whereIn('code', ['leave.services', 'leave.governance', 'leave.government_reports'])->delete();
    }
};
