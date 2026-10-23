<?php

namespace App\Services\Personnel;

use App\Models\Personnel;
use App\Models\PersonnelDocument;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ไฟล์เอกสารบุคลากรบน private disk (`local` = storage/app/private) — ชื่อไฟล์บนดิสก์สุ่ม ไม่ใช้ชื่อที่ผู้ใช้ส่งมา
 */
class PersonnelFiles
{
    public const DISK = 'local';

    private const MIMES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Personnel $personnel, UploadedFile $file, string $category, User $actor, bool $verified): PersonnelDocument
    {
        // ชนิดไฟล์จากเนื้อไฟล์ ไม่ใช่นามสกุลที่ผู้ใช้ตั้ง
        $mime = (string) $file->getMimeType();
        $extension = self::MIMES[$mime] ?? throw ValidationException::withMessages(['file' => 'รองรับเฉพาะ PDF, JPG และ PNG']);

        $path = 'personnel/'.$personnel->uuid.'/'.Str::uuid().'.'.$extension;
        Storage::disk(self::DISK)->putFileAs(dirname($path), $file, basename($path));

        return DB::transaction(function () use ($personnel, $file, $category, $actor, $verified, $path, $mime) {
            $document = PersonnelDocument::create([
                'personnel_id' => $personnel->id,
                'category' => $category,
                'storage_path' => $path,
                'original_name' => mb_substr(basename((string) $file->getClientOriginalName()), 0, 200),
                'mime' => $mime,
                'size' => (int) $file->getSize(),
                'uploaded_by' => $actor->id,
                'verified_by' => $verified ? $actor->id : null,
                'verified_at' => $verified ? now() : null,
            ]);
            $this->audit->log('personnel_document.uploaded', $personnel, null, null, ['document' => $document->uuid, 'category' => $category, 'size' => $document->size], null, $actor->id);

            return $document;
        });
    }

    public function download(PersonnelDocument $document): StreamedResponse
    {
        $disk = Storage::disk(self::DISK);
        abort_unless($disk->exists($document->storage_path), 404, 'ไม่พบไฟล์');

        $response = $disk->download($document->storage_path, $document->original_name, ['Content-Type' => $document->mime]);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public function delete(PersonnelDocument $document, User $actor, string $reason): void
    {
        DB::transaction(function () use ($document, $actor, $reason) {
            $this->audit->log('personnel_document.deleted', $document->personnel, null, ['document' => $document->uuid, 'category' => $document->category], null, $reason, $actor->id);
            $document->delete();
        });
        Storage::disk(self::DISK)->delete($document->storage_path);
    }
}
