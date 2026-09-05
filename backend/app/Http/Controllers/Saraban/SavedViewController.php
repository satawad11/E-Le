<?php

namespace App\Http\Controllers\Saraban;

use App\Http\Controllers\Controller;
use App\Models\Saraban\SavedView;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * มุมมองที่บันทึกไว้ — คิวงานส่วนตัวและที่แบ่งปันกัน (SoT §22)
 *
 * **มุมมองที่แบ่งปันไม่ได้แบ่งปันข้อมูล** — คนอื่นเปิดมุมมองเดียวกันจะเห็นเฉพาะ
 * หนังสือที่ตนมีสิทธิ์เห็นอยู่แล้ว เพราะสิ่งที่บันทึกคือ *เงื่อนไขการค้น*
 * ซึ่งถูกนำไปใช้กับ query ที่ผ่าน `DocumentPolicy` ตามปกติ
 *
 * **แก้และลบได้เฉพาะเจ้าของ** — แม้แต่มุมมองที่แบ่งปันแล้ว คนอื่นก็แก้ไม่ได้
 * ไม่งั้นคิวงานของคนหนึ่งจะเปลี่ยนใต้มือโดยคนที่ไม่ได้สร้างมัน
 */
class SavedViewController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'resource' => ['nullable', 'string', Rule::in(SavedView::resources())],
        ]);

        return response()->json([
            'views' => SavedView::query()
                ->with('owner:id,name')
                ->when(isset($filters['resource']), fn ($query) => $query->where('resource', $filters['resource']))
                // ของตัวเอง หรือของคนอื่นที่แบ่งปันไว้
                ->where(fn ($query) => $query
                    ->where('owner_id', $request->user()->id)
                    ->orWhere('is_shared', true))
                ->orderBy('name')
                ->get()
                ->map(fn (SavedView $view) => [
                    ...$view->only(['id', 'uuid', 'name', 'resource', 'filters', 'is_shared']),
                    'owner' => $view->owner?->name,
                    'is_mine' => (int) $view->owner_id === (int) $request->user()->id,
                ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'resource' => ['required', 'string', Rule::in(SavedView::resources())],
            'filters' => ['required', 'array'],
            'is_shared' => ['nullable', 'boolean'],
        ]);

        $view = SavedView::create([
            ...$data,
            'is_shared' => $data['is_shared'] ?? false,
            'owner_id' => $request->user()->id,
        ]);

        return response()->json(['view' => $view->only(['id', 'uuid', 'name', 'resource', 'filters', 'is_shared'])], 201);
    }

    public function update(Request $request, SavedView $view)
    {
        $this->assertOwner($request, $view);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'filters' => ['sometimes', 'array'],
            'is_shared' => ['sometimes', 'boolean'],
        ]);

        $view->update($data);

        return response()->json(['view' => $view->only(['id', 'uuid', 'name', 'resource', 'filters', 'is_shared'])]);
    }

    public function destroy(Request $request, SavedView $view)
    {
        $this->assertOwner($request, $view);

        $view->delete();

        return response()->json(['message' => 'ลบมุมมองแล้ว']);
    }

    /**
     * ใช้ 404 ไม่ใช่ 403 — การตอบ 403 ยืนยันว่ามุมมองนั้นมีอยู่จริง
     * ซึ่งเป็นการรั่วข้อมูลว่าคนอื่นทำงานอะไรอยู่
     */
    private function assertOwner(Request $request, SavedView $view): void
    {
        abort_unless((int) $view->owner_id === (int) $request->user()->id, 404);
    }
}
