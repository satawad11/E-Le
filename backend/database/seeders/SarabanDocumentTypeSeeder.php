<?php

namespace Database\Seeders;

use App\Models\Saraban\DocumentType;
use Illuminate\Database\Seeder;

class SarabanDocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'name' => 'หนังสือภายนอก',
                'code' => 'ภน.',
                'color' => '#1d4ed8', // blue-700
            ],
            [
                'name' => 'หนังสือภายใน',
                'code' => 'ภย.',
                'color' => '#b91c1c', // red-700
            ],
            [
                'name' => 'หนังสือประทับตรา',
                'code' => 'ปต.',
                'color' => '#c2410c', // orange-700
            ],
            [
                'name' => 'หนังสือสั่งการ',
                'code' => 'สก.',
                'color' => '#047857', // emerald-700
            ],
            [
                'name' => 'หนังสือประชาสัมพันธ์',
                'code' => 'ปช.',
                'color' => '#6d28d9', // violet-700
            ],
            [
                'name' => 'หนังสือที่เจ้าหน้าที่ทำขึ้นหรือรับไว้เป็นหลักฐานในราชการ',
                'code' => 'จท.',
                'color' => '#475569', // slate-600
            ],
        ];

        foreach ($types as $type) {
            DocumentType::updateOrCreate(
                ['name' => $type['name']],
                ['code' => $type['code'], 'color' => $type['color']]
            );
        }
    }
}
