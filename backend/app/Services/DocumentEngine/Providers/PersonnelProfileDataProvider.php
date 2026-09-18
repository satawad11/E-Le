<?php

namespace App\Services\DocumentEngine\Providers;

use App\Models\Setting;
use App\Models\User;
use App\Services\DocumentEngine\DocumentDataProvider;
use App\Services\ThaiDateService;

/**
 * แหล่งข้อมูลประวัติและข้อมูลบุคลากรสำหรับ Document Engine
 *
 * ให้ข้อมูลบุคลากร ตำแหน่ง สังกัดหน่วยงาน และข้อมูลสถานศึกษา
 * สำหรับนำไปวางในแบบฟอร์ม เช่น หนังสือรับรองการทำงาน, บัตรประจำตัว, แบบคำขอทั่วไป
 */
class PersonnelProfileDataProvider implements DocumentDataProvider
{
    public const CODE = 'PERSONNEL_PROFILE_DETAIL';

    public function code(): string
    {
        return self::CODE;
    }

    public function module(): string
    {
        return 'core';
    }

    /**
     * @param  array{user?: User, actor?: User}  $context
     * @return array<string,mixed>
     */
    public function fields(array $context): array
    {
        $user = $context['user'] ?? $context['actor'] ?? null;

        if (! $user instanceof User) {
            throw new \InvalidArgumentException('PERSONNEL_PROFILE_DETAIL ต้องได้รับข้อมูลผู้ใช้');
        }

        $personnel = $user->personnel;
        $profile = $user->profile;
        $assignment = $personnel?->currentAssignment ?? $user->currentAssignment;
        $orgUnit = $assignment?->orgUnit ?? $user->orgUnit ?? $user->department;

        $schoolName = Setting::get('school_name', 'วิทยาลัยเทคนิคดอนเมือง');
        $schoolAffiliation = Setting::get('school_affiliation', 'สำนักงานคณะกรรมการการอาชีวศึกษา');
        $directorName = Setting::get('director_name', '');
        $directorPosition = Setting::get('director_position', 'ผู้อำนวยการวิทยาลัยเทคนิคดอนเมือง');

        $fullName = $personnel?->full_name ?: ($profile?->full_name ?: $user->name);
        $position = $assignment?->position ?: ($profile?->position ?: '');
        $academicStanding = $profile?->academic_standing ?: ($personnel?->academic_standing ?? '');
        $personnelType = $assignment?->personnelType?->name ?: ($profile?->personnel_type ?: 'ข้าราชการครู');

        $thaiDateService = app(ThaiDateService::class);

        return [
            'user.name' => $user->name,
            'user.email' => $user->email,
            'user.phone' => $profile?->phone ?: ($user->phone ?: ''),
            'user.id_card_number' => $profile?->id_card_number ?: '',

            'personnel.prefix' => $personnel?->title ?: '',
            'personnel.first_name' => $personnel?->first_name ?: '',
            'personnel.last_name' => $personnel?->last_name ?: '',
            'personnel.full_name' => $fullName,
            'personnel.code' => $personnel?->personnel_code ?: '',
            'personnel.position' => $position,
            'personnel.academic_standing' => $academicStanding,
            'personnel.type' => $personnelType,
            'personnel.signature_path' => $profile?->signature_path ?: '',

            'org_unit.name' => $orgUnit?->name ?: '',
            'org_unit.code' => $orgUnit?->code ?: '',
            'org_unit.full_path' => $orgUnit?->full_path ?: ($orgUnit?->name ?: ''),

            'organization.name' => $schoolName,
            'organization.affiliation' => $schoolAffiliation,
            'organization.director_name' => $directorName,
            'organization.director_position' => $directorPosition,

            'system.today_thai' => $thaiDateService->toThaiDate(today()->toDateString()),
            'system.today_short_thai' => $thaiDateService->formatToThaiSlash(today()->toDateString()),
            'system.current_year_th' => (string) (today()->year + 543),
        ];
    }

    /**
     * @return array<int,array{code:string,label_th:string,group_th:string,data_type:string}>
     */
    public function catalog(): array
    {
        return [
            ['code' => 'personnel.full_name', 'label_th' => 'ชื่อ-นามสกุล บุคลากร', 'group_th' => 'ข้อมูลบุคลากร', 'data_type' => 'TEXT'],
            ['code' => 'personnel.position', 'label_th' => 'ตำแหน่ง', 'group_th' => 'ข้อมูลบุคลากร', 'data_type' => 'TEXT'],
            ['code' => 'personnel.academic_standing', 'label_th' => 'วิทยฐานะ', 'group_th' => 'ข้อมูลบุคลากร', 'data_type' => 'TEXT'],
            ['code' => 'personnel.type', 'label_th' => 'ประเภทบุคลากร', 'group_th' => 'ข้อมูลบุคลากร', 'data_type' => 'TEXT'],
            ['code' => 'personnel.code', 'label_th' => 'รหัสบุคลากร', 'group_th' => 'ข้อมูลบุคลากร', 'data_type' => 'TEXT'],
            ['code' => 'user.phone', 'label_th' => 'เบอร์โทรศัพท์', 'group_th' => 'ข้อมูลบุคลากร', 'data_type' => 'TEXT'],
            ['code' => 'user.email', 'label_th' => 'อีเมล', 'group_th' => 'ข้อมูลบุคลากร', 'data_type' => 'TEXT'],
            ['code' => 'user.id_card_number', 'label_th' => 'เลขประจำตัวประชาชน', 'group_th' => 'ข้อมูลบุคลากร', 'data_type' => 'TEXT'],
            ['code' => 'personnel.signature_path', 'label_th' => 'ลายมือชื่อดิจิทัล', 'group_th' => 'ข้อมูลบุคลากร', 'data_type' => 'IMAGE'],

            ['code' => 'org_unit.name', 'label_th' => 'หน่วยงาน / งาน / แผนกวิชา', 'group_th' => 'หน่วยงาน', 'data_type' => 'TEXT'],
            ['code' => 'org_unit.code', 'label_th' => 'รหัสหน่วยงาน', 'group_th' => 'หน่วยงาน', 'data_type' => 'TEXT'],
            ['code' => 'org_unit.full_path', 'label_th' => 'สังกัดตามลำดับชั้น', 'group_th' => 'หน่วยงาน', 'data_type' => 'TEXT'],

            ['code' => 'organization.name', 'label_th' => 'ชื่อสถานศึกษา', 'group_th' => 'สถานศึกษา', 'data_type' => 'TEXT'],
            ['code' => 'organization.affiliation', 'label_th' => 'สังกัดสถานศึกษา', 'group_th' => 'สถานศึกษา', 'data_type' => 'TEXT'],
            ['code' => 'organization.director_name', 'label_th' => 'ชื่อผู้อำนวยการ', 'group_th' => 'สถานศึกษา', 'data_type' => 'TEXT'],
            ['code' => 'organization.director_position', 'label_th' => 'ตำแหน่งผู้อำนวยการ', 'group_th' => 'สถานศึกษา', 'data_type' => 'TEXT'],

            ['code' => 'system.today_thai', 'label_th' => 'วันที่ปัจจุบัน (ไทยเต็ม)', 'group_th' => 'วันที่และเวลา', 'data_type' => 'TEXT'],
            ['code' => 'system.today_short_thai', 'label_th' => 'วันที่ปัจจุบัน (ไทยย่อ)', 'group_th' => 'วันที่และเวลา', 'data_type' => 'TEXT'],
            ['code' => 'system.current_year_th', 'label_th' => 'ปี พ.ศ. ปัจจุบัน', 'group_th' => 'วันที่และเวลา', 'data_type' => 'TEXT'],
        ];
    }
}
