<?php

use App\Http\Controllers\Admin\LeavePolicyController;
use App\Http\Controllers\Admin\PersonnelTypeController;
use App\Http\Controllers\Admin\WorkCalendarController;
use App\Http\Controllers\GovernmentApprovalController;
use App\Http\Controllers\GovernmentLeaveReportController;
use App\Http\Controllers\LeaveAdjustmentController;
use App\Http\Controllers\LeaveCardController;
use App\Http\Controllers\LeaveEvidenceController;
use App\Http\Controllers\LeaveGovernanceController;
use App\Http\Controllers\LeaveGovernanceWorklistController;
use App\Http\Controllers\PromotionScreeningController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/approvals/{leave}/approve', [GovernmentApprovalController::class, 'approve']);
    Route::post('/approvals/{leave}/reject', [GovernmentApprovalController::class, 'reject']);
    Route::get('/personnel-types', [LeavePolicyController::class, 'personnelTypes']);
    Route::get('/leave-services', [LeaveGovernanceWorklistController::class, 'myLeaves']);
    Route::get('/leaves/{leave}/evidences', [LeaveEvidenceController::class, 'index']);
    Route::post('/leaves/{leave}/evidences', [LeaveEvidenceController::class, 'store']);
    Route::get('/leave-evidences/{evidence}/download', [LeaveEvidenceController::class, 'download'])->middleware('throttle:10,1');
    Route::put('/leave-evidences/{evidence}/verify', [LeaveEvidenceController::class, 'verify']);
    Route::get('/leaves/{leave}/audit', [LeaveGovernanceController::class, 'audit']);
    Route::post('/leaves/{leave}/void', [LeaveGovernanceController::class, 'void']);
    Route::post('/leaves/{leave}/partial-cancellation', [LeaveAdjustmentController::class, 'store']);
    Route::put('/leave-adjustments/{adjustment}/decision', [LeaveAdjustmentController::class, 'decide']);
    Route::get('/reports/leave-card', [LeaveCardController::class, 'show']);
    Route::get('/reports/leave-card.pdf', [LeaveCardController::class, 'pdf'])->middleware('throttle:10,1');
    Route::get('/reports/promotion-screening', [PromotionScreeningController::class, 'index']);
    Route::get('/reports/promotion-screening.csv', [PromotionScreeningController::class, 'csv'])->middleware('throttle:10,1');
    Route::get('/reports/government-leave-register', [GovernmentLeaveReportController::class, 'register']);
    Route::get('/reports/government-leave-summary', [GovernmentLeaveReportController::class, 'summary']);
    Route::get('/reports/government-leave-register.csv', [GovernmentLeaveReportController::class, 'csv'])->middleware('throttle:10,1');
    Route::middleware('can:leave.approval.hr')->prefix('admin')->group(function () {
        Route::get('/leave-governance/worklist', [LeaveGovernanceWorklistController::class, 'worklist']);
        Route::get('/leave-governance/adjustments', [LeaveGovernanceWorklistController::class, 'adjustments']);
    });
    Route::middleware('can:leave.policy.manage')->prefix('admin')->group(function () {
        Route::get('/leave-policies', [LeavePolicyController::class, 'index']);
        Route::post('/leave-policies', [LeavePolicyController::class, 'store']);
        Route::put('/leave-policies/{policy}', [LeavePolicyController::class, 'update']);
    });
    Route::middleware('can:edit_users')->prefix('admin')->group(function () {
        Route::post('/personnel-types', [PersonnelTypeController::class, 'store']);
        Route::put('/personnel-types/{personnelType}', [PersonnelTypeController::class, 'update']);
        Route::put('/users/{user}/personnel-type', [PersonnelTypeController::class, 'assign']);
    });
    Route::middleware('can:leave.calendar.manage')->prefix('admin')->group(function () {
        Route::get('/work-calendar', [WorkCalendarController::class, 'index']);
        Route::post('/work-calendar', [WorkCalendarController::class, 'store']);
        Route::put('/work-calendar/{calendarException}', [WorkCalendarController::class, 'update']);
        Route::delete('/work-calendar/{calendarException}', [WorkCalendarController::class, 'destroy']);
    });
});
