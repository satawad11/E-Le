<?php

namespace App\Services\Operations;

use RuntimeException;

/** การจัดการคิวข้อผิดพลาดที่ทำไม่ได้ (SoT §19) */
class ExceptionQueueException extends RuntimeException {}
