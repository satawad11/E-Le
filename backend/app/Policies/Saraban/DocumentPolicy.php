<?php

namespace App\Policies\Saraban;

use App\Models\Saraban\Document;
use App\Models\User;

class DocumentPolicy
{
    /**
     * Statuses after which a document is part of the official record and must
     * no longer be edited or deleted through the normal CRUD endpoints.
     */
    public const LOCKED_STATUSES = ['signed', 'dispatched', 'archived'];

    /**
     * Users who may see every document regardless of authorship or receivership.
     *
     * LEGACY-04 (ปิดงาน) · Decision 2 — เดิมบรรทัดนี้เช็ค `hasRole('admin_saraban')`
     * ซึ่งเป็น role ที่ **ไม่มีอยู่จริงบนระบบ** เจ้าหน้าที่สารบรรณตัวจริง
     * (`Document Officer`) จึงไม่เคยถูกนับเป็นเจ้าหน้าที่ทะเบียนเลย
     *
     * ตัดสินจากสิทธิ์ "รับ/ส่งหนังสือ" แทน ตามที่เจ้าของระบบสั่งให้แยกสิทธิ์นี้ออกมา
     * Super Admin ยังผ่านได้เหมือนเดิมผ่าน Gate::before ที่ `can()` เรียกใช้
     */
    private function isRegistrar(User $user): bool
    {
        return $user->can('saraban.document.register');
    }

    /**
     * A document is visible to its creator, its receivers, and to registrars.
     *
     * SEC-026 — ผู้ที่ได้รับหนังสือเวียนนับเป็นผู้เกี่ยวข้องด้วย
     *
     * เดิมไม่นับ ผลคือระบบเวียนหนังสือขัดกันเอง: `/circulars/incoming` ส่งเนื้อ
     * หนังสือกลับไปให้ผู้รับ (eager load `circular.document`) แต่พอผู้รับกดเปิด
     * หน้ารายละเอียดกลับได้ 403 — ทั้งเห็นข้อมูลที่ policy ไม่อนุญาต
     * และใช้งานฟีเจอร์ไม่ได้ไปพร้อมกัน
     *
     * ชั้นความลับยังถูกบังคับแยกต่างหากใน view() และผู้ส่งก็เลือกผู้รับที่ไม่มี
     * สิทธิ์เห็นไม่ได้ตั้งแต่ต้น (CircularController::send)
     */
    private function isInvolved(User $user, Document $document): bool
    {
        return $document->created_by === $user->id
            || $document->receivers()->where('users.id', $user->id)->exists()
            || $document->circulars()
                ->whereHas('recipients', fn ($query) => $query->where('user_id', $user->id))
                ->exists();
    }

    public function viewAny(User $user): bool
    {
        return $user->can('saraban.document.view');
    }

    public function view(User $user, Document $document): bool
    {
        if (! $user->can('saraban.document.view')) {
            return false;
        }

        if (! $this->isRegistrar($user) && ! $this->isInvolved($user, $document)) {
            return false;
        }

        // Classified documents need the dedicated permission on top of the above.
        if ($document->secrecy !== 'normal' && ! $user->can('saraban.document.view_classified')) {
            return false;
        }

        return true;
    }

    public function create(User $user): bool
    {
        return $user->can('saraban.document.create');
    }

    public function update(User $user, Document $document): bool
    {
        if (! $user->can('saraban.document.edit') || ! $this->view($user, $document)) {
            return false;
        }

        if (in_array($document->status, self::LOCKED_STATUSES, true)) {
            return false;
        }

        return $this->isRegistrar($user) || $document->created_by === $user->id;
    }

    public function delete(User $user, Document $document): bool
    {
        if (! $user->can('saraban.document.delete') || ! $this->view($user, $document)) {
            return false;
        }

        if (in_array($document->status, self::LOCKED_STATUSES, true)) {
            return false;
        }

        return $this->isRegistrar($user) || $document->created_by === $user->id;
    }

    public function approve(User $user, Document $document): bool
    {
        return $user->can('saraban.document.approve') && $this->view($user, $document);
    }

    public function submit(User $user, Document $document): bool
    {
        return $this->view($user, $document)
            && ($this->isRegistrar($user) || $document->created_by === $user->id);
    }

    public function sign(User $user, Document $document): bool
    {
        return $user->can('saraban.document.sign')
            && $document->status === 'approved_pending_signature'
            && $document->receivers()->where('users.id', $user->id)->exists()
            && $this->view($user, $document);
    }

    public function circulate(User $user, Document $document): bool
    {
        return $this->view($user, $document)
            && ($this->isRegistrar($user)
                || $user->can('saraban.document.approve')
                || $document->created_by === $user->id);
    }

    /**
     * Downloading a file attached to a document. `allow_download` is an explicit
     * opt-out set by the registrar; registrars themselves are never blocked by it.
     */
    public function download(User $user, Document $document): bool
    {
        if (! $this->view($user, $document)) {
            return false;
        }

        return $this->isRegistrar($user) || (bool) $document->allow_download;
    }
}
