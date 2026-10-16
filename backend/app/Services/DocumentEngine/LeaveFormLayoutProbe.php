<?php

namespace App\Services\DocumentEngine;

use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\LeaveDocumentFields;
use App\Services\ThaiDateService;
use Dompdf\Dompdf;
use Dompdf\Frame;
use Dompdf\Options;

/**
 * สร้างแบบฟอร์มเปล่า + ตำแหน่งฟิลด์ จากแบบฟอร์ม HTML ในระบบ ให้ใช้เป็นจุดเริ่มต้นบนหน้าออกแบบ
 *
 * วิธีหาตำแหน่ง: เรนเดอร์แบบฟอร์มด้วย dompdf โดยใส่ "ตัวระบุ" (Q001, Q002 …) แทนค่าจริงของแต่ละฟิลด์
 * แล้วอ่านกล่องของแต่ละตัวระบุจากผลการจัดหน้าของ dompdf เอง — ได้พิกัดตรงกับช่องบนแบบฟอร์มเป๊ะ
 * ไม่ต้องกะด้วยตา ช่องติ๊กและลายเซ็นหาด้วยการเรนเดอร์ทีละกรณี (ติ๊กช่องเดียว/ใส่ลายเซ็นช่องเดียว)
 *
 * แบบฟอร์มเปล่า (พื้นหลัง) คือการเรนเดอร์เดียวกันโดยไม่มีค่าใด ๆ — ไม่มีชื่อหน่วยงานหรือชื่อบุคคลติดอยู่
 * ค่าทั้งหมดถูกวางทับด้วยฟิลด์ของ Document Engine ตอนออกเอกสาร
 *
 * พิกัดที่ได้เป็น PDF point วัดจากมุมซ้ายบน — ระบบเดียวกับที่หน้าออกแบบและ PdfOverlayRenderer ใช้
 */
class LeaveFormLayoutProbe
{
    private const MARKER_PATTERN = '/Q\d{3}/';

    /**
     * ค่าว่างของฟิลด์ข้อความในแบบฟอร์มเปล่า — ช่องว่างไม่ตัดบรรทัด (มองไม่เห็น)
     *
     * ต้องไม่ใช่สตริงว่าง เพราะบรรทัดที่ว่างจริงจะเตี้ยกว่าบรรทัดที่มีตัวระบุ ทำให้ทุกอย่างด้านล่าง
     * เลื่อนขึ้นและตำแหน่งที่หาได้ไม่ตรงกับพื้นหลัง
     */
    private const BLANK = "\u{00A0}";

    /** ขนาดตัวอักษรของฟิลด์ข้อความ (แบบฟอร์มใช้ 14pt, ตารางสถิติ 13pt) */
    private const FONT_SIZE = 14;

    private ?string $signatureImage = null;

    public function __construct(
        private readonly LeaveFormFieldMap $fieldMap,
        private readonly ThaiDateService $thaiDateService,
    ) {}

    /**
     * @return array{background: string, page_count: int, mappings: array<int, array<string, mixed>>}
     */
    public function probe(string $view): array
    {
        $definitions = collect($this->fieldMap->definitions());
        $layout = $this->layoutData($definitions->all());
        [$markerData, $markers] = $this->markerData($layout, $definitions->all());

        $base = $this->render($view, $markerData);
        $mappings = $this->textMappings($base['texts'], $markers, $definitions->keyBy('code')->all());

        foreach ($this->checkboxVariants() as $code => $overrides) {
            $variant = $this->render($view, $this->apply($layout, $overrides));

            foreach ($variant['boxes'] as $box) {
                if ($box['checked']) {
                    $mappings[] = $this->mapping($code, 'CHECKBOX', $box['page'], $box['x'] - 1, $box['y'] - 2, $box['w'] + 2, $box['h'] + 2, 'center', 12, ['mark' => '/']);
                }
            }
        }

        foreach ($this->signatureVariants() as $code => $path) {
            $variant = $this->render($view, $this->apply($layout, [$path => $this->signatureImage()]));

            foreach ($variant['images'] as $image) {
                $mappings[] = $this->mapping($code, 'SIGNATURE', $image['page'], $image['x'], $image['y'], $image['w'], $image['h'], 'left', self::FONT_SIZE);
            }
        }

        $blank = $this->render($view, $layout);

        // พื้นหลังต้องเป็นแบบฟอร์มเปล่าจริง — ช่องที่ติ๊กติดพื้นหลังจะติ๊กอยู่ในเอกสารทุกฉบับ
        if (collect($blank['boxes'])->contains('checked', true)) {
            throw new \LogicException("แบบฟอร์มเปล่าของ {$view} มีช่องที่ถูกติ๊กอยู่ — ตรวจเงื่อนไขการติ๊กในแบบฟอร์ม");
        }

        return [
            'background' => $blank['pdf'],
            'page_count' => $blank['pages'],
            'mappings' => $mappings,
        ];
    }

    // ───────────────────────────── ข้อมูลที่ใช้เรนเดอร์ ─────────────────────────────

    /**
     * ชุดตัวแปรว่างทั้งหมด — แบบฟอร์มเปล่าที่ไม่มีช่องใดถูกติ๊ก และแสดงทุกส่วนของแบบฟอร์ม
     *
     * @return array<string, mixed>
     */
    private function blankData(): array
    {
        $data = [];

        foreach (LeaveDocumentFields::catalog() as $field) {
            $data[$field['name']] = '';
        }

        $extra = [];
        foreach (array_keys($this->fieldMap->extraFields()) as $key) {
            // ผู้ร่วมเดินทางว่างทั้งหมด = ไม่แสดงส่วน "พร้อมด้วย" ในบันทึกไปราชการ จึงใส่ช่องว่างไว้ให้แสดงเสมอ
            $extra[$key] = str_starts_with($key, 'companion_') ? ' ' : '';
        }

        $leave = new Leave(['meta_data' => []]);
        $leave->setRelation('substitute', null);
        $leave->setRelation('user', (new User)->setRelation('profile', new UserProfile)->setRelation('department', new Department));
        $leave->setRelation('type', new LeaveType);

        return array_merge($data, [
            'is_sick' => false, 'is_personal' => false, 'is_maternity' => false,
            // ให้แบบฟอร์มแสดงช่อง "ลาครั้งสุดท้าย" เป็นช่องว่าง (ไม่ใช่เครื่องหมาย -)
            'has_last_leave' => true,
            'done_before' => null,
            'previous_same_type_count' => null,
            'stats' => [],
            'extra' => $extra,
            'requester_signature' => '',
            'approverBlocks' => collect(['HR Head', 'Deputy Director', 'Director'])->map(fn (string $role) => [
                'name' => '', 'position' => '', 'comment' => '', 'date' => null,
                'signature' => null, 'signature_path' => null, 'status' => '', 'role_name' => $role, 'acting_line' => '',
            ])->all(),
            'leave' => $leave,
            'settings' => collect(),
            'lastLeave' => null,
            'thaiDateService' => $this->thaiDateService,
            'verificationQr' => null,
        ]);
    }

    /**
     * แบบฟอร์มเปล่าที่ทุกฟิลด์ข้อความเป็นช่องว่างที่มองไม่เห็น — จัดหน้าเหมือนตอนมีค่าทุกประการ
     *
     * @param  array<int, array<string, mixed>>  $definitions
     * @return array<string, mixed>
     */
    private function layoutData(array $definitions): array
    {
        $data = $this->blankData();

        foreach ($this->textPaths($definitions) as $path) {
            data_set($data, $path, $this->isSignerDate($path) ? new MarkerDate(self::BLANK) : self::BLANK);
        }

        return $data;
    }

    /**
     * ชุดตัวแปรที่ทุกฟิลด์ข้อความเป็นตัวระบุ (Q001 …)
     *
     * @param  array<string, mixed>  $layout
     * @param  array<int, array<string, mixed>>  $definitions
     * @return array{0: array<string, mixed>, 1: array<string, string>} [ข้อมูล, ตัวระบุ => code]
     */
    private function markerData(array $layout, array $definitions): array
    {
        $data = $layout;
        $markers = [];
        $next = 1;

        foreach ($this->textPaths($definitions) as $code => $path) {
            $marker = sprintf('Q%03d', $next++);
            $markers[$marker] = $code;

            data_set($data, $path, $this->isSignerDate($path) ? new MarkerDate($marker) : $marker);
        }

        return [$data, $markers];
    }

    /**
     * @param  array<int, array<string, mixed>>  $definitions
     * @return array<string, string> code => path ของฟิลด์ข้อความ (ไม่รวมช่องติ๊กและลายเซ็น)
     */
    private function textPaths(array $definitions): array
    {
        $paths = [];

        foreach ($definitions as $definition) {
            if ($definition['path'] !== null && ! in_array($definition['data_type'], ['CHECKBOX', 'SIGNATURE'], true)) {
                $paths[$definition['code']] = $definition['path'];
            }
        }

        return $paths;
    }

    private function isSignerDate(string $path): bool
    {
        return str_starts_with($path, 'approverBlocks.') && str_ends_with($path, '.date');
    }

    /** @return array<string, array<string, mixed>> code => ค่าที่ทำให้ช่องนั้นช่องเดียวถูกติ๊ก */
    private function checkboxVariants(): array
    {
        $variants = [
            'leave.is_sick' => ['is_sick' => true],
            'leave.is_personal' => ['is_personal' => true],
            'leave.is_maternity' => ['is_maternity' => true],
            'history.done_before' => ['done_before' => true],
            'history.never_before' => ['done_before' => false],
            'leave.has_substitute' => ['leave.substitute_id' => 1],
        ];

        foreach (array_keys(LeaveFormFieldMap::ROLES) as $index => $role) {
            $variants["approval.{$role}.approved"] = ["approverBlocks.{$index}.status" => 'Approved'];
            $variants["approval.{$role}.rejected"] = ["approverBlocks.{$index}.status" => 'Rejected'];
        }

        foreach ($this->fieldMap->extraFields() as $key => $field) {
            foreach ($field['options'] as $option) {
                $variants["extra.{$key}.{$option}"] = ["extra.{$key}" => $option];
            }
        }

        return $variants;
    }

    /** @return array<string, string> code => ตัวแปรที่ใส่รูปลายเซ็น */
    private function signatureVariants(): array
    {
        $variants = ['leave.signature' => 'requester_signature'];

        foreach (array_keys(LeaveFormFieldMap::ROLES) as $index => $role) {
            $variants["approval.{$role}.signature"] = "approverBlocks.{$index}.signature";
        }

        return $variants;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function apply(array $data, array $overrides): array
    {
        foreach ($overrides as $path => $value) {
            if ($path === 'leave.substitute_id') {
                // คัดลอกก่อนแก้ — ใบลาตัวอย่างเป็นอ็อบเจกต์เดียวกันทุกรอบ แก้ตรง ๆ จะติดไปถึงแบบฟอร์มเปล่า
                $data['leave'] = clone $data['leave'];
                $data['leave']->substitute_id = $value;

                continue;
            }

            data_set($data, $path, $value);
        }

        return $data;
    }

    // ───────────────────────────── การเรนเดอร์และอ่านกล่อง ─────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     * @return array{pdf: string, pages: int, texts: array<int, array<string, mixed>>, boxes: array<int, array<string, mixed>>, images: array<int, array<string, mixed>>}
     */
    private function render(string $view, array $data): array
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setChroot([base_path(), sys_get_temp_dir()]);

        $dompdf = new Dompdf($options);
        $texts = $boxes = $images = [];

        $dompdf->setCallbacks([[
            'event' => 'end_frame',
            'f' => function (Frame $frame, $canvas) use (&$texts, &$boxes, &$images, $dompdf) {
                $node = $frame->get_node();
                $page = $canvas->get_page_number();

                if ($node->nodeName === '#text') {
                    $text = (string) $frame->get_text();

                    if (preg_match_all(self::MARKER_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE)) {
                        $style = $frame->get_style();
                        [$x, $y, , $h] = $frame->get_border_box();

                        foreach ($matches[0] as [$marker, $offset]) {
                            $prefix = substr($text, 0, $offset);
                            $texts[] = [
                                'marker' => $marker,
                                'page' => $page,
                                'x' => $x + $dompdf->getFontMetrics()->getTextWidth($prefix, $style->font_family, $style->font_size),
                                'y' => $y,
                                'h' => $h,
                                'font_size' => (float) $style->font_size,
                                'container' => $this->container($frame),
                            ];
                        }
                    }

                    return;
                }

                $class = ' '.$node->getAttribute('class').' ';

                if ($node->nodeName === 'span' && (str_contains($class, ' box ') || str_contains($class, ' circle '))) {
                    [$x, $y, $w, $h] = $frame->get_border_box();
                    $boxes[] = ['page' => $page, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'checked' => str_contains($class, ' checked ')];
                }

                if ($node->nodeName === 'img' && $node->getAttribute('src') === $this->signatureImage) {
                    [$x, $y, $w, $h] = $frame->get_border_box();
                    $images[] = ['page' => $page, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
                }
            },
        ]]);

        $dompdf->loadHtml(view($view, $data)->render());
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        return [
            'pdf' => (string) $dompdf->output(),
            'pages' => $dompdf->getCanvas()->get_page_count(),
            'texts' => $texts,
            'boxes' => $boxes,
            'images' => $images,
        ];
    }

    /**
     * กล่องของช่องที่ค่าอยู่ — ช่องตาราง (td) หรือช่องเติม (span.fill) ใช้ทั้งช่อง
     * นอกนั้นใช้บล็อกที่ครอบอยู่ (div) และวางต่อจากข้อความนำหน้า
     *
     * @return array{tag: string, class: string, x: float, y: float, w: float, h: float}|null
     */
    private function container(Frame $frame): ?array
    {
        for ($parent = $frame->get_parent(); $parent !== null; $parent = $parent->get_parent()) {
            $node = $parent->get_node();
            $class = ' '.$node->getAttribute('class').' ';
            $tag = $node->nodeName;

            if ($tag === 'td' || $tag === 'th' || $tag === 'div' || ($tag === 'span' && str_contains($class, ' fill '))) {
                [$x, $y, $w, $h] = $parent->get_border_box();
                $align = strtolower((string) $parent->get_style()->text_align);

                return ['tag' => $tag, 'class' => trim($class), 'align' => $align, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $texts
     * @param  array<string, string>  $markers
     * @param  array<string, array<string, mixed>>  $definitions
     * @return array<int, array<string, mixed>>
     */
    private function textMappings(array $texts, array $markers, array $definitions): array
    {
        $mappings = [];

        foreach ($texts as $text) {
            $code = $markers[$text['marker']] ?? null;
            $box = $text['container'];

            if ($code === null || $box === null) {
                continue;
            }

            $type = in_array($definitions[$code]['data_type'], ['NUMBER', 'DATE'], true) ? $definitions[$code]['data_type'] : 'TEXT';
            $fontSize = min(self::FONT_SIZE, $text['font_size']);

            // ช่องตาราง/ช่องเติมที่จัดกึ่งกลาง: ใช้ทั้งช่องและจัดกึ่งกลาง
            if (in_array($box['tag'], ['td', 'th', 'span'], true) && $box['align'] === 'center') {
                $mappings[] = $this->mapping($code, $type, $text['page'], $box['x'], $box['y'], $box['w'], $box['h'], 'center', $fontSize);

                continue;
            }

            // ชิดซ้าย: เริ่มที่ตำแหน่งค่า กว้างถึงขอบขวาของช่อง
            $x = $text['x'];
            $width = max(20.0, $box['x'] + $box['w'] - $x);
            $isCell = in_array($box['tag'], ['td', 'th', 'span'], true);

            $mappings[] = $this->mapping($code, $type, $text['page'], $x, $isCell ? $box['y'] : $text['y'], $width, $isCell ? $box['h'] : $text['h'], 'left', $fontSize);
        }

        return $mappings;
    }

    /** @return array<string, mixed> */
    private function mapping(string $code, string $type, int $page, float $x, float $y, float $w, float $h, string $align, float $fontSize, ?array $options = null): array
    {
        return [
            'field_code' => $code,
            'field_type' => $type,
            'page' => max(1, $page),
            'x' => round($x, 2),
            'y' => round($y, 2),
            'width' => round($w, 2),
            'height' => round($h, 2),
            'font' => null,
            'font_size' => $fontSize,
            'align' => $align,
            'overflow' => 'shrink',
            'options' => $options,
        ];
    }

    /** รูปลายเซ็นตัวอย่าง (สัดส่วน 3:1) ใช้หาตำแหน่งช่องลายเซ็นเท่านั้น */
    private function signatureImage(): string
    {
        if ($this->signatureImage !== null && is_file($this->signatureImage)) {
            return $this->signatureImage;
        }

        $path = sys_get_temp_dir().'/leave-form-probe-signature.png';
        $image = imagecreatetruecolor(90, 30);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagepng($image, $path);
        imagedestroy($image);

        return $this->signatureImage = $path;
    }
}
