<?php

namespace App\Services\Operations;

use App\Models\FeatureFlag;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * ธงเปิด-ปิดความสามารถของทั้งระบบ (SoT §21)
 *
 * **Feature Flag ≠ Permission** — เป็นข้อบังคับที่ SoT เขียนไว้ตรง ๆ
 *
 *   สิทธิ์ ตอบว่า "คนนี้ทำสิ่งนี้ได้ไหม"        → ต่างกันไปตามคน
 *   ธง    ตอบว่า "ความสามารถนี้เปิดอยู่ไหม"   → เหมือนกันทั้งระบบ
 *
 * ธงที่เปิดไม่ได้ให้สิทธิ์ใครเพิ่มแม้แต่คนเดียว และธงที่ปิดก็ปิดสำหรับทุกคน
 * รวมถึงผู้ดูแลสูงสุด · จุดเรียกจึงต้องตรวจ **ทั้งสองอย่าง** เสมอ ไม่ใช่อย่างใด
 * อย่างหนึ่ง — และห้ามใช้ธงแทนสิทธิ์เพื่อความสะดวก
 *
 * **ค่าเริ่มต้นอยู่ในโค้ด** เหมือนศูนย์นโยบาย (INT-16) ระบบจึงทำงานได้ตั้งแต่
 * วันแรกก่อนที่ผู้ดูแลจะเข้ามาตั้งอะไร
 */
class FeatureFlags
{
    private const CACHE_KEY = 'system.feature_flags';

    private const CACHE_TTL_SECONDS = 300;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * ธงที่ระบบรู้จัก พร้อมค่าเริ่มต้น
     *
     * @return array<string,array{name:string,description:string,module:string,default:bool}>
     */
    public static function catalogue(): array
    {
        return [
            'saraban.webhooks' => [
                'name' => 'ส่งเหตุการณ์ออกทาง webhook',
                'description' => 'ปิดเพื่อหยุดส่งข้อมูลออกนอกระบบชั่วคราวโดยไม่ต้องลบปลายทางที่ตั้งไว้',
                'module' => 'saraban',
                'default' => true,
            ],
            'saraban.distribution.all_authorized' => [
                'name' => 'เวียนหนังสือถึงผู้มีสิทธิ์ทั้งหมด',
                'description' => 'ปิดเพื่อบังคับให้ระบุหน่วยงานหรือรายบุคคลเสมอ ในองค์กรที่มีผู้ใช้จำนวนมาก',
                'module' => 'saraban',
                'default' => true,
            ],
            /*
             * INT-21 — โหมดอ่านอย่างเดียว (SoT §27)
             *
             * **เป็นธง ไม่ใช่ `artisan down`** — ต่างกันตรงที่ระบบยังเปิดอยู่และ
             * ค้นหนังสือได้ · ใช้ตอนกู้คืนข้อมูลหรือย้ายฐาน ซึ่งเป็นช่วงที่ต้อง
             * หยุดการเขียนแต่งานราชการหยุดรอไม่ได้
             */
            'system.read_only' => [
                'name' => 'โหมดอ่านอย่างเดียว',
                'description' => 'หยุดการบันทึกทุกอย่างชั่วคราวโดยยังให้ค้นหาและเปิดดูเอกสารได้',
                'module' => 'system',
                'default' => false,
            ],
            'notification.web_push' => [
                'name' => 'การแจ้งเตือนผ่านเบราว์เซอร์',
                'description' => 'ปิดเพื่อหยุดช่องทาง Web Push ทั้งระบบโดยไม่ต้องลบการสมัครของผู้ใช้',
                'module' => 'notification',
                'default' => true,
            ],
        ];
    }

    public function enabled(string $key): bool
    {
        $defaults = self::catalogue()[$key] ?? null;

        if ($defaults === null) {
            /*
             * ธงที่ไม่รู้จักถือว่า **ปิด** ไม่ใช่เปิด — ค่าเริ่มต้นที่ปลอดภัยของ
             * ชื่อที่พิมพ์ผิดต้องเป็นการไม่ทำงาน ไม่ใช่การทำงานโดยไม่มีใครตั้งใจ
             */
            return false;
        }

        return $this->all()[$key] ?? $defaults['default'];
    }

    /** @return array<string,bool> */
    public function all(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            function (): array {
                /*
                 * ระบบที่ยังไม่ได้รัน migration ต้องใช้ค่าเริ่มต้นในโค้ดได้ (INT-21)
                 *
                 * `EnforceReadOnlyMode` ถามธงนี้ในทุกคำขอที่เปลี่ยนแปลงข้อมูล
                 * ถ้าตารางยังไม่มีแล้วโยน exception ระบบที่เพิ่งติดตั้งจะตอบ 500
                 * ทุกคำขอ — รวมถึงคำขอที่ใช้ตั้งค่าระบบให้ใช้งานได้
                 *
                 * "ยังไม่มีตาราง" กับ "ยังไม่มีใครเปลี่ยนธง" มีความหมายเดียวกัน
                 * ในทางปฏิบัติ: ใช้ค่าเริ่มต้น
                 */
                if (! Schema::hasTable('feature_flags')) {
                    return [];
                }

                return FeatureFlag::query()->pluck('enabled', 'key')
                    ->map(fn ($enabled) => (bool) $enabled)
                    ->all();
            },
        );
    }

    /** สถานะของทุกธงพร้อมคำอธิบาย — ใช้แสดงหน้าจอ */
    public function overview(): array
    {
        $stored = FeatureFlag::query()->with('editor:id,name')->get()->keyBy('key');

        return collect(self::catalogue())
            ->map(fn (array $meta, string $key) => [
                'key' => $key,
                'name' => $meta['name'],
                'description' => $meta['description'],
                'module' => $meta['module'],
                'default' => $meta['default'],
                'enabled' => $this->enabled($key),
                'overridden' => $stored->has($key),
                'updated_by' => $stored->get($key)?->editor?->name,
                'updated_at' => $stored->get($key)?->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * เปิดหรือปิดธง — **ทุกการเปลี่ยนแปลงถูกบันทึก** (SoT §21)
     *
     * @throws ExceptionQueueException
     */
    public function set(string $key, bool $enabled, User $actor): FeatureFlag
    {
        $meta = self::catalogue()[$key] ?? null;

        if ($meta === null) {
            throw new ExceptionQueueException("ไม่รู้จักธงความสามารถ: {$key}");
        }

        $before = $this->enabled($key);

        $flag = FeatureFlag::updateOrCreate(
            ['key' => $key],
            [
                'name' => $meta['name'],
                'description' => $meta['description'],
                'module' => $meta['module'],
                'enabled' => $enabled,
                'updated_by' => $actor->id,
            ],
        );

        Cache::forget(self::CACHE_KEY);

        $this->audit->log('system.feature_flag.changed', $flag, $flag->id, ['enabled' => $before], [
            'key' => $key,
            'enabled' => $enabled,
        ], null, $actor->id);

        return $flag;
    }
}
