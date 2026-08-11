<?php

namespace App\Policies\Saraban;

use App\Models\Saraban\Document;
use App\Models\User;

class DocumentPolicy
{
    /**
     * Role names that act as เจ้าหน้าที่สารบัญ (full registry access).
     * Kept as a single list so controllers/services never hardcode role strings.
     */
    public const OFFICER_ROLES = ['Saraban Officer', 'เจ้าหน้าที่สารบัญ', 'admin_saraban'];

    public const ADMIN_ROLES = ['Super Admin', 'SuperAdmin'];

    public const APPROVER_ROLES = ['Director', 'Deputy Director', 'Deputy', 'HR Head', 'HRHead'];

    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole(self::ADMIN_ROLES) ? true : null;
    }

    public static function isOfficer(User $user): bool
    {
        return $user->hasRole(self::ADMIN_ROLES) || $user->hasRole(self::OFFICER_ROLES);
    }

    public static function isApprover(User $user): bool
    {
        return $user->hasRole(self::APPROVER_ROLES) || $user->can('saraban.document.approve');
    }

    protected function isInvolved(User $user, Document $document): bool
    {
        return $document->created_by === $user->id
            || $document->receivers()->where('users.id', $user->id)->exists();
    }

    /**
     * ทุกคนเปิดหน้าทะเบียนได้ แต่ query ถูกกรองรายแถวตามความเกี่ยวข้อง/ชั้นความลับ
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Document $document): bool
    {
        if (!self::isOfficer($user) && !$this->isInvolved($user, $document)) {
            return false;
        }

        return $this->viewClassified($user, $document);
    }

    /**
     * ชั้นความลับ: หนังสือที่ไม่ใช่ "ปกติ" ต้องมีสิทธิ์เพิ่มเติม
     * (ผู้สร้างเอกสารเองเข้าถึงได้เสมอ)
     */
    public function viewClassified(User $user, Document $document): bool
    {
        if ($document->secrecy === 'normal') {
            return true;
        }

        return $document->created_by === $user->id
            || $user->can('saraban.document.view_classified')
            || $user->hasRole(self::OFFICER_ROLES);
    }

    public function create(User $user): bool
    {
        return $user->can('saraban.document.create') || self::isOfficer($user);
    }

    public function update(User $user, Document $document): bool
    {
        if (in_array($document->status, ['signed', 'archived'], true) && !self::isOfficer($user)) {
            return false;
        }

        return self::isOfficer($user)
            || ($document->created_by === $user->id && $user->can('saraban.document.edit'));
    }

    public function delete(User $user, Document $document): bool
    {
        return self::isOfficer($user)
            || ($document->created_by === $user->id && $user->can('saraban.document.delete'));
    }

    public function submit(User $user, Document $document): bool
    {
        return self::isOfficer($user) || $document->created_by === $user->id;
    }

    public function approve(User $user, Document $document): bool
    {
        return self::isOfficer($user) || self::isApprover($user);
    }

    public function sign(User $user, Document $document): bool
    {
        return self::isOfficer($user)
            || self::isApprover($user)
            || $user->can('saraban.document.sign');
    }

    public function circulate(User $user, Document $document): bool
    {
        return self::isOfficer($user) || $document->created_by === $user->id;
    }

    public function download(User $user, Document $document): bool
    {
        if (!$this->view($user, $document)) {
            return false;
        }

        // allow_download = false → เฉพาะเจ้าหน้าที่สารบัญ/ผู้สร้างเท่านั้น
        if (($document->allow_download ?? true) == false) {
            return self::isOfficer($user) || $document->created_by === $user->id;
        }

        return true;
    }
}
