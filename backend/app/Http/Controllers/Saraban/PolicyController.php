<?php

namespace App\Http\Controllers\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\Policy;
use App\Services\Saraban\PolicyCenter;
use App\Services\Saraban\PolicyException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ศูนย์นโยบายงานสารบรรณ (SoT §18)
 *
 * **การเผยแพร่นโยบายเป็นการกระทำที่อ่อนไหวตาม SoT §24** — ด่าน `step_up`
 * อยู่ที่ route เพราะนโยบายที่เปลี่ยนไปมีผลกับหนังสือทุกฉบับที่ออกหลังจากนั้น
 * และย้อนกลับได้เฉพาะในความหมายของ "ประกาศกติกาใหม่" ไม่ใช่ลบสิ่งที่เกิดไปแล้ว
 *
 * การ **อ่าน** นโยบายเปิดให้ผู้ที่ดูหนังสือได้ เพราะผู้ปฏิบัติงานต้องรู้กติกา
 * ที่ตนถูกตัดสิน · การ **แก้** จำกัดที่ `saraban.policy.manage`
 */
class PolicyController extends Controller
{
    public function __construct(private readonly PolicyCenter $policies) {}

    public function index()
    {
        return response()->json(['policies' => $this->policies->overview()]);
    }

    public function history(string $key)
    {
        $this->assertKnown($key);

        return response()->json(['versions' => $this->policies->history($key)]);
    }

    /** บันทึกฉบับร่าง — ยังไม่มีผลบังคับจนกว่าจะเผยแพร่ */
    public function draft(Request $request, string $key)
    {
        $this->assertKnown($key);

        $data = $request->validate([
            'payload' => ['required', 'array'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $draft = $this->policies->draft($key, $data['payload'], $request->user(), $data['notes'] ?? null);
        } catch (PolicyException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'version' => $draft->only(['version_no', 'status', 'payload', 'notes']),
        ], 201);
    }

    public function publish(Request $request, string $key)
    {
        $this->assertKnown($key);

        try {
            $version = $this->policies->publish($key, $request->user());
        } catch (PolicyException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'version' => [
                ...$version->only(['version_no', 'status', 'payload']),
                'published_at' => $version->published_at?->toIso8601String(),
            ],
        ]);
    }

    public function rollback(Request $request, string $key)
    {
        $this->assertKnown($key);

        $data = $request->validate(['version_no' => ['required', 'integer', 'min:1']]);

        try {
            $version = $this->policies->rollbackTo($key, $data['version_no'], $request->user());
        } catch (PolicyException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'version' => $version->only(['version_no', 'status', 'payload']),
        ]);
    }

    /**
     * ชื่อนโยบายมาจากรายการปิดในโค้ด ไม่ใช่ค่าที่ผู้เรียกกำหนดเอง
     *
     * ถ้ารับชื่ออะไรก็ได้ ระบบจะสร้างนโยบายที่ไม่มีใครอ่านขึ้นมาเรื่อย ๆ
     * จากการพิมพ์ผิด และหน้าจอจะเต็มไปด้วยรายการที่ไม่มีความหมาย
     */
    private function assertKnown(string $key): void
    {
        abort_unless(
            in_array($key, array_keys(PolicyCenter::defaults()), true),
            404,
            'ไม่รู้จักนโยบายนี้',
        );
    }

    /** ใช้ตอน validate ค่าที่ส่งมาในเส้นทางอื่น */
    public static function keyRule(): Rule|string
    {
        return Rule::in([
            Policy::KEY_DOCUMENT_LAYOUT,
            Policy::KEY_DISTRIBUTION,
            Policy::KEY_NUMBERING,
        ]);
    }
}
