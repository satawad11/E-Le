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
    Route::get('/run-migration', function () {
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        return response()->json(['message' => 'Migration completed!', 'output' => \Illuminate\Support\Facades\Artisan::output()]);
    });

    // Public Saraban verify routes
    Route::get('/saraban/verify/{hash}', [\App\Http\Controllers\Api\Saraban\SignatureController::class, 'verify']);
    Route::get('/saraban/verify/{hash}/qrcode', [\App\Http\Controllers\Api\Saraban\SignatureController::class, 'getQrCode']);
});
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login');

// Protected routes
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::post('/logout',     [AuthController::class, 'logout']);
    Route::get('/user',        [AuthController::class, 'user']);
    Route::get('/users/list',  [\App\Http\Controllers\Admin\UserController::class, 'list']);
    Route::get('/profile',                 [ProfileController::class, 'show']);
    Route::post('/profile',                [ProfileController::class, 'update']);
    Route::get('/profile/delegations',     [ProfileController::class, 'getDelegation']);
    Route::post('/profile/delegations',    [ProfileController::class, 'setDelegation']);

    // Global settings for frontend
    Route::get('/settings',                [SettingController::class, 'index']);

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

    // Attendance routes
    Route::post('/attendances/check-in',   [AttendanceController::class, 'checkIn']);
    Route::post('/attendances/check-out',  [AttendanceController::class, 'checkOut']);
    Route::post('/attendances/ot-check-in', [AttendanceController::class, 'otCheckIn']);
    Route::post('/attendances/ot-check-out',[AttendanceController::class, 'otCheckOut']);
    Route::get('/attendances/me',          [AttendanceController::class, 'myRecords']);
    Route::get('/attendances/today',       [AttendanceController::class, 'today']);
    Route::group(['middleware' => ['can:view_all_attendances']], function () {
        Route::get('/admin/attendances',       [AttendanceController::class, 'allRecords']);
        Route::get('/admin/attendances/export/pdf',  [AttendanceController::class, 'exportPdf']);
        Route::get('/admin/attendances/export/word', [AttendanceController::class, 'exportWord']);
        Route::get('/admin/attendances/summary', [AttendanceController::class, 'summary']);
        Route::get('/admin/attendances/ot-summary', [AttendanceController::class, 'otSummary']);
        Route::post('/admin/attendances/sync-leaves', [AttendanceController::class, 'syncLeaves']);
    });
    
    Route::post('/admin/leaves', [LeaveController::class, 'storeAsAdmin']);

    // Approval routes
    Route::get('/approvals/pending',          [ApprovalController::class, 'pending']);
    Route::post('/approvals/{leave}/approve', [ApprovalController::class, 'approve']);
    Route::post('/approvals/{leave}/reject',  [ApprovalController::class, 'reject']);

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

    Route::group(['middleware' => ['can:edit_users']], function () {
        Route::post('/admin/users',            [UserController::class, 'store']);
        Route::post('/admin/users/import',     [UserController::class, 'importCsv']);
        Route::put('/admin/users/{user}',      [UserController::class, 'update']);
        Route::post('/admin/users/{user}/toggle-status', [UserController::class, 'toggleStatus']);
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
    Route::middleware('permission:manage_settings')->group(function () {
        Route::get('/admin/settings', [\App\Http\Controllers\SettingController::class, 'index']);
        Route::post('/admin/settings', [\App\Http\Controllers\SettingController::class, 'update']);
        Route::post('/admin/settings/upload-logo', [\App\Http\Controllers\SettingController::class, 'uploadLogo']);
        Route::post('/admin/settings/upload-garuda', [\App\Http\Controllers\SettingController::class, 'uploadGarudaLogo']);

        Route::get('/admin/fiscal-years',                     [\App\Http\Controllers\FiscalYearController::class, 'index']);
        Route::post('/admin/fiscal-years',                    [\App\Http\Controllers\FiscalYearController::class, 'store']);
        Route::put('/admin/fiscal-years/{fiscalYear}',        [\App\Http\Controllers\FiscalYearController::class, 'update']);
        Route::delete('/admin/fiscal-years/{fiscalYear}',     [\App\Http\Controllers\FiscalYearController::class, 'destroy']);
    });
    
    // Saraban Document routes.
    // Route-level `permission:` middleware is the coarse gate; DocumentPolicy
    // (checked inside the controllers) enforces per-document rules on top of it.
    Route::prefix('saraban')->group(function () {
        // Document Types — read for anyone who can see the register, write for registrars.
        Route::middleware('permission:saraban.document.view')->group(function () {
            Route::get('/types', [\App\Http\Controllers\Api\Saraban\DocumentTypeController::class, 'index']);
        });
        Route::middleware('permission:saraban.document.manage_types')->group(function () {
            Route::post('/types', [\App\Http\Controllers\Api\Saraban\DocumentTypeController::class, 'store']);
            Route::put('/types/{type}', [\App\Http\Controllers\Api\Saraban\DocumentTypeController::class, 'update']);
            Route::delete('/types/{type}', [\App\Http\Controllers\Api\Saraban\DocumentTypeController::class, 'destroy']);

            Route::post('/workflow-templates', [\App\Http\Controllers\Api\Saraban\DocumentWorkflowTemplateController::class, 'store']);
            Route::put('/workflow-templates/{id}', [\App\Http\Controllers\Api\Saraban\DocumentWorkflowTemplateController::class, 'update']);
            Route::delete('/workflow-templates/{id}', [\App\Http\Controllers\Api\Saraban\DocumentWorkflowTemplateController::class, 'destroy']);
        });

        Route::middleware('permission:saraban.document.view')->group(function () {
            Route::get('/workflow-templates', [\App\Http\Controllers\Api\Saraban\DocumentWorkflowTemplateController::class, 'index']);

            // Pickers for the document form / circular recipients. Scoped read-only
            // lists so saraban users do not need the admin `view_users` permission.
            Route::get('/users-list', [\App\Http\Controllers\Api\Saraban\DocumentController::class, 'usersList']);
            Route::get('/departments-list', [\App\Http\Controllers\Api\Saraban\DocumentController::class, 'departmentsList']);

            // Documents — read
            Route::get('/documents',       [\App\Http\Controllers\Api\Saraban\DocumentController::class, 'index']);
            Route::get('/documents/stats', [\App\Http\Controllers\Api\Saraban\DocumentController::class, 'stats']);
            Route::get('/documents/{document}', [\App\Http\Controllers\Api\Saraban\DocumentController::class, 'show'])
                ->middleware('saraban.classified');
            Route::post('/documents/{document}/read', [\App\Http\Controllers\Api\Saraban\DocumentController::class, 'read']);
            Route::post('/documents/{id}/state', [\App\Http\Controllers\Api\Saraban\DocumentController::class, 'updateState']);
            Route::get('/attachments/{attachment}/download', [\App\Http\Controllers\Api\Saraban\DocumentController::class, 'downloadAttachment']);
        });

        Route::post('/documents', [\App\Http\Controllers\Api\Saraban\DocumentController::class, 'store'])
            ->middleware('permission:saraban.document.create');

        Route::put('/documents/{document}', [\App\Http\Controllers\Api\Saraban\DocumentController::class, 'update'])
            ->middleware(['permission:saraban.document.edit', 'saraban.classified']);

        Route::delete('/documents/bulk', [\App\Http\Controllers\Api\Saraban\DocumentController::class, 'destroyMultiple'])
            ->middleware('permission:saraban.document.delete');
        Route::delete('/documents/{document}', [\App\Http\Controllers\Api\Saraban\DocumentController::class, 'destroy'])
            ->middleware(['permission:saraban.document.delete', 'saraban.classified']);

        // Saraban Workflow
        Route::middleware('permission:saraban.document.view')->group(function () {
            Route::post('/documents/{id}/workflow/submit', [\App\Http\Controllers\Api\Saraban\WorkflowController::class, 'submit']);
        });
        Route::middleware(['permission:saraban.document.approve', 'saraban.classified'])->group(function () {
            Route::post('/documents/{id}/workflow/approve', [\App\Http\Controllers\Api\Saraban\WorkflowController::class, 'approve']);
            Route::post('/documents/{id}/workflow/reject', [\App\Http\Controllers\Api\Saraban\WorkflowController::class, 'reject']);
        });

        // Saraban Signatures
        Route::post('/documents/{id}/sign', [\App\Http\Controllers\Api\Saraban\SignatureController::class, 'sign'])
            ->middleware(['permission:saraban.document.sign', 'saraban.classified']);

        // Saraban Circulars
        Route::middleware('permission:saraban.document.view')->group(function () {
            Route::get('/circulars/incoming', [\App\Http\Controllers\Api\Saraban\CircularController::class, 'incoming']);
            Route::get('/circulars/outgoing', [\App\Http\Controllers\Api\Saraban\CircularController::class, 'outgoing']);
            Route::post('/documents/{id}/circular', [\App\Http\Controllers\Api\Saraban\CircularController::class, 'send']);
            Route::post('/circulars/{id}/acknowledge', [\App\Http\Controllers\Api\Saraban\CircularController::class, 'acknowledge']);
            Route::get('/documents/{id}/circulars/tracking', [\App\Http\Controllers\Api\Saraban\CircularController::class, 'tracking']);
        });

        // Saraban Reports
        Route::middleware('permission:saraban.document.report')->group(function () {
            Route::get('/reports/summary', [\App\Http\Controllers\Api\Saraban\ReportController::class, 'summary']);
            Route::get('/reports/export', [\App\Http\Controllers\Api\Saraban\ReportController::class, 'exportCsv']);
        });
    });

    Route::get('/fiscal-year/current',                    [\App\Http\Controllers\FiscalYearController::class, 'current']);

    // Notifications
    Route::get('/notifications',                          [\App\Http\Controllers\NotificationController::class, 'index']);
    Route::post('/notifications/mark-all-read',           [\App\Http\Controllers\NotificationController::class, 'markAllAsRead']);
    Route::put('/notifications/{id}/read',                [\App\Http\Controllers\NotificationController::class, 'markAsRead']);


});

// We put preview and exports outside sanctum group so window.open works with ?token= query param
Route::get('/admin/attendances/summary/preview', [\App\Http\Controllers\AttendanceController::class, 'previewSummary']);
Route::get('/admin/attendances/summary/export/pdf', [\App\Http\Controllers\AttendanceController::class, 'exportSummaryPdf']);
Route::get('/admin/attendances/summary/export/word', [\App\Http\Controllers\AttendanceController::class, 'exportSummaryWord']);

Route::get('/admin/attendances/ot-summary/preview', [\App\Http\Controllers\AttendanceController::class, 'previewOTSummary']);
Route::get('/admin/attendances/ot-summary/export/pdf', [\App\Http\Controllers\AttendanceController::class, 'exportOTSummaryPdf']);
Route::get('/admin/attendances/ot-summary/export/word', [\App\Http\Controllers\AttendanceController::class, 'exportOTSummaryWord']);
Route::get('/admin/attendances/ot-summary/export/excel', [\App\Http\Controllers\AttendanceController::class, 'exportOTSummaryExcel']);

// Public file access for attachments
Route::middleware('throttle:300,1')->get('/storage/{path}', function ($path) {
    // Prevent directory traversal attacks
    $path = str_replace(['..', '\\', '%00'], '', $path);
    $fullPath = storage_path('app/public/' . ltrim($path, '/'));
    
    // Verify realpath to ensure it's still inside the public directory
    $realPublicPath = realpath(storage_path('app/public'));
    $realFullPath = realpath($fullPath);
    
    if ($realFullPath && file_exists($realFullPath) && strpos($realFullPath, $realPublicPath) === 0) {
        return response()->file($realFullPath);
    }
    abort(404);
})->where('path', '.*');
