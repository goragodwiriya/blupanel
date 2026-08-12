<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Agent\AgentException;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * สะพานเข้า phpMyAdmin แบบไม่ต้องพิมพ์รหัส — PLAN-V2 เฟส M5
 *
 * phpMyAdmin รองรับ `$cfg['Servers'][$i]['auth_type'] = 'signon'` ซึ่งอ่านชื่อผู้ใช้และ
 * รหัสผ่านจาก session ของ PHP ที่แอปอื่นเตรียมไว้ให้ · เราจึงขอบัญชี MariaDB ประจำผู้ใช้
 * จาก agent (ที่เดียวที่ถอดรหัสได้) แล้วยัดลง session นั้น
 *
 * **ทำไมยังเป็น POST ทั้งที่ย้ายมาเป็น REST แล้ว**
 * นี่คือ "การล็อกอิน" ไม่ใช่การอ่านข้อมูล — ถ้าเป็น GET เว็บใดก็ตามที่ผู้ใช้เปิดอยู่
 * สามารถฝัง `<img src="https://panel:8443/api/v2/phpmyadmin/session">` แล้วทำให้เบราว์เซอร์
 * ของเหยื่อสร้าง session ของ phpMyAdmin ขึ้นมาเงียบ ๆ ได้ · POST บังคับให้ผ่านด่าน CSRF
 *
 * **ต่างจากตัวเดิมตรงเดียว: ตอบ JSON แทนที่จะ redirect**
 * SPA ยิงคำขอนี้ด้วย `fetch` จึงตาม 302 เองไม่ได้อย่างมีประโยชน์ · คุกกี้ signon ยัง
 * ถูกตั้งจากคำตอบนี้ตามปกติเพราะเป็น origin เดียวกัน หน้าเว็บจึงแค่พาไปที่ `data.url`
 * ต่อได้ทันที · **รหัสผ่านยังไม่เคยออกไปที่หน้าเว็บเลยแม้แต่ครั้งเดียว** — มันเดินจาก
 * agent → session ของ phpMyAdmin เท่านั้น ไม่ผ่าน HTML ไม่ผ่าน query string
 * และไม่ผ่าน JSON ที่ JavaScript อ่านได้
 */
final class PhpMyAdminController extends ApiController
{
    /**
     * ชื่อ session ที่ phpMyAdmin จะไปอ่าน — ต้องตรงกับ `SignonSession` ใน config.inc.php
     *
     * ตั้งชื่อไม่ให้ชนกับ session ของ panel เอง เพราะทั้งสองอยู่บนโดเมนเดียวกัน
     */
    private const SIGNON_SESSION = 'phpcp_pma_signon';

    public function create(Request $request): Response
    {
        if (!$this->app->config->paths->hasPhpMyAdmin()) {
            return $this->problem(ApiProblem::NotFound, 'phpMyAdmin is not installed on this machine');
        }

        try {
            $credentials = $this->agent()->data('db.account_credentials', [], $this->ctx->actor($request));
        } catch (AgentException $e) {
            return $this->problem(ApiProblem::InternalError, $this->t('The database account could not be prepared') . ': '.$e->getMessage());
        }

        if (!$this->writeSignonSession((string) $credentials['user'], (string) $credentials['password'])) {
            return $this->problem(
                ApiProblem::InternalError,
                $this->t('The phpMyAdmin session could not be opened — check that session.save_path of pool ')
                .'ชี้ไปยังโฟลเดอร์ที่อยู่ใน open_basedir และเขียนได้',
            );
        }

        // ไปที่หน้าโครงสร้างของฐานข้อมูลที่ระบุ ถ้าไม่ระบุก็หน้าแรก
        $database = $request->payloadString('db');
        $url = '/phpmyadmin/';

        if ($database !== '' && preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) === 1) {
            $url .= 'index.php?route=/database/structure&db='.rawurlencode($database);
        }

        return $this->ok(['url' => $url]);
    }

    /**
     * เขียนชื่อผู้ใช้และรหัสผ่านลง session ที่ phpMyAdmin จะมาอ่าน
     *
     * ต้องปิด session ทันทีหลังเขียน — ถ้าเปิดค้างไว้ คำขอถัดไปของผู้ใช้คนเดียวกัน
     * (ซึ่งคือการเปิด phpMyAdmin นั่นเอง) จะถูกล็อกรอจนหมดเวลา
     *
     * session ของ panel ต้องถูกปิดก่อนสลับชื่อ ไม่งั้นข้อมูลของทั้งสองจะปนกัน
     */
    private function writeSignonSession(string $user, string $password): bool
    {
        $previousName = session_name();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_name(self::SIGNON_SESSION);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        // session ของ PHP เขียนไม่ได้เมื่อ session.save_path อยู่นอก open_basedir ซึ่งเป็น
        // ค่าปริยายบน Debian (/var/lib/php/sessions) · ต้องจับเองแล้วบอกให้ชัด ไม่ใช่ปล่อย
        // ให้กลายเป็น 500 ที่ไม่มีใครเดาสาเหตุถูก — เจอจากการทดสอบบนเครื่องจริง
        if (!@session_start()) {
            session_name($previousName);

            return false;
        }

        // หมุน id ทุกครั้งที่ออกบัตรใหม่ — กัน session fixation แบบเดียวกับตอนล็อกอิน panel
        session_regenerate_id(true);

        $_SESSION['PMA_single_signon_user'] = $user;
        $_SESSION['PMA_single_signon_password'] = $password;
        $_SESSION['PMA_single_signon_host'] = 'localhost';

        session_write_close();
        session_name($previousName);

        return true;
    }
}
