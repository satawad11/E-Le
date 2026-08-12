<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// เดิมมี route `GET /fix-leaves` ที่เขียนทับ document_number ของใบลาทุกใบที่ยังว่าง
// โดยไม่ต้องยืนยันตัวตน — ถอดออกแล้วเมื่อ 2026-08-12 (SEC-011)
//
// งานเดียวกันนี้ทำผ่าน artisan command ที่มีอยู่แล้ว:
//     php artisan leaves:fix-document-numbers
