<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $entry) => $entry->linkIntoHashChain());
        static::updating(fn () => throw new \LogicException('Audit logs are append-only.'));
        static::deleting(fn () => throw new \LogicException('Audit logs are append-only.'));
    }

    /**
     * ผูกแถวนี้เข้ากับโซ่แฮชของประวัติ (SEC-GAP-03 · SoT §25)
     *
     * ทำใน `creating` ไม่ใช่ใน `AuditLogger` เพราะมีหลายทางที่เขียนตารางนี้
     * (เช่น การเขียนคู่จาก `LeaveAuditLog`) ถ้าผูกไว้ที่ service ทางอื่นจะได้แถว
     * ที่ไม่มีแฮช แล้วโซ่ขาดเองโดยไม่มีใครแก้อะไรผิดเลย
     *
     * `lockForUpdate()` กันสองคำขอที่เขียนพร้อมกันอ่านแถวสุดท้ายตัวเดียวกัน
     * ซึ่งจะทำให้โซ่แตกเป็นสองสายและตรวจสอบภายหลังแล้วเตือนผิด
     */
    protected function linkIntoHashChain(): void
    {
        if (! Schema::hasColumn($this->getTable(), 'entry_hash')) {
            return;
        }

        $previous = static::query()
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first(['id', 'entry_hash']);

        $this->previous_hash = $previous?->entry_hash;
        $this->entry_hash = self::hashFor($this->attributesToArray(), $this->previous_hash);
    }

    /**
     * แฮชของเนื้อหาแถวหนึ่ง ผูกกับแฮชของแถวก่อนหน้า
     *
     * ใช้เฉพาะฟิลด์ที่เป็น "สาระของเหตุการณ์" — การเพิ่มคอลัมน์เชิงเทคนิคในอนาคต
     * จึงไม่ทำให้โซ่ที่ลงไว้แล้วกลายเป็นไม่ตรง
     *
     * @param  array<string,mixed>  $attributes
     */
    public static function hashFor(array $attributes, ?string $previousHash): string
    {
        $material = [
            'actor_id' => $attributes['actor_id'] ?? null,
            'effective_actor_id' => $attributes['effective_actor_id'] ?? null,
            'action' => $attributes['action'] ?? null,
            'resource_type' => $attributes['resource_type'] ?? null,
            'resource_id' => $attributes['resource_id'] ?? null,
            'before' => $attributes['before'] ?? null,
            'after' => $attributes['after'] ?? null,
            'reason' => $attributes['reason'] ?? null,
            'created_at' => (string) ($attributes['created_at'] ?? ''),
            'previous_hash' => $previousHash,
        ];

        return hash('sha256', json_encode($material, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** ผู้กระทำ — ใช้แสดงชื่อในหน้าประวัติการดำเนินการ */
    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
