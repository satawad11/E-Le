<?php

namespace App\Services\Workflow;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * การกระทำที่ขอมาไม่มีเส้นทางรองรับในขั้นตอนปัจจุบันของ workflow version นี้
 */
class WorkflowTransitionException extends HttpException
{
    public function __construct(string $message = 'ไม่สามารถดำเนินการนี้ได้ในขั้นตอนปัจจุบัน')
    {
        parent::__construct(422, $message);
    }
}
