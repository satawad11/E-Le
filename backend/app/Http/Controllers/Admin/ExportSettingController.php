<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\UploadRules;
use Illuminate\Http\Request;

class ExportSettingController extends Controller
{
    /**
     * Get all document export & form settings.
     */
    public function index()
    {
        $settings = Setting::all()->pluck('value', 'key')->toArray();

        $exportConfig = [
            'school_name' => $settings['school_name'] ?? 'วิทยาลัยเทคนิคดอนเมือง',
            'school_affiliation' => $settings['school_affiliation'] ?? 'สำนักงานคณะกรรมการการอาชีวศึกษา กระทรวงศึกษาธิการ',
            'school_address' => $settings['school_address'] ?? '425 ถนนสรงประภา แขวงดอนเมือง เขตดอนเมือง กรุงเทพมหานคร 10210',
            'school_phone' => $settings['school_phone'] ?? '02-565-3888',
            'school_email' => $settings['school_email'] ?? 'info@dmtc.ac.th',
            'director_name' => $settings['director_name'] ?? 'ผู้อำนวยการวิทยาลัยเทคนิคดอนเมือง',
            'director_position' => $settings['director_position'] ?? 'ผู้อำนวยการวิทยาลัยเทคนิคดอนเมือง',
            'deputy_admin_name' => $settings['deputy_admin_name'] ?? 'รองผู้อำนวยการฝ่ายบริหารทรัพยากร',
            'head_hr_name' => $settings['head_hr_name'] ?? 'หัวหน้างานบุคลากร',
            'garuda_logo_url' => $settings['garuda_logo_url'] ?? '/storage/settings/garuda.png',
            'school_logo_url' => $settings['school_logo_url'] ?? '/storage/settings/school_logo.png',
            'default_font' => $settings['default_font'] ?? 'TH Sarabun New',
            'margin_top' => $settings['margin_top'] ?? '2.5',
            'margin_bottom' => $settings['margin_bottom'] ?? '2.0',
            'margin_left' => $settings['margin_left'] ?? '3.0',
            'margin_right' => $settings['margin_right'] ?? '2.0',
            'doc_number_prefix' => $settings['doc_number_prefix'] ?? 'ศธ 0584.1',
        ];

        return response()->json($exportConfig);
    }

    /**
     * Update document export & form settings.
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            'school_name' => 'nullable|string|max:255',
            'school_affiliation' => 'nullable|string|max:255',
            'school_address' => 'nullable|string|max:500',
            'school_phone' => 'nullable|string|max:50',
            'school_email' => 'nullable|string|email|max:255',
            'director_name' => 'nullable|string|max:255',
            'director_position' => 'nullable|string|max:255',
            'deputy_admin_name' => 'nullable|string|max:255',
            'head_hr_name' => 'nullable|string|max:255',
            'default_font' => 'nullable|string|max:100',
            'margin_top' => 'nullable|numeric',
            'margin_bottom' => 'nullable|numeric',
            'margin_left' => 'nullable|numeric',
            'margin_right' => 'nullable|numeric',
            'doc_number_prefix' => 'nullable|string|max:100',
        ]);

        foreach ($data as $key => $val) {
            Setting::updateOrCreate(['key' => $key], ['value' => $val]);
        }

        return response()->json(['message' => 'บันทึกการตั้งค่าเอกสารเรียบร้อยแล้ว']);
    }

    /**
     * Upload Garuda / School logos.
     */
    public function uploadLogo(Request $request)
    {
        $request->validate([
            'type' => 'required|in:garuda,school',
            // SEC-023R — เดิมรับ `svg` ซึ่งเป็นไฟล์ที่รันสคริปต์ได้เมื่อเบราว์เซอร์เปิด
            'logo' => array_merge(['required'], UploadRules::image()),
        ]);

        $path = $request->file('logo')->store('settings', 'private_uploads');
        $url = '/storage/'.$path;

        $key = $request->type === 'garuda' ? 'garuda_logo_url' : 'school_logo_url';
        Setting::updateOrCreate(['key' => $key], ['value' => $url]);

        return response()->json([
            'message' => 'อัปโหลดตราสัญลักษณ์เรียบร้อยแล้ว',
            'url' => $url,
        ]);
    }
}
