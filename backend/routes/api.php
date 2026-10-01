<?php

use App\Http\Controllers\Admin\DocumentEngineController;
use App\Http\Controllers\Admin\DocumentReportController;
use App\Http\Controllers\Admin\LeaveTypeController;
use App\Http\Controllers\Admin\ModuleController;
use App\Http\Controllers\Admin\OrgUnitController;
use App\Http\Controllers\Admin\OrgUnitManagerController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\PreflightCheckController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WorkflowController;
use App\Http\Controllers\Api\ApiDescriptorController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\VocationalReportController;
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
     * เป็นข้อมูลภายในที่อยู่หลังด่านสิทธิ์ที่ `/api/admin/operations/health`
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
    /*
     * ความลับ TOTP ออกให้เฉพาะผู้ที่กรอกรหัสผ่านซ้ำได้ — จึงเป็น POST และ
     * จำกัดอัตราด้วย throttle ชุดเดียวกับการเดารหัสผ่าน
     */
    Route::post('/auth/totp-setup', [AuthController::class, 'totpSetup'])->middleware('throttle:password-change');
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
    /*
     * รายชื่อหน่วยงานสำหรับช่องตัวกรอง — ผู้ใช้ที่ล็อกอินแล้วเรียกได้ทุกคน
     *
     * เดิมชื่อ `/departments` และชี้ไปยังโครงหน่วยงานชั้นเดียวที่ถูกถอดออกแล้ว
     * ทุกหน้าจอย้ายมาเรียกเส้นทางนี้พร้อมกันในรอบเดียว จึงไม่คงชื่อพ้องไว้ให้เป็น
     * พื้นผิว API ที่ไม่มีใครเรียก (ดู `ApiSurfaceContractTest`)
     */
    Route::get('/org-units', [OrgUnitController::class, 'options']);

    Route::group(['middleware' => ['can:system.user.manage']], function () {
        Route::post('/admin/users', [UserController::class, 'store']);
        Route::post('/admin/users/import', [UserController::class, 'importCsv']);
        Route::put('/admin/users/{user}', [UserController::class, 'update']);
        Route::post('/admin/users/{user}/toggle-status', [UserController::class, 'toggleStatus']);
        /*
         * ดำเนินการหลายคนพร้อมกัน — ย้ายสังกัด เปิด/ระงับการใช้งาน
         * ใช้ด่านเดียวกับการทำทีละคน ไม่ใช่ทางลัดที่ข้ามขอบเขตหรือกติกาผู้ดูแลสูงสุด
         */
        Route::post('/admin/users/bulk', [UserController::class, 'bulk']);
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

    /*
     * ลบบัญชีเป็นแบบ soft และถูกปฏิเสธเมื่อยังมีเอกสารอ้างถึง — ประวัติการลาเป็น
     * เอกสารที่ต้องเก็บตามระเบียบ · `restore` ใช้สิทธิ์เดียวกันเพราะเป็นการย้อนคำสั่งเดิม
     */
    Route::delete('/admin/users/{user}', [UserController::class, 'destroy'])->middleware('can:delete_users');
    Route::post('/admin/users/{id}/restore', [UserController::class, 'restore'])->middleware('can:delete_users');

    // Admin: หน่วยงานในผังองค์กร
    Route::group(['middleware' => ['can:manage_departments']], function () {
        /*
         * `org_units` เคยไม่มีหน้าจอจัดการเลย หน่วยงานจึงเกิดได้ทางเดียวคือเป็น
         * ผลพลอยได้ตอนสร้าง "แผนกวิชา" ซึ่งแขวนใต้ยอดผังเสมอ — ผังลึกเกินสองชั้นไม่ได้
         */
        Route::get('/admin/org-units', [OrgUnitController::class, 'index']);
        Route::post('/admin/org-units', [OrgUnitController::class, 'store']);
        Route::put('/admin/org-units/{orgUnit}', [OrgUnitController::class, 'update']);
        Route::delete('/admin/org-units/{orgUnit}', [OrgUnitController::class, 'destroy']);
        Route::put('/admin/org-units/{orgUnit}/toggle-status', [OrgUnitController::class, 'toggleStatus']);

        /*
         * หัวหน้าหน่วยงาน — ข้อมูลที่ตัวหาผู้อนุมัติใช้จริง
         *
         * `org_unit_managers` มีมาตั้งแต่ LEGACY-04 แต่ไม่เคยมีทางกรอกจากหน้าจอ
         * ตารางจึงว่างเปล่าทุกการติดตั้ง และขั้น "หัวหน้าหน่วยงานของผู้ยื่น"
         * ตกไปใช้ทางถอยตามชื่อบทบาทเสมอ
         */
        Route::get('/admin/org-unit-managers', [OrgUnitManagerController::class, 'index']);
        Route::get('/admin/org-unit-managers/units', [OrgUnitManagerController::class, 'units']);
        Route::post('/admin/org-unit-managers', [OrgUnitManagerController::class, 'store']);
        Route::put('/admin/org-unit-managers/{id}/end', [OrgUnitManagerController::class, 'end']);
    });

    // Admin: Workflow Steps
    Route::group(['middleware' => ['can:manage_workflow_steps']], function () {
        Route::get('/admin/workflow-steps', [WorkflowController::class, 'index']);
        Route::post('/admin/workflow-steps', [WorkflowController::class, 'store']);
        // ทดลองว่าใครคือผู้อนุมัติจริงของแต่ละขั้น ก่อนบันทึกการตั้งค่า
        Route::post('/admin/workflow-steps/preview', [WorkflowController::class, 'preview']);
        /*
         * ตรวจความพร้อมของข้อมูลบุคลากรทั้งองค์กรเทียบกับสายอนุมัติ
         *
         * POST เพราะรับสายที่ยังไม่บันทึกมาใน body ได้ แต่เป็นการอ่านอย่างเดียว
         * ไม่แก้ข้อมูลใดและไม่สร้างใบลา
         */
        Route::post('/admin/workflow-steps/readiness', [WorkflowController::class, 'readiness']);
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
        Route::get('/admin/preflight-check', [PreflightCheckController::class, 'index']);
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
        Route::get('/admin/document-engine/leave-type-forms', [DocumentEngineController::class, 'leaveTypeForms']);
        Route::get('/admin/document-engine/calibrations', [DocumentEngineController::class, 'calibrations']);
        Route::get('/admin/document-engine/versions/{version}/mappings', [DocumentEngineController::class, 'mappings']);
        Route::get('/admin/document-engine/versions/{version}/geometry', [DocumentEngineController::class, 'versionGeometry']);
        Route::get('/admin/document-engine/versions/{version}/pdf', [DocumentEngineController::class, 'pdfFile'])->middleware('throttle:documents');
        Route::get('/admin/document-engine/versions/{version}/preview', [DocumentEngineController::class, 'previewVersion'])->middleware('throttle:documents');
        Route::get('/admin/document-engine/versions/{version}/preflight', [DocumentEngineController::class, 'preflight']);
        Route::get('/admin/document-engine/versions/{version}/diff', [DocumentEngineController::class, 'diffVersion']);
        Route::get('/admin/document-engine/versions/{version}/calibration-sheet', [DocumentEngineController::class, 'calibrationSheet'])->middleware('throttle:documents');
        Route::get('/admin/document-engine/versions/{version}/package', [DocumentEngineController::class, 'exportPackage'])->middleware('throttle:documents');
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
        Route::put('/admin/document-engine/fields/{field}', [DocumentEngineController::class, 'updateField']);
        Route::delete('/admin/document-engine/fields/{field}', [DocumentEngineController::class, 'deactivateField']);
        Route::post('/admin/document-engine/enabled-entities', [DocumentEngineController::class, 'updateEnabledEntities']);
        Route::post('/admin/document-engine/templates/import', [DocumentEngineController::class, 'importPackage']);
        Route::post('/admin/document-engine/calibrations/solve', [DocumentEngineController::class, 'solveCalibration']);
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

    /*
     * ศูนย์ปฏิบัติการระบบ (INT-17 · INT-20 · SoT §19–§21, §23)
     *
     * เดิมอยู่ใต้ `/api/saraban/*` และใช้สิทธิ์ `saraban.*` ทั้งที่ข้อมูลเป็นของทั้งระบบ
     * (คิวงาน การแจ้งเตือน webhook ธงความสามารถ) — ย้ายมาเป็นของผู้ดูแลระบบเมื่อถอดโมดูลสารบรรณ
     *
     * **แยกสิทธิ์ดูออกจากสิทธิ์แก้** — ผู้บริหารที่ต้องเห็นสถานะไม่จำเป็นต้องมีสิทธิ์ไปกดสั่งงานระบบ
     * ทะเบียนหลักฐาน (compliance) อ่านอย่างเดียว สถานะทุกช่องคำนวณสดจากการตรวจจริง
     */
    Route::prefix('admin')->group(function () {
        Route::get('/operations/command-center', CommandCenterController::class)
            ->middleware('ability:system.operations.view');
        Route::get('/operations/exceptions', [ExceptionQueueController::class, 'index'])
            ->middleware('ability:system.exception.view|system.operations.view');

        Route::middleware('ability:system.exception.manage|system.operations.retry')->group(function () {
            Route::post('/operations/exceptions/{exception}/acknowledge', [ExceptionQueueController::class, 'acknowledge']);
            Route::post('/operations/exceptions/{exception}/assign', [ExceptionQueueController::class, 'assign']);
            Route::post('/operations/exceptions/{exception}/resolve', [ExceptionQueueController::class, 'resolve']);
            Route::post('/operations/exceptions/{exception}/retry', [ExceptionQueueController::class, 'retry']);
        });

        Route::get('/operations/health', [HealthController::class, 'details'])
            ->middleware('ability:system.operations.view');
        Route::get('/operations/compliance', ComplianceEvidenceController::class)
            ->middleware('ability:system.operations.view');
        Route::get('/feature-flags', [FeatureFlagController::class, 'index'])
            ->middleware('ability:system.operations.view|system.feature_flags.manage');
        Route::put('/feature-flags/{key}', [FeatureFlagController::class, 'update'])
            ->middleware('ability:system.feature_flags.manage');
    });

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
     * VOCATIONAL-PHASE-02 — รายงานการประเมิน ก.ค.ศ. และระบบนำเข้าเครื่องสแกน Biometric
     */
    Route::get('/v1/reports/vocational/evaluation-summary', [VocationalReportController::class, 'evaluationSummary']);
    Route::get('/v1/reports/vocational/teacher-pa-evidence', [VocationalReportController::class, 'teacherPaEvidence']);
    Route::post('/v1/attendance/biometric/import-file', [VocationalReportController::class, 'importBiometricFile']);

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
