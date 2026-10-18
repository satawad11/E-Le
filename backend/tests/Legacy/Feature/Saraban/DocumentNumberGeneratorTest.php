<?php

namespace Tests\Legacy\Feature\Saraban;

use App\Models\Saraban\DocumentSequence;
use App\Services\Saraban\DocumentNumberGeneratorService;

class DocumentNumberGeneratorTest extends SarabanTestCase
{
    private function service(): DocumentNumberGeneratorService
    {
        return app(DocumentNumberGeneratorService::class);
    }

    public function test_numbers_are_sequential_and_never_repeat(): void
    {
        $service = $this->service();

        $numbers = [];
        for ($i = 0; $i < 25; $i++) {
            $numbers[] = $service->generate($this->department->id, $this->type->id, 2569);
        }

        $this->assertSame(range(1, 25), $numbers);
        $this->assertCount(25, array_unique($numbers));
    }

    /**
     * Regression: the very first call for a (year, department, type) key found no
     * row to lock, so two concurrent callers could each insert their own sequence.
     * Only one row must ever exist for a key.
     */
    public function test_first_generation_creates_exactly_one_sequence_row(): void
    {
        $service = $this->service();

        $service->generate($this->department->id, $this->type->id, 2569);
        $service->generate($this->department->id, $this->type->id, 2569);

        $this->assertSame(1, DocumentSequence::where([
            'department_id' => $this->department->id,
            'document_type_id' => $this->type->id,
            'year_be' => 2569,
        ])->count());
    }

    public function test_sequences_are_independent_per_year_and_type(): void
    {
        $service = $this->service();

        $this->assertSame(1, $service->generate($this->department->id, $this->type->id, 2569));
        $this->assertSame(1, $service->generate($this->department->id, $this->type->id, 2570));
    }

    public function test_format_pads_to_four_digits(): void
    {
        $this->assertSame('0007', $this->service()->format(7));
        $this->assertSame('1234', $this->service()->format(1234));
    }

    public function test_two_documents_never_share_a_number(): void
    {
        $payload = [
            'title' => 'Numbered document',
            'type_id' => $this->type->id,
            'department_id' => $this->department->id,
            'priority' => 'normal',
            'secrecy' => 'normal',
        ];

        $first = $this->actingAs($this->registrar)->postJson('/api/saraban/documents', $payload)
            ->assertCreated()->json('document_number');
        $second = $this->actingAs($this->registrar)->postJson('/api/saraban/documents', $payload)
            ->assertCreated()->json('document_number');

        $this->assertNotSame($first, $second);
    }
}
