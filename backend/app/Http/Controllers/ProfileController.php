<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        return response()->json($request->user()->load('profile', 'department', 'roles'));
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'phone' => 'nullable|string|max:20',
            'telegram_chat_id' => 'nullable|string|max:255',
            'password' => 'nullable|string|min:6',
            'profile' => 'nullable|array',
            'signature_file' => 'nullable|image|max:2048',
            'signature_base64' => 'nullable|string',
        ]);

        $user->phone = $request->phone;
        $user->telegram_chat_id = $request->telegram_chat_id;
        
        if ($request->filled('password')) {
            $user->password = bcrypt($request->password);
        }
        
        $user->save();

        if ($request->has('profile')) {
            $allowedProfileFields = [
                'house_registration_number', 'house_number', 'moo', 'soi', 'road', 
                'province', 'district', 'sub_district', 'zipcode', 
                'fax', 'website', 'religion'
            ];
            
            $profileData = is_string($request->profile) ? json_decode($request->profile, true) : $request->profile;
            $filteredData = [];
            
            foreach ($allowedProfileFields as $field) {
                if (isset($profileData[$field])) {
                    $filteredData[$field] = $profileData[$field];
                }
            }

            if ($request->hasFile('profile_photo')) {
                $path = $request->file('profile_photo')->store('profile_photos', 'public');
                $filteredData['profile_photo_path'] = '/storage/' . $path;
            }

            if ($request->hasFile('signature_file')) {
                $path = $request->file('signature_file')->store('signatures', 'public');
                $filteredData['signature_path'] = '/storage/' . $path;
            } else if ($request->filled('signature_base64')) {
                $base64 = $request->signature_base64;
                if (preg_match('/^data:image\/(\w+);base64,/', $base64, $type)) {
                    $base64 = substr($base64, strpos($base64, ',') + 1);
                    $type = strtolower($type[1]); // jpg, png, gif
                    $base64 = base64_decode($base64);
                    if ($base64 !== false) {
                        $fileName = 'signatures/' . uniqid() . '.' . $type;
                        \Illuminate\Support\Facades\Storage::disk('public')->put($fileName, $base64);
                        $filteredData['signature_path'] = '/storage/' . $fileName;
                    }
                }
            }

            if (!empty($filteredData)) {
                $user->profile()->updateOrCreate(
                    ['user_id' => $user->id],
                    $filteredData
                );
            }
        } else {
            $filteredData = [];
            if ($request->hasFile('profile_photo')) {
                $path = $request->file('profile_photo')->store('profile_photos', 'public');
                $filteredData['profile_photo_path'] = '/storage/' . $path;
            }
            if ($request->hasFile('signature_file')) {
                $path = $request->file('signature_file')->store('signatures', 'public');
                $filteredData['signature_path'] = '/storage/' . $path;
            } else if ($request->filled('signature_base64')) {
                $base64 = $request->signature_base64;
                if (preg_match('/^data:image\/(\w+);base64,/', $base64, $type)) {
                    $base64 = substr($base64, strpos($base64, ',') + 1);
                    $type = strtolower($type[1]);
                    $base64 = base64_decode($base64);
                    if ($base64 !== false) {
                        $fileName = 'signatures/' . uniqid() . '.' . $type;
                        \Illuminate\Support\Facades\Storage::disk('public')->put($fileName, $base64);
                        $filteredData['signature_path'] = '/storage/' . $fileName;
                    }
                }
            }

            if (!empty($filteredData)) {
                $user->profile()->updateOrCreate(
                    ['user_id' => $user->id],
                    $filteredData
                );
            }
        }

        return response()->json([
            'message' => 'อัปเดตข้อมูลส่วนตัวเรียบร้อยแล้ว',
            'user' => $user->load('profile', 'department', 'roles')
        ]);
    }

    public function getDelegation(Request $request)
    {
        $delegations = \App\Models\UserDelegation::with('delegate:id,name')
            ->where('user_id', $request->user()->id)
            ->get();
        return response()->json($delegations);
    }

    public function setDelegation(Request $request)
    {
        $request->validate([
            'delegate_id' => 'required|exists:users,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'is_active' => 'boolean'
        ]);

        $delegation = \App\Models\UserDelegation::updateOrCreate(
            ['user_id' => $request->user()->id, 'delegate_id' => $request->delegate_id],
            [
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'is_active' => $request->is_active ?? true
            ]
        );

        return response()->json([
            'message' => 'บันทึกการมอบหมายสำเร็จ',
            'delegation' => $delegation->load('delegate:id,name')
        ]);
    }
}
