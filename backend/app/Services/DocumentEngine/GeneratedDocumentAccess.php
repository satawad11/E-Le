<?php

namespace App\Services\DocumentEngine;

use App\Models\Document\DocumentGeneratedFile;
use App\Models\Leave;
use App\Models\User;
use App\Policies\LeavePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * ใครเปิด/ดาวน์โหลดเอกสารที่ระบบออกให้ได้บ้าง (DC-01)
 *
 * เดิมรายการและการดาวน์โหลดตรวจเพียงสิทธิ์ของศูนย์จัดการเอกสาร (route ability)
 * ผู้มีสิทธิ์ดูแม่แบบจึงอ่าน snapshot ของใบลาทุกฉบับได้ ซึ่งมีชื่อ เหตุผลการลา
 * เบอร์โทร ที่อยู่ และข้อมูลผู้อนุมัติ — สิทธิ์ดูแลแม่แบบไม่ใช่สิทธิ์อ่านเอกสารจริง
 *
 * คลาสนี้ผูกเอกสารแต่ละฉบับกลับไปที่ต้นทางของมัน แล้วให้ policy ของต้นทางเป็นผู้ตัดสิน
 * ต้นทางที่ไม่รู้จักถูกปฏิเสธเสมอ (fail-closed) — โมดูลใหม่ที่จะเก็บเอกสารต้องลงทะเบียนที่นี่ก่อน
 */
class GeneratedDocumentAccess
{
    /** source_type ที่มาจากใบลา (ดู LeaveDocumentGateway::SOURCE_TYPE*) */
    public const LEAVE_SOURCES = [
        LeaveDocumentGateway::SOURCE_TYPE,
        LeaveDocumentGateway::SOURCE_TYPE_CANCELLATION,
    ];

    public function __construct(private readonly LeavePolicy $leavePolicy) {}

    /**
     * หรี่ query ของเอกสารให้เหลือเฉพาะฉบับที่ผู้ใช้เปิดต้นทางได้
     *
     * @param  Builder<DocumentGeneratedFile>  $files
     * @return Builder<DocumentGeneratedFile>
     */
    public function scopeVisible(Builder $files, User $user): Builder
    {
        $visibleLeaves = $this->leavePolicy->viewableQuery(Leave::query(), $user)->select('id');

        return $files
            ->whereIn('source_type', self::LEAVE_SOURCES)
            ->whereIn('source_id', $visibleLeaves);
    }

    public function canDownload(User $user, DocumentGeneratedFile $file): bool
    {
        if (in_array($file->source_type, self::LEAVE_SOURCES, true)) {
            $leave = Leave::withTrashed()->find($file->source_id);

            return $leave !== null && Gate::forUser($user)->allows('download', $leave);
        }

        return false;
    }
}
