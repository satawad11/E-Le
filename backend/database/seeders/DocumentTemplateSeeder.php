<?php

namespace Database\Seeders;

use App\Services\DefaultDocumentTemplateService;
use Illuminate\Database\Seeder;

class DocumentTemplateSeeder extends Seeder
{
    public function run(DefaultDocumentTemplateService $templates): void
    {
        $templates->sync();
    }
}
