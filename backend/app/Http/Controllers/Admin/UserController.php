<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Role;
use App\Models\Department;
use App\Models\LeaveType;
use App\Models\LeaveBalance;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{


    public function index(Request $request)
    {

        $users = User::with(['roles', 'department', 'profile'])->orderBy('name', 'asc')->get();
        return response()->json($users);
    }

    public function list(Request $request)
    {
        // Accessible by any authenticated user for dropdowns
        $users = User::select('id', 'name', 'department_id')->orderBy('name', 'asc')->get();
        return response()->json($users);
    }

    public function store(Request $request)
    {

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'nullable|string|min:6',
            'role_id' => 'required|exists:roles,id',
            'department_id' => 'required|exists:departments,id',
            'telegram_chat_id' => 'nullable|string|max:255',
            'profile' => 'nullable|array',
            'custom_permissions' => 'nullable',
        ]);

        $profileData = $request->has('profile') ? (is_string($request->profile) ? json_decode($request->profile, true) : $request->profile) : [];
        $idCard = $profileData['id_card_number'] ?? null;
        
        $password = $request->filled('password') ? $request->password : ($idCard ?: 'password');

        $user = DB::transaction(function() use ($request, $profileData, $password) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($password),
                'role_id' => $request->role_id,
                'department_id' => $request->department_id,
                'telegram_chat_id' => $request->telegram_chat_id,
            ]);

            $roleName = $request->user()->role->name ?? '';
            if (in_array($roleName, ['Super Admin', 'SuperAdmin']) && $request->has('custom_permissions')) {
                $user->custom_permissions = is_string($request->custom_permissions) ? json_decode($request->custom_permissions, true) : $request->custom_permissions;
                $user->save();
            }

            if ($request->hasFile('profile_photo')) {
                $path = $request->file('profile_photo')->store('profile_photos', 'public');
                $profileData['profile_photo_path'] = '/storage/' . $path;
            }
            $user->profile()->create($profileData);

            // Seed leave balances for current fiscal year
            $currentFiscalYear = \App\Models\FiscalYear::current();
            if ($currentFiscalYear) {
                $leaveBalanceService = new \App\Services\LeaveBalanceService();
                $user->load('profile'); // Ensure profile is loaded for start_date
                
                foreach (LeaveType::all() as $lt) {
                    $quota = $leaveBalanceService->calculateQuota($user, $lt, $currentFiscalYear);
                    
                    LeaveBalance::create([
                        'user_id' => $user->id,
                        'leave_type_id' => $lt->id,
                        'year' => $currentFiscalYear->year,
                        'quota' => $quota,
                        'used' => 0,
                    ]);
                }
            }

            return $user;
        });

        return response()->json([
            'message' => 'สร้างผู้ใช้เรียบร้อยแล้ว',
            'user' => $user->load('roles', 'department', 'profile')
        ], 201);
    }

    public function update(Request $request, User $user)
    {

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:6',
            'role_id' => 'required|exists:roles,id',
            'department_id' => 'required|exists:departments,id',
            'telegram_chat_id' => 'nullable|string|max:255',
            'profile' => 'nullable|array',
            'custom_permissions' => 'nullable',
        ]);

        $user->name = $request->name;
        $user->email = $request->email;
        $user->role_id = $request->role_id;
        $user->department_id = $request->department_id;
        $user->telegram_chat_id = $request->telegram_chat_id;

        if ($request->filled('password')) {
            $user->password = bcrypt($request->password);
        }

        $roleName = $request->user()->role->name ?? '';
        if (in_array($roleName, ['Super Admin', 'SuperAdmin']) && $request->has('custom_permissions')) {
            $user->custom_permissions = is_string($request->custom_permissions) ? json_decode($request->custom_permissions, true) : $request->custom_permissions;
        }

        $user->save();

        if ($request->has('profile')) {
            $profileData = is_string($request->profile) ? json_decode($request->profile, true) : $request->profile;
            if ($request->hasFile('profile_photo')) {
                $path = $request->file('profile_photo')->store('profile_photos', 'public');
                $profileData['profile_photo_path'] = '/storage/' . $path;
            }
            $user->profile()->updateOrCreate(
                ['user_id' => $user->id],
                $profileData
            );
        } else if ($request->hasFile('profile_photo')) {
            $path = $request->file('profile_photo')->store('profile_photos', 'public');
            $user->profile()->updateOrCreate(
                ['user_id' => $user->id],
                ['profile_photo_path' => '/storage/' . $path]
            );
        }

        return response()->json([
            'message' => 'แก้ไขข้อมูลผู้ใช้เรียบร้อยแล้ว',
            'user' => $user->load('roles', 'department', 'profile')
        ]);
    }

    public function destroy(Request $request, User $user)
    {

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'ไม่สามารถลบบัญชีของคุณเองได้'], 400);
        }

        if ($user->hasRole(['Super Admin', 'SuperAdmin'])) {
            return response()->json(['message' => 'ไม่สามารถลบผู้ดูแลระบบสูงสุดได้'], 403);
        }

        $user->delete();

        return response()->json(['message' => 'ลบผู้ใช้เรียบร้อยแล้ว']);
    }

    public function toggleStatus(Request $request, User $user)
    {

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'ไม่สามารถเปลี่ยนสถานะตัวเองได้'], 400);
        }

        $user->is_active = !$user->is_active;
        $user->save();

        return response()->json([
            'message' => 'เปลี่ยนสถานะผู้ใช้เรียบร้อยแล้ว',
            'is_active' => $user->is_active
        ]);
    }

    public function importCsv(Request $request)
    {

        $request->validate([
            'file' => 'required|mimes:csv,txt'
        ]);

        $path = $request->file('file')->getRealPath();
        $file = fopen($path, 'r');
        $headers = fgetcsv($file); // Read headers
        
        $successCount = 0;
        $skipCount = 0;
        $errors = [];

        $defaultRoleId = Role::where('name', 'Employee')->value('id') ?? 1;
        $defaultDeptId = Department::first()->id ?? 1;

        DB::beginTransaction();
        try {
            while (($row = fgetcsv($file)) !== FALSE) {
                // Map row to headers to create assoc array
                $data = [];
                foreach ($headers as $index => $header) {
                    $headerStr = trim($header);
                    $data[$headerStr] = isset($row[$index]) ? trim($row[$index]) : null;
                }

                $email = $data['email'] ?? null;
                $idCard = $data['id_card_number'] ?? null;
                $name = $data['name'] ?? null;

                if (!$email && !$idCard) {
                    $skipCount++;
                    $errors[] = "ไม่มี Email หรือ รหัสบัตรประชาชน (บรรทัดที่ซ้อนทับ)";
                    continue;
                }

                // Check duplicates in users table by email
                $existsByEmail = $email ? User::where('email', $email)->exists() : false;
                // Check duplicates in user_profiles by id_card_number
                $existsByIdCard = $idCard ? \App\Models\UserProfile::where('id_card_number', $idCard)->exists() : false;

                if ($existsByEmail || $existsByIdCard) {
                    $skipCount++;
                    $errors[] = "ข้าม: " . ($name ?? $email ?? $idCard) . " มีในระบบแล้ว";
                    continue;
                }

                // Create user
                // Default password to id_card_number or 'password'
                $password = $idCard ? $idCard : 'password';

                $user = User::create([
                    'name' => $name ?? 'Unknown',
                    'email' => $email ?? ($idCard . '@example.com'),
                    'password' => bcrypt($password),
                    'role_id' => $data['role_id'] ?? $defaultRoleId,
                    'department_id' => $data['department_id'] ?? $defaultDeptId,
                ]);

                // Create profile
                $user->profile()->create([
                    'id_card_number' => $idCard,
                    'first_name' => $data['first_name'] ?? null,
                    'last_name' => $data['last_name'] ?? null,
                    'title' => $data['title'] ?? 'นาย',
                    'gender' => $data['gender'] ?? 'ชาย',
                    'phone' => $data['phone'] ?? null,
                    'position' => $data['position'] ?? null,
                    // Map other fields as needed based on CSV columns
                ]);

                $successCount++;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error importing CSV: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'message' => "นำเข้าสำเร็จ $successCount รายการ, ข้าม $skipCount รายการ",
            'errors' => $errors
        ]);
    }

    public function roles(Request $request)
    {
        return response()->json(Role::all());
    }

    public function departments(Request $request)
    {
        return response()->json(Department::all());
    }
}
