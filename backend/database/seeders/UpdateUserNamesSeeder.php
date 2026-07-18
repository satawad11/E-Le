<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateUserNamesSeeder extends Seeder
{
    public function run()
    {
        $users = User::all();

        $thaiNames = [
            [
                'title' => 'นาย',
                'first_name' => 'ธราเชฏฐ์',
                'last_name' => 'สุคนธ์',
            ],
            [
                'title' => 'นาย',
                'first_name' => 'สุริยา',
                'last_name' => 'เผด็จศึก',
            ],
            [
                'title' => 'นาง',
                'first_name' => 'พรรณสิริ',
                'last_name' => 'ขุนพรัตน์',
            ],
            [
                'title' => 'นาย',
                'first_name' => 'จักรภพ',
                'last_name' => 'โตนันทวณิช',
            ],
            [
                'title' => 'นางสาว',
                'first_name' => 'อัญชลี',
                'last_name' => 'จันทร์มา',
            ],
            [
                'title' => 'นาย',
                'first_name' => 'สมชาย',
                'last_name' => 'ใจดี',
            ],
            [
                'title' => 'นางสาว',
                'first_name' => 'สมหญิง',
                'last_name' => 'รักเรียน',
            ]
        ];

        foreach ($users as $index => $user) {
            $nameData = $thaiNames[$index % count($thaiNames)];
            $fullName = $nameData['first_name'] . ' ' . $nameData['last_name'];
            
            // Update user's name
            $user->update(['name' => $fullName]);
            
            // Update or create user profile
            $user->profile()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'title' => $nameData['title'],
                    'first_name' => $nameData['first_name'],
                    'last_name' => $nameData['last_name']
                ]
            );
        }
    }
}
