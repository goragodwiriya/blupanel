<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\MailboxRepository;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Mail\MailboxManager;
use Phpcp\Driver\Mail\MailCertificate;
use Phpcp\Driver\Ssl\CertbotManager;

/**
 * ผูกใบรับรองของ mail hostname เข้ากับ Postfix และ Dovecot — PLAN-MAIL เฟส M3
 *
 * ทำสองอย่างที่ต้องมีคนทำแต่ยังไม่มีใครทำ:
 *
 *   1. **หาใบที่ครอบคลุม mail hostname** ที่มีอยู่แล้วบนเครื่อง แล้วชี้ให้เดมอนทั้งสอง
 *      ใช้ใบนั้นแทนใบ snakeoil ของดิสโทร ที่โปรแกรมเมลทุกตัวขึ้นคำเตือน
 *   2. **บอกเดมอนเมื่อใบถูกต่ออายุ** · certbot ต่ออายุเองทุก 60 วันโดยไม่ผ่าน panel
 *      เลย และ Dovecot ถือใบที่อ่านตอนสตาร์ตไว้จนกว่าจะถูกสั่ง reload — ไม่มีงานนี้
 *      ลูกค้าจะเจอใบหมดอายุตอนเปิดกล่อง ทั้งที่ไฟล์บนดิสก์เป็นใบใหม่เรียบร้อยแล้ว
 *      และไม่มีอะไรบนหน้าจอผิดปกติสักอย่าง
 *
 * **ไม่ขอใบเอง** — เหตุผลอยู่ที่หัวคลาส `MailCertificate` · ผู้ดูแลเพิ่ม mail hostname
 * เป็นโดเมนของเว็บไซต์แล้วกดปุ่มขอใบรับรองที่มีอยู่ในหน้า SSL ตามปกติ
 *
 * **ไม่โยนข้อผิดพลาดเมื่อยังไม่มีใบ** เพราะงานนี้ทำงานตามเวลาทุกวันด้วย · เครื่องที่
 * ยังไม่ได้ขอใบไม่ใช่เครื่องที่ผิดพลาด มันแค่ยังไม่ถึงขั้นนั้น — งานที่ล้มทุกวันคือ
 * งานที่ผู้ดูแลเลิกอ่านภายในสัปดาห์เดียว แล้ววันที่ล้มจริงจะไม่มีใครเห็น
 */
final class MailCert extends MailCapability
{
    public static function name(): string
    {
        return 'mail.cert';
    }

    /**
     * ใบรับรองของ mail hostname เป็นของทั้งเครื่อง ไม่ใช่ของโดเมนใดโดเมนหนึ่ง —
     * เจ้าของเว็บที่จัดการกล่องของตัวเองได้ ไม่ควรเปลี่ยนใบที่ทุกโดเมนบนเครื่องใช้ร่วมกัน
     */
    public function permission(): string
    {
        return 'settings.manage';
    }

    public function summary(): string
    {
        return 'ผูกใบรับรองของ mail hostname เข้ากับ Postfix และ Dovecot';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);
        // ชื่อเดียวกับที่ Postfix ประกาศจริง ไม่ใช่แค่ค่าในช่องกรอก — ดู mailHostname()
        $hostname = self::mailHostname($settings);
        $domains = (new MailboxRepository($context->db))->enabledDomains();

        /*
         * ไม่มีโดเมนไหนเปิดเมล = ไม่มีส่วนรับเมลใน main.cf เลย จึงไม่มีที่ให้ใส่ใบ ·
         * เขียนไฟล์ตั้งค่าใหม่ตรงนี้มีแต่จะกวนเครื่องที่ใช้ Postfix ส่งเมลแจ้งเตือนเฉย ๆ
         */
        if ($domains === []) {
            return $this->idle('ยังไม่มีโดเมนไหนเปิดเมล — ใบรับรองของเมลยังไม่มีผลกับอะไร');
        }

        if ($hostname === '') {
            return $this->idle('ยังไม่ได้ตั้งชื่อโฮสต์ของเมล — ตั้งในหน้าตั้งค่าก่อน แล้วค่อยผูกใบรับรอง');
        }

        $certificates = new MailCertificate(new CertbotManager());
        $found = $certificates->locate($executor, $hostname);

        // ยังไม่มีใบจริง = ใช้ใบของดิสโทรไปก่อน · **ยังต้องเขียนลงไฟล์ตั้งค่าอยู่ดี**
        // ดูเหตุผลที่ drift() — บรรทัดที่ไม่มีอยู่จริงคือบรรทัดที่แก้ไม่ได้ในวันที่มีใบ
        $desired = MailCertificate::pathsOrDefault(
            (string) ($found['cert'] ?? ''),
            (string) ($found['key'] ?? ''),
        );

        $moved = $settings->get('mail.tls_cert') !== ($found['cert'] ?? '');

        /*
         * ต่ออายุแล้วเส้นทางเหมือนเดิมทุกตัวอักษร — เทียบเส้นทางอย่างเดียวจึงไม่พอ
         * และเป็นกรณีที่เกิดบ่อยที่สุด (ทุก 60 วัน ตลอดอายุของเครื่อง)
         */
        $renewed = $found !== null
            && $certificates->changedSince($executor, $found['cert'], MailboxManager::DOVECOT_CONF);

        $drifted = $this->drift($executor, $desired['cert'])
            || $this->outdated($executor, $context);

        if (!$moved && !$renewed && !$drifted) {
            return $this->idle(
                $found === null
                    ? sprintf(
                        'ยังไม่มีใบรับรองที่ครอบคลุม %s บนเครื่องนี้ — เพิ่ม %s เป็นโดเมนของเว็บไซต์ '
                        . 'แล้วกดขอใบรับรองในหน้า SSL ตามปกติ จากนั้นเมลจะใช้ใบนั้นตามไปเอง · '
                        . 'ชื่อที่ยังไม่มี DNS สาธารณะ (เช่นลงท้าย .test หรือ .local) ขอใบจริงไม่ได้เลย '
                        . 'ให้เลือกวิธี "ใบที่เซ็นเอง" แทน — เมลใช้ใบแบบนั้นได้เหมือนกัน '
                        . 'อย่างน้อยชื่อในใบก็ตรงกับชื่อที่เครื่องประกาศ '
                        . '(ระหว่างนี้ใช้ใบของดิสโทรไปก่อน โปรแกรมเมลจะขึ้นคำเตือน)',
                        $hostname,
                        $hostname,
                    )
                    : sprintf('ใบรับรองของ %s เป็นปัจจุบันอยู่แล้ว (เหลือ %d วัน)', $hostname, $found['days_left']),
                $found,
            );
        }

        $settings->save([
            'mail.tls_cert' => (string) ($found['cert'] ?? ''),
            'mail.tls_key' => (string) ($found['key'] ?? ''),
        ]);

        // เขียน main.cf กับ 99-phpcp.conf ใหม่ทั้งชุดแล้ว reload ทั้งสองเดมอน
        $this->sync($executor, $context);

        return [
            'found' => $found !== null,
            'changed' => true,
            'hostname' => $hostname,
            'certificate' => $found,
            'message' => match (true) {
                $found === null => sprintf(
                    'ยังไม่มีใบรับรองที่ครอบคลุม %s — ตั้งให้ Dovecot กับ Postfix ใช้ใบของดิสโทรใบเดียวกันไว้ก่อน '
                    . 'แล้วขอใบจริงในหน้า SSL เมื่อพร้อม',
                    $hostname,
                ),
                $moved => sprintf(
                    'เมลใช้ใบรับรองของ %s แล้ว (%s · เหลือ %d วัน)',
                    $hostname,
                    $found['source'] === 'letsencrypt' ? "Let's Encrypt" : 'ใบที่เซ็นเอง',
                    $found['days_left'],
                ),
                default => sprintf(
                    'ใบรับรองของ %s เปลี่ยนไป บอก Postfix กับ Dovecot ให้อ่านใบใหม่เรียบร้อย (เหลือ %d วัน)',
                    $hostname,
                    $found['days_left'],
                ),
            },
        ];
    }

    /**
     * ไฟล์ตั้งค่าที่ใช้อยู่จริงพูดถึงใบที่เราต้องการหรือเปล่า
     *
     * **จำเป็นเพราะไฟล์เหล่านี้ถูกเขียนใหม่เฉพาะตอนมีคนแตะกล่องจดหมายเท่านั้น** ·
     * อัปเกรด panel ที่เพิ่มบรรทัดใหม่ลงเทมเพลตจึงไม่มีผลกับเครื่องที่ตั้งเมลเสร็จไปแล้ว
     * จนกว่าจะมีใครสร้างหรือลบกล่องสักกล่อง — ซึ่งอาจไม่เกิดขึ้นอีกเลยเป็นปี
     *
     * เจอจริงบนเครื่องจริง: `doveconf -n` ตอบว่า `ssl_cert` เป็นใบของดิสโทร ทั้งที่
     * เทมเพลตตั้งให้แล้ว เพราะ `99-phpcp.conf` บนดิสก์ยังเป็นไฟล์ที่สร้างก่อนหน้านั้น ·
     * ผลคือคุณสมบัติที่ "ทำเสร็จแล้ว" ไม่เคยไปถึงเครื่องที่ใช้งานอยู่จริงสักเครื่อง
     */
    /**
     * ไฟล์ตั้งค่าบนเครื่องเก่ากว่าเทมเพลตที่ใช้สร้างมันหรือเปล่า
     *
     * **ปัญหาที่กัดซ้ำสามครั้งในเฟสนี้:** ติดตั้ง panel รุ่นใหม่ไม่ได้แปลว่าไฟล์ใน `/etc`
     * ถูกเขียนใหม่ · ไฟล์พวกนั้นถูกเขียนเฉพาะตอนมีคนสั่งงานเมลเท่านั้น เครื่องที่ตั้งเมล
     * เสร็จไปแล้วจึงถือไฟล์รุ่นก่อนอัปเกรดต่อไปเรื่อย ๆ — การแก้เทมเพลตไปไม่ถึงเครื่องจริง
     * สักเครื่อง และไม่มีอะไรฟ้องเพราะเมลยังทำงานปกติทุกอย่าง
     *
     * เทียบเวลาแก้ไขของเทมเพลตกับไฟล์ที่มันสร้าง — ตอบคำถาม "อัปเกรดแล้วแต่ยังไม่ได้
     * เขียนใหม่" ได้ตรง ๆ โดยไม่ต้องรู้ว่าเทมเพลตเปลี่ยนอะไรไปบ้าง · แบบเดียวกับที่
     * `webserver.rescan` ทำให้ vhost
     */
    private function outdated(Executor $executor, Context $context): bool
    {
        $templates = rtrim($context->config->paths->templates(), '/');

        // เทมเพลต → ไฟล์ที่มันสร้าง · main.cf ถูกประกอบจากสองเทมเพลต จึงเทียบทั้งคู่
        $pairs = [
            $templates . '/postfix/main.cf.tpl' => '/etc/postfix/main.cf',
            $templates . '/postfix/hosting.cf.tpl' => '/etc/postfix/main.cf',
            $templates . '/postfix/master.cf.tpl' => '/etc/postfix/master.cf',
            $templates . '/dovecot/99-phpcp.conf.tpl' => MailboxManager::DOVECOT_CONF,
        ];

        foreach ($pairs as $template => $generated) {
            /*
             * **เทมเพลตอ่านตรงจากดิสก์ ไม่ผ่าน Executor** — มันเป็นไฟล์ของตัว panel เอง
             * ที่มาพร้อมโค้ด ไม่ใช่ไฟล์บนเครื่องที่ agent ดูแล · ส่งผ่าน Executor เมื่อไหร่
             * เส้นทางจะถูกเติมรากของ sandbox แล้วหาไฟล์ไม่เจอ กลายเป็น "ไม่มีอะไรเก่า"
             * ตลอดกาลโดยไม่มีอะไรฟ้อง
             */
            /*
             * ล้างแคชของ stat ก่อนอ่านเสมอ — `phpcp-agentd` เป็นโปรเซสที่อยู่ยาว
             * ค่าที่ PHP จำไว้จากรอบก่อนจะค้างข้ามการอัปเกรด แล้วงานนี้จะตอบว่า
             * "ไม่มีอะไรเปลี่ยน" ตลอดไปทั้งที่เทมเพลตถูกแทนที่ไปแล้ว
             */
            clearstatcache(true, $template);

            $source = @filemtime($template);

            if ($source === false) {
                continue;   // ไม่มีเทมเพลตนี้ในรุ่นที่ติดตั้งอยู่ — ไม่ใช่เรื่องของงานนี้
            }

            $target = $executor->stat($executor->path($generated));

            if ($target === null || $source > $target['mtime']) {
                return true;
            }
        }

        return false;
    }

    private function drift(Executor $executor, string $certPath): bool
    {
        try {
            $conf = $executor->readFile($executor->path(MailboxManager::DOVECOT_CONF));
        } catch (\Throwable) {
            // อ่านไม่ได้หรือยังไม่มีไฟล์ = ยังไม่ได้บอก Dovecot เรื่องใบนี้
            return true;
        }

        return !str_contains($conf, 'ssl_cert = <' . $certPath);
    }

    /**
     * ไม่มีอะไรต้องทำ — ยังเป็นผลสำเร็จ ไม่ใช่ข้อผิดพลาด
     *
     * @param array<string,mixed>|null $certificate
     * @return array<string,mixed>
     */
    private function idle(string $message, ?array $certificate = null): array
    {
        return [
            'found' => $certificate !== null,
            'changed' => false,
            'certificate' => $certificate,
            'message' => $message,
        ];
    }
}
