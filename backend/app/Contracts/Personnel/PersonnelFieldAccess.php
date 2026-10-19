<?php

namespace App\Contracts\Personnel;

use App\Models\Personnel;
use App\Models\User;

/**
 * จุดตัดสินว่าผู้ใช้เห็น/เปิดดู/แก้ข้อมูลบุคลากรได้แค่ไหน (CORE-02)
 *
 * R-03 ผูกกับ `DenyByDefaultPersonnelFieldAccess` (เจ้าของข้อมูลเห็นของตนเอง ผู้อื่นเห็นเฉพาะชั้น public)
 * R-04 เปลี่ยนการผูกเป็นตัวที่ถาม PermissionEngine ตาม permission + scope ของ assignment
 */
interface PersonnelFieldAccess
{
    /**
     * ชั้นความลับที่ผู้ใช้เห็นค่าจริงได้ใน PersonnelPresenter (ไม่รวม sensitive — ต้องผ่าน reveal เสมอ)
     *
     * @return array<int, string> subset ของ public / internal / confidential
     */
    public function visibleClasses(User $actor, Personnel $personnel): array;

    /** เปิดดูฟิลด์ชั้น sensitive ได้หรือไม่ (ยังต้องระบุเหตุผลและถูกบันทึกเสมอ) */
    public function canReveal(User $actor, Personnel $personnel): bool;

    /** แก้ข้อมูลของผู้อื่นในฐานะงานบุคคลได้หรือไม่ */
    public function canEditAsHr(User $actor, Personnel $personnel): bool;

    /** พิจารณาคำขอแก้ไขข้อมูลของบุคลากรคนนี้ได้หรือไม่ */
    public function canReviewChanges(User $actor, Personnel $personnel): bool;
}
