<?php

namespace App\Services\DocumentEngine;

use setasign\Fpdi\Fpdi;

/**
 * FPDI ที่วาดข้อความหมุนและกำหนดระยะห่างตัวอักษรได้
 *
 * แบบฟอร์มราชการบางฉบับมีข้อความตามแนวตั้ง (เช่น ป้ายริมกระดาษ) และมีช่องกรอก
 * ที่แบ่งเป็นกล่องทีละตัวอักษร ซึ่ง FPDF มาตรฐานทำไม่ได้ ทั้งสองอย่างทำได้ด้วย
 * ตัวดำเนินการของ PDF โดยตรง จึงห่อไว้ที่นี่ที่เดียวแทนที่จะกระจายไปอยู่ใน renderer
 */
class OverlayPdf extends Fpdi
{
    private float $angle = 0;

    /**
     * หมุนระบบพิกัดรอบจุด (x, y) — เรียกด้วยมุม 0 เพื่อยกเลิกการหมุนเสมอ
     *
     * ใช้วิธีมาตรฐานของ FPDF (สคริปต์ Rotation) คือครอบด้วย q/Q แล้วใส่เมทริกซ์ cm
     */
    public function rotate(float $angle, float $x, float $y): void
    {
        if ($this->angle !== 0.0) {
            $this->_out('Q');
        }

        $this->angle = $angle;

        if ($angle === 0.0) {
            return;
        }

        $radian = $angle * M_PI / 180;
        $cos = cos($radian);
        $sin = sin($radian);
        $cx = $x * $this->k;
        $cy = ($this->h - $y) * $this->k;

        $this->_out(sprintf(
            'q %.5F %.5F %.5F %.5F %.2F %.2F cm 1 0 0 1 %.2F %.2F cm',
            $cos, $sin, -$sin, $cos, $cx, $cy, -$cx, -$cy,
        ));
    }

    /**
     * ระยะห่างระหว่างตัวอักษร (ตัวดำเนินการ Tc ของ PDF)
     *
     * เป็นค่าใน text state จึงมีผลกับทุกข้อความที่วาดต่อจากนี้จนกว่าจะตั้งกลับเป็น 0
     * เหมาะกับช่องตัวเลข/ภาษาอังกฤษที่แบ่งเป็นกล่องทีละตัว ส่วนข้อความไทยที่มีสระบนล่าง
     * จะถูกถ่างตามไปด้วย จึงไม่ควรใช้กับช่องข้อความไทยทั่วไป
     */
    public function setCharSpacing(float $spacing): void
    {
        $this->_out(sprintf('BT %.3F Tc ET', $spacing));
    }

    protected function _endpage()
    {
        if ($this->angle !== 0.0) {
            $this->angle = 0;
            $this->_out('Q');
        }

        parent::_endpage();
    }
}
