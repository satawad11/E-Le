<?php

namespace App\Services;

use Carbon\Carbon;

class ThaiDateService
{
    /**
     * Convert Gregorian (A.D.) date to Thai Buddhist Date format (e.g. 13/06/2569)
     */
    public function formatToThaiSlash(string $date): string
    {
        $carbonDate = Carbon::parse($date);
        $thaiYear = $carbonDate->year + 543;

        return $carbonDate->format('d/m/').$thaiYear;
    }

    /**
     * Convert Gregorian (A.D.) date to Thai text format (e.g. 13 มิถุนายน 2569)
     */
    public function formatToThaiText(string $date): string
    {
        $carbonDate = Carbon::parse($date);
        $thaiYear = $carbonDate->year + 543;
        $day = $carbonDate->day;

        $months = [
            1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
            5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
            9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม',
        ];
        $month = $months[$carbonDate->month];

        return "{$day} {$month} {$thaiYear}";
    }

    /**
     * Convert Gregorian (A.D.) date to Thai text format (alias for formatToThaiText)
     * e.g. 13 มิถุนายน 2569
     */
    public function toThaiDate(string $date): string
    {
        return $this->formatToThaiText($date);
    }

    /**
     * Convert Thai Buddhist Date string (d/m/Y) to Gregorian (Y-m-d)
     */
    public function parseFromThaiSlash(string $thaiDate): string
    {
        [$day, $month, $year] = explode('/', $thaiDate);
        $gregorianYear = (int) $year - 543;

        return Carbon::createFromDate($gregorianYear, $month, $day)->format('Y-m-d');
    }
}
