<?php

namespace App\Services\DocumentEngine;

/**
 * เนื้อหาแม่แบบใช้ไวยากรณ์ที่ SafeTemplateRenderer ไม่รองรับ
 */
class TemplateSyntaxException extends \RuntimeException {}
