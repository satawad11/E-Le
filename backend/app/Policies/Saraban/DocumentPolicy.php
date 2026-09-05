<?php

namespace App\Policies\Saraban;

use App\Models\Saraban\Distribution;
use App\Models\Saraban\DistributionRecipient;
use App\Models\Saraban\Document;
use App\Models\Saraban\Policy;
use App\Models\User;
use App\Models\Workflow\WorkflowAction;
use App\Models\Workflow\WorkflowInstance;
use App\Models\Workflow\WorkflowTask;
use App\Services\OrgScopeResolver;
use App\Services\Saraban\PolicyCenter;

/**
 * สิทธิ์การเข้าถึงหนังสือรายฉบับ (SoT §6 · INT-05)
 *
 * ลำดับการตัดสินตามสัญญา INT-01 §1.6:
 *   explicit deny → scoped allow → role allow → **default DENY**
 *
 * ใช้ **permission เท่านั้น ไม่อ้างชื่อ role** ตามแบบเดียวกับ `LeavePolicy`
 * (ชื่อ role ในระบบนี้ผู้ดูแลแก้ได้จากหน้าจอ การผูกสิทธิ์กับชื่อ role ทำให้
 * การเปลี่ยนชื่อครั้งเดียวทำลายการควบคุมสิทธิ์ทั้งระบบ)
 *
 * ขอบเขตการมองเห็นมาจาก `OrgScopeResolver` ซึ่งเป็นตัวตัดสินขอบเขตกลางของระบบ
 * — สารบรรณไม่ได้สร้างกติกาขอบเขตของตัวเอง
 *
 * หมายเหตุ: Super Admin ผ่านทุกข้อผ่าน `Gate::before` ใน `AppServiceProvider`
 */
class DocumentPolicy
{
    public function __construct(
        private readonly OrgScopeResolver $scopes,
        private readonly PolicyCenter $policies,
    ) {}

    /**
     * ผู้ใช้คนนี้ได้รับอนุญาตให้เข้าถึงชั้นความลับของหนังสือฉบับนี้หรือไม่
     *
     * ตามระเบียบว่าด้วยการรักษาความลับของทางราชการ พ.ศ. 2544:
     *
     *   - ชั้นความลับมีสามชั้นเรียงลำดับ — ลับ · ลับมาก · ลับที่สุด
     *   - **ผู้ได้รับอนุญาตชั้นสูงกว่า เข้าถึงชั้นที่ต่ำกว่าได้** จึงตัดสินด้วย
     *     *อันดับ* ไม่ใช่การจับคู่ชื่อชั้นกับสิทธิ์แบบตรงตัว · ถ้าตัดสินแบบตรงตัว
     *     ผู้ที่ได้รับอนุญาตชั้นลับที่สุดจะเปิดหนังสือชั้นลับธรรมดาไม่ได้
     *   - ชั้นที่นโยบายไม่ได้อธิบายไว้ = **ปฏิเสธ** ตามหลัก default deny
     *
     * ตารางชั้นมาจากนโยบาย `confidentiality` ในศูนย์นโยบาย ไม่ได้ฝังในโค้ด (SoT §18)
     */
    private function hasClearance(User $user, Document $document): bool
    {
        $levels = $this->levels();
        $level = $document->confidentiality ?? Document::CONFIDENTIALITY_NORMAL;

        if (! array_key_exists($level, $levels)) {
            return false;
        }

        $required = (int) ($levels[$level]['rank'] ?? 0);

        if ($required === 0) {
            return true;
        }

        /*
         * ไล่จากชั้นที่ต้องการขึ้นไปจนสูงสุด — ถือสิทธิ์ของชั้นใดชั้นหนึ่งที่
         * อันดับไม่ต่ำกว่าที่ต้องการ ถือว่าได้รับอนุญาต
         */
        foreach ($levels as $candidate) {
            if ((int) ($candidate['rank'] ?? 0) < $required) {
                continue;
            }

            $permission = $candidate['permission'] ?? null;

            if ($permission !== null && $user->can((string) $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ตารางชั้นความลับที่บังคับใช้อยู่
     *
     * @return array<string,array<string,mixed>>
     */
    private function levels(): array
    {
        $levels = $this->policies->current(Policy::KEY_CONFIDENTIALITY)['levels'] ?? [];

        return is_array($levels) ? $levels : [];
    }

    /**
     * ชั้นความลับของหนังสือฉบับนี้ต้องบันทึกการเข้าถึงหรือไม่ (ระเบียบฯ หมวด 4)
     *
     * เปิดให้ `DocumentController` เรียกได้ เพราะการบันทึกเป็นหน้าที่ของผู้เรียก
     * ไม่ใช่ของ policy — policy ตอบแค่ว่า "ได้หรือไม่ได้" ตามสัญญาของ Laravel
     * ถ้า policy เขียน audit เอง การถามสิทธิ์เพื่อซ่อนปุ่มบนหน้าจอจะกลายเป็น
     * การบันทึกว่ามีคนเข้าถึงเอกสารลับทั้งที่ยังไม่มีใครเปิดอ่าน
     */
    public function requiresAccessLog(Document $document): bool
    {
        $policy = $this->policies->current(Policy::KEY_CONFIDENTIALITY);
        $threshold = (int) ($policy['log_access_from_rank'] ?? 1);
        $level = $document->confidentiality ?? Document::CONFIDENTIALITY_NORMAL;
        $rank = (int) ($policy['levels'][$level]['rank'] ?? 0);

        return $rank >= $threshold && $threshold > 0;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('saraban.document.view');
    }

    /**
     * เปิดดูหนังสือฉบับนี้ได้หรือไม่
     *
     * เจ้าของเรื่องเห็นของตัวเองเสมอ ส่วนคนอื่นต้องมีสิทธิ์ **และ** อยู่ในขอบเขต
     * หน่วยงานที่ได้รับ ไม่ใช่เห็นทั้งองค์กรเพียงเพราะถือสิทธิ์
     */
    public function view(User $user, Document $document): bool
    {
        $confidentiality = $this->policies->current(Policy::KEY_CONFIDENTIALITY);
        $bypass = (bool) ($confidentiality['owner_and_recipient_bypass'] ?? true);
        $participantBypass = (bool) ($confidentiality['workflow_participant_bypass'] ?? true);

        if ($this->isOwner($user, $document)) {
            return $bypass || $this->hasClearance($user, $document);
        }

        /*
         * ผู้ที่ได้รับหนังสือเวียนต้องเปิดอ่านได้เสมอ (SoT §15 · INT-13)
         *
         * ขอบเขตหน่วยงานตอบคำถามว่า "ใครค้นเจอหนังสือของหน่วยงานไหนได้" ซึ่งเป็น
         * คนละคำถามกับ "ใครได้รับหนังสือฉบับนี้" — การส่งหนังสือข้ามหน่วยงาน
         * เป็นเรื่องปกติของงานสารบรรณ ถ้าไม่มีข้อนี้ กล่องหนังสือเข้าจะมีรายการ
         * ที่กดแล้วขึ้น 403 ซึ่งเท่ากับส่งหนังสือไม่ถึง
         */
        if ($this->isRecipient($user, $document)) {
            return $bypass || $this->hasClearance($user, $document);
        }

        /*
         * **ผู้เกี่ยวข้องในเรื่องเข้าถึงเรื่องนั้นได้** — หลักความจำเป็นต้องรู้
         *
         * ผู้ที่อยู่ในสายงานเดินหนังสือฉบับนี้เข้าถึงได้แม้อยู่นอกขอบเขตหน่วยงาน
         * และแม้ไม่ได้รับอนุญาตชั้นความลับนั้นไว้ล่วงหน้า · การเดินเรื่องข้าม
         * หน่วยงานเป็นเรื่องปกติของงานสารบรรณ และการมอบหมายให้พิจารณาเรื่องหนึ่ง
         * คือการให้ความจำเป็นต้องรู้ในเรื่องนั้นอยู่ในตัว
         */
        if ($this->isWorkflowParticipant($user, $document)) {
            return $participantBypass || $this->hasClearance($user, $document);
        }

        if (! $user->can('saraban.document.view')) {
            return false;
        }

        /*
         * **ชั้นความลับเป็นด่านเพิ่ม ไม่ใช่ด่านแทน** (SoT §18)
         *
         * ที่มา (การตรวจทาน 2026-09-05): `DocumentPolicy` ไม่เคยดูชั้นความลับเลย
         * หนังสือ "ลับที่สุด" จึงเปิดได้ด้วยเกณฑ์เดียวกับหนังสือทั่วไป — ขอแค่มี
         * `saraban.document.view` และอยู่ในขอบเขตหน่วยงาน
         *
         * กติกาว่าชั้นใดต้องมีสิทธิ์ใด **มาจากศูนย์นโยบาย** ไม่ได้ฝังในโค้ด
         * เพราะเป็นกฎของแต่ละส่วนราชการ และต้องมีเวอร์ชัน/ย้อนกลับได้ตาม SoT §18
         */
        if (! $this->hasClearance($user, $document)) {
            return false;
        }

        return $this->scopes->canSeeOrgUnit(
            $user,
            'saraban.document.view',
            $document->owner_org_unit_id,
            OrgScopeResolver::UNIT,
        );
    }

    public function create(User $user): bool
    {
        return $user->can('saraban.document.create');
    }

    /**
     * แก้ไขได้เฉพาะฉบับร่างเท่านั้น
     *
     * SoT §8 — เมื่อหนังสือเข้าสู่กระบวนการแล้ว เนื้อหาที่ผู้อื่นเห็นและใช้ตัดสินใจ
     * ต้องไม่เปลี่ยนใต้มือ การแก้หลังจากนั้นต้องผ่านการสร้างเวอร์ชันใหม่ตามสายงาน
     * ไม่ใช่แก้ทับของเดิม
     */
    public function update(User $user, Document $document): bool
    {
        if (! $document->isDraft()) {
            return false;
        }

        if (! $user->can('saraban.document.update')) {
            return false;
        }

        return $this->isOwner($user, $document) || $this->scopes->canSeeOrgUnit(
            $user,
            'saraban.document.update',
            $document->owner_org_unit_id,
            OrgScopeResolver::UNIT,
        );
    }

    /**
     * ประทับตรา/วางวัตถุบนหน้าหนังสือ (INT-10)
     *
     * **แยกจาก `update()` โดยตั้งใจ** — `update()` คือการแก้ *ข้อมูล* ของหนังสือ
     * ซึ่งทำได้เฉพาะตอนเป็นฉบับร่าง ส่วนการประทับตรารับเป็นการทำงานกับ *หน้ากระดาษ*
     * ของหนังสือที่ลงทะเบียนรับแล้ว (สถานะ `registered`) หรือกำลังเดินเรื่องอยู่
     *
     * ถ้าใช้กติกาเดียวกัน จะประทับตรารับบนหนังสือรับไม่ได้เลย ซึ่งเป็นงานหลัก
     * ของงานสารบรรณ
     *
     * ปิดเมื่อหนังสือลงนามหรือยกเลิกแล้ว เพราะการเติมตราลงบนหนังสือที่ออกไปแล้ว
     * คือการแก้เอกสารที่เป็นหลักฐาน
     */
    public function stamp(User $user, Document $document): bool
    {
        if (in_array($document->status, [Document::STATUS_SIGNED, Document::STATUS_CANCELLED], true)) {
            return false;
        }

        return $user->can('saraban.incoming.stamp') && $this->view($user, $document);
    }

    /**
     * ลงนามในหนังสือ (SoT §14, §16 · INT-11)
     *
     * **งานสารบรรณเตรียมที่ว่างให้ได้ แต่ลงนามแทนไม่ได้** — ผู้ลงนามต้องมีอำนาจ
     * ลงนามกับหน่วยงานเจ้าของหนังสือด้วยตนเอง ไม่ใช่แค่ถือสิทธิ์ชื่อเดียวกัน
     *
     * ลงนามซ้ำบนหนังสือที่ลงนามแล้วไม่ได้ และหนังสือที่ยกเลิกแล้วก็ลงนามไม่ได้
     */
    public function sign(User $user, Document $document): bool
    {
        if (in_array($document->status, [Document::STATUS_SIGNED, Document::STATUS_CANCELLED], true)) {
            return false;
        }

        if (! $user->can('saraban.signature.sign')) {
            return false;
        }

        return $this->scopes->canSeeOrgUnit(
            $user,
            'saraban.signature.sign',
            $document->owner_org_unit_id,
            OrgScopeResolver::UNIT,
        );
    }

    /**
     * เกษียนหนังสือ — ลายมือ ความเห็น เครื่องหมาย (SoT §15)
     *
     * แยกจาก `sign()` เพราะ **ลายมือ ≠ ลายเซ็น** — การให้ความเห็นไม่ใช่การรับรอง
     * เอกสารในทางกฎหมาย จึงใช้สิทธิ์คนละตัวและไม่ต้องยืนยันตัวตนซ้ำ
     */
    public function endorse(User $user, Document $document): bool
    {
        if (in_array($document->status, [Document::STATUS_SIGNED, Document::STATUS_CANCELLED], true)) {
            return false;
        }

        return $user->can('saraban.endorse.create') && $this->view($user, $document);
    }

    /**
     * ออกหนังสือฉบับสมบูรณ์พร้อม QR (SoT §8, §12 · INT-12)
     *
     * ใช้สิทธิ์เดียวกับการลงนาม เพราะเป็นขั้นสุดท้ายของการออกหนังสือฉบับเดียวกัน
     * และผู้ที่ลงนามได้คือผู้ที่มีอำนาจออกหนังสือนั้นอยู่แล้ว — การแยกสิทธิ์เพิ่ม
     * จะบังคับให้ต้องแจกสิทธิ์สองตัวให้คนเดียวกันเสมอโดยไม่ได้เพิ่มการควบคุมจริง
     */
    public function finalize(User $user, Document $document): bool
    {
        return $document->status === Document::STATUS_SIGNED
            && $user->can('saraban.signature.sign')
            && $this->scopes->canSeeOrgUnit(
                $user,
                'saraban.signature.sign',
                $document->owner_org_unit_id,
                OrgScopeResolver::UNIT,
            );
    }

    /** เสนอหนังสือเข้าสู่กระบวนการ — ทำได้ครั้งเดียวจากสถานะร่าง */
    public function submit(User $user, Document $document): bool
    {
        return $document->isDraft()
            && $user->can('saraban.document.submit')
            && $this->isOwner($user, $document);
    }

    /**
     * เวียนหนังสือถึงผู้รับ (SoT §15 · INT-13)
     *
     * ผู้เวียนต้องเปิดดูหนังสือฉบับนั้นได้อยู่แล้ว — คนที่ยังไม่มีสิทธิ์เห็นหนังสือ
     * ย่อมไม่มีสิทธิ์ตัดสินใจว่าจะให้ใครเห็นบ้าง
     *
     * เงื่อนไขว่าหนังสืออยู่ในสถานะที่เวียนได้หรือไม่ อยู่ที่ `DistributionService`
     * ซึ่งเป็นที่เดียวที่กติกานั้นถูกบังคับ ไม่กระจายกติกาเดียวกันไว้สองที่
     */
    public function distribute(User $user, Document $document): bool
    {
        return $user->can('saraban.distribution.send') && $this->view($user, $document);
    }

    /**
     * ติดตามผลการเวียนหนังสือ — ใครรับทราบแล้ว ใครยังไม่ได้ดำเนินการ
     *
     * แยกจาก `distribute()` เพราะงานติดตามเป็นงานของผู้บังคับบัญชาและงานสารบรรณ
     * ซึ่งอาจไม่ใช่คนเดียวกับผู้ที่มีสิทธิ์ส่ง
     */
    public function trackDistribution(User $user, Document $document): bool
    {
        return $user->can('saraban.distribution.track') && $this->view($user, $document);
    }

    private function isOwner(User $user, Document $document): bool
    {
        return (int) $document->created_by === (int) $user->id;
    }

    private function isRecipient(User $user, Document $document): bool
    {
        return DistributionRecipient::query()
            ->where('recipient_id', $user->id)
            ->whereIn(
                'distribution_id',
                Distribution::query()->where('document_id', $document->id)->select('id'),
            )
            ->exists();
    }

    /**
     * **ผู้เกี่ยวข้องในเรื่องนั้น** — ผู้ที่อยู่ในสายงานเดินหนังสือฉบับนี้
     *
     * ระเบียบว่าด้วยการรักษาความลับของทางราชการ พ.ศ. 2544 ใช้หลัก
     * **ความจำเป็นต้องรู้** ไม่ใช่ยศหรือตำแหน่งเพียงอย่างเดียว — ผู้ที่ได้รับ
     * มอบหมายให้ปฏิบัติงานในเรื่องนั้นย่อมเข้าถึงเรื่องนั้นได้ แม้ไม่ได้รับอนุญาต
     * ชั้นความลับนั้นไว้ล่วงหน้า
     *
     * ถ้าไม่มีข้อนี้ หนังสือลับที่เดินมาถึงโต๊ะใครสักคนตามสายงาน จะกลายเป็น
     * หนังสือที่เขาเปิดอ่านไม่ได้ทั้งที่ต้องเป็นผู้พิจารณา — งานจะค้างทันที
     *
     * นับสามกลุ่ม: ผู้เริ่มเรื่อง · ผู้ที่มีงานในสายงานนี้ (ทั้งที่ค้างอยู่และที่ทำไปแล้ว)
     * · ผู้ที่เคยลงมือทำอะไรกับเรื่องนี้ · รวมผู้ปฏิบัติแทนตามการมอบอำนาจด้วย
     */
    private function isWorkflowParticipant(User $user, Document $document): bool
    {
        $instances = WorkflowInstance::query()
            ->where('subject_type', $document->getMorphClass())
            ->where('subject_id', $document->id)
            ->select('id', 'initiated_by');

        $instanceIds = $instances->clone()->pluck('id');

        if ($instanceIds->isEmpty()) {
            return false;
        }

        if ($instances->clone()->where('initiated_by', $user->id)->exists()) {
            return true;
        }

        $hasTask = WorkflowTask::query()
            ->whereIn('workflow_instance_id', $instanceIds)
            ->where(fn ($query) => $query
                ->where('assignee_user_id', $user->id)
                ->orWhere('completed_by', $user->id)
                ->orWhere('effective_actor_id', $user->id))
            ->exists();

        if ($hasTask) {
            return true;
        }

        return WorkflowAction::query()
            ->whereIn('workflow_instance_id', $instanceIds)
            ->where(fn ($query) => $query
                ->where('actor_id', $user->id)
                ->orWhere('effective_actor_id', $user->id))
            ->exists();
    }
}
