<?php

namespace Tests\Unit;

use App\Services\DocumentEngine\SafeTemplateRenderer;
use App\Services\DocumentEngine\TemplateSyntaxException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DC-02 — แม่แบบที่แก้จากหน้าเว็บต้องแสดงข้อมูลได้ แต่ห้ามรันโค้ดได้
 */
class SafeTemplateRendererTest extends TestCase
{
    private SafeTemplateRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new SafeTemplateRenderer;
    }

    public function test_it_prints_variables_and_escapes_them(): void
    {
        $html = $this->renderer->render('<p>{{ $user_name }}</p>', ['user_name' => '<b>สมชาย</b>']);

        $this->assertSame('<p>&lt;b&gt;สมชาย&lt;/b&gt;</p>', $html);
    }

    public function test_it_reads_nested_array_keys_and_fallbacks(): void
    {
        $template = "{{ \$stats['ป่วย']['total'] }}|{{ \$missing ?? 'ไม่มี' }}|{{ \$empty ?? \$user_name }}";

        $html = $this->renderer->render($template, [
            'stats' => ['ป่วย' => ['total' => 5]],
            'user_name' => 'สมหญิง',
        ]);

        $this->assertSame('5|ไม่มี|สมหญิง', $html);
    }

    public function test_conditions_and_loops_render_like_blade(): void
    {
        $template = <<<'TPL'
            @foreach($approverBlocks as $i => $block)
            [{{ $i }}:{{ $block['name'] }}@if($block['status'] === 'Approved') อนุญาต@elseif($block['status'] === 'Rejected') ไม่อนุญาต@else รอ@endif]
            @endforeach
            @if(!$comment)ไม่มีความเห็น@endif
            TPL;

        $html = $this->renderer->render($template, [
            'approverBlocks' => [
                ['name' => 'ก', 'status' => 'Approved'],
                ['name' => 'ข', 'status' => 'Rejected'],
                ['name' => 'ค', 'status' => 'Pending'],
            ],
            'comment' => '',
        ]);

        $this->assertStringContainsString('[0:ก อนุญาต]', $html);
        $this->assertStringContainsString('[1:ข ไม่อนุญาต]', $html);
        $this->assertStringContainsString('[2:ค รอ]', $html);
        $this->assertStringContainsString('ไม่มีความเห็น', $html);
    }

    public function test_css_at_rules_and_comments_pass_through(): void
    {
        $template = '<style>@page { margin: 8mm } @font-face { font-family: X }</style>{{-- ซ่อน --}}ok';

        $this->assertSame('<style>@page { margin: 8mm } @font-face { font-family: X }</style>ok', $this->renderer->render($template, []));
    }

    public function test_objects_are_never_reached_through_paths(): void
    {
        $object = new class
        {
            public string $secret = 'ความลับ';
        };

        $this->assertSame('', $this->renderer->render("{{ \$obj['secret'] }}", ['obj' => $object]));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function codeExecutionAttempts(): array
    {
        return [
            '@php block' => ["@php echo file_get_contents('/etc/passwd'); @endphp"],
            'raw echo' => ['{!! $user_name !!}'],
            'function call' => ["{{ system('id') }}"],
            'method call' => ['{{ $leave->user->delete() }}'],
            'static call' => ['{{ \\App\\Models\\User::first() }}'],
            'concatenation' => ["{{ \$a . shell_exec('id') }}"],
            'include' => ["@include('pdf.leave')"],
            'inject' => ["@inject('db', 'db')"],
            'condition with call' => ["@if(exec('id'))x@endif"],
            'foreach over call' => ['@foreach(range(1, 3) as $i)x@endforeach'],
            'unclosed if' => ['@if($a) x'],
            'stray endforeach' => ['@endforeach'],
        ];
    }

    #[DataProvider('codeExecutionAttempts')]
    public function test_it_rejects_anything_outside_the_grammar(string $template): void
    {
        $this->assertNotSame([], $this->renderer->validate($template));

        $this->expectException(TemplateSyntaxException::class);
        $this->renderer->render($template, ['a' => 'x', 'user_name' => 'x']);
    }

    public function test_errors_name_the_line(): void
    {
        $errors = $this->renderer->validate("<p>a</p>\n<p>b</p>\n{{ system('id') }}");

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('บรรทัด 3', $errors[0]);
    }

    public function test_unknown_variables_are_reported_when_a_catalogue_is_given(): void
    {
        $errors = $this->renderer->validate(
            '{{ $user_name }} {{ $password }} @foreach($approverBlocks as $b){{ $b[\'name\'] }}@endforeach',
            ['user_name', 'approverBlocks'],
        );

        $this->assertSame(['ไม่รู้จักตัวแปร $password'], $errors);
    }
}
