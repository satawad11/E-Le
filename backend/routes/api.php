<?php

use App\Http\Controllers\Admin\DelegationController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\DocumentEngineController;
use App\Http\Controllers\Admin\DocumentReportController;
use App\Http\Controllers\Admin\DocumentTemplateController;
use App\Http\Controllers\Admin\LeaveTypeController;
use App\Http\Controllers\Admin\OrgStructureController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\PositionController;
use App\Http\Controllers\Admin\SecureExportSettingController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WorkflowController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\Saraban\CircularController;
use App\Http\Controllers\Api\Saraban\DocumentController;
use App\Http\Controllers\Api\Saraban\DocumentTypeController;
use App\Http\Controllers\Api\Saraban\DocumentWorkflowTemplateController;
use App\Http\Controllers\Api\Saraban\SettingController as SarabanSettingController;
use App\Http\Controllers\Api\Saraban\SignatureController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttendanceCorrectionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FiscalYearController;
use App\Http\Controllers\LeaveCancellationController;
use App\Http\Controllers\LeaveDocumentController;
use App\Http\Controllers\LeaveQueryController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\LeaveVerificationController;
use App\Http\Controllers\LeaveWorkflowActionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Overtime\OvertimeOrderController;
use App\Http\Controllers\Overtime\OvertimeReportController;
use App\Http\Controllers\Overtime\OvertimeTimeController;
use App\Http\Controllers\PersonalDataController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportingController;
use App\Http\Controllers\SecureFileController;
use App\Http\Controllers\SecureSettingController;
use App\Services\Reporting\ReportingDataService;
use Illuminate\Support\Facades\Route;

// Public routes
Route::middleware('throttle:public-api')->group(function () {
    Route::get('/ping', function () {
        return response()->json(['message' => 'pong']);
    });

    // Public Saraban verify routes
    Route::get('/saraban/verify/{hash}', [SignatureController::class, 'verify'])->middleware('throttle:verify');
    Route::get('/saraban/verify/{hash}/qrcode', [SignatureController::class, 'getQrCode'])->middleware('throttle:verify');

    // ตรวจสอบใบลาจาก QR บนเอกสารที่พิมพ์ออกไป — จำกัดอัตราเพื่อกันการสุ่มโทเคน
    Route::middleware('throttle:verify')->group(function () {
        Route::get('/leaves/verify/{hash}', [LeaveVerificationController::class, 'verify']);
        Route::get('/leaves/verify/{hash}/qrcode', [LeaveVerificationController::class, 'qrcode']);
    });
});
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password');

// Protected routes
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh-token', [AuthController::class, 'refresh'])->middleware('throttle:token-refresh');
    Route::get('/user', [AuthController::class, 'user']);
    Route::get('/users/list', [UserController::class, 'list']);
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::post('/profile', [ProfileController::class, 'update']);
    // สิทธิขอรับสำเนาข้อมูลส่วนบุคคลของตนเอง (ม.30 พ.ร.บ.คุ้มครองข้อมูลส่วนบุคคล)
    Route::get('/profile/personal-data', [PersonalDataController::class, 'export'])->middleware('throttle:personal-data');
    Route::get('/profile/access-history', [PersonalDataController::class, 'accessHistory']);
    Route::get('/profile/delegations', [ProfileController::class, 'getDelegation']);
    Route::post('/profile/delegations', [ProfileController::class, 'setDelegation']);

    // Global settings for frontend
    Route::get('/settings', [SecureSettingController::class, 'index']);

    // Dashboard stats
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Leave routes
    Route::get('/leaves', [LeaveQueryController::class, 'index']);
    Route::get('/leaves/team-calendar', [LeaveQueryController::class, 'teamCalendar']);
    Route::post('/leaves', [LeaveRequestController::class, 'store']);
    Route::get('/leaves/balances', [LeaveQueryController::class, 'balances']);
    Route::get('/leaves/types', [LeaveQueryController::class, 'types']);
    Route::get('/leaves/{leave}/pdf', [LeaveDocumentController::class, 'exportPdf'])->middleware('throttle:documents');
    Route::get('/leaves/{leave}/word', [LeaveDocumentController::class, 'exportWord'])->middleware('throttle:documents');
    Route::post('/leaves/{leave}/cancel', [LeaveCancellationController::class, 'cancel']);
    Route::post('/leaves/{leave}/approve-cancellation', [LeaveCancellationController::class, 'approve']);
    Route::post('/leaves/{leave}/reject-cancellation', [LeaveCancellationController::class, 'reject']);
    Route::post('/leaves/{leave}/hr-verify', [LeaveWorkflowActionController::class, 'hrVerify']);
    Route::post('/leaves/{leave}/substitute-accept', [LeaveWorkflowActionController::class, 'acceptSubstitute']);
    Route::post('/leaves/{leave}/substitute-reject', [LeaveWorkflowActionController::class, 'rejectSubstitute']);
    Route::put('/leaves/{leave}', [LeaveRequestController::class, 'update']);
    Route::delete('/leaves/{leave}', [LeaveRequestController::class, 'destroy']);

    // Attendance routes — ตรวจสอบสิทธิ์รายฟังก์ชันตามโมดูล attendance
    Route::group(['middleware' => ['can:attendance.checkin']], function () {
        Route::post('/attendances/check-in', [AttendanceController::class, 'checkIn']);
        Route::post('/attendances/check-out', [AttendanceController::class, 'checkOut']);
    });

    Route::get('/attendances/today', [AttendanceController::class, 'today'])
        ->middleware('permission:attendance.checkin|attendance.view_self');
    Route::post('/attendances/{attendance:uuid}/corrections', [AttendanceCorrectionController::class, 'store'])
        ->middleware('permission:attendance.view_self|attendance.view_all');

    Route::group(['middleware' => ['can:overtime.operation.execute']], function () {
        Route::post('/attendances/ot-check-in', [OvertimeTimeController::class, 'checkIn']);
        Route::post('/attendances/ot-check-out', [OvertimeTimeController::class, 'checkOut']);
        Route::get('/overtime/my-active', [OvertimeTimeController::class, 'active']);
        Route::post('/overtime/orders', [OvertimeOrderController::class, 'store']);
    });

    Route::group(['middleware' => ['can:overtime.management.review']], function () {
        Route::get('/overtime/orders', [OvertimeOrderController::class, 'index']);
        Route::post('/overtime/orders/{order:uuid}/approve', [OvertimeOrderController::class, 'approve']);
        Route::post('/overtime/orders/{order:uuid}/reject', [OvertimeOrderController::class, 'reject']);
        Route::post('/overtime/orders/{order:uuid}/assignments', [OvertimeOrderController::class, 'assign']);
        Route::post('/overtime/time-records/{record:uuid}/certify', [OvertimeTimeController::class, 'certify']);
        Route::get('/overtime/reports', [OvertimeReportController::class, 'index']);
    });

    Route::get('/attendances/me', [AttendanceController::class, 'myRecords'])
        ->middleware('can:attendance.view_self');

    // Admin attendance — ต้องมีสิทธิ์ view_all
    Route::group(['middleware' => ['can:attendance.view_all']], function () {
        Route::get('/admin/attendances', [AttendanceController::class, 'allRecords']);
        Route::get('/admin/attendances/export/pdf', [AttendanceController::class, 'exportPdf'])->middleware('throttle:exports');
        Route::get('/admin/attendances/export/word', [AttendanceController::class, 'exportWord'])->middleware('throttle:exports');
        Route::get('/admin/attendances/summary', [AttendanceController::class, 'summary']);
        Route::post('/admin/attendances/sync-leaves', [AttendanceController::class, 'syncLeaves']);
        Route::post('/admin/attendance-corrections/{correction:uuid}/approve', [AttendanceCorrectionController::class, 'approve']);
        Route::post('/admin/attendance-corrections/{correction:uuid}/reject', [AttendanceCorrectionController::class, 'reject']);
    });

    // OT Summary — สิทธิ์เฉพาะ
    Route::get('/admin/attendances/ot-summary', [AttendanceController::class, 'otSummary'])
        ->middleware('can:attendance.ot_summary');

    Route::post('/admin/leaves', [LeaveRequestController::class, 'storeAsAdmin']);
    Route::get('/admin/leaves/record-signers', [LeaveRequestController::class, 'recordSigners']);

    // Approval routes
    // approve/reject ประกาศไว้ที่ routes/leave_government.php (GovernmentApprovalController)
    // ซึ่งลงทะเบียนทีหลังจึงทับเส้นทางนี้อยู่แล้ว — ย้ายไปไว้ที่เดียวเพื่อไม่ให้เข้าใจผิดว่าใช้ตัวไหน
    Route::get('/approvals/pending', [ApprovalController::class, 'pending']);

    // Report routes
    Route::get('/reports/summary', [ReportController::class, 'summary']);
    Route::get('/reports/export-excel', [ReportController::class, 'exportExcel'])->middleware('throttle:exports');
    Route::get('/reports/export-pdf', [ReportController::class, 'exportPdf'])->middleware('throttle:exports');

    // Unified, scope-aware reporting layer. Existing Government Report endpoints remain unchanged.
    Route::get('/reporting/evaluation-metrics', [ReportingController::class, 'evaluation']);
    Route::get('/reporting/{report}/export/{format}', [ReportingController::class, 'export'])
        ->whereIn('report', ReportingDataService::REPORTS)
        ->whereIn('format', ['pdf', 'xlsx', 'docx', 'csv'])
        ->middleware('throttle:exports');
    Route::get('/reporting/{report}', [ReportingController::class, 'show'])
        ->whereIn('report', ReportingDataService::REPORTS);

    // Admin routes
    Route::group(['middleware' => ['can:system.user.view']], function () {
        Route::get('/admin/users', [UserController::class, 'index']);
        Route::get('/admin/roles', [UserController::class, 'roles']);
        Route::get('/admin/departments-list', [UserController::class, 'departments']);
    });

    Route::group(['middleware' => ['permission:system.user.manage']], function () {
        // K-ORG-01: บทบาทที่ผู้ใช้คนนี้มอบได้ (ผู้ไม่มีสิทธิ์กำหนดสิทธิ์จะเห็นเฉพาะบทบาทที่ไม่เกินของตน)
        Route::get('/admin/assignable-roles', [UserController::class, 'assignableRoles']);
        Route::post('/admin/users', [UserController::class, 'store']);
        Route::post('/admin/users/import', [UserController::class, 'importCsv']);
        Route::put('/admin/users/{user}', [UserController::class, 'update']);
        Route::post('/admin/users/{user}/toggle-status', [UserController::class, 'toggleStatus']);
        // ACCESS-GOV-01: บุคลากรพ้นสภาพ — ระงับบัญชีและปิดวาระหัวหน้า/การทำการแทน/ขอบเขตสิทธิ์ ไม่ลบบัญชี
        Route::post('/admin/users/{user}/separate', [UserController::class, 'separate']);
    });

    // ORG-ACCESS-02: ทะเบียนตำแหน่ง — อ่านได้ทุกคน (ฟอร์มผู้ใช้/ตัวกรองรายงาน) ไม่มีการลบ
    // แก้ไข/ผูกตำแหน่งใช้ system.position.manage (งานบุคลากร) โดยไม่ต้องมีสิทธิ์แก้บทบาท/สิทธิ์ของผู้ใช้
    Route::get('/positions', [PositionController::class, 'index']);
    Route::group(['middleware' => ['permission:system.user.manage|system.position.manage']], function () {
        Route::post('/admin/position-groups', [PositionController::class, 'storeGroup']);
        Route::put('/admin/position-groups/{positionGroup}', [PositionController::class, 'updateGroup']);
        Route::post('/admin/positions', [PositionController::class, 'store']);
        Route::put('/admin/positions/{position}', [PositionController::class, 'update']);
        Route::put('/admin/users/{user:uuid}/position', [PositionController::class, 'assign']);
    });

    /*
     * การกำหนดสิทธิ์รายบุคคล — LEGACY-04 (ปิดงาน)
     *
     * เดิมสี่ endpoint นี้อยู่ในกลุ่มเดียวกับการแก้ไขผู้ใช้ ซึ่งยอมรับ `edit_users`
     * ด้วย แปลว่าผู้ที่แก้ไขข้อมูลผู้ใช้ได้ (เช่น HR) จะ **มอบสิทธิ์ใด ๆ ให้ตนเอง
     * หรือผู้อื่นได้ทันที** เป็นช่องยกระดับสิทธิ์ที่มีมาก่อนหน้านี้
     *
     * แยกออกมาให้ต้องมี `admin.users.manage_permissions` โดยเฉพาะ ตามข้อกำหนด
     * least privilege ของงานนี้ที่ระบุ "การจัดการ permission" ไว้ชัดเจน
     */
    Route::group(['middleware' => ['permission:admin.users.manage_permissions']], function () {
        Route::get('/admin/permissions/modules', [PermissionController::class, 'modules']);
        Route::get('/admin/permissions/user-counts', [PermissionController::class, 'userCounts']);
        Route::get('/admin/users/{user}/permissions', [PermissionController::class, 'getUserPermissions']);
        Route::put('/admin/users/{user}/permissions', [PermissionController::class, 'updateUserPermissions']);
    });

    // ระบบไม่ลบบัญชี (destroy ตอบ 409 พร้อมคำแนะนำให้ระงับ/บันทึกพ้นสภาพ) — RBAC-CLEANUP-01 เลิกสิทธิ์ delete_users
    Route::delete('/admin/users/{user}', [UserController::class, 'destroy'])->middleware('permission:system.user.manage');

    // Admin: Departments CRUD
    Route::group(['middleware' => ['can:manage_departments']], function () {
        Route::get('/admin/departments', [DepartmentController::class, 'index']);
        Route::post('/admin/departments', [DepartmentController::class, 'store']);
        Route::put('/admin/departments/{department}', [DepartmentController::class, 'update']);
        Route::delete('/admin/departments/{department}', [DepartmentController::class, 'destroy']);
        Route::put('/admin/departments/{department}/toggle-status', [DepartmentController::class, 'toggleStatus']);
    });

    // Admin: โครงสร้างหน่วยงานและทะเบียนหัวหน้าหน่วยงาน (ACCESS-GOV-01) — ไม่มี delete โดยตั้งใจ
    // หน้าลำดับการอนุมัติอ่านรายการหน่วยงานได้ด้วย เพื่อเลือกหน่วยของขั้นแบบ ORG_UNIT_MANAGER
    Route::get('/admin/org-units', [OrgStructureController::class, 'index'])->middleware('permission:system.org_structure.manage|manage_workflow_steps');
    Route::group(['middleware' => ['permission:system.org_structure.manage']], function () {
        Route::post('/admin/org-units', [OrgStructureController::class, 'store']);
        Route::put('/admin/org-units/{uuid}', [OrgStructureController::class, 'update']);
        Route::get('/admin/org-units/{uuid}/managers', [OrgStructureController::class, 'managerHistory']);
        Route::post('/admin/org-units/{uuid}/managers', [OrgStructureController::class, 'storeManager']);
        Route::post('/admin/org-unit-managers/{uuid}/end', [OrgStructureController::class, 'endManager']);
        Route::put('/admin/org-structure/overtime-permit-unit', [OrgStructureController::class, 'updateOvertimePermitUnit']);
        Route::post('/admin/org-units/{uuid}/members', [OrgStructureController::class, 'storeMember']);
        Route::post('/admin/org-unit-members/{uuid}/end', [OrgStructureController::class, 'endMember']);
    });

    // Admin: Workflow Steps
    Route::group(['middleware' => ['can:manage_workflow_steps']], function () {
        Route::get('/admin/workflow-steps', [WorkflowController::class, 'index']);
        Route::post('/admin/workflow-steps', [WorkflowController::class, 'store']);
        // ทดลองว่าใครคือผู้อนุมัติจริงของแต่ละขั้น ก่อนบันทึกการตั้งค่า
        Route::post('/admin/workflow-steps/preview', [WorkflowController::class, 'preview']);
    });

    // Admin: ทะเบียนการรักษาราชการแทน / ปฏิบัติราชการแทน (DELEGATION-01) — ไม่มี delete โดยตั้งใจ
    // สิทธิ์ตรวจใน DelegationController (LeaveApprovalSigner::canRecord) แบบเดียวกับงานจัดการวันลาอื่น
    Route::get('/admin/delegations', [DelegationController::class, 'index']);
    Route::post('/admin/delegations', [DelegationController::class, 'store']);
    Route::put('/admin/delegations/{delegation}', [DelegationController::class, 'update']);
    Route::post('/admin/delegations/{delegation}/approve', [DelegationController::class, 'approve']);
    Route::post('/admin/delegations/{delegation}/revoke', [DelegationController::class, 'revoke']);

    // Admin: Leave Types CRUD
    Route::group(['middleware' => ['can:manage_leave_types']], function () {
        Route::get('/admin/leave-types', [LeaveTypeController::class, 'index']);
        Route::post('/admin/leave-types', [LeaveTypeController::class, 'store']);
        Route::put('/admin/leave-types/{type}', [LeaveTypeController::class, 'update']);
        Route::delete('/admin/leave-types/{type}', [LeaveTypeController::class, 'destroy']);
    });

    // Admin: Settings & Fiscal Years
    /*
     * ตั้งค่าระบบ — LEGACY-04 (ปิดงาน)
     *
     * ถอด `saraban.setting.manage` ออกจากกลุ่มนี้ เพราะกลุ่มนี้ครอบคลุมค่า SMTP,
     * Telegram/LINE, การล้างค่าลับ, ปีงบประมาณ และเทมเพลตเอกสารทั้งหมด
     * ผู้ดูแลงานสารบรรณไม่ควรเห็นหรือแก้ค่าเหล่านั้น
     *
     * ค่าที่เป็นของงานสารบรรณย้ายไปที่ `/api/saraban/settings` ด้านล่าง
     */
    Route::middleware('permission:system.setting.manage')->group(function () {
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

        // TPL-SEC-01: แบบฟอร์ม HTML อ่านอย่างเดียว — ไม่มี endpoint รับ HTML จากผู้ใช้ไปเรนเดอร์อีกแล้ว
        Route::get('/admin/document-templates', [DocumentTemplateController::class, 'index']);
        Route::post('/admin/document-templates/seed-defaults', [DocumentTemplateController::class, 'seedDefaults']);
        Route::get('/admin/document-templates/fields', [DocumentTemplateController::class, 'fields']);
        Route::get('/admin/document-templates/{template}', [DocumentTemplateController::class, 'show']);
        Route::get('/admin/document-templates/{template}/preview', [DocumentTemplateController::class, 'preview'])->middleware('throttle:documents');

        Route::get('/admin/fiscal-years', [FiscalYearController::class, 'index']);

        Route::post('/admin/fiscal-years', [FiscalYearController::class, 'store']);
        Route::put('/admin/fiscal-years/{fiscalYear}', [FiscalYearController::class, 'update']);
        Route::delete('/admin/fiscal-years/{fiscalYear}', [FiscalYearController::class, 'destroy']);
    });

    /*
     * ศูนย์จัดการเอกสารและรายงาน (Document & Report Engine)
     *
     * ยอมรับทั้งสิทธิ์เฉพาะชุดใหม่และสิทธิ์ตั้งค่าระบบเดิม เพื่อให้ผู้ดูแลที่ใช้งานอยู่
     * ไม่ถูกตัดสิทธิ์ในวันที่อัปเกรด แล้วค่อยถอดสิทธิ์เดิมออกเมื่อกำหนดบทบาทใหม่ครบ
     */
    Route::middleware('permission:document.template.view|document.template.manage|system.setting.manage')->group(function () {
        Route::get('/admin/document-engine/overview', [DocumentEngineController::class, 'overview']);
        Route::get('/admin/document-engine/templates', [DocumentEngineController::class, 'templates']);
        Route::get('/admin/document-engine/templates/{template}', [DocumentEngineController::class, 'showTemplate']);
        Route::get('/admin/document-engine/fields', [DocumentEngineController::class, 'fieldDictionary']);
        Route::get('/admin/document-engine/data-sources', [DocumentEngineController::class, 'dataSources']);
        Route::get('/admin/document-engine/bindings', [DocumentEngineController::class, 'bindings']);
        Route::get('/admin/document-engine/leave-type-forms', [DocumentEngineController::class, 'leaveTypeForms']);
        Route::get('/admin/document-engine/calibrations', [DocumentEngineController::class, 'calibrations']);
        Route::get('/admin/document-engine/versions/{version}/mappings', [DocumentEngineController::class, 'mappings']);
        Route::get('/admin/document-engine/versions/{version}/pdf', [DocumentEngineController::class, 'pdfFile'])->middleware('throttle:documents');
        Route::get('/admin/document-engine/versions/{version}/preview', [DocumentEngineController::class, 'previewVersion'])->middleware('throttle:documents');
        Route::get('/admin/document-engine/generated', [DocumentEngineController::class, 'generatedFiles']);
    });

    Route::middleware('permission:document.template.manage|system.setting.manage')->group(function () {
        Route::post('/admin/document-engine/templates', [DocumentEngineController::class, 'storeTemplate']);
        Route::post('/admin/document-engine/templates/{template}/versions', [DocumentEngineController::class, 'createVersion']);
        Route::post('/admin/document-engine/versions/{version}/pdf', [DocumentEngineController::class, 'uploadPdf']);
        Route::post('/admin/document-engine/versions/{version}/publish', [DocumentEngineController::class, 'publishVersion']);
        Route::post('/admin/document-engine/versions/{version}/retire', [DocumentEngineController::class, 'retireVersion']);
        Route::delete('/admin/document-engine/versions/{version}', [DocumentEngineController::class, 'deleteVersion']);
        Route::put('/admin/document-engine/versions/{version}/mappings', [DocumentEngineController::class, 'saveMappings']);
        Route::post('/admin/document-engine/bindings', [DocumentEngineController::class, 'saveBinding']);
        Route::post('/admin/document-engine/leave-type-forms/{target}/generate', [DocumentEngineController::class, 'generateLeaveTypeForm'])
            ->where('target', '[0-9]+|cancel')->middleware('throttle:documents');
        Route::put('/admin/document-engine/leave-type-forms/{target}', [DocumentEngineController::class, 'setLeaveTypeForm'])
            ->where('target', '[0-9]+|cancel');
        Route::delete('/admin/document-engine/bindings/{rule}', [DocumentEngineController::class, 'deleteBinding']);
        Route::post('/admin/document-engine/calibrations', [DocumentEngineController::class, 'saveCalibration']);
    });

    Route::middleware('permission:document.report.view|document.report.manage|system.setting.manage')->group(function () {
        Route::get('/admin/document-reports', [DocumentReportController::class, 'index']);
        Route::get('/admin/document-reports/{report}/run', [DocumentReportController::class, 'run']);
        Route::get('/admin/document-reports/{report}/export/{format}', [DocumentReportController::class, 'export'])
            ->whereIn('format', ['pdf', 'xlsx', 'csv'])
            ->middleware('throttle:exports');
    });

    Route::post('/admin/document-reports', [DocumentReportController::class, 'store'])
        ->middleware('permission:document.report.manage|system.setting.manage');

    Route::get('/admin/document-engine/generated/{file}/download', [DocumentEngineController::class, 'downloadGenerated'])
        ->middleware(['permission:document.final.download|system.setting.manage', 'throttle:documents']);

    // Saraban Document routes.
    // Route-level `permission:` middleware is the coarse gate; DocumentPolicy
    // (checked inside the controllers) enforces per-document rules on top of it.
    Route::prefix('saraban')->group(function () {
        /*
         * ตั้งค่าระบบสารบรรณ — จำกัดเฉพาะค่าของงานสารบรรณ
         * (Decision 1: `saraban.setting.manage` "เฉพาะผู้ได้รับมอบหมาย")
         */
        Route::middleware('permission:saraban.setting.manage')->group(function () {
            Route::get('/settings', [SarabanSettingController::class, 'index']);
            Route::post('/settings', [SarabanSettingController::class, 'update']);
            Route::post('/settings/garuda-logo', [SarabanSettingController::class, 'uploadGarudaLogo']);
        });

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
            /*
             * SEC-025 — read/state ใช้ route model binding และผ่านด่านชั้นความลับ
             * เหมือน `show` เดิม `/documents/{id}/state` รับ id ดิบและไม่ตรวจอะไรเลย
             */
            Route::post('/documents/{document}/read', [DocumentController::class, 'read'])
                ->middleware('saraban.classified');
            Route::post('/documents/{document}/state', [DocumentController::class, 'updateState'])
                ->middleware('saraban.classified');
            Route::get('/attachments/{attachment}/download', [DocumentController::class, 'downloadAttachment'])->middleware('throttle:documents');
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
            Route::get('/reports/export', [App\Http\Controllers\Api\Saraban\ReportController::class, 'exportCsv'])->middleware('throttle:exports');
        });
    });

    Route::get('/fiscal-year/current', [FiscalYearController::class, 'current']);

    // ดาวน์โหลดไฟล์อัปโหลด — ต้องยืนยันตัวตน (แทน /api/storage/{path} เดิมที่เปิดสาธารณะ)
    Route::get('/files/{path}', [SecureFileController::class, 'show'])
        ->middleware('throttle:files')
        ->where('path', '.*');

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::put('/notifications/{id}/read', [NotificationController::class, 'markAsRead'])->whereUuid('id');
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::get('/notification-preferences', [NotificationController::class, 'preferences']);
    Route::put('/notification-preferences', [NotificationController::class, 'updatePreference']);
    // Dynamic Menu & Role Management routes
    Route::get('/user/menus', [MenuController::class, 'index']);
    /*
     * จัดการเมนูและบทบาท — LEGACY-04 (ปิดงาน)
     *
     * เดิมกลุ่มนี้ยอมรับ `edit_users` ด้วย ผู้ที่แก้ไขผู้ใช้ได้จึง **สร้าง role
     * และผูก permission ใดก็ได้เข้ากับ role นั้น** แล้วมอบให้ตนเอง
     * เป็นช่องยกระดับสิทธิ์แบบเดียวกับการกำหนดสิทธิ์รายบุคคลด้านบน
     *
     * เหลือเฉพาะ `system.menu.manage` ซึ่งเป็นสิทธิ์ของผู้ดูแลระบบ
     */
    Route::group(['middleware' => ['permission:system.menu.manage']], function () {
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
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::middleware('can:attendance.view_all')->group(function () {
        Route::get('/admin/attendances/summary/preview', [AttendanceController::class, 'previewSummary']);
        Route::get('/admin/attendances/summary/export/pdf', [AttendanceController::class, 'exportSummaryPdf'])->middleware('throttle:exports');
        Route::get('/admin/attendances/summary/export/word', [AttendanceController::class, 'exportSummaryWord'])->middleware('throttle:exports');
    });

    Route::middleware('can:attendance.ot_summary')->group(function () {
        Route::get('/admin/attendances/ot-summary/preview', [AttendanceController::class, 'previewOTSummary']);
        Route::get('/admin/attendances/ot-summary/export/pdf', [AttendanceController::class, 'exportOTSummaryPdf'])->middleware('throttle:exports');
        Route::get('/admin/attendances/ot-summary/export/word', [AttendanceController::class, 'exportOTSummaryWord'])->middleware('throttle:exports');
        Route::get('/admin/attendances/ot-summary/export/excel', [AttendanceController::class, 'exportOTSummaryExcel'])->middleware('throttle:exports');
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
