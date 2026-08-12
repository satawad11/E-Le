<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // ข้อมูลส่วนตัว
            $table->string('id_card_number')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('nationality')->nullable();
            $table->string('ethnicity')->nullable();
            $table->string('passport_number')->nullable();
            $table->date('passport_issue_date')->nullable();
            $table->date('passport_expiry_date')->nullable();
            $table->string('title')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('gender')->nullable();
            $table->string('first_name_en')->nullable();
            $table->string('last_name_en')->nullable();
            $table->string('religion')->nullable();

            // ข้อมูลที่อยู่
            $table->string('house_registration_number')->nullable();
            $table->string('house_number')->nullable();
            $table->string('moo')->nullable();
            $table->string('soi')->nullable();
            $table->string('road')->nullable();
            $table->string('province')->nullable();
            $table->string('district')->nullable();
            $table->string('sub_district')->nullable();
            $table->string('zipcode')->nullable();
            $table->string('fax')->nullable();
            $table->string('website')->nullable();

            // ข้อมูลการจ้าง
            $table->string('personnel_code')->nullable();
            $table->string('position')->nullable();
            $table->string('education_level')->nullable();
            $table->string('degree')->nullable();
            $table->date('start_date')->nullable();
            $table->string('license_type')->nullable();
            $table->string('license_number')->nullable();
            $table->date('license_issue_date')->nullable();
            $table->date('license_expiry_date')->nullable();
            $table->string('status')->default('ปฏิบัติหน้าที่');
            $table->string('profile_photo_path')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
