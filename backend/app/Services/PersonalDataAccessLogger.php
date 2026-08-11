<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * บันทึกว่าใครเปิดดูข้อมูลส่วนบุคคลของใคร
 * ไม่บันทึกกรณีดูข้อมูลของตนเอง เพราะไม่ใช่การเข้าถึงข้อมูลของผู้อื่น
 */
class PersonalDataAccessLogger
{
    public function record(Request $request, int $subjectId, string $resource, ?string $purpose = null): void
    {
        $actor = $request->user();
        if (!$actor || $actor->id === $subjectId) return;

        try {
            DB::table('personal_data_access_logs')->insert([
                'actor_id' => $actor->id,
                'subject_id' => $subjectId,
                'resource' => $resource,
                'purpose' => $purpose,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // การบันทึกล้มเหลวต้องไม่ทำให้ผู้ใช้ทำงานไม่ได้ แต่ต้องเห็นใน log ของระบบ
            Log::warning('บันทึกการเข้าถึงข้อมูลส่วนบุคคลไม่สำเร็จ', [
                'subject_id' => $subjectId, 'resource' => $resource, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * บันทึกการเข้าถึงข้อมูลของบุคลากรหลายคนพร้อมกัน (รายงานรวม / การส่งออกไฟล์)
     * ไม่มีเจ้าของข้อมูลรายเดียว จึงเก็บเป็นจำนวนคนที่อยู่ในผลลัพธ์แทน
     */
    public function recordBulk(Request $request, string $resource, int $subjectCount, ?string $purpose = null): void
    {
        $actor = $request->user();
        if (!$actor) return;

        try {
            DB::table('personal_data_access_logs')->insert([
                'actor_id' => $actor->id,
                'subject_id' => null,
                'subject_count' => $subjectCount,
                'resource' => $resource,
                'purpose' => $purpose,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('บันทึกการเข้าถึงข้อมูลส่วนบุคคลแบบรวมไม่สำเร็จ', [
                'resource' => $resource, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** query ประวัติการเข้าถึงข้อมูลของผู้ใช้คนหนึ่ง เรียงจากใหม่ไปเก่า */
    public function historyQuery(User $subject)
    {
        return DB::table('personal_data_access_logs as l')
            ->join('users as a', 'a.id', '=', 'l.actor_id')
            ->leftJoin('user_profiles as p', 'p.user_id', '=', 'a.id')
            ->where('l.subject_id', $subject->id)
            ->orderByDesc('l.created_at')
            ->select(['l.id', 'a.name as accessed_by', 'p.position as accessed_by_position', 'l.resource', 'l.purpose', 'l.created_at']);
    }

    /** ประวัติการเข้าถึงข้อมูลของผู้ใช้คนหนึ่ง สำหรับตอบคำขอตาม ม.30 */
    public function historyFor(User $subject, int $limit = 200): array
    {
        return $this->historyQuery($subject)
            ->limit($limit)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }
}
