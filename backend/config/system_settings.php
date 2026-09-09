<?php

return [
    'public_keys' => [
        'institution_name',
        'short_name_th',
        'organization_logo',
    ],

    'secret_keys' => [
        'telegram_bot_token',
        'line_channel_access_token',
        'line_notify_token',
        'mail_password',
    ],

    'aliases' => [
        'institution_name' => ['school_name'],
        'organization_logo' => ['school_logo_url'],
        'document_number_out' => ['doc_number_prefix'],
    ],

    'rules' => [
        'institution_code' => ['nullable', 'string', 'max:50'],
        'institution_type' => ['nullable', 'string', 'max:100'],
        'institution_name' => ['nullable', 'string', 'max:255'],
        'vocational_province' => ['nullable', 'string', 'max:255'],
        'vocational_institute' => ['nullable', 'string', 'max:255'],
        'organization_logo' => ['nullable', 'string', 'max:2048'],
        'director_name' => ['nullable', 'string', 'max:255'],
        'director_position' => ['nullable', 'string', 'max:255'],
        'deputy_acting_name' => ['nullable', 'string', 'max:255'],
        'deputy_academic_name' => ['nullable', 'string', 'max:255'],
        'deputy_development_name' => ['nullable', 'string', 'max:255'],
        'deputy_admin_name' => ['nullable', 'string', 'max:255'],
        'deputy_planning_name' => ['nullable', 'string', 'max:255'],
        'head_hr_name' => ['nullable', 'string', 'max:255'],
        'head_registrar_name' => ['nullable', 'string', 'max:255'],
        'head_finance_name' => ['nullable', 'string', 'max:255'],
        'head_curriculum_name' => ['nullable', 'string', 'max:255'],
        'head_evaluation_name' => ['nullable', 'string', 'max:255'],
        'head_guidance_name' => ['nullable', 'string', 'max:255'],
        'head_discipline_name' => ['nullable', 'string', 'max:255'],
        'head_activity_name' => ['nullable', 'string', 'max:255'],
        'head_data_center_name' => ['nullable', 'string', 'max:255'],
        'short_name_th' => ['nullable', 'string', 'max:100'],
        'short_name_en' => ['nullable', 'string', 'max:100'],
        'full_name_en' => ['nullable', 'string', 'max:255'],
        'school_type' => ['nullable', 'in:ในระบบ,นอกระบบ'],
        'teaching_language' => ['nullable', 'string', 'max:100'],
        'document_number_out' => ['nullable', 'string', 'max:100'],
        'founding_date' => ['nullable', 'date'],
        'start_time' => ['nullable', 'date_format:H:i'],
        'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
        'default_work_start_time' => ['nullable', 'date_format:H:i'],
        'default_work_end_time' => ['nullable', 'date_format:H:i', 'after:default_work_start_time'],
        'college_latitude' => ['nullable', 'numeric', 'between:-90,90'],
        'college_longitude' => ['nullable', 'numeric', 'between:-180,180'],
        'max_attendance_radius' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        'require_attendance_selfie' => ['nullable', 'in:0,1'],
        'postal_code' => ['nullable', 'digits:5'],
        /*
         * CLEANUP-02 — การเปิด-ปิดโมดูลใช้คีย์ `module_{code}_enabled` ชุดเดียว
         * (เดิม `enable_leave_menu` / `enable_attendance_menu` ไม่มีอะไรอ่านอีกแล้ว)
         */
        'module_leave_enabled' => ['nullable', 'in:0,1'],
        'module_attendance_enabled' => ['nullable', 'in:0,1'],
        'module_overtime_enabled' => ['nullable', 'in:0,1'],
        'module_notification_enabled' => ['nullable', 'in:0,1'],
        'module_reporting_enabled' => ['nullable', 'in:0,1'],
        'cancel_allowed_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        'leave_evidence_retention_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
        'telegram_bot_token' => ['nullable', 'string', 'max:4096'],
        'line_channel_access_token' => ['nullable', 'string', 'max:4096'],
        'line_notify_token' => ['nullable', 'string', 'max:4096'],
        'line_admin_user_id' => ['nullable', 'string', 'max:255'],
        'line_group_id' => ['nullable', 'string', 'max:255'],
        'mail_host' => ['nullable', 'string', 'max:255'],
        'mail_port' => ['nullable', 'integer', 'between:1,65535'],
        'mail_username' => ['nullable', 'string', 'max:255'],
        'mail_password' => ['nullable', 'string', 'max:4096'],
        'mail_encryption' => ['nullable', 'in:tls,ssl,none'],
        'mail_from_address' => ['nullable', 'email', 'max:255'],
        'mail_from_name' => ['nullable', 'string', 'max:255'],
        'school_affiliation' => ['nullable', 'string', 'max:255'],
        'school_address' => ['nullable', 'string', 'max:500'],
        'school_phone' => ['nullable', 'string', 'max:50'],
        'school_email' => ['nullable', 'email', 'max:255'],
        /*
         * เลือกได้เฉพาะฟอนต์ที่มีไฟล์อยู่จริงใน `public/fonts/THSarabunNew`
         * (ดู `LeaveDocumentTemplateResolver::FONT_FILES`) — เดิมมีตัวเลือกที่ระบบไม่มีไฟล์
         * ซึ่งถ้าเลือกแล้วเอกสารจะเรนเดอร์ด้วยฟอนต์ละตินและภาษาไทยกลายเป็นกล่องสี่เหลี่ยม
         */
        'default_font' => ['nullable', 'in:TH Sarabun New,TH Sarabun,TH SarabunIT๙'],
        'margin_top' => ['nullable', 'numeric', 'between:0,10'],
        'margin_bottom' => ['nullable', 'numeric', 'between:0,10'],
        'margin_left' => ['nullable', 'numeric', 'between:0,10'],
        'margin_right' => ['nullable', 'numeric', 'between:0,10'],
    ],
];
