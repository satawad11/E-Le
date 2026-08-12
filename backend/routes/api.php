<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WorkflowController;
use App\Http\Controllers\Admin\LeaveTypeController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\ProfileController;

// Public routes
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/ping', function () {
        return response()->json(['message' => 'pong']);
    });

    // Public Saraban verify routes
    Route::get('/saraban/verify/{hash}', [\App\Http\Controllers\Api\Saraban\SignatureController::class, 'verify']);
    Route::get('/saraban/verify/{hash}/qrcode', [\App\Http\Controllers\Api\Saraban\SignatureController::class, 'getQrCode']);

    // ตรวจสอบใบลาจาก QR บนเอกสารที่พิมพ์ออกไป — จำกัดอัตราเพื่อกันการสุ่มโทเคน
    Route::middleware('throttle:30,1')->group(function () {
        Route::get('/leaves/verify/{hash}', [\App\Http\Controllers\LeaveVerificationController::class, 'verify']);
        Route::get('/leaves/verify/{hash}/qrcode', [\App\Http\Controllers\LeaveVerificationController::class, 'qrcode']);
    });
});
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login');

// Protected routes
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::post('/logout',     [AuthController::class, 'logout']);
    Route::get('/user',        [AuthController::class, 'user']);
    Route::get('/users/list',  [\App\Http\Controllers\Admin\UserController::class, 'list']);
    Route::get('/profile',                 [ProfileController::class, 'show']);
    Route::post('/profile',                [ProfileController::class, 'update']);
    // สิทธิขอรับสำเนาข้อมูลส่วนบุคคลของตนเอง (ม.30 พ.ร.บ.คุ้มครองข้อมูลส่วนบุคคล)
    Route::get('/profile/personal-data',   [\App\Http\Controllers\PersonalDataController::class, 'export']);
    Route::get('/profile/access-history',  [\App\Http\Controllers\PersonalDataController::class, 'accessHistory']);
    Route::get('/profile/delegations',     [ProfileController::class, 'getDelegation']);
    Route::post('/profile/delegations',    [ProfileController::class, 'setDelegation']);

    // Global settings for frontend
    Route::get('/settings',                [\App\Http\Controllers\SecureSettingController::class, 'index']);

    // Dashboard stats
    Route::get('/dashboard',   [DashboardController::class, 'index']);

    // Leave routes
    Route::get('/leaves',                  [LeaveController::class, 'index']);
    Route::get('/leaves/team-calendar',    [LeaveController::class, 'teamCalendar']);
    Route::post('/leaves',                 [LeaveController::class, 'store']);
    Route::get('/leaves/balances',         [LeaveController::class, 'balances']);
    Route::get('/leaves/types',            [LeaveController::class, 'types']);
    Route::get('/leaves/{leave}/pdf',      [LeaveController::class, 'exportPdf']);
    Route::get('/leaves/{leave}/word',     [LeaveController::class, 'exportWord']);
    Route::post('/leaves/{leave}/cancel',  [LeaveController::class, 'cancel']);
    Route::post('/leaves/{leave}/approve-cancellation', [LeaveController::class, 'approveCancellation']);
    Route::post('/leaves/{leave}/reject-cancellation', [LeaveController::class, 'rejectCancellation']);
    Route::post('/leaves/{leave}/hr-verify', [LeaveController::class, 'hrVerify']);
    Route::post('/leaves/{leave}/substitute-accept', [LeaveController::class, 'acceptSubstitute']);
    Route::post('/leaves/{leave}/substitute-reject', [LeaveController::class, 'rejectSubstitute']);
    Route::put('/leaves/{leave}',          [LeaveController::class, 'update']);
    Route::delete('/leaves/{leave}',       [LeaveController::class, 'destroy']);

    // Attendance routes — ตรวจสอบสิทธิ์รายฟังก์ชันตามโมดูล attendance
    Route::group(['middleware' => ['permission:attendance.checkin|attendance.view_self']], function () {
        Route::post('/attendances/check-in',    [AttendanceController::class, 'checkIn']);
        Route::post('/attendances/check-out',   [AttendanceController::class, 'checkOut']);
        Route::get('/attendances/today',        [AttendanceController::class, 'today']);
    });

    Route::group(['middleware' => ['can:attendance.ot']], function () {
        Route::post('/attendances/ot-check-in',  [AttendanceController::class, 'otCheckIn']);
        Route::post('/attendances/ot-check-out', [AttendanceController::class, 'otCheckOut']);
    });

    Route::get('/attendances/me', [AttendanceController::class, 'myRecords'])
        ->middleware('can:attendance.view_self');

    // Admin attendance — ต้องมีสิทธิ์ view_all
    Route::group(['middleware' => ['can:attendance.view_all']], function () {
        Route::get('/admin/attendances',              [AttendanceController::class, 'allRecords']);
        Route::get('/admin/attendances/export/pdf',   [AttendanceController::class, 'exportPdf']);
        Route::get('/admin/attendances/export/word',  [AttendanceController::class, 'exportWord']);
        Route::get('/admin/attendances/summary',      [AttendanceController::class, 'summary']);
        Route::post('/admin/attendances/sync-leaves', [AttendanceController::class, 'syncLeaves']);
    });

    // OT Summary — สิทธิ์เฉพาะ
    Route::get('/admin/attendances/ot-summary', [AttendanceController::class, 'otSummary'])
        ->middleware('can:attendance.ot_summary');


    Route::post('/admin/leaves', [LeaveController::class, 'storeAsAdmin']);

    // Approval routes
    // approve/reject ประกาศไว้ที่ routes/leave_government.php (GovernmentApprovalController)
    // ซึ่งลงทะเบียนทีหลังจึงทับเส้นทางนี้อยู่แล้ว — ย้ายไปไว้ที่เดียวเพื่อไม่ให้เข้าใจผิดว่าใช้ตัวไหน
    Route::get('/approvals/pending',          [ApprovalController::class, 'pending']);

    // Report routes
    Route::get('/reports/summary',        [ReportController::class, 'summary']);
    Route::get('/reports/export-excel',   [ReportController::class, 'exportExcel']);
    Route::get('/reports/export-pdf',     [ReportController::class, 'exportPdf']);

    // Admin routes
    Route::group(['middleware' => ['can:view_users']], function () {
        Route::get('/admin/users',             [UserController::class, 'index']);
        Route::get('/admin/roles',             [UserController::class, 'roles']);
        Route::get('/admin/departments-list',  [UserController::class, 'departments']);
    });

    Route::group(['middleware' => ['permission:edit_users|system.user.manage|admin.users.manage_permissions']], function () {
        Route::post('/admin/users',            [UserController::class, 'store']);
        Route::post('/admin/users/import',     [UserController::class, 'importCsv']);
        Route::put('/admin/users/{user}',      [UserController::class, 'update']);
        Route::post('/admin/users/{user}/toggle-status', [UserController::class, 'toggleStatus']);

        // Individual Permission Management API
        Route::get('/admin/permissions/modules', [\App\Http\Controllers\Admin\PermissionController::class, 'modules']);
        Route::get('/admin/permissions/user-counts', [\App\Http\Controllers\Admin\PermissionController::class, 'userCounts']);
        Route::get('/admin/users/{user}/permissions', [\App\Http\Controllers\Admin\PermissionController::class, 'getUserPermissions']);
        Route::put('/admin/users/{user}/permissions', [\App\Http\Controllers\Admin\PermissionController::class, 'updateUserPermissions']);
    });

    Route::delete('/admin/users/{user}',   [UserController::class, 'destroy'])->middleware('can:delete_users');

    // Admin: Departments CRUD
    Route::group(['middleware' => ['can:manage_departments']], function () {
        Route::get('/admin/departments',               [DepartmentController::class, 'index']);
        Route::post('/admin/departments',              [DepartmentController::class, 'store']);
        Route::put('/admin/departments/{department}',  [DepartmentController::class, 'update']);
        Route::delete('/admin/departments/{department}', [DepartmentController::class, 'destroy']);
        Route::put('/admin/departments/{department}/toggle-status', [DepartmentController::class, 'toggleStatus']);
    });

    // Admin: Workflow Steps
    Route::group(['middleware' => ['can:manage_workflow_steps']], function () {
        Route::get('/admin/workflow-steps',     [WorkflowController::class, 'index']);
        Route::post('/admin/workflow-steps',    [WorkflowController::class, 'store']);
    });

    // Admin: Leave Types CRUD
    Route::group(['middleware' => ['can:manage_leave_types']], function () {
        Route::get('/admin/leave-types',           [LeaveTypeController::class, 'index']);
        Route::post('/admin/leave-types',          [LeaveTypeController::class, 'store']);
        Route::put('/admin/leave-types/{type}',    [LeaveTypeController::class, 'update']);
        Route::delete('/admin/leave-types/{type}', [LeaveTypeController::class, 'destroy']);
    });

    // Admin: Settings & Fiscal Years
    Route::middleware('permission:manage_settings|system.setting.manage|saraban.setting.manage')->group(function () {
        Route::get('/admin/settings', [\App\Http\Controllers\SecureSettingController::class, 'adminIndex']);
        Route::post('/admin/settings', [\App\Http\Controllers\SecureSettingController::class, 'update']);
        Route::post('/admin/settings/upload-logo', [\App\Http\Controllers\SecureSettingController::class, 'uploadLogo']);
        Route::post('/admin/settings/upload-garuda', [\App\Http\Controllers\SecureSettingController::class, 'uploadGarudaLogo']);
        Route::delete('/admin/settings/logo', [\App\Http\Controllers\SecureSettingController::class, 'deleteLogo']);
        Route::delete('/admin/settings/secrets/{key}', [\App\Http\Controllers\SecureSettingController::class, 'clearSecret']);
        Route::post('/admin/settings/test-notification/{channel}', [\App\Http\Controllers\SecureSettingController::class, 'testNotification']);

        Route::get('/admin/export-settings', [\App\Http\Controllers\Admin\SecureExportSettingController::class, 'index']);
        Route::post('/admin/export-settings', [\App\Http\Controllers\Admin\SecureExportSettingController::class, 'update']);
        Route::post('/admin/export-settings/upload-logo', [\App\Http\Controllers\Admin\SecureExportSettingController::class, 'uploadLogo']);

        Route::get('/admin/document-templates', [\App\Http\Controllers\Admin\DocumentTemplateController::class, 'index']);
        Route::post('/admin/document-templates', [\App\Http\Controllers\Admin\DocumentTemplateController::class, 'store']);
        Route::post('/admin/document-templates/seed-defaults', [\App\Http\Controllers\Admin\DocumentTemplateController::class, 'seedDefaults']);
        Route::get('/admin/document-templates/{template}', [\App\Http\Controllers\Admin\DocumentTemplateController::class, 'show']);
        Route::put('/admin/document-templates/{template}', [\App\Http\Controllers\Admin\DocumentTemplateController::class, 'update']);
        Route::delete('/admin/document-templates/{template}', [\App\Http\Controllers\Admin\DocumentTemplateController::class, 'destroy']);

        Route::get('/admin/fiscal-years',                     [\App\Http\Controllers\FiscalYearController::class, 'index']);
        Route::post('/admin/fiscal-years',                    [\App\Http\Controllers\FiscalYearController::class, 'store']);
        Route::put('/admin/fiscal-years/{fiscalYear}',        [\App\Http\Controllers\FiscalYearController::class, 'update']);
        Route::delete('/admin/fiscal-years/{fiscalYear}',     [\App\Http\Controllers\FiscalYearController::class, 'destroy']);
    });
    
    // Saraban Document routes
    Route::group(['middleware' => ['auth:sanctum']], function () {
        // Document Types
        Route::get('/saraban/types', [\App\Http\Controllers\Api\Saraban\DocumentTypeController::class, 'index']);
        Route::post('/saraban/types', [\App\Http\Controllers\Api\Saraban\DocumentTypeController::class, 'store']);
        Route::put('/saraban/types/{type}', [\App\Http\Controllers\Api\Saraban\DocumentTypeController::class, 'update']);
        Route::delete('/saraban/types/{type}', [\App\Http\Controllers\Api\Saraban\DocumentTypeController::class, 'destroy']);

        // Workflow Templates
        Route::get('/saraban/workflow-templates', [\App\Http\Controllers\Api\Saraban\DocumentWorkflowTemplateController::class, 'index']);
        Route::post('/saraban/workflow-templates', [\App\Http\Controllers\Api\Saraban\DocumentWorkflowTemplateController::class, 'store']);
        Route::put('/saraban/workflow-templates/{id}', [\App\Http\Controllers\Api\Saraban\DocumentWorkflowTemplateController::class, 'update']);
        Route::delete('/saraban/workflow-templates/{id}', [\App\Http\Controllers\Api\Saraban\DocumentWorkflowTemplateController::class, 'destroy']);

        // Documents
        Route::get('/saraban/documents',       [\App\Http\Controllers\Api\Saraban\DocumentController::class, 'index']);
        Route::get('/saraban/documents/stats', [\App\Http\Controllers\Api\Saraban\DocumentController::class, 'stats']);
        Route::delete('/saraban/documents/bulk', [\App\Http\Controllers\Api\Saraban\DocumentController::class, 'destroyMultiple']);
        Route::post('/saraban/documents',      [\App\Http\Controllers\Api\Saraban\DocumentController::class, 'store']);
        Route::get('/saraban/documents/{document}', [\App\Http\Controllers\Api\Saraban\DocumentController::class, 'show']);
        Route::put('/saraban/documents/{document}', [\App\Http\Controllers\Api\Saraban\DocumentController::class, 'update']);
        Route::delete('/saraban/documents/{document}', [\App\Http\Controllers\Api\Saraban\DocumentController::class, 'destroy']);
        Route::post('/saraban/documents/{document}/read', [\App\Http\Controllers\Api\Saraban\DocumentController::class, 'read']);
        Route::post('/saraban/documents/{id}/state', [\App\Http\Controllers\Api\Saraban\DocumentController::class, 'updateState']);

        // Saraban Workflow
        Route::post('/saraban/documents/{id}/workflow/submit', [\App\Http\Controllers\Api\Saraban\WorkflowController::class, 'submit']);
        Route::post('/saraban/documents/{id}/workflow/approve', [\App\Http\Controllers\Api\Saraban\WorkflowController::class, 'approve']);
        Route::post('/saraban/documents/{id}/workflow/reject', [\App\Http\Controllers\Api\Saraban\WorkflowController::class, 'reject']);

        // Saraban Signatures
        Route::post('/saraban/documents/{id}/sign', [\App\Http\Controllers\Api\Saraban\SignatureController::class, 'sign']);

        // Saraban Circulars
        Route::get('/saraban/circulars/incoming', [\App\Http\Controllers\Api\Saraban\CircularController::class, 'incoming']);
        Route::get('/saraban/circulars/outgoing', [\App\Http\Controllers\Api\Saraban\CircularController::class, 'outgoing']);
        Route::post('/saraban/documents/{id}/circular', [\App\Http\Controllers\Api\Saraban\CircularController::class, 'send']);
        Route::post('/saraban/circulars/{id}/acknowledge', [\App\Http\Controllers\Api\Saraban\CircularController::class, 'acknowledge']);
        Route::get('/saraban/documents/{id}/circulars/tracking', [\App\Http\Controllers\Api\Saraban\CircularController::class, 'tracking']);

        // Saraban Reports
        Route::get('/saraban/reports/summary', [\App\Http\Controllers\Api\Saraban\ReportController::class, 'summary']);
        Route::get('/saraban/reports/export', [\App\Http\Controllers\Api\Saraban\ReportController::class, 'exportCsv']);
    });

    Route::get('/fiscal-year/current',                    [\App\Http\Controllers\FiscalYearController::class, 'current']);

    // ดาวน์โหลดไฟล์อัปโหลด — ต้องยืนยันตัวตน (แทน /api/storage/{path} เดิมที่เปิดสาธารณะ)
    Route::get('/files/{path}', [\App\Http\Controllers\SecureFileController::class, 'show'])
        ->where('path', '.*');

    // Notifications
    Route::get('/notifications',                          [\App\Http\Controllers\NotificationController::class, 'index']);
    Route::post('/notifications/mark-all-read',           [\App\Http\Controllers\NotificationController::class, 'markAllAsRead']);
    // Dynamic Menu & Role Management routes
    Route::get('/user/menus', [\App\Http\Controllers\Api\MenuController::class, 'index']);
    Route::group(['middleware' => ['permission:edit_users|system.menu.manage']], function () {
        Route::get('/admin/menus', [\App\Http\Controllers\Api\MenuController::class, 'adminIndex']);
        Route::put('/admin/menus/{menu}/permissions', [\App\Http\Controllers\Api\MenuController::class, 'updatePermissions']);
        Route::get('/admin/permissions', [\App\Http\Controllers\Api\RoleController::class, 'permissions']);
        Route::apiResource('/admin/roles', \App\Http\Controllers\Api\RoleController::class);
    });

});

// We put preview and exports outside sanctum group so window.open works with ?token= query param
Route::get('/admin/attendances/summary/preview', [\App\Http\Controllers\AttendanceController::class, 'previewSummary']);
Route::get('/admin/attendances/summary/export/pdf', [\App\Http\Controllers\AttendanceController::class, 'exportSummaryPdf']);
Route::get('/admin/attendances/summary/export/word', [\App\Http\Controllers\AttendanceController::class, 'exportSummaryWord']);

Route::get('/admin/attendances/ot-summary/preview', [\App\Http\Controllers\AttendanceController::class, 'previewOTSummary']);
Route::get('/admin/attendances/ot-summary/export/pdf', [\App\Http\Controllers\AttendanceController::class, 'exportOTSummaryPdf']);
Route::get('/admin/attendances/ot-summary/export/word', [\App\Http\Controllers\AttendanceController::class, 'exportOTSummaryWord']);
Route::get('/admin/attendances/ot-summary/export/excel', [\App\Http\Controllers\AttendanceController::class, 'exportOTSummaryExcel']);

// เดิมมี route `GET /api/storage/{path}` ที่เสิร์ฟไฟล์ใน storage/app/public
// โดยไม่ต้องยืนยันตัวตน — ถอดออกแล้วเมื่อ 2026-08-12 (SEC-001)
//
// ตรวจแล้วว่าไม่มีโค้ดฝั่งใดเรียก route นี้: frontend ใช้ `/storage/{path}` ผ่าน
// symlink `public/storage` และฝั่ง PDF อ่านไฟล์ตรงจาก public_path() ไม่ผ่าน HTTP
//
// ช่องทางดาวน์โหลดที่ตรวจสอบสิทธิ์จะถูกสร้างใน LEGACY-02 §02-A
// แพทเทิร์นอ้างอิงที่ทำถูกอยู่แล้ว: LeaveEvidenceController::download()



