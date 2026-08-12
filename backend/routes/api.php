<?php

use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\DocumentTemplateController;
use App\Http\Controllers\Admin\LeaveTypeController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\SecureExportSettingController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WorkflowController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\Saraban\CircularController;
use App\Http\Controllers\Api\Saraban\DocumentController;
use App\Http\Controllers\Api\Saraban\DocumentTypeController;
use App\Http\Controllers\Api\Saraban\DocumentWorkflowTemplateController;
use App\Http\Controllers\Api\Saraban\SignatureController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FiscalYearController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\LeaveVerificationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PersonalDataController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SecureFileController;
use App\Http\Controllers\SecureSettingController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/ping', function () {
        return response()->json(['message' => 'pong']);
    });

    // Public Saraban verify routes
    Route::get('/saraban/verify/{hash}', [SignatureController::class, 'verify']);
    Route::get('/saraban/verify/{hash}/qrcode', [SignatureController::class, 'getQrCode']);

    // ตรวจสอบใบลาจาก QR บนเอกสารที่พิมพ์ออกไป — จำกัดอัตราเพื่อกันการสุ่มโทเคน
    Route::middleware('throttle:30,1')->group(function () {
        Route::get('/leaves/verify/{hash}', [LeaveVerificationController::class, 'verify']);
        Route::get('/leaves/verify/{hash}/qrcode', [LeaveVerificationController::class, 'qrcode']);
    });
});
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login');

// Protected routes
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::get('/users/list', [UserController::class, 'list']);
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::post('/profile', [ProfileController::class, 'update']);
    // สิทธิขอรับสำเนาข้อมูลส่วนบุคคลของตนเอง (ม.30 พ.ร.บ.คุ้มครองข้อมูลส่วนบุคคล)
    Route::get('/profile/personal-data', [PersonalDataController::class, 'export']);
    Route::get('/profile/access-history', [PersonalDataController::class, 'accessHistory']);
    Route::get('/profile/delegations', [ProfileController::class, 'getDelegation']);
    Route::post('/profile/delegations', [ProfileController::class, 'setDelegation']);

    // Global settings for frontend
    Route::get('/settings', [SecureSettingController::class, 'index']);

    // Dashboard stats
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Leave routes
    Route::get('/leaves', [LeaveController::class, 'index']);
    Route::get('/leaves/team-calendar', [LeaveController::class, 'teamCalendar']);
    Route::post('/leaves', [LeaveController::class, 'store']);
    Route::get('/leaves/balances', [LeaveController::class, 'balances']);
    Route::get('/leaves/types', [LeaveController::class, 'types']);
    Route::get('/leaves/{leave}/pdf', [LeaveController::class, 'exportPdf']);
    Route::get('/leaves/{leave}/word', [LeaveController::class, 'exportWord']);
    Route::post('/leaves/{leave}/cancel', [LeaveController::class, 'cancel']);
    Route::post('/leaves/{leave}/approve-cancellation', [LeaveController::class, 'approveCancellation']);
    Route::post('/leaves/{leave}/reject-cancellation', [LeaveController::class, 'rejectCancellation']);
    Route::post('/leaves/{leave}/hr-verify', [LeaveController::class, 'hrVerify']);
    Route::post('/leaves/{leave}/substitute-accept', [LeaveController::class, 'acceptSubstitute']);
    Route::post('/leaves/{leave}/substitute-reject', [LeaveController::class, 'rejectSubstitute']);
    Route::put('/leaves/{leave}', [LeaveController::class, 'update']);
    Route::delete('/leaves/{leave}', [LeaveController::class, 'destroy']);

    // Attendance routes — ตรวจสอบสิทธิ์รายฟังก์ชันตามโมดูล attendance
    Route::group(['middleware' => ['permission:attendance.checkin|attendance.view_self']], function () {
        Route::post('/attendances/check-in', [AttendanceController::class, 'checkIn']);
        Route::post('/attendances/check-out', [AttendanceController::class, 'checkOut']);
        Route::get('/attendances/today', [AttendanceController::class, 'today']);
    });

    Route::group(['middleware' => ['can:attendance.ot']], function () {
        Route::post('/attendances/ot-check-in', [AttendanceController::class, 'otCheckIn']);
        Route::post('/attendances/ot-check-out', [AttendanceController::class, 'otCheckOut']);
    });

    Route::get('/attendances/me', [AttendanceController::class, 'myRecords'])
        ->middleware('can:attendance.view_self');

    // Admin attendance — ต้องมีสิทธิ์ view_all
    Route::group(['middleware' => ['can:attendance.view_all']], function () {
        Route::get('/admin/attendances', [AttendanceController::class, 'allRecords']);
        Route::get('/admin/attendances/export/pdf', [AttendanceController::class, 'exportPdf']);
        Route::get('/admin/attendances/export/word', [AttendanceController::class, 'exportWord']);
        Route::get('/admin/attendances/summary', [AttendanceController::class, 'summary']);
        Route::post('/admin/attendances/sync-leaves', [AttendanceController::class, 'syncLeaves']);
    });

    // OT Summary — สิทธิ์เฉพาะ
    Route::get('/admin/attendances/ot-summary', [AttendanceController::class, 'otSummary'])
        ->middleware('can:attendance.ot_summary');

    Route::post('/admin/leaves', [LeaveController::class, 'storeAsAdmin']);

    // Approval routes
    // approve/reject ประกาศไว้ที่ routes/leave_government.php (GovernmentApprovalController)
    // ซึ่งลงทะเบียนทีหลังจึงทับเส้นทางนี้อยู่แล้ว — ย้ายไปไว้ที่เดียวเพื่อไม่ให้เข้าใจผิดว่าใช้ตัวไหน
    Route::get('/approvals/pending', [ApprovalController::class, 'pending']);

    // Report routes
    Route::get('/reports/summary', [ReportController::class, 'summary']);
    Route::get('/reports/export-excel', [ReportController::class, 'exportExcel']);
    Route::get('/reports/export-pdf', [ReportController::class, 'exportPdf']);

    // Admin routes
    Route::group(['middleware' => ['can:view_users']], function () {
        Route::get('/admin/users', [UserController::class, 'index']);
        Route::get('/admin/roles', [UserController::class, 'roles']);
        Route::get('/admin/departments-list', [UserController::class, 'departments']);
    });

    Route::group(['middleware' => ['permission:edit_users|system.user.manage|admin.users.manage_permissions']], function () {
        Route::post('/admin/users', [UserController::class, 'store']);
        Route::post('/admin/users/import', [UserController::class, 'importCsv']);
        Route::put('/admin/users/{user}', [UserController::class, 'update']);
        Route::post('/admin/users/{user}/toggle-status', [UserController::class, 'toggleStatus']);

        // Individual Permission Management API
        Route::get('/admin/permissions/modules', [PermissionController::class, 'modules']);
        Route::get('/admin/permissions/user-counts', [PermissionController::class, 'userCounts']);
        Route::get('/admin/users/{user}/permissions', [PermissionController::class, 'getUserPermissions']);
        Route::put('/admin/users/{user}/permissions', [PermissionController::class, 'updateUserPermissions']);
    });

    Route::delete('/admin/users/{user}', [UserController::class, 'destroy'])->middleware('can:delete_users');

    // Admin: Departments CRUD
    Route::group(['middleware' => ['can:manage_departments']], function () {
        Route::get('/admin/departments', [DepartmentController::class, 'index']);
        Route::post('/admin/departments', [DepartmentController::class, 'store']);
        Route::put('/admin/departments/{department}', [DepartmentController::class, 'update']);
        Route::delete('/admin/departments/{department}', [DepartmentController::class, 'destroy']);
        Route::put('/admin/departments/{department}/toggle-status', [DepartmentController::class, 'toggleStatus']);
    });

    // Admin: Workflow Steps
    Route::group(['middleware' => ['can:manage_workflow_steps']], function () {
        Route::get('/admin/workflow-steps', [WorkflowController::class, 'index']);
        Route::post('/admin/workflow-steps', [WorkflowController::class, 'store']);
    });

    // Admin: Leave Types CRUD
    Route::group(['middleware' => ['can:manage_leave_types']], function () {
        Route::get('/admin/leave-types', [LeaveTypeController::class, 'index']);
        Route::post('/admin/leave-types', [LeaveTypeController::class, 'store']);
        Route::put('/admin/leave-types/{type}', [LeaveTypeController::class, 'update']);
        Route::delete('/admin/leave-types/{type}', [LeaveTypeController::class, 'destroy']);
    });

    // Admin: Settings & Fiscal Years
    Route::middleware('permission:manage_settings|system.setting.manage|saraban.setting.manage')->group(function () {
        Route::get('/admin/settings', [SecureSettingController::class, 'adminIndex']);
        Route::post('/admin/settings', [SecureSettingController::class, 'update']);
        Route::post('/admin/settings/upload-logo', [SecureSettingController::class, 'uploadLogo']);
        Route::post('/admin/settings/upload-garuda', [SecureSettingController::class, 'uploadGarudaLogo']);
        Route::delete('/admin/settings/logo', [SecureSettingController::class, 'deleteLogo']);
        Route::delete('/admin/settings/secrets/{key}', [SecureSettingController::class, 'clearSecret']);
        Route::post('/admin/settings/test-notification/{channel}', [SecureSettingController::class, 'testNotification']);

        Route::get('/admin/export-settings', [SecureExportSettingController::class, 'index']);
        Route::post('/admin/export-settings', [SecureExportSettingController::class, 'update']);
        Route::post('/admin/export-settings/upload-logo', [SecureExportSettingController::class, 'uploadLogo']);

        Route::get('/admin/document-templates', [DocumentTemplateController::class, 'index']);
        Route::post('/admin/document-templates', [DocumentTemplateController::class, 'store']);
        Route::post('/admin/document-templates/seed-defaults', [DocumentTemplateController::class, 'seedDefaults']);
        Route::get('/admin/document-templates/{template}', [DocumentTemplateController::class, 'show']);
        Route::put('/admin/document-templates/{template}', [DocumentTemplateController::class, 'update']);
        Route::delete('/admin/document-templates/{template}', [DocumentTemplateController::class, 'destroy']);

        Route::get('/admin/fiscal-years', [FiscalYearController::class, 'index']);
        Route::post('/admin/fiscal-years', [FiscalYearController::class, 'store']);
        Route::put('/admin/fiscal-years/{fiscalYear}', [FiscalYearController::class, 'update']);
        Route::delete('/admin/fiscal-years/{fiscalYear}', [FiscalYearController::class, 'destroy']);
    });

    // Saraban Document routes.
    // Route-level `permission:` middleware is the coarse gate; DocumentPolicy
    // (checked inside the controllers) enforces per-document rules on top of it.
    Route::prefix('saraban')->group(function () {
        // Document Types — read for anyone who can see the register, write for registrars.
        Route::middleware('permission:saraban.document.view')->group(function () {
            Route::get('/types', [DocumentTypeController::class, 'index']);
        });
        Route::middleware('permission:saraban.document.manage_types')->group(function () {
            Route::post('/types', [DocumentTypeController::class, 'store']);
            Route::put('/types/{type}', [DocumentTypeController::class, 'update']);
            Route::delete('/types/{type}', [DocumentTypeController::class, 'destroy']);

            Route::post('/workflow-templates', [DocumentWorkflowTemplateController::class, 'store']);
            Route::put('/workflow-templates/{id}', [DocumentWorkflowTemplateController::class, 'update']);
            Route::delete('/workflow-templates/{id}', [DocumentWorkflowTemplateController::class, 'destroy']);
        });

        Route::middleware('permission:saraban.document.view')->group(function () {
            Route::get('/workflow-templates', [DocumentWorkflowTemplateController::class, 'index']);

            // Pickers for the document form / circular recipients. Scoped read-only
            // lists so saraban users do not need the admin `view_users` permission.
            Route::get('/users-list', [DocumentController::class, 'usersList']);
            Route::get('/departments-list', [DocumentController::class, 'departmentsList']);

            // Documents — read
            Route::get('/documents', [DocumentController::class, 'index']);
            Route::get('/documents/stats', [DocumentController::class, 'stats']);
            Route::get('/documents/{document}', [DocumentController::class, 'show'])
                ->middleware('saraban.classified');
            Route::post('/documents/{document}/read', [DocumentController::class, 'read']);
            Route::post('/documents/{id}/state', [DocumentController::class, 'updateState']);
            Route::get('/attachments/{attachment}/download', [DocumentController::class, 'downloadAttachment']);
        });

        Route::post('/documents', [DocumentController::class, 'store'])
            ->middleware('permission:saraban.document.create');

        Route::put('/documents/{document}', [DocumentController::class, 'update'])
            ->middleware(['permission:saraban.document.edit', 'saraban.classified']);

        Route::delete('/documents/bulk', [DocumentController::class, 'destroyMultiple'])
            ->middleware('permission:saraban.document.delete');
        Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])
            ->middleware(['permission:saraban.document.delete', 'saraban.classified']);

        // Saraban Workflow
        Route::middleware('permission:saraban.document.view')->group(function () {
            Route::post('/documents/{id}/workflow/submit', [App\Http\Controllers\Api\Saraban\WorkflowController::class, 'submit']);
        });
        Route::middleware(['permission:saraban.document.approve', 'saraban.classified'])->group(function () {
            Route::post('/documents/{id}/workflow/approve', [App\Http\Controllers\Api\Saraban\WorkflowController::class, 'approve']);
            Route::post('/documents/{id}/workflow/reject', [App\Http\Controllers\Api\Saraban\WorkflowController::class, 'reject']);
        });

        // Saraban Signatures
        Route::post('/documents/{id}/sign', [SignatureController::class, 'sign'])
            ->middleware(['permission:saraban.document.sign', 'saraban.classified']);

        // Saraban Circulars
        Route::middleware('permission:saraban.document.view')->group(function () {
            Route::get('/circulars/incoming', [CircularController::class, 'incoming']);
            Route::get('/circulars/outgoing', [CircularController::class, 'outgoing']);
            Route::post('/documents/{id}/circular', [CircularController::class, 'send']);
            Route::post('/circulars/{id}/acknowledge', [CircularController::class, 'acknowledge']);
            Route::get('/documents/{id}/circulars/tracking', [CircularController::class, 'tracking']);
        });

        // Saraban Reports
        Route::middleware('permission:saraban.document.report')->group(function () {
            Route::get('/reports/summary', [App\Http\Controllers\Api\Saraban\ReportController::class, 'summary']);
            Route::get('/reports/export', [App\Http\Controllers\Api\Saraban\ReportController::class, 'exportCsv']);
        });
    });

    Route::get('/fiscal-year/current', [FiscalYearController::class, 'current']);

    // ดาวน์โหลดไฟล์อัปโหลด — ต้องยืนยันตัวตน (แทน /api/storage/{path} เดิมที่เปิดสาธารณะ)
    Route::get('/files/{path}', [SecureFileController::class, 'show'])
        ->where('path', '.*');

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    // Dynamic Menu & Role Management routes
    Route::get('/user/menus', [MenuController::class, 'index']);
    Route::group(['middleware' => ['permission:edit_users|system.menu.manage']], function () {
        Route::get('/admin/menus', [MenuController::class, 'adminIndex']);
        Route::put('/admin/menus/{menu}/permissions', [MenuController::class, 'updatePermissions']);
        Route::get('/admin/permissions', [RoleController::class, 'permissions']);
        Route::apiResource('/admin/roles', RoleController::class);
    });

});

/*
 * SEC-009 — รายงานสรุปการลงเวลาและ OT ของทั้งองค์กร
 *
 * เดิม 7 endpoint นี้อยู่ **นอก** กลุ่ม auth:sanctum โดยตั้งใจ เพื่อให้หน้าเว็บ
 * เรียกผ่าน window.open พร้อมแนบ Sanctum token ทาง query string ได้
 * ผลคือ token ไปโผล่ใน access log / referrer / browser history และ controller
 * ตรวจเพียงว่า token ใช้ได้ ไม่ได้ตรวจ permission เลย — ผู้ใช้ที่ล็อกอินคนใดก็ได้
 * จึง export ข้อมูลลงเวลาของทุกคนในองค์กรออกไปได้
 *
 * ย้ายเข้ามาอยู่หลัง authentication แล้วเมื่อ 2026-08-12 และให้หน้าเว็บดาวน์โหลด
 * แบบ authenticated request (blob) แทน window.open
 */
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::middleware('can:attendance.view_all')->group(function () {
        Route::get('/admin/attendances/summary/preview', [AttendanceController::class, 'previewSummary']);
        Route::get('/admin/attendances/summary/export/pdf', [AttendanceController::class, 'exportSummaryPdf']);
        Route::get('/admin/attendances/summary/export/word', [AttendanceController::class, 'exportSummaryWord']);
    });

    Route::middleware('can:attendance.ot_summary')->group(function () {
        Route::get('/admin/attendances/ot-summary/preview', [AttendanceController::class, 'previewOTSummary']);
        Route::get('/admin/attendances/ot-summary/export/pdf', [AttendanceController::class, 'exportOTSummaryPdf']);
        Route::get('/admin/attendances/ot-summary/export/word', [AttendanceController::class, 'exportOTSummaryWord']);
        Route::get('/admin/attendances/ot-summary/export/excel', [AttendanceController::class, 'exportOTSummaryExcel']);
    });
});

// เดิมมี route `GET /api/storage/{path}` ที่เสิร์ฟไฟล์ใน storage/app/public
// โดยไม่ต้องยืนยันตัวตน — ถอดออกแล้วเมื่อ 2026-08-12 (SEC-001)
//
// ตรวจแล้วว่าไม่มีโค้ดฝั่งใดเรียก route นี้: frontend ใช้ `/storage/{path}` ผ่าน
// symlink `public/storage` และฝั่ง PDF อ่านไฟล์ตรงจาก public_path() ไม่ผ่าน HTTP
//
// ช่องทางดาวน์โหลดที่ตรวจสอบสิทธิ์จะถูกสร้างใน LEGACY-02 §02-A
// แพทเทิร์นอ้างอิงที่ทำถูกอยู่แล้ว: LeaveEvidenceController::download()
