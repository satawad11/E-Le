<?php

namespace Tests\Feature;

use App\Models\LeaveActionLog;
use App\Models\Saraban\DocumentWorkflowStep;
use App\Support\Labels\LeaveActionLabel;
use App\Support\Labels\SarabanWorkflowActionLabel;
use Tests\TestCase;

/**
 * REDESIGN-2569 · มาตรฐานภาษาไทย L4 — หน้าจอต้องไม่แสดงรหัสการดำเนินการดิบ
 */
class ActionLabelTest extends TestCase
{
    /** รหัสที่โค้ดเขียนลง leave_action_logs ทุกค่าต้องมีป้ายภาษาไทย */
    public function test_every_leave_action_written_by_the_code_has_a_thai_label(): void
    {
        $sources = [
            'LeaveRequestController.php', 'LeaveCancellationController.php', 'LeaveWorkflowActionController.php',
        ];
        $patterns = [
            "/'action' => '([^']+)'/",
            "/\\\$action = '([^']+)'/",
            "/->log\\(\\\$leave, \\\$request, '([^']+)'\\)/",
            "/respondAsSubstitute\\(\\\$request, \\\$leave, '[^']+', '([^']+)'/",
        ];

        $found = [];
        foreach ($sources as $file) {
            $code = file_get_contents(app_path('Http/Controllers/'.$file));
            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $code, $m);
                array_push($found, ...$m[1]);
            }
        }

        $this->assertNotEmpty($found);
        $this->assertSame([], array_values(array_diff(array_unique($found), array_keys(LeaveActionLabel::LABELS))), 'เพิ่มป้ายใน LeaveActionLabel::LABELS');
    }

    public function test_leave_action_labels_are_thai_and_handle_step_suffix(): void
    {
        foreach (LeaveActionLabel::LABELS as $code => $label) {
            $this->assertMatchesRegularExpression('/\p{Thai}/u', $label, $code);
        }

        $this->assertSame('อนุญาตให้ยกเลิกวันลา (ขั้นที่ 2)', LeaveActionLabel::label('Cancellation Approved (Level 2)'));
        $this->assertSame('การดำเนินการอื่น', LeaveActionLabel::label('something_new'), 'รหัสที่ไม่รู้จักต้องไม่หลุดไปแสดง');
    }

    public function test_models_serialise_the_label(): void
    {
        $this->assertSame('ยื่นใบลา', (new LeaveActionLog(['action' => 'Created']))->toArray()['action_label']);
        $this->assertSame('ส่งกลับแก้ไข', (new DocumentWorkflowStep(['action' => 'rejected']))->toArray()['action_label']);
    }

    public function test_every_saraban_workflow_action_has_a_thai_label(): void
    {
        preg_match_all("/transitionState\\([^;]*?, '([a-z_]+)', \\\$comment/", file_get_contents(app_path('Services/Saraban/DocumentWorkflowService.php')), $m);

        $this->assertNotEmpty($m[1]);
        $this->assertSame([], array_values(array_diff(array_unique($m[1]), array_keys(SarabanWorkflowActionLabel::LABELS))));
    }
}
