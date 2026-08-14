<?php

namespace App\Services\Workflow;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ผู้ใช้ไม่มีสิทธิ์ทำงานขั้นปัจจุบันของ workflow
 *
 * เป็น 403 เสมอ เพื่อไม่ให้เผยว่า instance นั้นมีอยู่จริงหรืออยู่ขั้นไหน
 */
class WorkflowAuthorizationException extends HttpException
{
    public function __construct(public readonly string $reason = 'forbidden')
    {
        parent::__construct(403, 'ไม่มีสิทธิ์ดำเนินการในขั้นตอนนี้');
    }
}
