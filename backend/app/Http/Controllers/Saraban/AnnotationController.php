<?php

namespace App\Http\Controllers\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentAnnotation;
use App\Models\Saraban\DocumentVersion;
use App\Services\Saraban\AnnotationService;
use App\Support\StoredFile;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PDF Workspace — วางตราประทับและข้อความบนหน้าหนังสือ (SoT §14 · INT-10)
 *
 * **ไม่มี endpoint ใดรับภาพจากเบราว์เซอร์เลย** — รับเฉพาะพิกัดและเนื้อหาเป็นข้อมูล
 * แล้วให้ `SarabanPdfRenderer` วาดที่ฝั่ง server ตามที่ SoT §14 บังคับ
 *
 * ไฟล์ของเวอร์ชันยังเสิร์ฟผ่าน `SecureFileController` เดิมเหมือนทุกไฟล์ในระบบ
 * — controller นี้คืนเพียง path ให้หน้าเว็บนำไปเรียกผ่านช่องทางที่ตรวจสิทธิ์แล้ว
 */
class AnnotationController extends Controller
{
    public function __construct(private readonly AnnotationService $annotations) {}

    /** วัตถุที่วางไว้บนเวอร์ชันปัจจุบัน พร้อมข้อมูลตราประทับรับที่เติมให้แล้ว */
    public function index(Document $document)
    {
        $this->authorize('view', $document);

        $version = $document->currentVersion;

        return response()->json([
            'version' => $version === null ? null : [
                'id' => $version->id,
                'version_no' => $version->version_no,
                'stage' => $version->stage,
                // path สำหรับเรียกผ่าน /api/files/{path} ซึ่งตรวจสิทธิ์รายไฟล์อยู่แล้ว
                'file_path' => $version->pdf_file_path,
            ],
            'editable' => $this->annotations->isEditable($document),
            'annotations' => $version === null ? [] : DocumentAnnotation::query()
                ->where('document_version_id', $version->id)
                ->orderBy('page')
                ->get(),
            'receive_stamp' => $this->annotations->receiveStampPayloadFor($document),
        ]);
    }

    /**
     * บันทึกชุดวัตถุทั้งชุดของเวอร์ชันปัจจุบัน
     *
     * พิกัดที่รับเป็น **PDF point จากมุมซ้ายบน** — หน้าเว็บเป็นผู้แปลงจากพิกัด
     * บนจอตามระดับการซูมก่อนส่งมา เพื่อให้ผลลัพธ์ไม่ขึ้นกับขนาดจอของคนวาง
     */
    public function store(Request $request, Document $document)
    {
        $this->authorize('stamp', $document);

        $version = $document->currentVersion;

        abort_if($version === null, 422, 'หนังสือฉบับนี้ยังไม่มีไฟล์ให้วางตรา');

        if (! $this->annotations->isEditable($document)) {
            return response()->json([
                'message' => 'หนังสือที่ลงนามหรือยกเลิกแล้ว วางตราเพิ่มไม่ได้',
            ], 422);
        }

        $data = $request->validate([
            'annotations' => ['present', 'array'],
            'annotations.*.page' => ['required', 'integer', 'min:1'],
            'annotations.*.type' => ['required', 'string', Rule::in(DocumentAnnotation::types())],
            'annotations.*.x' => ['required', 'numeric', 'min:0', 'max:5000'],
            'annotations.*.y' => ['required', 'numeric', 'min:0', 'max:5000'],
            'annotations.*.width' => ['nullable', 'numeric', 'min:1', 'max:5000'],
            'annotations.*.height' => ['nullable', 'numeric', 'min:1', 'max:5000'],
            'annotations.*.payload' => ['nullable', 'array'],
        ]);

        return response()->json([
            'annotations' => $this->annotations->replaceFor($version, $data['annotations'], $request->user()),
        ]);
    }

    /**
     * เรนเดอร์ไฟล์ที่ประทับตราแล้วเป็นเวอร์ชันใหม่ — ที่ฝั่ง server (SoT §14)
     */
    public function render(Request $request, Document $document)
    {
        $this->authorize('stamp', $document);

        $version = $document->currentVersion;

        abort_if($version === null, 422, 'หนังสือฉบับนี้ยังไม่มีไฟล์ให้เรนเดอร์');

        $rendered = $this->annotations->renderStampedVersion($document, $version, $request->user());

        return response()->json([
            'version' => [
                'id' => $rendered->id,
                'version_no' => $rendered->version_no,
                'stage' => $rendered->stage,
                'file_path' => $rendered->pdf_file_path,
                'file_hash' => $rendered->file_hash,
            ],
        ], 201);
    }

    /** ขนาดหน้าเป็น PDF point — หน้าเว็บใช้คำนวณอัตราส่วนตอนแสดงผล */
    public function pageInfo(Document $document, DocumentVersion $version)
    {
        $this->authorize('view', $document);

        abort_unless((int) $version->document_id === (int) $document->id, 404);

        return response()->json([
            'file_path' => $version->pdf_file_path,
            'exists' => StoredFile::exists('/storage/'.$version->pdf_file_path),
        ]);
    }
}
