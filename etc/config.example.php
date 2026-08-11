<?php

declare (strict_types = 1);

/**
 * ค่าตั้งของ PHP Server Control Panel
 *
 * ติดตั้งแบบ system : คัดลอกไปที่ /etc/phpcp/config.php (สิทธิ์ 0640 root:phpcp)
 * ทดสอบแบบ portable : คัดลอกเป็น etc/config.php ในโฟลเดอร์โปรเจกต์
 *
 * ไฟล์นี้มี secret key จึงห้าม world-readable และห้าม commit เข้า git
 */

return [
    /**
     * production — ทำงานกับระบบจริง ต้องรัน agent ด้วย root
     * sandbox    — เปลี่ยนเส้นทางทุกอย่างเข้า prefix ไม่แตะระบบจริง (สำหรับเครื่องทดสอบ)
     * dryrun     — ไม่ทำอะไรเลย แสดงเฉพาะคำสั่งที่จะรัน
     */
    'mode' => 'sandbox',

    /**
     * system   — /etc/phpcp, /var/lib/phpcp, /run/phpcp, /var/log/phpcp
     * portable — ทุกอย่างอยู่ใต้โฟลเดอร์โปรเจกต์ (ใช้ตอนพัฒนา ไม่ต้องใช้ root)
     * ว่าง     — ตรวจจากสภาพเครื่องให้อัตโนมัติ
     */
    'layout' => 'portable',

    'panel' => [
        'port' => 8443,
        'base_url' => '',

        // ต้องเป็น true เมื่อให้บริการผ่าน HTTPS จริง (คุกกี้จะใช้ prefix __Host- ให้เอง)
        'cookie_secure' => false,

        'session_ttl' => 28800,
        'session_idle' => 1800,
        'session_rotate' => 900,

        // จำกัด IP ที่เข้า panel ได้ เช่น ['203.0.113.0/24', '10.0.0.5'] — ว่าง = ไม่จำกัด
        'ip_allowlist' => [],
        'trusted_proxies' => []
    ],

    'agent' => [
        'socket' => '',
        'timeout' => 30,

        // uid ที่ยอมให้ต่อ socket ได้ (root ต่อได้เสมอ) — ว่าง = พึ่งสิทธิ์ไฟล์ของ socket อย่างเดียว
        'allowed_uids' => []
    ],

    'sandbox' => [
        'prefix' => ''
    ],

    'sites' => [
        // ที่เก็บไฟล์ของเว็บไซต์ทุกเว็บ — โครงสร้างคือ <dir>/<โดเมน>/{public,logs,tmp,backups}
        // เปลี่ยนค่านี้แล้วเว็บเดิมจะหาไฟล์ไม่เจอ ต้องย้ายโฟลเดอร์ตามแล้วรัน `phpcp sites:rebuild`
        'dir' => '/srv/phpcp/sites',

        /**
         * ยอมรับว่า filesystem ที่เก็บเว็บไซต์เก็บเจ้าของไฟล์ไม่ได้ จึงข้ามการแยกสิทธิ์ระหว่างเว็บ
         *
         * เปิดได้เฉพาะเมื่อ dir อยู่บน NTFS / exFAT / FAT เท่านั้น — agent จะทดสอบ chown จริง
         * ก่อนทำงานทุกครั้ง ถ้า filesystem รองรับ ownership อยู่แล้วจะปฏิเสธจนกว่าจะปิดค่านี้
         * ตั้งใจให้เป็นแบบนี้ เพื่อไม่ให้ค่านี้หลุดขึ้น server จริงแล้วลดความปลอดภัยโดยเงียบ ๆ
         *
         * เปิดแล้วจะเสียอะไร: เว็บหนึ่งอ่าน/เขียนไฟล์ของอีกเว็บได้ในระดับ filesystem
         * สิ่งที่ยังกันอยู่: open_basedir และ disable_functions ของแต่ละ FPM pool
         *
         * ห้ามใช้กับเครื่องที่ให้บริการเว็บของคนอื่น
         */
        'shared_owner' => false,

        /**
         * โฟลเดอร์นอก dir ที่ยอมให้ชี้ DocumentRoot เข้าไปได้ (Domain Pointer)
         *
         * ว่าง = ชี้ได้เฉพาะภายใน dir เท่านั้น
         * ใส่เฉพาะโฟลเดอร์ที่เก็บโค้ดเว็บจริง ๆ ห้ามใส่ / หรือ /etc หรือ /home
         * เพราะเท่ากับเปิดให้สร้าง vhost เสิร์ฟไฟล์ทั้งเครื่องผ่านเว็บ
         */
        'pointer_roots' => []
    ],

    // เว็บเซิร์ฟเวอร์ที่ใช้โฮสต์เว็บไซต์ของผู้ใช้ — 'apache' หรือ 'nginx'
    //
    // เปลี่ยนค่านี้แล้วต้องสร้างไฟล์ vhost ของทุกเว็บไซต์ใหม่ทั้งหมด
    // (`phpcp sites:rebuild`) เพราะไฟล์เดิมเป็นรูปแบบของเซิร์ฟเวอร์ตัวเก่า
    //
    // ข้อควรรู้ก่อนเลือก nginx: ไม่มี .htaccess — เว็บที่พึ่งไฟล์นั้นจะทำงานไม่เหมือนเดิม
    // ต้องแปลงกฎเป็นรูปแบบของ nginx เอง
    'webserver' => 'apache',

    'security' => [
        // สร้างด้วย `bin/phpcp key:generate` — เป็น base64 ของข้อมูล 32 ไบต์
        'secret_key' => '',

        'require_2fa_roles' => ['superadmin', 'sysadmin'],
        'max_login_attempts' => 5,
        'lockout_seconds' => 900,
        'password_min_length' => 12
    ],

    'log' => [
        'level' => 'info'
    ]
];
