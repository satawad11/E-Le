<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\UploadRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SettingController extends Controller
{
    public function index()
    {
        $settings = Setting::all()->pluck('value', 'key');

        return response()->json($settings);
    }

    public function update(Request $request)
    {
        // Allow all keys dynamically for flexibility
        $data = $request->all();

        foreach ($data as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => is_array($value) ? json_encode($value) : $value]
            );
        }

        // ลบ cache เพื่อให้ request ถัดไปโหลด settings ใหม่
        Cache::forget('app_settings');

        return response()->json(['message' => 'บันทึกการตั้งค่าเรียบร้อยแล้ว']);
    }

    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => array_merge(['required'], UploadRules::image(2048)),
        ]);

        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $filename = time().'_'.$file->getClientOriginalName();
            $path = $file->storeAs('logos', $filename, 'private_uploads');

            $url = '/storage/'.$path;

            Setting::updateOrCreate(
                ['key' => 'organization_logo'],
                ['value' => $url]
            );

            // ลบ cache เมื่อ logo เปลี่ยน
            Cache::forget('app_settings');

            return response()->json([
                'message' => 'อัปโหลดตราสัญลักษณ์เรียบร้อยแล้ว',
                'url' => $url,
            ]);
        }

        return response()->json(['message' => 'ไม่พบไฟล์ที่อัปโหลด'], 400);
    }
}
