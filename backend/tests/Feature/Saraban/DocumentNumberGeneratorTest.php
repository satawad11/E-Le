<?php

namespace Tests\Feature\Saraban;

use App\Models\Department;
use App\Models\Saraban\DocumentSequence;
use App\Models\Saraban\DocumentType;
use App\Services\Saraban\DocumentNumberGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentNumberGeneratorService $service;
    protected Department $department;
    protected DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DocumentNumberGeneratorService::class);
        $this->department = Department::create(['name' => 'ฝ่ายบริหาร', 'status' => 'active']);
        $this->type = DocumentType::create(['name' => 'หนังสือภายใน', 'code' => 'int']);
    }

    public function test_running_numbers_are_sequential_and_unique(): void
    {
        $numbers = [];

        for ($i = 0; $i < 20; $i++) {
            $numbers[] = $this->service->generate($this->department->id, $this->type->id, 2569);
        }

        $this->assertSame(range(1, 20), $numbers);
        $this->assertCount(20, array_unique($numbers));
    }

    public function test_only_one_sequence_row_is_created_per_key(): void
    {
        $this->service->generate($this->department->id, $this->type->id, 2569);
        $this->service->generate($this->department->id, $this->type->id, 2569);

        $this->assertSame(1, DocumentSequence::where([
            'department_id' => $this->department->id,
            'document_type_id' => $this->type->id,
            'year_be' => 2569,
        ])->count());
    }

    public function test_sequences_are_separated_by_year_department_and_type(): void
    {
        $otherType = DocumentType::create(['name' => 'คำสั่ง', 'code' => 'ord']);

        $this->assertSame(1, $this->service->generate($this->department->id, $this->type->id, 2569));
        $this->assertSame(1, $this->service->generate($this->department->id, $this->type->id, 2570));
        $this->assertSame(1, $this->service->generate($this->department->id, $otherType->id, 2569));
    }

    public function test_document_number_format(): void
    {
        $number = $this->service->buildDocumentNumber($this->department->id, $this->type->id, 'ว', 2569);

        $this->assertSame('ว 0001/2569', $number);
    }
}
