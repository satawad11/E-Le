<?php

namespace App\Services\Saraban;

use App\Models\Saraban\Policy;
use App\Models\Saraban\PolicyVersion;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TrustedClock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ศูนย์นโยบายของงานสารบรรณ (SoT §18)
 *
 * บังคับสี่ข้อที่ SoT §18 กำหนด: **versioned / published / rollback / audit**
 *
 * หลักที่คลาสนี้บังคับ:
 *
 *   1. **มีฉบับร่างได้ครั้งละหนึ่งฉบับต่อนโยบาย** — ร่างซ้อนกันหลายฉบับทำให้
 *      ไม่มีใครรู้ว่าฉบับไหนคือสิ่งที่กำลังจะประกาศ
 *   2. **เผยแพร่แล้วแก้ไม่ได้** — เปลี่ยนกติกาต้องสร้างเวอร์ชันใหม่เสมอ
 *      ไม่งั้นคำถามว่า "หนังสือฉบับนั้นถูกตัดสินด้วยกติกาอะไร" จะตอบไม่ได้
 *   3. **ย้อนกลับ = เผยแพร่เวอร์ชันใหม่ที่มีเนื้อหาเหมือนของเก่า** ไม่ใช่ปลุก
 *      เวอร์ชันเก่าขึ้นมาใช้ใหม่ ซึ่งจะทำให้ไทม์ไลน์ขาดตอน
 *   4. **ค่าเริ่มต้นอยู่ในโค้ด ไม่ใช่ในฐานข้อมูล** — ระบบต้องทำงานได้ตั้งแต่วันแรก
 *      ก่อนที่ผู้ดูแลจะเข้ามาตั้งนโยบายใด ๆ
 */
class PolicyCenter
{
    private const CACHE_PREFIX = 'saraban.policy.';

    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TrustedClock $clock,
    ) {}

    /**
     * ค่าเริ่มต้นของแต่ละนโยบาย — ใช้เมื่อยังไม่มีเวอร์ชันที่เผยแพร่
     *
     * @return array<string,array<string,mixed>>
     */
    public static function defaults(): array
    {
        return [
            /*
             * ผังหน้าเอกสาร (ปิด RISK-32 จาก INT-12)
             *
             * เดิมตำแหน่ง QR มาจาก client พร้อมค่า default ที่ฝังในโค้ด ทำให้หนังสือ
             * แต่ละประเภทวาง QR ไม่เป็นมาตรฐานเดียวกัน · ตอนนี้มาจากนโยบายกลาง
             * ที่ตั้งต่อประเภทหนังสือได้ และมีประวัติว่าเปลี่ยนเมื่อใดโดยใคร
             */
            Policy::KEY_DOCUMENT_LAYOUT => [
                'qr' => ['page' => 1, 'x' => 470, 'y' => 740, 'size' => 72],
                'qr_by_document_type' => [],
            ],

            Policy::KEY_DISTRIBUTION => [
                // จำนวนวันทำการที่ผู้รับควรรับทราบ · ใช้แสดงรายการที่เกินกำหนด
                'acknowledgement_due_days' => 3,
                'allow_all_authorized_target' => true,
            ],

            Policy::KEY_NUMBERING => [
                // ปีพุทธศักราชและการรีเซ็ตรายปีเป็นข้อบังคับของระเบียบ ไม่ใช่ตัวเลือก
                'buddhist_era' => true,
                'annual_reset' => true,
            ],

            /*
             * ชั้นความลับ (SoT §18 · ปิดช่องว่างที่พบจากการตรวจทาน 2026-09-05)
             *
             * เดิม `DocumentPolicy` **ไม่ได้ดูชั้นความลับเลย** — หนังสือ "ลับที่สุด"
             * กับหนังสือทั่วไปถูกตัดสินด้วยเกณฑ์เดียวกันทุกประการ ทั้งที่ฟิลด์
             * ชั้นความลับมีอยู่ในข้อมูลตั้งแต่ INT-05
             *
             * **ค่าเริ่มต้นนี้ไม่ใช่กฎราชการ** — SoT §18 บังคับแค่ว่าต้องเป็นนโยบาย
             * ที่มีเวอร์ชัน ไม่ได้บอกว่าใครเปิดชั้นไหนได้ · ค่าที่ตั้งไว้เป็นจุดตั้งต้น
             * ที่ปลอดภัยตามหลัก "ยิ่งลับ ยิ่งแคบ" และ **ผู้ดูแลต้องยืนยันหรือแก้ให้ตรง
             * กับระเบียบของหน่วยงานก่อนขึ้นระบบจริง** (รายการตรวจรับข้อ ง.5)
             *
             * ชั้นที่ไม่ได้ระบุไว้ในตารางนี้ = **ปฏิเสธ** ตามหลัก default deny —
             * ชั้นความลับใหม่ที่เพิ่มเข้ามาโดยไม่มีใครกำหนดกติกา ต้องไม่เปิดกว้างเอง
             */
            Policy::KEY_CONFIDENTIALITY => [
                /*
                 * **ชั้นความลับสามชั้นตามระเบียบว่าด้วยการรักษาความลับของทางราชการ
                 * พ.ศ. 2544 ข้อ 12** — ลับ · ลับมาก · ลับที่สุด
                 *
                 * `normal` (ไม่ลับ) ไม่ใช่ชั้นความลับตามระเบียบ แต่เป็นสภาพปกติ
                 * ของหนังสือทั่วไป จึงมีอันดับ 0 และไม่ต้องมีสิทธิ์เพิ่ม
                 *
                 * **อันดับสำคัญกว่าชื่อ** — ใช้ตัดสินว่าใครเข้าถึงชั้นใดได้ตามหลัก
                 * "ผู้ที่ได้รับอนุญาตชั้นสูงกว่า ย่อมเข้าถึงชั้นต่ำกว่าได้"
                 * (ระเบียบฯ ข้อ 26 · การอนุญาตให้เข้าถึงชั้นความลับหนึ่ง ครอบคลุม
                 * ชั้นที่ต่ำกว่าด้วย) · ถ้าไม่มีข้อนี้ ผู้ที่ได้รับอนุญาตชั้นลับที่สุด
                 * จะเปิดหนังสือชั้นลับธรรมดาไม่ได้ ซึ่งขัดกับการปฏิบัติจริง
                 */
                'levels' => [
                    'normal' => ['rank' => 0, 'label' => 'ไม่ลับ', 'permission' => null],
                    'confidential' => ['rank' => 1, 'label' => 'ลับ', 'permission' => 'saraban.confidential.view'],
                    'secret' => ['rank' => 2, 'label' => 'ลับมาก', 'permission' => 'saraban.secret.view'],
                    'top_secret' => ['rank' => 3, 'label' => 'ลับที่สุด', 'permission' => 'saraban.top_secret.view'],
                ],

                /*
                 * **หลักความจำเป็นต้องรู้** (need to know · ระเบียบฯ ข้อ 26)
                 *
                 * การถือสิทธิ์ชั้นความลับไม่ได้แปลว่าเข้าถึงหนังสือลับทุกฉบับในระบบ —
                 * ต้องอยู่ในขอบเขตหน่วยงาน เป็นเจ้าของเรื่อง หรือเป็นผู้รับหนังสือ
                 * นั้นด้วย · ข้อนี้บังคับอยู่แล้วโดย `OrgScopeResolver` ที่ชั้นถัดไป
                 * ค่านี้จึงเป็นการประกาศเจตนาให้ผู้อ่านนโยบายเห็นว่ากติกาไม่ได้หายไป
                 */
                'need_to_know' => true,

                /*
                 * เจ้าของเรื่องและผู้รับหนังสือเปิดของตัวเองได้เสมอ ไม่ว่าชั้นใด
                 *
                 * ถ้าปิดข้อนี้ การส่งหนังสือลับถึงใครสักคนจะกลายเป็นการส่งที่เขา
                 * เปิดไม่ได้ — เท่ากับส่งไม่ถึง · ตรงกับระเบียบฯ ที่ให้ผู้รับซึ่ง
                 * ได้รับมอบหมายให้ปฏิบัติงานนั้นเข้าถึงได้ · ตั้งเป็น `false` ได้
                 * เมื่อหน่วยงานต้องการให้ชั้นความลับชนะแม้กระทั่งการเป็นผู้รับ
                 */
                'owner_and_recipient_bypass' => true,

                /*
                 * **ผู้เกี่ยวข้องในเรื่องเข้าถึงเรื่องนั้นได้** — หัวใจของหลัก
                 * ความจำเป็นต้องรู้
                 *
                 * ผู้ที่อยู่ในสายงานเดินหนังสือฉบับนี้ (ผู้เริ่มเรื่อง ผู้ได้รับมอบหมาย
                 * ให้พิจารณา และผู้ที่เคยลงมือทำอะไรกับเรื่องนี้ รวมผู้ปฏิบัติแทน
                 * ตามการมอบอำนาจ) เข้าถึงได้แม้ไม่ได้รับอนุญาตชั้นความลับนั้นไว้
                 * ล่วงหน้า และแม้อยู่นอกขอบเขตหน่วยงาน
                 *
                 * ถ้าปิดข้อนี้ หนังสือลับที่เดินมาถึงโต๊ะใครตามสายงานจะกลายเป็น
                 * หนังสือที่เขาเปิดไม่ได้ทั้งที่ต้องเป็นผู้พิจารณา — งานค้างทันที
                 * และผู้ดูแลจะถูกบีบให้แจกสิทธิ์ชั้นความลับกว้างเกินจำเป็นแทน
                 */
                'workflow_participant_bypass' => true,

                /*
                 * **ทะเบียนการเข้าถึงข้อมูลข่าวสารลับ** (ระเบียบฯ หมวด 4)
                 *
                 * ระเบียบกำหนดให้บันทึกว่าใครเข้าถึงเอกสารลับเมื่อใด · ระบบนี้บันทึก
                 * ลงห่วงโซ่ audit เดียวกับการกระทำอื่น จึงตรวจย้อนหลังได้และแก้ไม่ได้
                 *
                 * บันทึกเฉพาะชั้นที่มีอันดับตั้งแต่ 1 ขึ้นไป — บันทึกการเปิดหนังสือ
                 * ทั่วไปทุกครั้งจะทำให้ทะเบียนท่วมจนหาของจริงไม่เจอ
                 */
                'log_access_from_rank' => 1,
            ],

            /*
             * ความน่าเชื่อถือของลายเซ็น (SoT §14, §18)
             *
             * **รายชื่อว่างโดยเจตนา** — หน่วยงานยังไม่ได้ใช้ลายเซ็นดิจิทัลจริง
             * การเติมชื่อสมมติไว้ก่อนจะทำให้ระบบยอมรับใบรับรองที่ไม่มีใครตรวจสอบ
             *
             * เมื่อรายชื่อว่าง บริการตรวจสอบ **ไม่ตัดสินว่าไม่น่าเชื่อถือ** เพราะ
             * "ยังไม่ได้ประกาศว่าเชื่อถือใคร" ต่างจาก "ประกาศแล้วและรายนี้ไม่อยู่
             * ในรายการ" · เมื่อเริ่มใช้จริงให้เติมที่นี่ ใบรับรองนอกรายการจะถูก
             * รายงานว่า CERTIFICATE_UNTRUSTED ทันทีโดยไม่ต้องแก้โค้ด
             */
            Policy::KEY_SIGNATURE => [
                'trusted_certificate_issuers' => [],
                'trusted_timestamp_authorities' => [],
            ],
        ];
    }

    /**
     * นโยบายที่ใช้บังคับอยู่ ณ ตอนนี้
     *
     * รวมกับค่าเริ่มต้นเสมอ เพื่อให้คีย์ที่เพิ่มเข้ามาในโค้ดรุ่นใหม่มีค่าใช้ได้ทันที
     * โดยไม่ต้องบังคับให้ผู้ดูแลไปเผยแพร่นโยบายใหม่ทุกครั้งที่ระบบอัปเดต
     *
     * @return array<string,mixed>
     */
    public function current(string $key): array
    {
        $defaults = self::defaults()[$key] ?? [];

        $published = Cache::remember(
            self::CACHE_PREFIX.$key,
            self::CACHE_TTL_SECONDS,
            fn () => Policy::query()->where('key', $key)->first()?->publishedVersion?->payload ?? [],
        );

        return array_replace_recursive($defaults, $published);
    }

    /** ค่าหนึ่งค่าในนโยบาย รองรับเส้นทางแบบจุด */
    public function value(string $key, string $path, mixed $fallback = null): mixed
    {
        return data_get($this->current($key), $path, $fallback);
    }

    /**
     * สร้างหรือแก้ฉบับร่าง
     *
     * @param  array<string,mixed>  $payload
     *
     * @throws PolicyException
     */
    public function draft(string $key, array $payload, User $actor, ?string $notes = null): PolicyVersion
    {
        $policy = $this->policyFor($key);

        return DB::transaction(function () use ($policy, $payload, $actor, $notes) {
            $draft = PolicyVersion::query()
                ->where('policy_id', $policy->id)
                ->where('status', PolicyVersion::STATUS_DRAFT)
                ->first();

            if ($draft !== null) {
                $draft->forceFill(['payload' => $payload, 'notes' => $notes])->save();

                return $draft;
            }

            $next = (int) PolicyVersion::query()->where('policy_id', $policy->id)->max('version_no') + 1;

            return PolicyVersion::create([
                'policy_id' => $policy->id,
                'version_no' => $next,
                'status' => PolicyVersion::STATUS_DRAFT,
                'payload' => $payload,
                'notes' => $notes,
                'created_by' => $actor->id,
            ]);
        });
    }

    /**
     * เผยแพร่ฉบับร่างให้มีผลบังคับ
     *
     * **เป็นการกระทำที่อ่อนไหวตาม SoT §24** — ด่าน `step_up` อยู่ที่ route
     * และการเปลี่ยนแปลงถูกบันทึกลง audit กลางพร้อมเนื้อหาก่อน-หลัง
     *
     * @throws PolicyException
     */
    public function publish(string $key, User $actor): PolicyVersion
    {
        $policy = $this->policyFor($key);

        $published = DB::transaction(function () use ($policy, $actor) {
            $draft = PolicyVersion::query()
                ->where('policy_id', $policy->id)
                ->where('status', PolicyVersion::STATUS_DRAFT)
                ->lockForUpdate()
                ->first();

            if ($draft === null) {
                throw new PolicyException('ยังไม่มีฉบับร่างของนโยบายนี้ให้เผยแพร่');
            }

            // ฉบับที่ใช้อยู่เดิมเข้าคลัง — ไม่ลบ เพราะประวัติต้องอ่านย้อนหลังได้
            PolicyVersion::query()
                ->where('policy_id', $policy->id)
                ->where('status', PolicyVersion::STATUS_PUBLISHED)
                ->get()
                ->each(fn (PolicyVersion $old) => $old->forceFill(['status' => PolicyVersion::STATUS_ARCHIVED])->save());

            $now = $this->clock->now();

            $draft->forceFill([
                'status' => PolicyVersion::STATUS_PUBLISHED,
                'effective_from' => $now,
                'published_at' => $now,
                'published_by' => $actor->id,
            ])->save();

            return $draft;
        });

        $this->forget($key);

        $this->audit->log('saraban.policy.published', $policy, $policy->id, null, [
            'key' => $policy->key,
            'version_no' => $published->version_no,
            'payload' => $published->payload,
        ], null, $actor->id);

        return $published;
    }

    /**
     * ย้อนกลับไปใช้เนื้อหาของเวอร์ชันก่อนหน้า
     *
     * **สร้างเวอร์ชันใหม่ที่มีเนื้อหาเหมือนของเก่า แล้วเผยแพร่** ไม่ใช่ปลุกเวอร์ชัน
     * เก่าขึ้นมาใช้ใหม่ — ไทม์ไลน์ต้องเดินหน้าอย่างเดียว ไม่งั้นการตรวจสอบย้อนหลัง
     * จะเจอช่วงเวลาที่เวอร์ชันเดียวมีผลสองครั้งโดยไม่รู้ว่าครั้งไหนคือครั้งไหน
     *
     * @throws PolicyException
     */
    public function rollbackTo(string $key, int $versionNo, User $actor): PolicyVersion
    {
        $policy = $this->policyFor($key);

        $source = PolicyVersion::query()
            ->where('policy_id', $policy->id)
            ->where('version_no', $versionNo)
            ->first();

        if ($source === null) {
            throw new PolicyException('ไม่พบเวอร์ชันที่ต้องการย้อนกลับไปใช้');
        }

        if ($source->status === PolicyVersion::STATUS_PUBLISHED) {
            throw new PolicyException('เวอร์ชันนี้เป็นฉบับที่ใช้บังคับอยู่แล้ว');
        }

        $this->discardDraft($policy);

        $this->draft(
            $key,
            $source->payload,
            $actor,
            "ย้อนกลับไปใช้เนื้อหาของเวอร์ชันที่ {$source->version_no}",
        );

        $published = $this->publish($key, $actor);

        $this->audit->log('saraban.policy.rolled_back', $policy, $policy->id, null, [
            'key' => $policy->key,
            'from_version' => $source->version_no,
            'new_version' => $published->version_no,
        ], null, $actor->id);

        return $published;
    }

    /** นโยบายทั้งหมดพร้อมสถานะปัจจุบัน — ใช้แสดงหน้าศูนย์นโยบาย */
    public function overview(): array
    {
        $stored = Policy::query()->with(['publishedVersion', 'versions'])->get()->keyBy('key');

        return collect(self::defaults())
            ->map(function (array $defaults, string $key) use ($stored) {
                $policy = $stored->get($key);
                $draft = $policy?->versions->firstWhere('status', PolicyVersion::STATUS_DRAFT);

                return [
                    'key' => $key,
                    'name' => $policy?->name ?? $this->nameFor($key),
                    'effective' => $this->current($key),
                    'defaults' => $defaults,
                    'published_version' => $policy?->publishedVersion?->version_no,
                    'published_at' => $policy?->publishedVersion?->published_at?->toIso8601String(),
                    'has_draft' => $draft !== null,
                    'draft_payload' => $draft?->payload,
                ];
            })
            ->values()
            ->all();
    }

    /** ประวัติทุกเวอร์ชันของนโยบายหนึ่ง — หัวใจของการตรวจสอบย้อนหลัง */
    public function history(string $key): array
    {
        $policy = Policy::query()->where('key', $key)->first();

        if ($policy === null) {
            return [];
        }

        return $policy->versions()
            ->with(['publisher:id,name', 'author:id,name'])
            ->get()
            ->map(fn (PolicyVersion $version) => [
                ...$version->only(['version_no', 'status', 'payload', 'notes']),
                'published_at' => $version->published_at?->toIso8601String(),
                'published_by' => $version->publisher?->name,
                'created_by' => $version->author?->name,
            ])
            ->all();
    }

    private function discardDraft(Policy $policy): void
    {
        PolicyVersion::query()
            ->where('policy_id', $policy->id)
            ->where('status', PolicyVersion::STATUS_DRAFT)
            ->get()
            ->each(fn (PolicyVersion $draft) => $draft->delete());
    }

    /**
     * @throws PolicyException
     */
    private function policyFor(string $key): Policy
    {
        if (! array_key_exists($key, self::defaults())) {
            throw new PolicyException("ไม่รู้จักนโยบาย: {$key}");
        }

        return Policy::query()->firstOrCreate(['key' => $key], ['name' => $this->nameFor($key)]);
    }

    private function nameFor(string $key): string
    {
        return match ($key) {
            Policy::KEY_DOCUMENT_LAYOUT => 'ผังหน้าเอกสารและตำแหน่ง QR',
            Policy::KEY_DISTRIBUTION => 'การเวียนหนังสือและกรอบเวลารับทราบ',
            Policy::KEY_NUMBERING => 'การออกเลขหนังสือ',
            default => $key,
        };
    }

    private function forget(string $key): void
    {
        Cache::forget(self::CACHE_PREFIX.$key);
    }
}
