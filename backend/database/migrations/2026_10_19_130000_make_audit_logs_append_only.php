<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ประวัติการตรวจสอบเพิ่มได้อย่างเดียว ห้ามแก้หรือลบ — บังคับที่ระดับฐานข้อมูล
 * (REDESIGN-2569 เฟส 5.5 · D-07 เจ้าของระบบตัดสิน 18/10/2569: Super Admin ก็แก้ audit log ไม่ได้)
 *
 * PROJECT_RULES ข้อ 8 กำหนดให้ audit log เป็น append-oriented แต่เดิมบังคับแค่ในโค้ด ผู้ที่เข้าถึงฐานข้อมูลได้
 * หรือโค้ดที่ผิดพลาดจึงแก้ประวัติได้ — trigger ทำให้ UPDATE/DELETE ล้มเหลวไม่ว่ามาจากที่ใด
 *
 * ข้อยกเว้นเดียวของ UPDATE: คอลัมน์ foreign key ที่ตั้งเป็น NULL ได้ (เช่น actor_id) เปลี่ยนเป็น NULL ได้
 * เพราะเป็นผลของ ON DELETE SET NULL เมื่อแถวที่อ้างถึงถูกลบ — เนื้อหาของประวัติยังแก้ไม่ได้
 *
 * ตารางที่ foreign key เป็น ON DELETE CASCADE (leave_action_logs ← leaves, personal_data_access_logs ← users)
 * กันเฉพาะ UPDATE ไว้ก่อน เพราะการกัน DELETE จะทำให้ลบแถวต้นทางไม่ได้ การเปลี่ยน FK เป็น RESTRICT
 * เป็นการเปลี่ยน schema แยกต่างหาก (ระบบใช้ soft delete กับใบลาและไม่ลบบัญชีผู้ใช้อยู่แล้ว)
 *
 * รองรับ MySQL/MariaDB (ที่ใช้จริง) และ SQLite (ที่ใช้ทดสอบ)
 */
return new class extends Migration
{
    private const MESSAGE = 'ห้ามแก้ไขหรือลบประวัติการตรวจสอบ';

    /** ตาราง => [คอลัมน์ FK ที่ยอมให้เป็น NULL ได้, กัน DELETE ด้วยหรือไม่] */
    private const TABLES = [
        'audit_logs' => [['actor_id', 'effective_actor_id'], true],
        'leave_audit_logs' => [['leave_id', 'user_id'], true],
        'saraban_audit_logs' => [['document_id', 'user_id'], true],
        'setting_audit_logs' => [['user_id'], true],
        'leave_action_logs' => [['user_id'], false],
        'personal_data_access_logs' => [[], false],
    ];

    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            return;
        }

        foreach (self::TABLES as $table => [$nullableKeys, $blockDelete]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $this->dropTriggers($table);
            $columns = Schema::getColumnListing($table);

            if ($driver === 'sqlite') {
                $this->createSqlite($table, $columns, $nullableKeys, $blockDelete);
            } else {
                $this->createMysql($table, $columns, $nullableKeys, $blockDelete);
            }
        }
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb', 'sqlite'], true)) {
            return;
        }

        foreach (array_keys(self::TABLES) as $table) {
            $this->dropTriggers($table);
        }
    }

    private function createMysql(string $table, array $columns, array $nullableKeys, bool $blockDelete): void
    {
        $unchanged = collect($columns)->map(function (string $c) use ($nullableKeys) {
            $same = "NEW.`{$c}` <=> OLD.`{$c}`";

            return in_array($c, $nullableKeys, true) ? "({$same} OR NEW.`{$c}` IS NULL)" : $same;
        })->implode(' AND ');
        $message = self::MESSAGE;

        DB::unprepared("CREATE TRIGGER `{$table}_append_only_update` BEFORE UPDATE ON `{$table}` FOR EACH ROW
            BEGIN
                IF NOT ({$unchanged}) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';
                END IF;
            END");

        if ($blockDelete) {
            DB::unprepared("CREATE TRIGGER `{$table}_append_only_delete` BEFORE DELETE ON `{$table}` FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'");
        }
    }

    private function createSqlite(string $table, array $columns, array $nullableKeys, bool $blockDelete): void
    {
        $unchanged = collect($columns)->map(function (string $c) use ($nullableKeys) {
            $same = "NEW.\"{$c}\" IS OLD.\"{$c}\"";

            return in_array($c, $nullableKeys, true) ? "({$same} OR NEW.\"{$c}\" IS NULL)" : $same;
        })->implode(' AND ');
        $message = self::MESSAGE;

        DB::unprepared("CREATE TRIGGER \"{$table}_append_only_update\" BEFORE UPDATE ON \"{$table}\" FOR EACH ROW
            WHEN NOT ({$unchanged})
            BEGIN SELECT RAISE(ABORT, '{$message}'); END");

        if ($blockDelete) {
            DB::unprepared("CREATE TRIGGER \"{$table}_append_only_delete\" BEFORE DELETE ON \"{$table}\" FOR EACH ROW
                BEGIN SELECT RAISE(ABORT, '{$message}'); END");
        }
    }

    private function dropTriggers(string $table): void
    {
        $quote = DB::getDriverName() === 'sqlite' ? '"' : '`';
        foreach (['update', 'delete'] as $kind) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$quote}{$table}_append_only_{$kind}{$quote}");
        }
    }
};
