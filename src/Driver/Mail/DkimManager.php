<?php

declare(strict_types=1);

namespace Phpcp\Driver\Mail;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Driver\Template;

/**
 * เซ็น DKIM ให้เมลขาออก — PLAN-MAIL เฟส M3
 *
 * ## ทำไมต้องมี
 *
 * เมลที่ไม่ได้เซ็น DKIM เข้าถังขยะของ Gmail/Outlook แทบทุกฉบับ ไม่ว่า SPF จะถูกแค่ไหน ·
 * DKIM คือลายเซ็นดิจิทัลที่แนบไปกับเมล ปลายทางเอากุญแจสาธารณะจาก DNS ของโดเมนมาตรวจ
 * ว่าเมลออกจากเครื่องที่เจ้าของโดเมนอนุญาตจริงและไม่ถูกแก้ระหว่างทาง
 *
 * ## ทำไม rspamd ไม่ใช่ OpenDKIM
 *
 * rspamd เดมอนเดียวทำครบทั้งเซ็น DKIM กรองสแปม และจำกัดอัตราส่ง — สามอย่างที่เดิม
 * ต้องสามโปรแกรม สามไฟล์ตั้งค่า และสามจุดที่พังได้ · ที่สำคัญกว่าคือ rspamd อ่าน
 * กุญแจจาก**เส้นทางที่มีตัวแปร** (`/var/lib/rspamd/dkim/$domain.key`) จึงเพิ่มโดเมน
 * ใหม่ได้โดยไม่ต้องแก้ไฟล์ตั้งค่าเลย — วางไฟล์กุญแจแล้วจบ
 */
final class DkimManager
{
    /** ชื่อ selector ที่ใช้กับทุกโดเมน — เป็นส่วนหนึ่งของชื่อเรกคอร์ด DNS */
    public const SELECTOR = 'phpcp';

    private const KEY_DIR = '/var/lib/rspamd/dkim';
    private const SIGNING_CONF = '/etc/rspamd/local.d/dkim_signing.conf';
    private const RSPAMADM = '/usr/bin/rspamadm';

    public function __construct(private readonly Template $templates)
    {
    }

    public function isInstalled(Executor $executor): bool
    {
        return $executor->isSimulated() || $executor->exists($executor->path('/etc/rspamd'));
    }

    public function keyPath(string $domain): string
    {
        return self::KEY_DIR . '/' . $domain . '.key';
    }

    /**
     * สร้างกุญแจของโดเมนถ้ายังไม่มี แล้วคืนค่าที่ต้องใส่ใน DNS
     *
     * **ไม่สร้างทับของเดิม** — กุญแจใหม่แปลว่าเรกคอร์ดใน DNS ต้องเปลี่ยนตาม และ
     * ระหว่างที่ยังไม่เปลี่ยน เมลทุกฉบับจะตรวจลายเซ็นไม่ผ่าน ซึ่งแย่กว่าไม่เซ็นเลย
     *
     * @return array{selector:string,record:string,created:bool}
     */
    public function ensureKey(Executor $executor, string $domain): array
    {
        $path = $executor->path($this->keyPath($domain));
        $created = false;

        if (!$executor->exists($path)) {
            $executor->makeDirectory($executor->path(self::KEY_DIR), 0750);

            $result = $executor->exec([
                $executor->path(self::RSPAMADM), 'dkim_keygen',
                '-s', self::SELECTOR,
                '-d', $domain,
                '-b', '2048',
                '-k', $path,
            ], timeout: 60);

            if (!$result->ok()) {
                throw new ExecutionFailed('สร้างกุญแจ DKIM ไม่สำเร็จ: ' . trim($result->stderr));
            }

            // rspamd อ่านกุญแจด้วยผู้ใช้ของตัวเอง — ไฟล์นี้คือความลับที่ปลอมลายเซ็นได้ถ้าหลุด
            $executor->exec(['/bin/chown', '-R', '_rspamd:_rspamd', $executor->path(self::KEY_DIR)], timeout: 15);
            $executor->changeMode($path, 0600);

            $created = true;
        }

        return [
            'selector' => self::SELECTOR,
            'record' => $this->publicRecord($executor, $domain),
            'created' => $created,
        ];
    }

    /** ลบกุญแจของโดเมนที่ปิดเมลไปแล้ว */
    public function removeKey(Executor $executor, string $domain): void
    {
        $path = $executor->path($this->keyPath($domain));

        if ($executor->exists($path)) {
            $executor->exec(['/bin/rm', '-f', $path], timeout: 15);
        }
    }

    /**
     * ค่าที่ต้องใส่ใน TXT ของ `<selector>._domainkey.<โดเมน>`
     *
     * อ่านกุญแจสาธารณะจากไฟล์ส่วนตัวโดยตรง แทนที่จะเก็บไฟล์ `.txt` ที่ rspamadm
     * สร้างให้ — ไฟล์นั้นหายได้ แต่กุญแจส่วนตัวหายไม่ได้ (ลายเซ็นทั้งหมดจะใช้ไม่ได้)
     * จึงอนุมานจากแหล่งที่เชื่อถือได้กว่าเสมอ
     */
    private function publicRecord(Executor $executor, string $domain): string
    {
        $result = $executor->exec([
            $executor->path('/usr/bin/openssl'), 'rsa',
            '-in', $executor->path($this->keyPath($domain)),
            '-pubout', '-outform', 'PEM',
        ], timeout: 30);

        if (!$result->ok()) {
            return '';
        }

        $body = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $result->stdout) ?? '';

        return 'v=DKIM1; k=rsa; p=' . $body;
    }

    /**
     * ไฟล์ตั้งค่าของ rspamd — เขียนครั้งเดียว ไม่ต้องแก้เมื่อเพิ่มโดเมน
     *
     * @return array<string,string>
     */
    public function configFiles(): array
    {
        return [
            self::SIGNING_CONF => $this->templates->render('rspamd/dkim_signing.conf.tpl', [
                'KEY_DIR' => self::KEY_DIR,
                'SELECTOR' => self::SELECTOR,
                'GENERATED_AT' => date('Y-m-d H:i:s'),
            ]),
        ];
    }

    public function apply(Executor $executor): void
    {
        $transaction = new ConfigTransaction($executor);

        foreach ($this->configFiles() as $path => $contents) {
            $transaction->write($path, $contents, 0644);
        }

        $transaction->commit(static fn (): array => [true, '']);

        /*
         * ใช้คำสั่งของ rspamd เองก่อน — เสร็จในเสี้ยววินาทีและไม่ตัดการเชื่อมต่อ
         * `systemctl restart` ของ rspamd ใช้เวลานานกว่าที่ agent รอไหวบนเครื่องที่
         * ทรัพยากรจำกัด แล้วคำสั่งเปิดเมลทั้งคำสั่งล้มทั้งที่ไฟล์ถูกเขียนครบแล้ว
         * (บทเรียนเดียวกับ postfix/dovecot ในเฟส M1)
         */
        // `systemctl reload` ส่ง SIGHUP ให้ rspamd อ่านค่าใหม่ — เร็วและไม่หยุดบริการ
        //
        // **ห้ามใช้ `rspamadm control reload`** — ในการทดสอบจริงมันทำให้ rspamd
        // ปิดตัวเองทั้งกระบวนการ แล้วเมลที่ส่งหลังจากนั้นออกไปโดยไม่มีลายเซ็นเงียบ ๆ
        // (milter_default_action = accept แปลว่าเมลยังส่งได้ แค่ไม่ถูกเซ็น)
        if ($executor->exec([$executor->path('/usr/bin/systemctl'), 'reload', 'rspamd'], timeout: 15)->ok()) {
            return;
        }

        $executor->exec([$executor->path('/usr/bin/systemctl'), 'reload-or-restart', 'rspamd'], timeout: 20);
    }
}
