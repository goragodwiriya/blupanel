<?php

declare (strict_types = 1);

namespace Phpcp\Driver\Firewall;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;

/**
 * จัดการ firewall ผ่าน ufw — PROMPT.md ระบุว่า "อย่าทำให้ซับซ้อนเกินจำเป็น"
 *
 * เลือก ufw เพราะเป็นเครื่องมือมาตรฐานบน Debian/Ubuntu และมีรูปแบบคำสั่งที่ตรงไปตรงมา
 * ผู้ดูแลที่คุ้นกับ ufw อยู่แล้วจะเข้าใจสิ่งที่ panel ทำได้ทันทีโดยไม่ต้องเรียนรู้ใหม่
 *
 * ความปลอดภัย: ทุก argument เป็นตัวเลขหรือ IP ที่ผ่านการตรวจแล้ว
 * และกฎที่อนุญาตพอร์ตของ panel เองถูกปักหมุด ลบไม่ได้ (ARCHITECTURE §5.4)
 */
final class UfwDriver
{
    private const BINARY = '/usr/sbin/ufw';

    /**
     * โปรโตคอลที่รองรับ — ufw รองรับมากกว่านี้ แต่ที่ใช้จริงมีแค่นี้
     *
     * 'any' มีไว้เพื่ออ่านและย้อนกลับกฎเดิมที่ผู้ดูแลสร้างไว้เองโดยไม่ระบุโปรโตคอล
     * แบบฟอร์มเพิ่มกฎในหน้าเว็บให้เลือกได้แค่ tcp กับ udp เท่านั้น
     */
    private const PROTOCOLS = ['tcp', 'udp', 'any'];

    /**
     * ตั้งใจไม่มี reject และ limit — reject ต่างจาก deny แค่การตอบกลับ
     * ซึ่งไม่ใช่สิ่งที่ควรให้เลือกผ่านหน้าเว็บโดยไม่อธิบาย ส่วน limit ใช้กับ SSH เป็นหลัก
     * และควรมีหน้าจอของตัวเองมากกว่ามาปนอยู่ในแบบฟอร์มเพิ่มกฎทั่วไป
     */
    private const ACTIONS = ['allow', 'deny'];

    public function isInstalled(Executor $executor): bool
    {
        // ในโหมดจำลอง คำสั่ง ufw ถูกดักไว้ทั้งหมดจึงใช้งานได้เสมอ
        // ไม่ต้องขึ้นกับว่าเครื่องที่ทดสอบอยู่ติดตั้ง ufw จริงหรือไม่
        return $executor->isSimulated() || $executor->exists(self::BINARY);
    }

    /**
     * สถานะและกฎทั้งหมด
     *
     * `readable` แยกจาก `active` โดยเจตนา เพราะ "ปิดอยู่" กับ "อ่านไม่ได้"
     * เป็นคนละเรื่องกันโดยสิ้นเชิงสำหรับคนที่กำลังตัดสินใจ ถ้ายุบสองอย่างนี้เป็นค่าเดียว
     * หน้าจอจะประกาศว่าเครื่องไม่มีการป้องกันทั้งที่ความจริงคือระบบไม่รู้ —
     * แล้วผู้ดูแลอาจไปเปิด firewall ซ้ำหรือรื้อกฎใหม่ทั้งชุดโดยไม่จำเป็น
     *
     * @return array{installed:bool,active:bool,readable:bool,rules:list<array<string,mixed>>,raw:string,note:string}
     */
    public function status(Executor $executor): array
    {
        $blank = ['installed' => false, 'active' => false, 'readable' => true, 'rules' => [], 'raw' => '', 'note' => ''];

        if (!$this->isInstalled($executor)) {
            return $blank;
        }

        $result = $executor->exec([self::BINARY, 'status', 'numbered'], timeout: 20);

        if (!$result->ok()) {
            $error = trim($result->stderr ?: $result->stdout);

            // ufw อ่านสถานะที่ใช้งานจริงจาก iptables ซึ่งต้องมี CAP_NET_ADMIN
            // ใน container ที่ไม่ได้ --cap-add=NET_ADMIN จึงล้มตรงนี้เสมอ
            // แต่ `ufw show added` อ่านจากไฟล์ค่าตั้งล้วน ๆ จึงยังใช้ได้ —
            // แสดงกฎที่ตั้งไว้ต่อได้ ดีกว่าโชว์ตารางว่างซึ่งสื่อผิดว่าไม่มีกฎเลย
            if (str_contains($error, 'Permission denied') || str_contains($error, 'problem running iptables')) {
                return [
                    'installed' => true,
                    'active' => false,
                    'readable' => false,
                    'rules' => $this->added($executor),
                    'raw' => $error,
                    'note' => 'อ่านสถานะการทำงานจริงของ firewall ไม่ได้ เพราะเข้าถึง iptables ของเคอร์เนลไม่ได้ '
                        . '(container ต้องรันด้วย --cap-add=NET_ADMIN) — กฎด้านล่างคือกฎที่ตั้งไว้ '
                        . 'แต่ระบบยืนยันไม่ได้ว่ากำลังบังคับใช้อยู่หรือไม่',
                ];
            }

            throw new ExecutionFailed('อ่านสถานะ firewall ไม่สำเร็จ: ' . $error);
        }

        $raw = $result->stdout;

        if (str_contains($raw, 'Status: active')) {
            return [
                'installed' => true,
                'active' => true,
                'readable' => true,
                'rules' => $this->parseRules($raw),
                'raw' => $raw,
                'note' => '',
            ];
        }

        // ufw ที่ปิดอยู่พิมพ์แค่ "Status: inactive" ไม่แสดงกฎเลย ทั้งที่กฎยังถูกเก็บไว้ครบ
        // ถ้าเชื่อผลลัพธ์นั้นตรง ๆ หน้าจอจะบอกว่า "ไม่มีกฎ" ในจังหวะที่ผู้ดูแลต้องการ
        // ตรวจกฎมากที่สุด — คือก่อนกดเปิดใช้งาน จึงอ่านจาก `ufw show added` แทน
        return [
            'installed' => true,
            'active' => false,
            'readable' => true,
            'rules' => $this->added($executor),
            'raw' => $raw,
            'note' => '',
        ];
    }

    /**
     * กฎที่ตั้งไว้ อ่านจากไฟล์ค่าตั้งของ ufw ล้วน ๆ ไม่ต้องแตะเคอร์เนล
     *
     * @return list<array<string,mixed>>
     */
    private function added(Executor $executor): array
    {
        $result = $executor->exec([self::BINARY, 'show', 'added'], timeout: 20);

        return $result->ok() ? $this->parseAdded($result->stdout) : [];
    }

    /**
     * แปลงผลลัพธ์ `ufw show added` — ufw คืนกฎมาเป็นบรรทัดคำสั่งที่ใช้สร้างมัน
     *
     *   Added user rules (see 'ufw status' for running firewall):
     *   ufw allow 22/tcp
     *   ufw allow from 10.0.0.0/8 to any port 3306 proto tcp
     *
     * ไม่มีหมายเลขกำกับเหมือน `status numbered` จึงไล่หมายเลขตามลำดับที่ปรากฏ
     * ใช้อ้างอิงบนหน้าจอได้เพราะการลบจริงอ้างด้วยเนื้อกฎ ไม่ใช่หมายเลข
     *
     * @return list<array<string,mixed>>
     */
    private function parseAdded(string $raw): array
    {
        $rules = [];
        $number = 0;

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];

            if (count($parts) < 3 || $parts[0] !== 'ufw') {
                continue;
            }

            $action = strtolower($parts[1]);

            if (!in_array($action, ['allow', 'deny', 'reject', 'limit'], true)) {
                continue;
            }

            $rule = ['port' => '', 'protocol' => '', 'source' => ''];
            $spec = array_slice($parts, 2);

            // ตัดส่วน comment ออกก่อนแปลง แล้วเก็บข้อความไว้แสดงบนหน้าจอ
            $comment = '';
            $at = array_search('comment', $spec, true);

            if ($at !== false) {
                $comment = trim(implode(' ', array_slice($spec, $at + 1)), " '\"");
                $spec = array_slice($spec, 0, $at);
            }

            if ($spec === []) {
                continue;
            }

            if (preg_match('#^(\d+(?::\d+)?)(?:/(tcp|udp))?$#', $spec[0], $m) === 1) {
                $rule['port'] = $m[1];
                $rule['protocol'] = $m[2] ?? 'any';
            } else {
                $count = count($spec);

                for ($i = 0; $i < $count; $i++) {
                    $next = $spec[$i + 1] ?? '';

                    match ($spec[$i]) {
                        'from' => $rule['source'] = $next === 'any' ? '' : $next,
                        'port' => $rule['port'] = $next,
                        'proto' => $rule['protocol'] = $next,
                        default => null,
                    };
                }

                if ($rule['protocol'] === '') {
                    $rule['protocol'] = 'any';
                }
            }

            $target = $rule['protocol'] === 'any' ? $rule['port'] : $rule['port'].'/'.$rule['protocol'];

            $rules[] = [
                'number' => ++$number,
                'target' => $target,
                'port' => $rule['port'],
                'protocol' => $rule['protocol'],
                'action' => strtoupper($action),
                'direction' => 'IN',
                'source' => $rule['source'] === '' ? 'Anywhere' : $rule['source'],
                'source_spec' => $rule['source'],
                'manageable' => $rule['port'] !== '',
                'comment' => $comment,
                'is_panel_port' => false
            ];
        }

        return $rules;
    }

    /**
     * แปลงผลลัพธ์ `ufw status numbered` เป็นโครงสร้าง
     *
     * รูปแบบที่ ufw คืนมา:
     *   [ 1] 22/tcp                     ALLOW IN    Anywhere
     *   [ 2] 8443/tcp                   ALLOW IN    203.0.113.0/24
     *
     * @return list<array<string,mixed>>
     */
    private function parseRules(string $raw): array
    {
        $rules = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (preg_match('/^\[\s*(\d+)\]\s+(\S+)\s+(ALLOW|DENY|REJECT|LIMIT)\s+(IN|OUT)\s+(.+?)\s*$/', $line, $m) !== 1) {
                continue;
            }

            [, $number, $target, $action, $direction, $source] = $m;

            // ข้ามกฎของ IPv6 ที่ ufw แสดงซ้ำ — เป็นกฎเดียวกันกับ IPv4 ที่แสดงไปแล้ว
            if (str_contains($target, '(v6)') || str_contains($source, '(v6)')) {
                continue;
            }

            $port = '';
            $protocol = '';

            if (preg_match('#^(\d+(?::\d+)?)/(tcp|udp)$#', $target, $p) === 1) {
                $port = $p[1];
                $protocol = $p[2];
            } elseif (preg_match('/^\d+(:\d+)?$/', $target) === 1) {
                $port = $target;
                $protocol = 'any';
            }

            $from = trim($source);

            $rules[] = [
                'number' => (int) $number,
                'target' => $target,
                'port' => $port,
                'protocol' => $protocol,
                'action' => $action,
                'direction' => $direction,
                'source' => $from,
                // รูปแบบที่ป้อนกลับเข้า ufw ได้ — 'Anywhere' ของ ufw หมายถึงไม่ระบุต้นทาง
                'source_spec' => strcasecmp($from, 'Anywhere') === 0 ? '' : $from,
                // กฎที่แปลงกลับเป็นคำสั่งไม่ได้ (เช่นอ้างชื่อบริการหรือ interface) ห้ามให้ลบผ่านหน้าเว็บ
                // เพราะย้อนกลับให้ไม่ได้ถ้าผู้ใช้เปลี่ยนใจ
                'manageable' => $port !== '' && $direction === 'IN',
                // `ufw status` ไม่แสดงหมายเหตุของกฎ มีเฉพาะใน `ufw show added` เท่านั้น
                'comment' => '',
                // ผู้เรียกเป็นคนตั้งค่านี้ เพราะรู้พอร์ตของ panel
                'is_panel_port' => false
            ];
        }

        return $rules;
    }

    /**
     * @param string $port
     * @return mixed
     */
    public static function assertPort(string $port): string
    {
        // รองรับทั้งพอร์ตเดี่ยวและช่วง เช่น 6000:6010 ตามที่ ufw รองรับ
        if (preg_match('/^(\d{1,5})(:(\d{1,5}))?$/', $port, $m) !== 1) {
            throw new ValidationError('รูปแบบพอร์ตไม่ถูกต้อง (ใช้ 8080 หรือ 6000:6010)');
        }

        // เทียบกับ '' ตรง ๆ ไม่ใช้ array_filter — '0' เป็นค่า falsy ใน PHP
        // ถ้ากรองด้วย array_filter พอร์ต 0 จะรอดการตรวจช่วงไปทั้งดุ้น
        foreach ([$m[1], $m[3] ?? ''] as $value) {
            if ($value === '') {
                continue;
            }

            if ((int) $value < 1 || (int) $value > 65535) {
                throw new ValidationError('หมายเลขพอร์ตต้องอยู่ระหว่าง 1 ถึง 65535');
            }
        }

        if (isset($m[3]) && $m[3] !== '' && (int) $m[3] <= (int) $m[1]) {
            throw new ValidationError('ช่วงพอร์ตต้องเรียงจากน้อยไปมาก');
        }

        return $port;
    }

    /**
     * @param string $protocol
     * @return mixed
     */
    public static function assertProtocol(string $protocol): string
    {
        if (!in_array($protocol, self::PROTOCOLS, true)) {
            throw new ValidationError('โปรโตคอลต้องเป็น tcp หรือ udp');
        }

        return $protocol;
    }

    /** ที่มาของการเชื่อมต่อ — ว่างหมายถึงทุกที่ */
    public static function assertSource(string $source): string
    {
        if ($source === '' || strtolower($source) === 'any') {
            return '';
        }

        // รองรับทั้ง IP เดี่ยวและ CIDR
        [$address, $bits] = array_pad(explode('/', $source, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new ValidationError('ที่อยู่ต้นทางต้องเป็น IP หรือ CIDR ที่ถูกต้อง');
        }

        if ($bits !== null) {
            $max = str_contains($address, ':') ? 128 : 32;

            if (preg_match('/^\d{1,3}$/', $bits) !== 1 || (int) $bits < 0 || (int) $bits > $max) {
                throw new ValidationError("ความยาว prefix ต้องอยู่ระหว่าง 0 ถึง {$max}");
            }
        }

        return $source;
    }

    /**
     * @param string $action
     * @return mixed
     */
    public static function assertAction(string $action): string
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new ValidationError('การกระทำต้องเป็น allow หรือ deny');
        }

        return $action;
    }

    /**
     * เพิ่มกฎ — argv ประกอบจากค่าที่ผ่าน validator แล้วทั้งหมด
     */
    public function rule(
        Executor $executor,
        string $action,
        string $port,
        string $protocol,
        string $source,
        string $comment = '',
    ): void {
        $argv = array_merge([self::BINARY, self::assertAction($action)], $this->spec($port, $protocol, $source));

        if ($comment !== '') {
            array_push($argv, 'comment', self::assertComment($comment));
        }

        $result = $executor->exec($argv, timeout: 30);

        if (!$result->ok()) {
            $err = trim($result->stderr ?: $result->stdout);
            if (
                str_contains($err, 'Permission denied') ||
                str_contains($err, 'problem running') ||
                str_contains($err, 'iptables-restore')
            ) {
                throw new ExecutionFailed(
                    'ไม่สามารถเพิ่มกฎ Firewall ในคอนเทนเนอร์นี้ได้ '.
                    '(ufw ต้องใช้สิทธิ์ปรับแต่ง Kernel Network / CAP_NET_ADMIN หรือรันบนเซิร์ฟเวอร์/VM จริง)'
                );
            }

            throw new ExecutionFailed('เพิ่มกฎ firewall ไม่สำเร็จ: '.$err);
        }
    }

    /**
     * ลบกฎด้วยเนื้อของกฎเอง ไม่ใช่หมายเลข
     *
     * หมายเลขกฎของ ufw เลื่อนทุกครั้งที่มีการลบ ถ้าย้อนกลับด้วยหมายเลขที่จำไว้
     * ก็มีโอกาสไปลบกฎอื่นที่เลื่อนมาแทนที่ การอ้างด้วยเนื้อกฎจึงปลอดภัยกว่า
     * และเป็นวิธีที่ ufw รองรับอยู่แล้ว
     */
    public function removeRule(
        Executor $executor,
        string $action,
        string $port,
        string $protocol,
        string $source,
    ): void {
        $argv = array_merge(
            [self::BINARY, '--force', 'delete', self::assertAction($action)],
            $this->spec($port, $protocol, $source),
        );

        $result = $executor->exec($argv, timeout: 30);

        if (!$result->ok()) {
            $err = trim($result->stderr ?: $result->stdout);
            if (
                str_contains($err, 'Permission denied') ||
                str_contains($err, 'problem running') ||
                str_contains($err, 'iptables-restore')
            ) {
                throw new ExecutionFailed(
                    'ไม่สามารถลบกฎ Firewall ในคอนเทนเนอร์นี้ได้ '.
                    '(ufw ต้องใช้สิทธิ์ปรับแต่ง Kernel Network / CAP_NET_ADMIN หรือรันบนเซิร์ฟเวอร์/VM จริง)'
                );
            }

            throw new ExecutionFailed('ลบกฎ firewall ไม่สำเร็จ: '.$err);
        }
    }

    /**
     * ส่วนของ argv ที่บอกว่ากฎนี้ครอบคลุมอะไร — ใช้ร่วมกันทั้งตอนเพิ่มและตอนลบ
     * จึงมั่นใจได้ว่าคำสั่งลบอ้างถึงกฎเดียวกับที่เพิ่มไปเป๊ะ ๆ
     *
     * @return list<string>
     */
    private function spec(string $port, string $protocol, string $source): array
    {
        self::assertPort($port);
        self::assertProtocol($protocol);
        self::assertSource($source);

        if ($source !== '') {
            // รูปแบบเต็มของ ufw: ufw allow from <src> to any port <port> proto <proto>
            $spec = ['from', $source, 'to', 'any', 'port', $port];

            return $protocol === 'any' ? $spec : array_merge($spec, ['proto', $protocol]);
        }

        return [$protocol === 'any' ? $port : $port.'/'.$protocol];
    }

    /**
     * @param Executor $executor
     */
    public function enable(Executor $executor): void
    {
        // --force ข้ามคำถาม "อาจตัดการเชื่อมต่อ SSH" ที่ ufw ถามแบบโต้ตอบ
        // ความเสี่ยงนั้นถูกจัดการด้วย RollbackGuard ที่ชั้นบนแทน
        $result = $executor->exec([self::BINARY, '--force', 'enable'], timeout: 30);

        if (!$result->ok()) {
            $err = trim($result->stderr ?: $result->stdout);
            if (
                str_contains($err, 'Permission denied') ||
                str_contains($err, 'problem running') ||
                str_contains($err, 'iptables-restore')
            ) {
                throw new ExecutionFailed(
                    'ไม่สามารถเปิดใช้งาน Firewall ในคอนเทนเนอร์นี้ได้ '.
                    '(ufw ต้องใช้สิทธิ์ปรับแต่ง Kernel Network / CAP_NET_ADMIN หรือรันบนเซิร์ฟเวอร์/VM จริง)'
                );
            }

            throw new ExecutionFailed('เปิดใช้งาน firewall ไม่สำเร็จ: '.$err);
        }
    }

    /**
     * @param Executor $executor
     */
    public function disable(Executor $executor): void
    {
        $result = $executor->exec([self::BINARY, 'disable'], timeout: 30);

        if (!$result->ok()) {
            $err = trim($result->stderr ?: $result->stdout);
            if (
                str_contains($err, 'Permission denied') ||
                str_contains($err, 'problem running') ||
                str_contains($err, 'iptables-restore')
            ) {
                throw new ExecutionFailed(
                    'ไม่สามารถเปลี่ยนสถานะ Firewall ในคอนเทนเนอร์นี้ได้ '.
                    '(ufw ต้องใช้สิทธิ์ปรับแต่ง Kernel Network / CAP_NET_ADMIN หรือรันบนเซิร์ฟเวอร์/VM จริง)'
                );
            }

            throw new ExecutionFailed('ปิด firewall ไม่สำเร็จ: '.$err);
        }
    }

    /**
     * @param string $comment
     * @return mixed
     */
    private static function assertComment(string $comment): string
    {
        $clean = trim(preg_replace('/[^\p{Thai}\p{L}\p{N}\s._-]/u', '', $comment) ?? '');

        if (mb_strlen($clean) > 64) {
            $clean = mb_substr($clean, 0, 64);
        }

        return $clean;
    }
}
