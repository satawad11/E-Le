<?php

use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\DocumentEngineController;
use App\Http\Controllers\Admin\DocumentReportController;
use App\Http\Controllers\Admin\LeaveTypeController;
use App\Http\Controllers\Admin\ModuleController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WorkflowController;
use App\Http\Controllers\Api\ApiDescriptorController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\RoleController;
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
use App\Http\Controllers\NotificationCenter\NotificationOperationsController;
use App\Http\Controllers\NotificationCenter\WebPushSubscriptionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Operations\CommandCenterController;
use App\Http\Controllers\Operations\ComplianceEvidenceController;
use App\Http\Controllers\Operations\ExceptionQueueController;
use App\Http\Controllers\Operations\FeatureFlagController;
use App\Http\Controllers\Operations\HealthController;
use App\Http\Controllers\Overtime\OvertimeOrderController;
use App\Http\Controllers\Overtime\OvertimeReportController;
use App\Http\Controllers\Overtime\OvertimeTimeController;
use App\Http\Controllers\PersonalDataController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportingController;
use App\Http\Controllers\Saraban\AnnotationController;
use App\Http\Controllers\Saraban\AuthorityMatrixController;
use App\Http\Controllers\Saraban\CaseController;
use App\Http\Controllers\Saraban\DistributionController;
use App\Http\Controllers\Saraban\DocumentController;
use App\Http\Controllers\Saraban\DocumentVerificationController;
use App\Http\Controllers\Saraban\InboxController;
use App\Http\Controllers\Saraban\IncomingRegistrationController;
use App\Http\Controllers\Saraban\NumberReservationController;
use App\Http\Controllers\Saraban\NumberSeriesController;
use App\Http\Controllers\Saraban\PolicyController;
use App\Http\Controllers\Saraban\RedactionController;
use App\Http\Controllers\Saraban\RegisterReportController;
use App\Http\Controllers\Saraban\RetentionController;
use App\Http\Controllers\Saraban\SavedViewController;
use App\Http\Controllers\Saraban\SignatureController;
use App\Http\Controllers\Saraban\SignatureVerificationController;
use App\Http\Controllers\Saraban\WorkflowSimulationController;
use App\Http\Controllers\SecureFileController;
use App\Http\Controllers\SecureSettingController;
use App\Http\Controllers\Webhooks\WebhookEndpointController;
use App\Services\Reporting\ReportingDataService;
use Illuminate\Support\Facades\Route;

// Public routes
Route::middleware('throttle:public-api')->group(function () {
    Route::get('/ping', function () {
        return response()->json(['message' => 'pong']);
    });

    /*
     * INT-21 — สุขภาพระบบสำหรับ uptime monitor ภายนอก (SoT §27)
     *
     * เปิดสาธารณะโดยตั้งใจ และ **คืนแค่ ok/degraded/down ไม่มีรายละเอียดใด ๆ**
     * — monitor ภายนอกต้องเรียกได้โดยไม่มีบัญชีผู้ใช้ ส่วนรายละเอียดของแต่ละชิ้น
     * เป็นข้อมูลภายในที่อยู่หลังด่านสิทธิ์ที่ `/api/saraban/operations/health`
     *
     * คืน 503 เมื่อระบบใช้งานไม่ได้ เพื่อให้ monitor จับได้จาก status code
     * โดยไม่ต้องอ่าน body
     */
    Route::get('/health', [HealthController::class, 'summary']);

    // ตรวจสอบใบลาจาก QR บนเอกสารที่พิมพ์ออกไป — จำกัดอัตราเพื่อกันการสุ่มโทเคน
    Route::middleware('throttle:verify')->group(function () {
        Route::get('/leaves/verify/{hash}', [LeaveVerificationController::class, 'verify']);
        Route::get('/leaves/verify/{hash}/qrcode', [LeaveVerificationController::class, 'qrcode']);
    });

    /*
     * ตรวจสอบหนังสือของงานสารบรรณ (INT-12 · SoT §12)
     *
     * เปิดสาธารณะเช่นเดียวกับการตรวจสอบใบลา และใช้ throttle ชุดเดียวกัน
     * เพื่อกันการสุ่มโทเคน
     */
    Route::middleware('throttle:verify')->group(function () {
        Route::get('/saraban/verify/{hash}', [DocumentVerificationController::class, 'verify']);
        Route::get('/saraban/verify/{hash}/qrcode', [DocumentVerificationController::class, 'qrcode']);
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
    // SEC-024 — เปลี่ยนรหัสผ่านของตนเอง (ต้องกรอกรหัสผ่านปัจจุบัน) แยกจากการแก้โปรไฟล์ทั่วไป
    Route::post('/profile/password', [ProfileController::class, 'changePassword'])->middleware('throttle:password-change');
    /*
     * SEC-GAP-01 (INT-11) — ยืนยันตัวตนซ้ำก่อนทำสิ่งที่อ่อนไหว (SoT §24)
     *
     * ไม่ออกโทเคนใหม่ เพียงบันทึกว่าโทเคนใบปัจจุบันเพิ่งยืนยันตัวตน
     * จำกัดอัตราด้วย throttle ชุดเดิมของรหัสผ่าน เพื่อไม่ให้ใช้เดารหัสผ่าน
     */
    Route::post('/auth/confirm-password', [AuthController::class, 'confirmPassword'])->middleware('throttle:password-change');
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
    Route::get('/leaves/{leave}', [LeaveRequestController::class, 'show']);
    // แก้ไขร่างของตนเอง — ใช้ POST เพราะต้องรับไฟล์แนบผ่าน multipart ได้
    Route::post('/leaves/{leave}/draft', [LeaveRequestController::class, 'updateDraft']);
    // ส่งร่างเข้าสายอนุมัติ — จุดที่ใบลาออกเลขที่หนังสือและกันโควตา
    Route::post('/leaves/{leave}/submit', [LeaveRequestController::class, 'submit']);
    Route::delete('/leaves/{leave}', [LeaveRequestController::class, 'destroy']);

    // Attendance routes — ตรวจสอบสิทธิ์รายฟังก์ชันตามโมดูล attendance
    Route::group(['middleware' => ['can:attendance.checkin']], function () {
        Route::post('/attendances/check-in', [AttendanceController::class, 'checkIn']);
        Route::post('/attendances/check-out', [AttendanceController::class, 'checkOut']);
    });

    Route::get('/attendances/today', [AttendanceController::class, 'today'])
        ->middleware('ability:attendance.checkin|attendance.view_self');
    Route::post('/attendances/{attendance:uuid}/corrections', [AttendanceCorrectionController::class, 'store'])
        ->middleware('ability:attendance.view_self|attendance.view_all');
    // CLEANUP-02 — ดูสถานะคำขอแก้ไขเวลาของตนเอง (เดิมมีแต่ endpoint สร้างและตัดสิน)
    Route::get('/attendance-corrections', [AttendanceCorrectionController::class, 'mine'])
        ->middleware('ability:attendance.view_self|attendance.view_all');

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
        Route::get('/admin/attendance-corrections', [AttendanceCorrectionController::class, 'index']);
        Route::post('/admin/attendance-corrections/{correction:uuid}/approve', [AttendanceCorrectionController::class, 'approve']);
        Route::post('/admin/attendance-corrections/{correction:uuid}/reject', [AttendanceCorrectionController::class, 'reject']);
    });

    // OT Summary — สิทธิ์เฉพาะ
    Route::get('/admin/attendances/ot-summary', [AttendanceController::class, 'otSummary'])
        ->middleware('can:attendance.ot_summary');

    Route::post('/admin/leaves', [LeaveRequestController::class, 'storeAsAdmin']);

    // Approval routes
    // approve/reject ประกาศไว้ที่ routes/leave_government.php (GovernmentApprovalController)
    // ซึ่งลงทะเบียนทีหลังจึงทับเส้นทางนี้อยู่แล้ว — ย้ายไปไว้ที่เดียวเพื่อไม่ให้เข้าใจผิดว่าใช้ตัวไหน
    Route::get('/approvals/pending', [ApprovalController::class, 'pending']);

    /*
     * ชั้นรายงานกลาง — scope-aware ทุกรายงาน
     *
     * CLEANUP-01 (D3) — เดิมมี `/reports/summary`, `/reports/export-excel`,
     * `/reports/export-pdf` ของ `ReportController` ซ้อนอยู่อีกชุด ซึ่งไม่มีหน้าใด
     * ในระบบเรียกเลย และคำนวณขอบเขตการมองเห็นด้วยตรรกะคนละชุดกับที่นี่
     * ถอดออกแล้วเมื่อ 2026-08-28 — รายงานสถิติการลาใช้ `/reporting/leave` แทน
     *
     * ทะเบียนคุมการลาของราชการ (`/reports/government-leave-*`) เป็นเอกสารคนละชนิด
     * (รายใบ รวมใบที่ถูกลบ) จึงยังอยู่ตามเดิม
     */
    Route::get('/reporting', [ReportingController::class, 'index']);
    Route::get('/reporting-org-units', [ReportingController::class, 'orgUnits']);
    Route::get('/reporting/evaluation-metrics', [ReportingController::class, 'evaluation']);
    Route::get('/reporting/{report}/export/{format}', [ReportingController::class, 'export'])
        ->whereIn('report', ReportingDataService::REPORTS)
        ->whereIn('format', ['pdf', 'xlsx', 'docx', 'csv'])
        ->middleware('throttle:exports');
    Route::get('/reporting/{report}', [ReportingController::class, 'show'])
        ->whereIn('report', ReportingDataService::REPORTS);

    // Admin routes
    Route::group(['middleware' => ['can:view_users']], function () {
        Route::get('/admin/users', [UserController::class, 'index']);

        /*
         * SEC-030 — ทะเบียนประวัติรายคน
         *
         * `UserController::show()` มีอยู่มาตลอดแต่ไม่เคยมี route ชี้ถึง หน้าจอจึงต้อง
         * อาศัยข้อมูลจากรายการซึ่งบังคับให้รายการต้องส่งเลขบัตรประชาชนฉบับเต็มออกมา
         *
         * เปิดเส้นทางนี้แล้วรายการจึงส่งเลขแบบปิดบังได้ · เส้นทางนี้ตรวจขอบเขต
         * หน่วยงานและบันทึกการเข้าถึงข้อมูลส่วนบุคคลรายครั้ง (ดูในตัว controller)
         *
         * จำกัดอัตราด้วยถังเดียวกับการขอข้อมูลส่วนบุคคล เพื่อไม่ให้ไล่ดึงทีละคน
         * จนได้เลขครบทั้งองค์กรแทนการเรียกรายการ
         */
        Route::get('/admin/users/{user}', [UserController::class, 'show'])
            ->middleware('throttle:personal-record');
    });

    /*
     * CLEANUP-01 — รายชื่อหน่วยงานสำหรับช่องตัวกรอง
     *
     * เดิมมีสองเส้นทางที่พังทั้งคู่: `/departments` ไม่เคยมี route และ
     * `/admin/departments-list` ชี้ไปยังเมธอดที่ไม่มีอยู่ รวมเหลือเส้นทางเดียว
     */
    Route::get('/departments', [DepartmentController::class, 'options']);

    Route::group(['middleware' => ['can:system.user.manage']], function () {
        Route::post('/admin/users', [UserController::class, 'store']);
        Route::post('/admin/users/import', [UserController::class, 'importCsv']);
        Route::put('/admin/users/{user}', [UserController::class, 'update']);
        Route::post('/admin/users/{user}/toggle-status', [UserController::class, 'toggleStatus']);
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
    Route::group(['middleware' => ['can:admin.users.manage_permissions']], function () {
        Route::get('/admin/permissions/modules', [PermissionController::class, 'modules']);
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
        // ทดลองว่าใครคือผู้อนุมัติจริงของแต่ละขั้น ก่อนบันทึกการตั้งค่า
        Route::post('/admin/workflow-steps/preview', [WorkflowController::class, 'preview']);
    });

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
     */
    Route::middleware('can:system.setting.manage')->group(function () {
        Route::get('/admin/settings', [SecureSettingController::class, 'adminIndex']);
        Route::post('/admin/settings', [SecureSettingController::class, 'update']);
        Route::post('/admin/settings/upload-logo', [SecureSettingController::class, 'uploadLogo']);
        Route::delete('/admin/settings/logo', [SecureSettingController::class, 'deleteLogo']);
        Route::delete('/admin/settings/secrets/{key}', [SecureSettingController::class, 'clearSecret']);
        Route::post('/admin/settings/test-notification/{channel}', [SecureSettingController::class, 'testNotification']);

        /*
         * INT-14 — dead-letter และสุขภาพช่องทางส่ง (SoT §16)
         *
         * อยู่ใต้ `system.setting.manage` ซึ่งเป็นเจ้าของการตั้งค่าช่องทาง
         * แจ้งเตือนอยู่แล้ว · INT-17 จะดึงข้อมูลชุดนี้ไปแสดงในศูนย์เฝ้าระวัง
         * การปฏิบัติการ (SoT §20) โดยไม่ต้องเปลี่ยนสัญญาที่นี่
         */
        Route::get('/admin/notifications/dead-letters', [NotificationOperationsController::class, 'deadLetters']);
        Route::post('/admin/notifications/dead-letters/{delivery}/requeue', [NotificationOperationsController::class, 'requeue']);
        Route::get('/admin/notifications/channel-health', [NotificationOperationsController::class, 'channelHealth']);

        /*
         * INT-15 — ปลายทาง webhook ขาออก (SoT §17)
         *
         * การเปิดทางให้ข้อมูลไหลออกนอกระบบเป็นการตัดสินใจระดับผู้ดูแลระบบ
         * ไม่ใช่ระดับผู้ใช้งานโมดูล จึงอยู่ใต้สิทธิ์เดียวกับการตั้งค่าช่องทางแจ้งเตือน
         */
        Route::get('/admin/webhooks', [WebhookEndpointController::class, 'index']);
        Route::post('/admin/webhooks', [WebhookEndpointController::class, 'store']);
        Route::put('/admin/webhooks/{endpoint}', [WebhookEndpointController::class, 'update']);
        Route::delete('/admin/webhooks/{endpoint}', [WebhookEndpointController::class, 'destroy']);
        Route::get('/admin/webhooks/{endpoint}/deliveries', [WebhookEndpointController::class, 'deliveries']);
        Route::post('/admin/webhooks/deliveries/{delivery}/requeue', [WebhookEndpointController::class, 'requeue']);

        // CLEANUP-02 — เปิด-ปิดโมดูลด้วยกลไกเดียวกับที่เมนูและด่าน route ใช้จริง
        Route::get('/admin/modules', [ModuleController::class, 'index']);
        Route::post('/admin/modules', [ModuleController::class, 'update']);

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
    Route::middleware('ability:document.template.view|document.template.manage|system.setting.manage')->group(function () {
        Route::get('/admin/document-engine/overview', [DocumentEngineController::class, 'overview']);
        Route::get('/admin/document-engine/templates', [DocumentEngineController::class, 'templates']);
        /*
         * CLEANUP-01 (D2) — แม่แบบชนิด HTML ย้ายมาอยู่ใต้ศูนย์จัดการเอกสารทั้งหมด
         * เดิมอยู่ที่ `/admin/document-templates` ของ `DocumentTemplateController`
         * ซึ่งอ่านเขียนตารางเดียวกันแต่เป็นคนละเมนู
         *
         * ประกาศก่อน `{template}` เพราะ `content-fields` เป็น path คงที่
         */
        Route::get('/admin/document-engine/templates/content-fields', [DocumentEngineController::class, 'contentFields']);
        Route::get('/admin/document-engine/templates/{template}', [DocumentEngineController::class, 'showTemplate']);
        Route::get('/admin/document-engine/fields', [DocumentEngineController::class, 'fieldDictionary']);
        Route::get('/admin/document-engine/data-sources', [DocumentEngineController::class, 'dataSources']);
        Route::get('/admin/document-engine/bindings', [DocumentEngineController::class, 'bindings']);
        Route::get('/admin/document-engine/calibrations', [DocumentEngineController::class, 'calibrations']);
        Route::get('/admin/document-engine/versions/{version}/mappings', [DocumentEngineController::class, 'mappings']);
        Route::get('/admin/document-engine/versions/{version}/pdf', [DocumentEngineController::class, 'pdfFile'])->middleware('throttle:documents');
        Route::get('/admin/document-engine/versions/{version}/preview', [DocumentEngineController::class, 'previewVersion'])->middleware('throttle:documents');
        Route::get('/admin/document-engine/generated', [DocumentEngineController::class, 'generatedFiles']);
    });

    Route::middleware('ability:document.template.manage|system.setting.manage')->group(function () {
        Route::post('/admin/document-engine/templates', [DocumentEngineController::class, 'storeTemplate']);
        // CLEANUP-01 (D2) — แม่แบบชนิด HTML: แก้เนื้อหา พรีวิว สร้างชุดตั้งต้น และลบแม่แบบ
        Route::post('/admin/document-engine/templates/seed-defaults', [DocumentEngineController::class, 'seedDefaultTemplates']);
        Route::post('/admin/document-engine/templates/preview', [DocumentEngineController::class, 'previewContent'])->middleware('throttle:documents');
        Route::put('/admin/document-engine/templates/{template}/content', [DocumentEngineController::class, 'updateTemplateContent']);
        Route::delete('/admin/document-engine/templates/{template}', [DocumentEngineController::class, 'deleteTemplate']);
        Route::post('/admin/document-engine/templates/{template}/versions', [DocumentEngineController::class, 'createVersion']);
        Route::post('/admin/document-engine/versions/{version}/pdf', [DocumentEngineController::class, 'uploadPdf']);
        Route::post('/admin/document-engine/versions/{version}/publish', [DocumentEngineController::class, 'publishVersion']);
        Route::post('/admin/document-engine/versions/{version}/retire', [DocumentEngineController::class, 'retireVersion']);
        Route::delete('/admin/document-engine/versions/{version}', [DocumentEngineController::class, 'deleteVersion']);
        Route::put('/admin/document-engine/versions/{version}/mappings', [DocumentEngineController::class, 'saveMappings']);
        Route::post('/admin/document-engine/bindings', [DocumentEngineController::class, 'saveBinding']);
        Route::delete('/admin/document-engine/bindings/{rule}', [DocumentEngineController::class, 'deleteBinding']);
        Route::post('/admin/document-engine/calibrations', [DocumentEngineController::class, 'saveCalibration']);
    });

    Route::middleware('ability:document.report.view|document.report.manage|system.setting.manage')->group(function () {
        Route::get('/admin/document-reports', [DocumentReportController::class, 'index']);
        Route::get('/admin/document-reports/{report}/run', [DocumentReportController::class, 'run']);
        Route::get('/admin/document-reports/{report}/export/{format}', [DocumentReportController::class, 'export'])
            ->whereIn('format', ['pdf', 'xlsx', 'csv'])
            ->middleware('throttle:exports');
    });

    Route::post('/admin/document-reports', [DocumentReportController::class, 'store'])
        ->middleware('ability:document.report.manage|system.setting.manage');

    Route::get('/admin/document-engine/generated/{file}/download', [DocumentEngineController::class, 'downloadGenerated'])
        ->middleware(['ability:document.final.download|system.setting.manage', 'throttle:documents']);

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

    /*
     * INT-14 — การแจ้งเตือนผ่านเบราว์เซอร์ (SoT §16)
     *
     * ไม่มีด่าน `ability:` เพราะเป็นการตั้งค่าของ **ตัวผู้เรียกเอง** เช่นเดียวกับ
     * `notification-preferences` ที่อยู่เหนือขึ้นไป · ทุก endpoint ผูกกับ
     * `$request->user()` เสมอ ไม่มีพารามิเตอร์ที่ระบุผู้ใช้คนอื่นได้
     */
    Route::get('/notifications/web-push/key', [WebPushSubscriptionController::class, 'publicKey']);
    Route::get('/notifications/web-push/subscriptions', [WebPushSubscriptionController::class, 'index']);
    Route::post('/notifications/web-push/subscribe', [WebPushSubscriptionController::class, 'subscribe']);
    Route::post('/notifications/web-push/unsubscribe', [WebPushSubscriptionController::class, 'unsubscribe']);
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
    Route::group(['middleware' => ['can:system.menu.manage']], function () {
        Route::get('/admin/menus', [MenuController::class, 'adminIndex']);
        Route::put('/admin/menus/{menu}/permissions', [MenuController::class, 'updatePermissions']);
        Route::get('/admin/permissions', [RoleController::class, 'permissions']);
        // CLEANUP-01 — `show` ไม่มีเมธอดใน RoleController และไม่มีหน้าใดเรียก
        Route::apiResource('/admin/roles', RoleController::class)->except('show');
    });

    /*
     * งานสารบรรณ (INT-05)
     *
     * เส้นทางอยู่ใต้ `api/saraban*` ซึ่งตรงกับ `route_patterns` ของโมดูล
     * `EnsureModuleEnabled` จึงปิดทั้งชุดให้เองเมื่อโมดูลถูกปิด โดยไม่ต้องเขียน guard
     *
     * `ability:` เป็นด่านระดับความสามารถ ส่วนสิทธิ์รายฉบับตัดสินที่ `DocumentPolicy`
     * ใน controller — สองชั้นนี้ทำหน้าที่คนละอย่างและต้องมีทั้งคู่ (SoT §6)
     */
    $sarabanRoutes = function () {
        Route::get('/documents', [DocumentController::class, 'index'])->middleware('ability:saraban.document.view');
        Route::get('/documents/options', [DocumentController::class, 'options'])->middleware('ability:saraban.document.view');
        /*
         * คิวงานของผู้เรียก (NAV §4.7)
         *
         * ต้องประกาศ **ก่อน** `/documents/{document}` มิฉะนั้น `queue` จะถูกจับ
         * เป็นรหัสหนังสือ · ใช้สิทธิ์เดียวกับการเปิดทะเบียนเพราะคิวคือมุมมองหนึ่ง
         * ของทะเบียน ไม่ใช่ความสามารถใหม่
         */
        Route::get('/documents/queue', [DocumentController::class, 'queue'])->middleware('ability:saraban.document.view');
        Route::post('/documents', [DocumentController::class, 'store'])->middleware('ability:saraban.document.create');
        Route::get('/documents/{document}', [DocumentController::class, 'show'])->middleware('ability:saraban.document.view');
        Route::post('/documents/{document}', [DocumentController::class, 'update'])->middleware('ability:saraban.document.update');
        Route::post('/documents/{document}/submit', [DocumentController::class, 'submit'])->middleware('ability:saraban.document.submit');

        /*
         * การเดินเรื่อง (INT-08)
         *
         * ใช้ `saraban.document.view` เป็นด่านระดับความสามารถ เพราะสิทธิ์ในการ
         * "ทำขั้นตอน" ขึ้นกับว่าเป็นผู้ได้รับมอบหมายของขั้นนั้นหรือไม่ ซึ่ง
         * `WorkflowEngine::authorize()` เป็นผู้ตัดสิน ไม่ใช่ชื่อสิทธิ์ที่ route
         */
        Route::get('/documents/{document}/workflow', [DocumentController::class, 'workflow'])->middleware('ability:saraban.document.view');
        Route::post('/documents/{document}/workflow', [DocumentController::class, 'performWorkflowAction'])->middleware('ability:saraban.document.view');

        /*
         * การลงนามและการเกษียน (INT-11)
         *
         * **การลงนามมีด่าน `step_up`** — ต้องยืนยันตัวตนซ้ำภายในช่วงที่กำหนด
         * ก่อนจึงลงนามได้ (SoT §24) · การเกษียนไม่ต้อง เพราะลายมือ ≠ ลายเซ็น (SoT §15)
         */
        Route::post('/documents/{document}/sign', [SignatureController::class, 'sign'])
            ->middleware(['ability:saraban.signature.sign', 'step_up']);
        Route::post('/documents/{document}/endorse', [SignatureController::class, 'endorse'])
            ->middleware('ability:saraban.endorse.create');
        Route::get('/documents/{document}/signatures', [SignatureController::class, 'evidence'])
            ->middleware('ability:saraban.document.view');

        /*
         * ตรวจสอบลายเซ็น และวงจรการเพิกถอน (SIGNATURE-VERIFICATION · SoT §14, §24)
         *
         * **การตรวจสอบใช้สิทธิ์เดียวกับการเปิดดูหนังสือ** — ผู้ที่เปิดหนังสือได้
         * ต้องตรวจสอบความน่าเชื่อถือของมันได้ด้วย · การแยกสิทธิ์เพิ่มจะทำให้คน
         * ส่วนใหญ่เห็นลายเซ็นแต่ไม่รู้ว่ามันยังใช้ได้ไหม ซึ่งแย่กว่าไม่แสดงเลย
         *
         * **การเพิกถอนแยกเป็นสองสิทธิ์และบังคับ `step_up` ทั้งคู่** — เป็นการ
         * เปลี่ยนสถานะเอกสารที่เผยแพร่ออกไปแล้ว และต้องแยกผู้ขอออกจากผู้ตัดสิน
         * ตาม SoT §26 แบบเดียวกับการทำลายเอกสาร (INT-19)
         */
        Route::get('/documents/{document}/verification', [SignatureVerificationController::class, 'show'])
            ->middleware('ability:saraban.document.view');
        Route::get('/documents/{document}/verification/report', [SignatureVerificationController::class, 'report'])
            ->middleware('ability:saraban.document.view');
        Route::post('/documents/{document}/revocations', [SignatureVerificationController::class, 'requestRevocation'])
            ->middleware(['ability:saraban.signature.revoke', 'step_up']);
        Route::get('/revocations/pending', [SignatureVerificationController::class, 'pending'])
            ->middleware('ability:saraban.signature.revoke_approve');
        Route::post('/revocations/{revocation}/decision', [SignatureVerificationController::class, 'decide'])
            ->middleware(['ability:saraban.signature.revoke_approve', 'step_up']);

        /*
         * ออกหนังสือฉบับสมบูรณ์พร้อม QR (INT-12)
         *
         * มีด่าน `step_up` เช่นเดียวกับการลงนาม เพราะเป็นการทำให้หนังสือมีผล
         * ต่อบุคคลภายนอกอย่างถาวร — เป็นการกระทำที่ย้อนกลับไม่ได้ตาม SoT §24
         */
        Route::post('/documents/{document}/finalize', [SignatureController::class, 'finalize'])
            ->middleware(['ability:saraban.signature.sign', 'step_up']);

        /*
         * PDF Workspace (INT-10)
         *
         * ไม่มี endpoint ใดรับภาพจากเบราว์เซอร์ — รับเฉพาะพิกัดเป็นข้อมูล
         * แล้วเรนเดอร์ที่ฝั่ง server (SoT §14)
         */
        Route::get('/documents/{document}/annotations', [AnnotationController::class, 'index'])->middleware('ability:saraban.document.view');
        Route::put('/documents/{document}/annotations', [AnnotationController::class, 'store'])->middleware('ability:saraban.incoming.stamp');
        Route::post('/documents/{document}/annotations/render', [AnnotationController::class, 'render'])->middleware('ability:saraban.incoming.stamp');

        /*
         * จำลองเส้นทางเดินหนังสือ (INT-09)
         *
         * ใช้ POST เพราะรับพารามิเตอร์เป็น body แต่เป็นการอ่านอย่างเดียว
         * ไม่สร้าง instance ไม่ออกเลข ไม่ส่งแจ้งเตือน (SoT §12)
         */
        Route::get('/workflow/versions', [WorkflowSimulationController::class, 'versions'])->middleware('ability:saraban.workflow.simulate');
        Route::post('/workflow/simulate', [WorkflowSimulationController::class, 'simulate'])->middleware('ability:saraban.workflow.simulate');

        // ทะเบียนรับหนังสือ (INT-07)
        Route::get('/incoming', [IncomingRegistrationController::class, 'index'])->middleware('ability:saraban.document.view');
        Route::post('/incoming/duplicates', [IncomingRegistrationController::class, 'duplicates'])->middleware('ability:saraban.incoming.register');
        Route::post('/incoming', [IncomingRegistrationController::class, 'store'])->middleware('ability:saraban.incoming.register');
        Route::get('/incoming/{registration}', [IncomingRegistrationController::class, 'show'])->middleware('ability:saraban.document.view');

        /*
         * การเวียนหนังสือ (INT-13 · SoT §15)
         *
         * `create` คือสิทธิ์จัดทำรายการเวียน ส่วน `send` คือสิทธิ์ส่งจริง — ที่นี่
         * ใช้ `send` เพราะ endpoint นี้ส่งถึงผู้รับทันที การจัดทำรายการเวียนไว้ล่วงหน้า
         * โดยยังไม่ส่งเป็นความสามารถแยกที่ยังไม่มีในรอบนี้
         */
        Route::post('/documents/{document}/distributions', [DistributionController::class, 'store'])
            ->middleware('ability:saraban.distribution.send');
        Route::get('/documents/{document}/distributions', [DistributionController::class, 'index'])
            ->middleware('ability:saraban.distribution.track');
        Route::get('/distribution-targets', [DistributionController::class, 'targets'])
            ->middleware('ability:saraban.distribution.send');

        /*
         * กล่องหนังสือเข้า (INT-13 · SoT §15)
         *
         * ใช้ `saraban.inbox.view` ไม่ใช่ `saraban.document.view` — สองข้อนี้เป็นคนละ
         * คำถาม ข้าราชการทั่วไปต้องเปิดหนังสือที่เวียนถึงตนได้โดยไม่ต้องค้นทะเบียน
         * หนังสือของหน่วยงานได้
         *
         * สิทธิ์นี้เป็นเพียงด่านความสามารถ · **ขอบเขตจริงคือการเป็นผู้รับ** ซึ่ง
         * `InboxController` บังคับกับทุก query และทุกการกระทำ ผู้ถือสิทธิ์นี้จึงเห็น
         * ได้เฉพาะกล่องของตัวเองเสมอ
         */
        Route::middleware('ability:saraban.inbox.view')->group(function () {
            Route::get('/inbox', [InboxController::class, 'index']);
            Route::get('/inbox/{recipient}', [InboxController::class, 'show']);
            Route::post('/inbox/{recipient}/acknowledge', [InboxController::class, 'acknowledge']);
            Route::post('/inbox/{recipient}/progress', [InboxController::class, 'reportProgress']);
        });

        /*
         * ศูนย์นโยบาย (INT-16 · SoT §18)
         *
         * **อ่านได้กว้าง แก้ได้แคบ** — ผู้ปฏิบัติงานต้องรู้กติกาที่ตนถูกตัดสิน
         * จึงใช้สิทธิ์ดูหนังสือเป็นด่านการอ่าน ส่วนการแก้จำกัดที่ `policy.manage`
         *
         * **การเผยแพร่ต้องยืนยันตัวตนซ้ำ (SoT §24)** เพราะนโยบายที่เปลี่ยนไป
         * มีผลกับหนังสือทุกฉบับที่ออกหลังจากนั้น และ "ย้อนกลับ" ได้เฉพาะในความหมาย
         * ของการประกาศกติกาใหม่ ไม่ใช่ลบสิ่งที่เกิดไปแล้ว
         */
        Route::get('/policies', [PolicyController::class, 'index'])->middleware('ability:saraban.document.view');
        Route::get('/policies/{key}/history', [PolicyController::class, 'history'])->middleware('ability:saraban.document.view');
        Route::post('/policies/{key}/draft', [PolicyController::class, 'draft'])->middleware('ability:saraban.policy.manage');
        Route::post('/policies/{key}/publish', [PolicyController::class, 'publish'])
            ->middleware(['ability:saraban.policy.manage', 'step_up']);
        Route::post('/policies/{key}/rollback', [PolicyController::class, 'rollback'])
            ->middleware(['ability:saraban.policy.manage', 'step_up']);

        /*
         * ตารางอำนาจหน้าที่ (INT-16 · SoT §6)
         *
         * `authority` ไม่มีด่าน `ability:` เพราะทุกคนต้องตรวจสอบอำนาจ **ของตนเอง**
         * ได้โดยไม่ต้องขอสิทธิ์เพิ่ม · การดูของผู้อื่นถูกตรวจใน controller
         * ด้วย `saraban.authority.view`
         */
        Route::get('/authority', [AuthorityMatrixController::class, 'show']);
        Route::get('/authority/holders', [AuthorityMatrixController::class, 'holders'])
            ->middleware('ability:saraban.authority.view');

        /*
         * ศูนย์เฝ้าระวังการปฏิบัติการและคิวข้อผิดพลาด (INT-17 · SoT §19, §20, §21)
         *
         * **แยกสิทธิ์ดูออกจากสิทธิ์แก้** — ผู้บริหารที่ต้องเห็นสถานะไม่จำเป็นต้องมี
         * สิทธิ์ไปกดสั่งงานระบบ · `operations.view` สำหรับดู `operations.retry`
         * สำหรับสั่งทำซ้ำ มอบหมาย และปิดเรื่อง
         */
        Route::get('/operations/command-center', CommandCenterController::class)
            ->middleware('ability:saraban.operations.view');
        Route::get('/operations/exceptions', [ExceptionQueueController::class, 'index'])
            ->middleware('ability:saraban.exception.view|saraban.operations.view');

        Route::middleware('ability:saraban.exception.manage|saraban.operations.retry')->group(function () {
            Route::post('/operations/exceptions/{exception}/acknowledge', [ExceptionQueueController::class, 'acknowledge']);
            Route::post('/operations/exceptions/{exception}/assign', [ExceptionQueueController::class, 'assign']);
            Route::post('/operations/exceptions/{exception}/resolve', [ExceptionQueueController::class, 'resolve']);
            Route::post('/operations/exceptions/{exception}/retry', [ExceptionQueueController::class, 'retry']);
        });

        /*
         * ทะเบียนหลักฐานการปฏิบัติตามข้อกำหนด (INT-20 · SoT §23)
         *
         * **อ่านอย่างเดียว** — ไม่มี endpoint ที่ตั้งสถานะได้ · สถานะทุกช่อง
         * คำนวณสดจากการตรวจจริง ซึ่งเป็นการบังคับข้อ "ห้าม claim PASS
         * โดยไม่มี evidence จริง" ด้วยการออกแบบ ไม่ใช่ด้วยความไว้ใจ
         */
        Route::get('/operations/health', [HealthController::class, 'details'])
            ->middleware('ability:saraban.operations.view');
        Route::get('/operations/compliance', ComplianceEvidenceController::class)
            ->middleware('ability:saraban.operations.view|saraban.report.view');
        Route::get('/feature-flags', [FeatureFlagController::class, 'index'])
            ->middleware('ability:saraban.operations.view|saraban.feature_flags.manage');
        Route::put('/feature-flags/{key}', [FeatureFlagController::class, 'update'])
            ->middleware('ability:saraban.feature_flags.manage');

        /*
         * สารบบเรื่อง การปกปิดข้อมูล และมุมมองที่บันทึกไว้ (INT-18 · SoT §22)
         *
         * **ไม่มี endpoint ใดในกลุ่มนี้แก้ตัวหนังสือ** — ทั้งสามเรื่องเป็นชั้นบน
         * ที่เกิดรอบ ๆ หนังสือ ตัวตนและเลขที่หนังสือไม่เปลี่ยนแปลง
         */
        Route::get('/documents/{document}/compare', [DocumentController::class, 'compare'])
            ->middleware('ability:saraban.document.view');

        Route::get('/cases', [CaseController::class, 'index'])->middleware('ability:saraban.document.view');
        Route::get('/cases/{case}', [CaseController::class, 'show'])->middleware('ability:saraban.document.view');
        Route::post('/cases', [CaseController::class, 'store'])->middleware('ability:saraban.document.create');
        Route::post('/cases/{case}/documents', [CaseController::class, 'attach'])->middleware('ability:saraban.document.create');
        Route::delete('/cases/{case}/documents/{document}', [CaseController::class, 'detach'])->middleware('ability:saraban.document.create');
        Route::post('/cases/{case}/close', [CaseController::class, 'close'])->middleware('ability:saraban.document.create');

        /*
         * การปกปิดข้อมูลใช้สิทธิ์เดียวกับการลงนาม เพราะเป็นการรับรองว่าสำเนานี้
         * ปล่อยออกนอกระบบได้ ซึ่งเป็นอำนาจระดับเดียวกัน · ผลลัพธ์เรียกคืนไม่ได้
         */
        Route::get('/documents/{document}/redactions', [RedactionController::class, 'index'])
            ->middleware('ability:saraban.document.view');
        Route::post('/documents/{document}/redactions', [RedactionController::class, 'store'])
            ->middleware(['ability:saraban.signature.sign', 'step_up']);

        // มุมมองที่บันทึกไว้เป็นของผู้เรียกเอง จึงไม่มีด่านความสามารถเพิ่ม
        Route::get('/saved-views', [SavedViewController::class, 'index']);
        Route::post('/saved-views', [SavedViewController::class, 'store']);
        Route::put('/saved-views/{view}', [SavedViewController::class, 'update']);
        Route::delete('/saved-views/{view}', [SavedViewController::class, 'destroy']);
        /*
         * การเก็บรักษา การอายัด และการทำลายเอกสาร (INT-19 · SoT §25)
         *
         * **การทำลายเอกสารราชการย้อนกลับไม่ได้** — จึงบังคับ `step_up` และ
         * แยก endpoint การอนุมัติออกจากการลงมือทำ เพื่อให้แยกหน้าที่กันได้จริง
         * ตาม SoT §26 (separation of duties)
         */
        Route::get('/documents/{document}/retention', [RetentionController::class, 'show'])
            ->middleware('ability:saraban.document.view');
        Route::post('/documents/{document}/holds', [RetentionController::class, 'placeHold'])
            ->middleware('ability:saraban.policy.manage|saraban.config.manage');
        Route::post('/holds/{hold}/release', [RetentionController::class, 'releaseHold'])
            ->middleware(['ability:saraban.policy.manage|saraban.config.manage', 'step_up']);
        Route::post('/documents/{document}/retention', [RetentionController::class, 'schedule'])
            ->middleware('ability:saraban.config.manage');
        Route::get('/retention/due', [RetentionController::class, 'dueForDisposal'])
            ->middleware('ability:saraban.config.manage');
        Route::post('/retention/{schedule}/approve', [RetentionController::class, 'approveDisposal'])
            ->middleware(['ability:saraban.policy.manage', 'step_up']);
        Route::post('/retention/{schedule}/dispose', [RetentionController::class, 'dispose'])
            ->middleware(['ability:saraban.config.manage', 'step_up']);

        // รายงานทะเบียน (INT-19 · SoT §28)
        Route::middleware('ability:saraban.report.view')->group(function () {
            Route::get('/reports/register', [RegisterReportController::class, 'register']);
            Route::get('/reports/numbers', [RegisterReportController::class, 'numbers']);
            Route::get('/reports/distribution', [RegisterReportController::class, 'distribution']);
        });
        /*
         * การจองและคืนเลขหนังสือ (SoT §9 · NAV §4.3)
         *
         * **แยกความรุนแรงของแต่ละการกระทำ** — จองและคืนเลขเป็นงานประจำวันของ
         * เจ้าหน้าที่ธุรการ ส่วน **การยกเลิกเลขทำให้เลขนั้นไม่ถูกใช้อีกเลย**
         * และทะเบียนจะมีช่องว่างถาวรที่ผู้ตรวจสอบต้องอธิบายได้ จึงบังคับ `step_up`
         * ตามที่ NAV §3.2 จัดไว้เป็น sensitive action
         *
         * ดูรายการเลขที่จองไว้ใช้สิทธิ์เดียวกับการจอง เพราะเป็นคิวงานของคนกลุ่มเดียวกัน
         */
        Route::get('/numbers/reserved', [NumberReservationController::class, 'index'])
            ->middleware('ability:saraban.number.reserve');
        Route::post('/numbers/reserve', [NumberReservationController::class, 'store'])
            ->middleware('ability:saraban.number.reserve');
        Route::post('/numbers/{number}/release', [NumberReservationController::class, 'release'])
            ->middleware('ability:saraban.number.release');
        Route::post('/numbers/{number}/cancel', [NumberReservationController::class, 'cancel'])
            ->middleware(['ability:saraban.number.cancel', 'step_up']);

        // ตั้งค่าชุดเลขหนังสือ (INT-16 · ปิด RISK-21)
        Route::middleware('ability:saraban.config.manage')->group(function () {
            Route::get('/number-series', [NumberSeriesController::class, 'index']);
            Route::post('/number-series', [NumberSeriesController::class, 'store']);
            Route::put('/number-series/{series}', [NumberSeriesController::class, 'update']);
            Route::delete('/number-series/{series}', [NumberSeriesController::class, 'destroy']);
        });
    };

    /*
     * INT-15 — พื้นผิว API สองทางที่ชี้ไปยัง controller ชุดเดียวกัน (SoT §17)
     *
     *   `api/saraban/...`     เส้นทางเดิมที่หน้าเว็บของระบบใช้อยู่
     *   `api/v1/saraban/...`  เส้นทางที่มีเลขรุ่นสำหรับผู้เรียกภายนอก
     *
     * **นิยามชุดเดียว ผูกสองที่** ไม่คัดลอกรายการ route มาวางซ้ำ — ถ้าคัดลอก
     * วันหนึ่งสองชุดจะไม่ตรงกันโดยไม่มีใครรู้ว่าชุดไหนคือของจริง
     *
     * เส้นทาง v1 บังคับผ่าน `idempotent` เพิ่ม เพราะผู้เรียกภายนอกคือฝ่ายที่
     * ส่งซ้ำหลัง timeout บ่อยที่สุด (SEC-GAP-04)
     */
    Route::prefix('saraban')->group($sarabanRoutes);
    Route::prefix('v1/saraban')->middleware('idempotent')->group($sarabanRoutes);

    /*
     * คำอธิบายพื้นผิว API แบบอ่านด้วยเครื่องได้ (SoT §17 · MCP-ready)
     *
     * สร้างจากตาราง route จริง ผู้เรียกภายนอกจึงรู้ว่ามี endpoint อะไร
     * ต้องมีสิทธิ์อะไร และต้องยืนยันตัวตนซ้ำหรือไม่ **โดยไม่ต้องแตะฐานข้อมูลตรง**
     */
    Route::get('/v1/openapi.json', ApiDescriptorController::class);

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
