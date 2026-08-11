<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemSettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class SecureExportSettingController extends Controller
{
    private const KEYS = [
        'institution_name', 'school_affiliation', 'school_address', 'school_phone', 'school_email',
        'director_name', 'director_position', 'deputy_admin_name', 'head_hr_name',
        'saraban_garuda_logo', 'organization_logo', 'default_font',
        'margin_top', 'margin_bottom', 'margin_left', 'margin_right', 'document_number_out',
    ];

    public function __construct(private readonly SystemSettingService $settings)
    {
    }

    public function index()
    {
        $values = $this->settings->adminValues();
        return response()->json([
            'institution_name' => $values['institution_name'] ?? '',
            'school_affiliation' => $values['school_affiliation'] ?? '',
            'school_address' => $values['school_address'] ?? '',
            'school_phone' => $values['school_phone'] ?? '',
            'school_email' => $values['school_email'] ?? '',
            'director_name' => $values['director_name'] ?? '',
            'director_position' => $values['director_position'] ?? '',
            'deputy_admin_name' => $values['deputy_admin_name'] ?? '',
            'head_hr_name' => $values['head_hr_name'] ?? '',
            'saraban_garuda_logo' => $values['saraban_garuda_logo'] ?? '',
            'organization_logo' => $values['organization_logo'] ?? '',
            'default_font' => $values['default_font'] ?? 'TH Sarabun New',
            'margin_top' => $values['margin_top'] ?? '2.5',
            'margin_bottom' => $values['margin_bottom'] ?? '2.0',
            'margin_left' => $values['margin_left'] ?? '3.0',
            'margin_right' => $values['margin_right'] ?? '2.0',
            'document_number_out' => $values['document_number_out'] ?? '',
        ]);
    }

    public function update(Request $request)
    {
        $rules = Arr::only(config('system_settings.rules', []), self::KEYS);
        $validated = $request->validate($rules);
        $this->settings->updateMany($validated, $request->user(), $request->ip(), $request->userAgent());
        return response()->json(['message' => 'บันทึกการตั้งค่าเอกสารเรียบร้อยแล้ว']);
    }

    public function uploadLogo(Request $request)
    {
        $request->validate([
            'type' => 'required|in:garuda,school',
            'logo' => 'required|image|mimes:png,jpg,jpeg|max:4096',
        ]);
        $key = $request->type === 'garuda' ? 'saraban_garuda_logo' : 'organization_logo';
        $oldPath = $this->settings->runtimeValues()->get($key);
        $path = $request->file('logo')->store('settings', 'public');
        $url = '/storage/' . $path;
        $this->settings->updateMany([$key => $url], $request->user(), $request->ip(), $request->userAgent());
        if ($oldPath && $oldPath !== $url && str_starts_with($oldPath, '/storage/')) {
            Storage::disk('public')->delete(substr($oldPath, strlen('/storage/')));
        }
        return response()->json(['message' => 'อัปโหลดตราสัญลักษณ์เรียบร้อยแล้ว', 'url' => $url]);
    }
}
