<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Services\TrustedClock;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * กันการทำซ้ำจากคำขอเดิม (SEC-GAP-04 · SoT §17)
 *
 * **ปัญหาจริงที่ปิด:** ผู้ใช้กดปุ่มสองครั้งเพราะเน็ตช้า หรือระบบภายนอกส่งซ้ำ
 * หลัง timeout — ทั้งสองกรณีทำให้เกิดเลขหนังสือสองเลขสำหรับเรื่องเดียว
 * ซึ่งเป็นความเสียหายที่แก้ย้อนหลังไม่ได้ในงานสารบรรณ
 *
 * **ไม่บังคับ** — คำขอที่ไม่มีหัวข้อ `Idempotency-Key` ผ่านไปตามปกติ เพื่อไม่ให้
 * ผู้เรียกเดิมทั้งหมดพังในวันที่เปิดใช้ · ผู้เรียกที่ต้องการหลักประกันส่งกุญแจมา
 *
 * **กติกา**
 *   - กุญแจเดิม + เนื้อคำขอเดิม + สำเร็จแล้ว → คืนผลเดิม ไม่ทำงานซ้ำ
 *   - กุญแจเดิม + เนื้อคำขอต่าง → 422 (ผู้เรียกใช้กุญแจผิด ไม่ใช่การส่งซ้ำ)
 *   - กุญแจเดิม + ยังทำงานค้างอยู่ → 409 (คำขอซ้อนที่ยิงมาพร้อมกัน)
 *
 * **บันทึกเฉพาะผลที่สำเร็จ (2xx)** — คำขอที่ล้มเหลวต้องลองใหม่ได้ด้วยกุญแจเดิม
 * ถ้าจำผลล้มเหลวไว้ ผู้เรียกจะติดอยู่กับความล้มเหลวนั้นตลอดอายุกุญแจ
 */
class EnsureIdempotentRequest
{
    /** อายุกุญแจ — ยาวพอให้ผู้เรียกลองใหม่ สั้นพอไม่ให้ตารางโตไม่มีที่สิ้นสุด */
    private const TTL_HOURS = 24;

    public function __construct(private readonly TrustedClock $clock) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        $user = $request->user();

        if ($key === '' || $user === null || $request->isMethod('GET')) {
            return $next($request);
        }

        if (! preg_match('/^[A-Za-z0-9._:-]{8,190}$/', $key)) {
            return response()->json([
                'message' => 'Idempotency-Key ต้องยาว 8-190 ตัวอักษร และใช้ได้เฉพาะ A-Z a-z 0-9 . _ : -',
            ], 422);
        }

        $body = $request->getContent();
        if (str_starts_with(strtolower((string) $request->header('Content-Type')), 'multipart/form-data')) {
            // PHP แยก multipart ไปแล้ว raw body จึงว่างได้ และ boundary เปลี่ยนทุกครั้งที่ส่งซ้ำ
            $body = 'multipart-v1:'.json_encode($this->fingerprint([
                'fields' => $request->request->all(),
                'query' => $request->query->all(),
                'files' => $request->allFiles(),
            ]), JSON_THROW_ON_ERROR);
        }
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.$body);
        $now = $this->clock->now();

        /*
         * สร้างแถวจองไว้ก่อนทำงาน ภายใน transaction สั้น ๆ
         *
         * แถว unique เป็นตัวตัดสินผู้ชนะเมื่อสองคำขอมาพร้อมกัน — ผู้แพ้ได้
         * `UniqueConstraintViolationException` แล้วไปอ่านแถวที่มีอยู่ ซึ่งเชื่อถือได้
         * กว่าการเช็คก่อนแล้วค่อยเขียน (check-then-act) ที่มีช่องว่างระหว่างสองคำสั่ง
         */
        $claim = null;

        try {
            $claim = DB::transaction(fn () => IdempotencyKey::create([
                'idempotency_key' => $key,
                'user_id' => $user->id,
                'method' => $request->method(),
                'path' => mb_substr($request->path(), 0, 255),
                'request_hash' => $hash,
                'expires_at' => $now->copy()->addHours(self::TTL_HOURS),
            ]));
        } catch (UniqueConstraintViolationException) {
            // มีคนจองกุญแจนี้ไปก่อนแล้ว — คำขอนี้คือการส่งซ้ำหรือกุญแจถูกใช้ผิด
        }

        if ($claim === null) {
            return $this->replay($key, $user->id, $hash);
        }

        $response = $next($request);
        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            $claim->forceFill([
                'response_status' => $status,
                'response_body' => $response->getContent(),
                'completed_at' => $this->clock->now(),
            ])->save();

            return $response;
        }

        // ล้มเหลวแล้วต้องลองใหม่ด้วยกุญแจเดิมได้ จึงปล่อยกุญแจคืน
        $claim->delete();

        return $response;
    }

    /** ลำดับชื่อฟิลด์และชื่อไฟล์ชั่วคราวไม่มีผล แต่เนื้อหาไฟล์ต้องตรงกัน */
    private function fingerprint(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return [
                'name' => $value->getClientOriginalName(),
                'mime' => $value->getClientMimeType(),
                'error' => $value->getError(),
                'sha256' => $value->isValid() ? hash_file('sha256', $value->getPathname()) : null,
            ];
        }
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($this->fingerprint(...), $value);
        }

        return $value;
    }

    /** ตอบคำขอที่ใช้กุญแจซึ่งถูกจองไปแล้ว */
    private function replay(string $key, int $userId, string $hash): Response
    {
        $existing = IdempotencyKey::query()
            ->where('idempotency_key', $key)
            ->where('user_id', $userId)
            ->first();

        if ($existing === null) {
            // แถวถูกปล่อยคืนระหว่างทาง (คำขอเดิมล้มเหลว) — ให้ผู้เรียกลองใหม่
            return response()->json([
                'message' => 'คำขอก่อนหน้าที่ใช้กุญแจนี้ไม่สำเร็จ กรุณาส่งใหม่อีกครั้ง',
            ], 409);
        }

        if ($existing->request_hash !== $hash) {
            return response()->json([
                'message' => 'Idempotency-Key นี้ถูกใช้กับคำขอที่มีเนื้อหาต่างออกไปแล้ว',
            ], 422);
        }

        if ($existing->completed_at === null) {
            return response()->json([
                'message' => 'คำขอที่ใช้กุญแจนี้กำลังดำเนินการอยู่ กรุณารอสักครู่แล้วลองใหม่',
            ], 409);
        }

        return response(
            (string) $existing->response_body,
            (int) ($existing->response_status ?? 200),
        )->header('Content-Type', 'application/json')
            ->header('Idempotent-Replay', 'true');
    }
}
