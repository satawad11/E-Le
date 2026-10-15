<?php

namespace App\Services\DocumentEngine;

use App\Models\Document\DocumentBindingRule;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Services\AuditLogger;
use App\Services\LeaveDocumentTemplateResolver;

/**
 * แบบฟอร์มที่ออกแบบได้ของแต่ละประเภทการลา และสวิตช์ "ใช้แบบฟอร์มที่ออกแบบ"
 *
 * สวิตช์ = กติกาผูกแบบฟอร์ม (binding rule) ของประเภทการลานั้นกับแม่แบบ `designer_<code>`
 * เปิดสวิตช์ได้เมื่อแบบฟอร์มมีเวอร์ชันที่เผยแพร่แล้วเท่านั้น
 *
 * ใบลาที่ยื่น "หลัง" เปิดสวิตช์จะ pin เวอร์ชัน PDF และพิมพ์ด้วยแบบฟอร์มนี้
 * ใบลาที่ยื่นก่อนหน้ายังพิมพ์ด้วยแบบฟอร์มที่ pin ไว้เดิม (กฎเอกสารย้อนหลัง)
 */
class LeaveTypeForms
{
    /** ใช้แทน id ของประเภทการลาสำหรับแบบใบขอยกเลิกวันลา (ใช้กับทุกประเภท) */
    public const CANCELLATION = 'cancel';

    /** กติกาของแบบฟอร์มที่ออกแบบต้องชนะกติกาเดิม (500) และกติกาที่นำเข้า (50–150) */
    private const PRIORITY = 10;

    public function __construct(
        private readonly LeaveFormGenerator $generator,
        private readonly LeaveDocumentTemplateResolver $resolver,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function rows(): array
    {
        $rows = LeaveType::orderBy('id')->get()->map(function (LeaveType $type) {
            $code = $this->resolver->templateCodeForLeave((new Leave)->setRelation('type', $type));

            return $this->row((string) $type->id, $type->name, $code, 'LEAVE_TYPE', $type->id);
        })->all();

        $rows[] = $this->row(self::CANCELLATION, 'ใบขอยกเลิกวันลา (ทุกประเภท)', 'leave_cancel', 'LEAVE_CANCELLATION', null);

        return $rows;
    }

    public function setActive(string $target, bool $active, ?int $actorId = null): DocumentBindingRule
    {
        [$code, $entityType, $entityId] = $this->target($target);
        $template = $code === null ? null : $this->generator->templateFor($code);

        if ($template === null) {
            throw new \DomainException('ประเภทการลานี้ยังไม่มีแบบฟอร์มสำหรับหน้าออกแบบ — กด "สร้างแบบฟอร์ม" ก่อน');
        }

        if ($active && $this->activeVersion($template) === null) {
            throw new \DomainException('แบบฟอร์มยังไม่ได้เผยแพร่ — เปิดหน้าออกแบบแล้วกดเผยแพร่ก่อนเปิดใช้งาน');
        }

        $rule = DocumentBindingRule::updateOrCreate(
            ['module' => 'LEAVE', 'entity_type' => $entityType, 'entity_id' => $entityId, 'document_template_id' => $template->id],
            ['priority' => self::PRIORITY, 'is_active' => $active, 'created_by' => $actorId],
        );

        $this->audit->log($active ? 'leave_form_designer_enabled' : 'leave_form_designer_disabled', 'DocumentBindingRule', $rule->id, after: [
            'target' => $target, 'template' => $template->code,
        ], actorId: $actorId);

        return $rule;
    }

    public function generate(string $target, ?int $actorId = null): DocumentTemplateVersion
    {
        [$code] = $this->target($target);

        if ($code === null) {
            throw new \DomainException('ประเภทการลานี้ยังไม่มีแบบฟอร์มในระบบให้สร้างเป็นแบบฟอร์มสำหรับหน้าออกแบบ');
        }

        return $this->generator->generate($code, $actorId);
    }

    /**
     * แบบฟอร์มที่ออกแบบและเปิดใช้งานอยู่ของประเภทการลานี้ (เวอร์ชันที่เผยแพร่แล้ว) — null = ใช้แบบฟอร์มเดิม
     */
    public function enabledVersion(?int $leaveTypeId, bool $isCancel = false): ?DocumentTemplateVersion
    {
        $rule = DocumentBindingRule::query()
            ->where('module', 'LEAVE')
            ->where('entity_type', $isCancel ? 'LEAVE_CANCELLATION' : 'LEAVE_TYPE')
            ->where('is_active', true)
            ->where(fn ($query) => $isCancel ? $query->whereNull('entity_id')->orWhere('entity_id', $leaveTypeId) : $query->where('entity_id', $leaveTypeId))
            ->whereHas('template', fn ($query) => $query->where('code', 'like', LeaveFormGenerator::TEMPLATE_PREFIX.'%'))
            ->with('template')
            ->orderBy('priority')
            ->first();

        return $rule?->template ? $this->activeVersion($rule->template) : null;
    }

    /** @return array<string, mixed> */
    private function row(string $target, string $name, ?string $code, string $entityType, ?int $entityId): array
    {
        $template = $code === null ? null : $this->generator->templateFor($code);
        $active = $template ? $this->activeVersion($template) : null;
        $draft = $template?->versions()->where('status', DocumentTemplateVersion::STATUS_DRAFT)->orderByDesc('version')->first();
        $rule = $template === null ? null : DocumentBindingRule::where([
            'module' => 'LEAVE', 'entity_type' => $entityType, 'entity_id' => $entityId, 'document_template_id' => $template->id,
        ])->first();

        return [
            'target' => $target,
            'name' => $name,
            'form_code' => $code,
            'template' => $template?->only(['id', 'code', 'name']),
            'active_version' => $active?->only(['id', 'version', 'published_at']),
            'draft_version' => $draft?->only(['id', 'version']),
            'enabled' => (bool) ($rule?->is_active) && $active !== null,
            'can_generate' => $code !== null,
        ];
    }

    /** @return array{0: ?string, 1: string, 2: ?int} [form code, entity type, entity id] */
    private function target(string $target): array
    {
        if ($target === self::CANCELLATION) {
            return ['leave_cancel', 'LEAVE_CANCELLATION', null];
        }

        $type = LeaveType::findOrFail((int) $target);

        return [$this->resolver->templateCodeForLeave((new Leave)->setRelation('type', $type)), 'LEAVE_TYPE', $type->id];
    }

    private function activeVersion(DocumentTemplate $template): ?DocumentTemplateVersion
    {
        return $template->versions()
            ->where('status', DocumentTemplateVersion::STATUS_ACTIVE)
            ->whereNotNull('pdf_file_path')
            ->orderByDesc('version')
            ->first();
    }
}
