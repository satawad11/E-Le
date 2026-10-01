<?php

namespace App\Services\DocumentEngine;

use stdClass;

/**
 * เรนเดอร์เนื้อหาแม่แบบ HTML ที่ผู้ดูแลแก้ผ่านหน้าเว็บ โดยไม่ประมวลผลโค้ด (DC-02)
 *
 * เดิมเนื้อหาจากฐานข้อมูลถูกส่งเข้า `Blade::render()` ตรง ๆ ซึ่ง Blade คอมไพล์เป็น PHP
 * ผู้ที่มีสิทธิ์จัดการแม่แบบจึงรันโค้ดบนเซิร์ฟเวอร์ได้ (`@php`, `{{ system(...) }}`)
 * สิทธิ์แก้หน้าตาเอกสารไม่ควรเท่ากับสิทธิ์รันโค้ด
 *
 * คลาสนี้แปลไวยากรณ์ชุดเล็กที่หน้าตาเหมือน Blade เองทั้งหมด ไม่เรียก Blade ไม่ใช้ eval
 * สิ่งที่ไม่อยู่ในไวยากรณ์ถูกปฏิเสธพร้อมเลขบรรทัด:
 *
 *   {{ $name }}                      แสดงค่า (escape เสมอ)
 *   {{ $stats['ป่วย']['total'] }}     อ่านค่าในอาร์เรย์
 *   {{ $name ?? 'ค่าแทน' }}           ค่าแทนเมื่อว่าง
 *   {{-- หมายเหตุ --}}                ความเห็น ไม่ออกในเอกสาร
 *   เงื่อนไข  `@if($x)` `@elseif($y === 'ค่า')` `@else` `@endif`  (!, ===, !==, ==, !=)
 *   วนซ้ำ    `@foreach($rows as $row)` … `@endforeach`  (รองรับ $key => $row)
 *
 * CSS at-rule ใน <style> (`@page`, `@font-face`, `@media`) เป็นข้อความธรรมดา
 */
class SafeTemplateRenderer
{
    private const PATH = '\$[A-Za-z_][A-Za-z0-9_]*(?:\[(?:\'[^\']*\'|"[^"]*"|\d+)\])*';

    private const LITERAL = '\'[^\']*\'|"[^"]*"|-?\d+(?:\.\d+)?|true|false|null';

    /** directive ของไวยากรณ์นี้ */
    private const SUPPORTED = ['if', 'elseif', 'else', 'endif', 'foreach', 'endforeach'];

    /**
     * directive ของ Blade ที่ไม่รองรับ — ปฏิเสธให้ผู้ดูแลรู้ แทนที่จะเงียบแล้วพิมพ์ออกเป็นข้อความ
     * คำที่ไม่อยู่ในรายการนี้ (เช่น `@page`, `@font-face`) ถือเป็นข้อความธรรมดา
     */
    private const FORBIDDEN = [
        'php', 'endphp', 'include', 'includeIf', 'includeWhen', 'includeUnless', 'includeFirst',
        'each', 'extends', 'section', 'endsection', 'yield', 'show', 'parent', 'component', 'endcomponent',
        'slot', 'endslot', 'inject', 'json', 'js', 'eval', 'verbatim', 'endverbatim', 'unless', 'endunless',
        'isset', 'endisset', 'empty', 'endempty', 'forelse', 'endforelse', 'for', 'endfor', 'while', 'endwhile',
        'switch', 'case', 'break', 'continue', 'default', 'endswitch', 'can', 'endcan', 'cannot', 'canany',
        'auth', 'endauth', 'guest', 'endguest', 'env', 'production', 'once', 'push', 'endpush', 'stack',
        'prepend', 'csrf', 'method', 'dd', 'dump', 'lang', 'choice', 'error', 'enderror', 'class', 'style',
        'checked', 'selected', 'disabled', 'readonly', 'required', 'use', 'livewire', 'vite', 'session',
        'fragment', 'props', 'aware',
    ];

    /**
     * ตรวจไวยากรณ์ คืนรายการข้อผิดพลาด (ว่าง = ใช้ได้)
     *
     * @param  list<string>|null  $allowedVariables  ชื่อตัวแปรระดับบนที่อนุญาต (null = ไม่ตรวจชื่อ)
     * @return list<string>
     */
    public function validate(string $template, ?array $allowedVariables = null): array
    {
        try {
            $nodes = $this->parse($template);
        } catch (TemplateSyntaxException $exception) {
            return [$exception->getMessage()];
        }

        if ($allowedVariables === null) {
            return [];
        }

        $errors = [];
        $this->checkVariables($nodes, array_fill_keys($allowedVariables, true), $errors);

        return array_values(array_unique($errors));
    }

    public function isValid(string $template): bool
    {
        return $this->validate($template) === [];
    }

    /**
     * @param  array<string,mixed>  $data
     *
     * @throws TemplateSyntaxException
     */
    public function render(string $template, array $data): string
    {
        return $this->renderNodes($this->parse($template), $data);
    }

    // ───────────────────────────── parse ─────────────────────────────

    /**
     * @return list<stdClass>
     *
     * @throws TemplateSyntaxException
     */
    private function parse(string $template): array
    {
        $pattern = '/\{\{--.*?--\}\}|\{!!.*?!!\}|\{\{.*?\}\}|@([A-Za-z_][A-Za-z0-9_]*)/s';

        $root = $this->container('root', 0);
        /** @var list<stdClass> $stack */
        $stack = [$root];
        $offset = 0;
        $length = strlen($template);

        while ($offset < $length && preg_match($pattern, $template, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $token = $match[0][0];
            $position = $match[0][1];
            $this->appendText($stack, substr($template, $offset, $position - $offset));
            $offset = $position + strlen($token);
            $line = substr_count($template, "\n", 0, $position) + 1;

            if (str_starts_with($token, '{{--')) {
                continue;
            }

            if (str_starts_with($token, '{!!')) {
                throw new TemplateSyntaxException("บรรทัด {$line}: ไม่รองรับ {!! !!} (ทุกค่าต้องถูก escape) ใช้ {{ }} แทน");
            }

            if (str_starts_with($token, '{{')) {
                $this->append($stack, (object) ['type' => 'echo', 'expr' => $this->parseEcho(substr($token, 2, -2), $line)]);

                continue;
            }

            $name = $match[1][0];

            // `@@name` ของ Blade คือการพิมพ์ @ ตามตัว
            if ($position > 0 && $template[$position - 1] === '@') {
                $this->appendText($stack, $token);

                continue;
            }

            if (in_array($name, self::FORBIDDEN, true)) {
                throw new TemplateSyntaxException("บรรทัด {$line}: ไม่รองรับคำสั่ง @{$name} ในแม่แบบที่แก้จากหน้าเว็บ");
            }

            if (! in_array($name, self::SUPPORTED, true)) {
                $this->appendText($stack, $token); // เช่น @page, @font-face ใน CSS

                continue;
            }

            $argument = '';
            if (in_array($name, ['if', 'elseif', 'foreach'], true)) {
                [$argument, $offset] = $this->readArgument($template, $offset, $name, $line);
            }

            $this->applyDirective($stack, $name, $argument, $line);
        }

        if ($offset < $length) {
            $this->appendText($stack, substr($template, $offset));
        }

        if (count($stack) > 1) {
            $open = $stack[count($stack) - 1];
            throw new TemplateSyntaxException("บรรทัด {$open->line}: @{$open->type} ไม่มีคำสั่งปิด");
        }

        return $root->children;
    }

    private function container(string $type, int $line): stdClass
    {
        return (object) ['type' => $type, 'line' => $line, 'children' => []];
    }

    /**
     * เพิ่มโหนดเข้าบล็อกที่กำลังเปิดอยู่ (สำหรับ @if คือกิ่งล่าสุด)
     *
     * @param  list<stdClass>  $stack
     */
    private function append(array $stack, stdClass $node): void
    {
        $top = $stack[count($stack) - 1];

        if ($top->type === 'if') {
            $top->branches[count($top->branches) - 1]->children[] = $node;

            return;
        }

        $top->children[] = $node;
    }

    /**
     * @param  list<stdClass>  $stack
     */
    private function appendText(array $stack, string $text): void
    {
        if ($text !== '') {
            $this->append($stack, (object) ['type' => 'text', 'text' => $text]);
        }
    }

    /**
     * อ่านวงเล็บของ directive โดยนับวงเล็บซ้อนและข้ามสตริง
     *
     * @return array{0: string, 1: int}
     */
    private function readArgument(string $template, int $offset, string $name, int $line): array
    {
        $cursor = $offset;
        $length = strlen($template);
        while ($cursor < $length && ($template[$cursor] === ' ' || $template[$cursor] === "\t")) {
            $cursor++;
        }

        if ($cursor >= $length || $template[$cursor] !== '(') {
            throw new TemplateSyntaxException("บรรทัด {$line}: @{$name} ต้องมีวงเล็บเงื่อนไข");
        }

        $depth = 0;
        $quote = null;
        for ($i = $cursor; $i < $length; $i++) {
            $char = $template[$i];

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '\'' || $char === '"') {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return [trim(substr($template, $cursor + 1, $i - $cursor - 1)), $i + 1];
                }
            }
        }

        throw new TemplateSyntaxException("บรรทัด {$line}: วงเล็บของ @{$name} ไม่ปิด");
    }

    /**
     * @param  list<stdClass>  $stack
     */
    private function applyDirective(array &$stack, string $name, string $argument, int $line): void
    {
        $top = $stack[count($stack) - 1];

        switch ($name) {
            case 'if':
                $node = (object) [
                    'type' => 'if',
                    'line' => $line,
                    'has_else' => false,
                    'branches' => [(object) ['cond' => $this->parseCondition($argument, $line), 'children' => []]],
                ];
                $this->append($stack, $node);
                $stack[] = $node;
                break;

            case 'elseif':
            case 'else':
                if ($top->type !== 'if' || $top->has_else) {
                    throw new TemplateSyntaxException("บรรทัด {$line}: @{$name} ต้องอยู่ภายใน @if และก่อน @else");
                }
                $top->branches[] = (object) [
                    'cond' => $name === 'else' ? null : $this->parseCondition($argument, $line),
                    'children' => [],
                ];
                $top->has_else = $name === 'else';
                break;

            case 'endif':
                if ($top->type !== 'if') {
                    throw new TemplateSyntaxException("บรรทัด {$line}: @endif ไม่มี @if คู่กัน");
                }
                array_pop($stack);
                break;

            case 'foreach':
                if (preg_match('/^('.self::PATH.')\s+as\s+(?:\$([A-Za-z_][A-Za-z0-9_]*)\s*=>\s*)?\$([A-Za-z_][A-Za-z0-9_]*)$/u', $argument, $parts) !== 1) {
                    throw new TemplateSyntaxException("บรรทัด {$line}: @foreach ต้องอยู่ในรูป \$รายการ as \$แถว");
                }
                $node = (object) [
                    'type' => 'foreach',
                    'line' => $line,
                    'source' => $this->parsePath($parts[1]),
                    'key' => $parts[2] !== '' ? $parts[2] : null,
                    'item' => $parts[3],
                    'children' => [],
                ];
                $this->append($stack, $node);
                $stack[] = $node;
                break;

            case 'endforeach':
                if ($top->type !== 'foreach') {
                    throw new TemplateSyntaxException("บรรทัด {$line}: @endforeach ไม่มี @foreach คู่กัน");
                }
                array_pop($stack);
                break;
        }
    }

    private function parseEcho(string $expression, int $line): stdClass
    {
        $expression = trim($expression);

        if (preg_match('/^('.self::PATH.')(?:\s*\?\?\s*('.self::PATH.'|'.self::LITERAL.'))?$/u', $expression, $parts) !== 1) {
            throw new TemplateSyntaxException("บรรทัด {$line}: นิพจน์ {{ {$expression} }} ไม่รองรับ (ใช้ได้เฉพาะตัวแปร เช่น {{ \$name }} หรือ {{ \$name ?? '' }})");
        }

        return (object) [
            'path' => $this->parsePath($parts[1]),
            'fallback' => isset($parts[2]) ? $this->parseOperand($parts[2]) : null,
        ];
    }

    private function parseCondition(string $expression, int $line): stdClass
    {
        $operand = self::PATH.'|'.self::LITERAL;

        if (preg_match('/^(!?)\s*('.self::PATH.')$/u', $expression, $parts) === 1) {
            return (object) ['negate' => $parts[1] === '!', 'left' => $this->parseOperand($parts[2]), 'op' => null, 'right' => null];
        }

        if (preg_match('/^('.$operand.')\s*(===|!==|==|!=)\s*('.$operand.')$/u', $expression, $parts) === 1) {
            return (object) ['negate' => false, 'left' => $this->parseOperand($parts[1]), 'op' => $parts[2], 'right' => $this->parseOperand($parts[3])];
        }

        throw new TemplateSyntaxException("บรรทัด {$line}: เงื่อนไข ({$expression}) ไม่รองรับ (ใช้ได้: \$x, !\$x, \$x === 'ค่า')");
    }

    private function parseOperand(string $operand): stdClass
    {
        if (str_starts_with($operand, '$')) {
            return (object) ['path' => $this->parsePath($operand)];
        }

        return (object) ['value' => match (true) {
            $operand === 'true' => true,
            $operand === 'false' => false,
            $operand === 'null' => null,
            str_starts_with($operand, '\''), str_starts_with($operand, '"') => substr($operand, 1, -1),
            str_contains($operand, '.') => (float) $operand,
            default => (int) $operand,
        }];
    }

    /**
     * @return list<string>
     */
    private function parsePath(string $path): array
    {
        preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)/', $path, $root);
        preg_match_all('/\[(?:\'([^\']*)\'|"([^"]*)"|(\d+))\]/', $path, $keys, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        $segments = [$root[1]];
        foreach ($keys as $key) {
            $segments[] = (string) ($key[1] ?? $key[2] ?? $key[3]);
        }

        return $segments;
    }

    // ───────────────────────────── validate ─────────────────────────────

    /**
     * @param  list<stdClass>  $nodes
     * @param  array<string,bool>  $scope
     * @param  list<string>  $errors
     */
    private function checkVariables(array $nodes, array $scope, array &$errors): void
    {
        foreach ($nodes as $node) {
            if ($node->type === 'echo') {
                $this->checkOperand($node->expr, $scope, $errors);
                if ($node->expr->fallback !== null) {
                    $this->checkOperand($node->expr->fallback, $scope, $errors);
                }
            } elseif ($node->type === 'if') {
                foreach ($node->branches as $branch) {
                    if ($branch->cond !== null) {
                        $this->checkOperand($branch->cond->left, $scope, $errors);
                        if ($branch->cond->right !== null) {
                            $this->checkOperand($branch->cond->right, $scope, $errors);
                        }
                    }
                    $this->checkVariables($branch->children, $scope, $errors);
                }
            } elseif ($node->type === 'foreach') {
                $this->checkPath($node->source, $scope, $errors);
                $inner = $scope;
                $inner[$node->item] = true;
                if ($node->key !== null) {
                    $inner[$node->key] = true;
                }
                $this->checkVariables($node->children, $inner, $errors);
            }
        }
    }

    /**
     * @param  array<string,bool>  $scope
     * @param  list<string>  $errors
     */
    private function checkOperand(stdClass $operand, array $scope, array &$errors): void
    {
        if (isset($operand->path)) {
            $this->checkPath($operand->path, $scope, $errors);
        }
    }

    /**
     * @param  list<string>  $path
     * @param  array<string,bool>  $scope
     * @param  list<string>  $errors
     */
    private function checkPath(array $path, array $scope, array &$errors): void
    {
        if (! isset($scope[$path[0]])) {
            $errors[] = "ไม่รู้จักตัวแปร \${$path[0]}";
        }
    }

    // ───────────────────────────── render ─────────────────────────────

    /**
     * @param  list<stdClass>  $nodes
     * @param  array<string,mixed>  $data
     */
    private function renderNodes(array $nodes, array $data): string
    {
        $html = '';

        foreach ($nodes as $node) {
            $html .= match ($node->type) {
                'text' => $node->text,
                'echo' => $this->renderEcho($node->expr, $data),
                'if' => $this->renderIf($node, $data),
                'foreach' => $this->renderForeach($node, $data),
                default => '',
            };
        }

        return $html;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function renderEcho(stdClass $expr, array $data): string
    {
        $value = $this->lookup($expr->path, $data);

        if ($value === null && $expr->fallback !== null) {
            $value = $this->operand($expr->fallback, $data);
        }

        return e($this->stringify($value));
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function renderIf(stdClass $node, array $data): string
    {
        foreach ($node->branches as $branch) {
            if ($branch->cond === null || $this->evaluate($branch->cond, $data)) {
                return $this->renderNodes($branch->children, $data);
            }
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function renderForeach(stdClass $node, array $data): string
    {
        $items = $this->lookup($node->source, $data);

        if ($items instanceof \Traversable) {
            $items = iterator_to_array($items);
        }

        if (! is_array($items)) {
            return '';
        }

        $html = '';
        foreach ($items as $key => $item) {
            $scope = $data;
            $scope[$node->item] = $item;
            if ($node->key !== null) {
                $scope[$node->key] = $key;
            }
            $html .= $this->renderNodes($node->children, $scope);
        }

        return $html;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function evaluate(stdClass $cond, array $data): bool
    {
        $left = $this->operand($cond->left, $data);

        if ($cond->op === null) {
            $truthy = ! empty($left);

            return $cond->negate ? ! $truthy : $truthy;
        }

        $right = $this->operand($cond->right, $data);

        return match ($cond->op) {
            '===' => $left === $right,
            '!==' => $left !== $right,
            '==' => $this->stringify($left) === $this->stringify($right),
            default => $this->stringify($left) !== $this->stringify($right),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function operand(stdClass $operand, array $data): mixed
    {
        return isset($operand->path) ? $this->lookup($operand->path, $data) : $operand->value;
    }

    /**
     * อ่านค่าตามเส้นทาง — อ่านได้เฉพาะคีย์ของอาร์เรย์ ไม่เข้าถึง property หรือเมธอดของอ็อบเจกต์
     *
     * @param  list<string>  $path
     * @param  array<string,mixed>  $data
     */
    private function lookup(array $path, array $data): mixed
    {
        $value = $data;

        foreach ($path as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } elseif ($value instanceof \ArrayAccess && $value->offsetExists($segment)) {
                $value = $value[$segment];
            } else {
                return null;
            }
        }

        return $value;
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '',
            is_scalar($value) => (string) $value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            $value instanceof \Stringable => (string) $value,
            default => '',
        };
    }
}
