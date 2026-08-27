<?php

namespace Database\Seeders;

use App\Models\Menu;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        // Define root menus
        $menus = [
            [
                'code' => 'dashboard',
                'title_th' => 'แผงควบคุม (Dashboard)',
                'title_en' => 'Dashboard',
                'module' => 'system',
                'icon' => 'LayoutDashboard',
                'route_name' => 'Dashboard',
                'route_path' => '/dashboard',
                'component_path' => 'views/Dashboard.vue',
                'sort_order' => 1,
                'permissions' => [],
            ],
            [
                'code' => 'leave',
                'title_th' => 'ระบบการลา',
                'title_en' => 'Leave System',
                'module' => 'leave',
                'icon' => 'Calendar',
                'route_name' => null,
                'route_path' => null,
                'sort_order' => 2,
                'children' => [
                    [
                        'code' => 'leave.my',
                        'title_th' => 'ยื่นใบลา / ประวัติการลา',
                        'title_en' => 'My Leaves',
                        'module' => 'leave',
                        'icon' => 'FileText',
                        'route_name' => 'LeaveHistory',
                        'route_path' => '/leaves',
                        'sort_order' => 1,
                        'permissions' => [],
                    ],
                    [
                        'code' => 'leave.approval',
                        'title_th' => 'อนุมัติการลา',
                        'title_en' => 'Approve Leaves',
                        'module' => 'leave',
                        'icon' => 'CheckSquare',
                        'route_name' => 'LeaveApprovals',
                        'route_path' => '/approvals',
                        'sort_order' => 2,
                        'permissions' => ['approve_leaves'],
                    ],
                    [
                        'code' => 'leave.calendar',
                        'title_th' => 'ปฏิทินการลาทีม',
                        'title_en' => 'Team Calendar',
                        'module' => 'leave',
                        'icon' => 'CalendarDays',
                        'route_name' => 'TeamCalendar',
                        'route_path' => '/calendar',
                        'sort_order' => 3,
                        'permissions' => [],
                    ],
                ],
            ],
            [
                'code' => 'attendance',
                'title_th' => 'ระบบลงเวลาเข้า-ออก',
                'title_en' => 'Attendance System',
                'module' => 'attendance',
                'icon' => 'Clock',
                'route_name' => null,
                'route_path' => null,
                'sort_order' => 3,
                'children' => [
                    [
                        'code' => 'attendance.my',
                        'title_th' => 'บันทึกเวลาของฉัน',
                        'title_en' => 'My Attendance',
                        'module' => 'attendance',
                        'icon' => 'UserCheck',
                        'route_name' => 'MyAttendance',
                        'route_path' => '/attendance/me',
                        'sort_order' => 1,
                        'permissions' => [],
                    ],
                    [
                        'code' => 'attendance.all',
                        'title_th' => 'ตรวจสอบเวลาทั้งหมด',
                        'title_en' => 'All Attendance Records',
                        'module' => 'attendance',
                        'icon' => 'Users',
                        'route_name' => 'AllAttendance',
                        'route_path' => '/admin/attendances',
                        'sort_order' => 2,
                        'permissions' => ['view_all_attendances'],
                    ],
                ],
            ],
            [
                'code' => 'admin',
                'title_th' => 'จัดการระบบ (Admin)',
                'title_en' => 'System Administration',
                'module' => 'system',
                'icon' => 'Settings',
                'route_name' => null,
                'route_path' => null,
                'sort_order' => 5,
                'children' => [
                    [
                        'code' => 'admin.users',
                        'title_th' => 'จัดการผู้ใช้งาน',
                        'title_en' => 'User Management',
                        'module' => 'system',
                        'icon' => 'Users',
                        'route_name' => 'AdminUsers',
                        'route_path' => '/admin/users',
                        'sort_order' => 1,
                        'permissions' => ['view_users'],
                    ],
                    [
                        'code' => 'admin.menus',
                        'title_th' => 'จัดการเมนูตามสิทธิ์',
                        'title_en' => 'Dynamic Menu Management',
                        'module' => 'system',
                        'icon' => 'Menu',
                        'route_name' => 'AdminMenus',
                        'route_path' => '/admin/menus',
                        'sort_order' => 2,
                        'permissions' => ['edit_users'],
                    ],
                ],
            ],
        ];

        foreach ($menus as $item) {
            $children = $item['children'] ?? [];
            $rootPermissions = $item['permissions'] ?? [];
            unset($item['children'], $item['permissions']);

            $menu = Menu::updateOrCreate(
                ['code' => $item['code']],
                $item
            );

            if (! empty($rootPermissions)) {
                $permIds = Permission::whereIn('name', $rootPermissions)->pluck('id');
                $menu->permissions()->sync($permIds);
            }

            foreach ($children as $childItem) {
                $childPermissions = $childItem['permissions'] ?? [];
                unset($childItem['permissions']);

                $childItem['parent_id'] = $menu->id;
                $childMenu = Menu::updateOrCreate(
                    ['code' => $childItem['code']],
                    $childItem
                );

                if (! empty($childPermissions)) {
                    $permIds = Permission::whereIn('name', $childPermissions)->pluck('id');
                    $childMenu->permissions()->sync($permIds);
                }
            }
        }
    }
}
