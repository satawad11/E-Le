<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\ComplianceEvidence;

/**
 * ทะเบียนหลักฐานการปฏิบัติตามข้อกำหนด (SoT §23)
 *
 * **อ่านอย่างเดียว ไม่มี endpoint ที่ตั้งสถานะได้** — สถานะทุกช่องคำนวณสด
 * จากการตรวจจริง ณ เวลาที่เรียก · หัวข้อที่ตรวจอัตโนมัติไม่ได้จะเป็น
 * `NO_EVIDENCE` เสมอ และ **ไม่มีทางกลายเป็น PASS ด้วยการกดปุ่ม**
 *
 * เป็นการบังคับข้อ "ห้าม claim PASS โดยไม่มี evidence จริง" ด้วยการออกแบบ
 * ไม่ใช่ด้วยความไว้ใจ
 */
class ComplianceEvidenceController extends Controller
{
    public function __invoke(ComplianceEvidence $evidence)
    {
        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'summary' => $evidence->summary(),
            'topics' => $evidence->collect(),
        ]);
    }
}
