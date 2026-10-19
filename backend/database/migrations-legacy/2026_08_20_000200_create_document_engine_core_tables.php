<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DOC-ENGINE-01 — ตารางแกนของ Document & Report Engine
 *
 * ทั้งหมดเป็นตารางใหม่ ไม่แตะของเดิม:
 *   - `document_field_definitions` — Field Dictionary กลาง (ตัวแปรที่แบบฟอร์มใช้ได้)
 *   - `document_data_sources`      — whitelist ของ Data Provider (ห้าม SQL อิสระจาก UI)
 *   - `document_binding_rules`     — กติกาว่า entity ใดใช้แม่แบบใด (module/entity/priority/effective)
 *
 * binding rule ถูก seed จาก `leave_types.document_template_code` ที่ตั้งค่าไว้แล้ว
 * เพื่อให้พฤติกรรมการเลือกแบบฟอร์มของระบบเดิมยังเหมือนเดิมทุกประการ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_field_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 120)->unique();      // employee.full_name
            $table->string('label_th');
            $table->string('group_th', 120)->nullable();
            $table->string('data_type', 20)->default('TEXT'); // TEXT|MULTILINE_TEXT|DATE|NUMBER|CHECKBOX|SIGNATURE|IMAGE|QR_CODE|BARCODE
            $table->string('source', 120)->nullable();  // data source code ที่ให้ค่านี้
            $table->string('module', 30)->default('leave');
            $table->string('format')->nullable();       // เช่น d/m/Y (พ.ศ.) หรือ 0,0.0
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['module', 'group_th']);
        });

        Schema::create('document_data_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 120)->unique();      // LEAVE_REQUEST_DETAIL
            $table->string('name_th');
            $table->string('module', 30)->default('leave');
            $table->string('kind', 20)->default('RECORD'); // RECORD (1 เอกสาร) | DATASET (รายงานหลายแถว)
            $table->string('provider_class');           // FQCN ที่ลงทะเบียนไว้เท่านั้น
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('document_binding_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('module', 30);               // LEAVE, HR, FINANCE, ...
            $table->string('entity_type', 60);          // LEAVE_TYPE, LEAVE_CANCELLATION, ...
            $table->unsignedBigInteger('entity_id')->nullable(); // null = ใช้กับทุก entity ของชนิดนั้น
            $table->foreignId('document_template_id')->constrained('document_templates')->cascadeOnDelete();
            $table->unsignedInteger('priority')->default(100); // เลขน้อย = สำคัญกว่า
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->json('conditions')->nullable();     // เงื่อนไขเพิ่มเติมแบบ key/value
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['module', 'entity_type', 'entity_id', 'priority'], 'document_binding_lookup');
        });

        if (! Schema::hasTable('leave_types') || ! Schema::hasColumn('leave_types', 'document_template_code')) {
            return;
        }

        $templates = DB::table('document_templates')->pluck('id', 'code');

        foreach (DB::table('leave_types')->select('id', 'document_template_code')->get() as $type) {
            $templateId = $templates[$type->document_template_code] ?? null;

            if ($templateId === null) {
                continue;
            }

            DB::table('document_binding_rules')->insert([
                'module' => 'LEAVE',
                'entity_type' => 'LEAVE_TYPE',
                'entity_id' => $type->id,
                'document_template_id' => $templateId,
                // priority สูง (เลขมาก) เพราะเป็นค่าที่ย้ายมาจากระบบเดิม
                // กติกาที่ผู้ดูแลสร้างใหม่ภายหลังจะมีลำดับเหนือกว่าได้
                'priority' => 500,
                'effective_from' => null,
                'effective_to' => null,
                'conditions' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_binding_rules');
        Schema::dropIfExists('document_data_sources');
        Schema::dropIfExists('document_field_definitions');
    }
};
