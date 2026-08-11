<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\SelfProtection;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\ServiceCatalog;
use Phpcp\Support\BinaryPath;
use Phpcp\Support\Validator;

/**
 * ฐานของคำสั่งที่เปลี่ยนสถานะ service — start / stop / restart / reload
 *
 * เป็น capability กลุ่มแรกของระบบที่ "เปลี่ยนแปลง" อะไรจริง ๆ จึงผ่านเส้นทางเต็ม:
 *   ตรวจ permission → validate → เขียน audit ก่อนลงมือ → รัน → เขียนผล
 *
 * ความปลอดภัย: ชื่อ unit มาจาก allowlist ตายตัวใน ServiceCatalog และผ่าน SelfProtection
 * ก่อนเสมอ ค่าที่ผู้ใช้ส่งมาไม่เคยถูกนำไปประกอบเป็นคำสั่งโดยตรง
 */
abstract class ServiceAction implements Capability
{
    /** @var list<string> systemd อยู่ /usr/bin บน Debian/Ubuntu · บางระบบมีที่ /bin ด้วย */
    private const JOURNALCTL_PATHS = ['/usr/bin/journalctl', '/bin/journalctl'];

    /** @var list<string> iproute2 — ใช้หาว่าใครถือพอร์ตที่ชนกันอยู่ */
    private const SS_PATHS = ['/usr/bin/ss', '/bin/ss', '/usr/sbin/ss'];

    /** คำกริยาที่จะส่งให้ systemctl — มาจากโค้ด ไม่ใช่จาก input */
    abstract protected function verb(): string;

    public function permission(): string
    {
        return 'service.control';
    }

    public function isMutating(): bool
    {
        return true;
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        $unit = strtolower(Validator::requireString($args, 'service', 64));

        // ตรวจบริการของ panel ก่อนเรื่องอื่น เพื่อให้ข้อความผิดพลาดตรงกับสาเหตุจริง
        SelfProtection::assertUnit($unit);

        if (!ServiceCatalog::isAllowed($unit)) {
            throw new ValidationError("ไม่รู้จักบริการ: {$unit}");
        }

        return ['service' => $unit];
    }

    /**
     * @param array $args
     * @param Executor $executor
     * @param Context $context
     */
    public function run(array $args, Executor $executor, Context $context): array
    {
        $unit = $args['service'];
        $before = ServiceProbe::read($executor, $unit);

        if (!$before['installed']) {
            throw new ExecutionFailed("ไม่พบบริการ {$unit} บนเครื่องนี้");
        }

        $result = $executor->exec(
            [$executor->path('/usr/bin/systemctl'), $this->verb(), $unit],
            timeout: 30,
        );

        if (!$result->ok()) {
            // Fallback สำหรับ Docker/sysvinit หาก systemctl ล้มเหลว
            $serviceResult = $executor->exec(
                [$executor->path('/usr/sbin/service'), $unit, $this->verb()],
                timeout: 30,
            );

            if (!$serviceResult->ok()) {
                $detail = trim($result->stderr) !== '' ? ' — '.trim($result->stderr) : '';

                throw new ExecutionFailed(
                    sprintf('%s บริการ %s ไม่สำเร็จ%s', $this->actionLabel(), $unit, $detail)
                    . $this->explainFailure($executor, $unit),
                    $result->exitCode,
                    $result->stderr,
                );
            }
        }

        // อ่านสถานะกลับทันทีเพื่อให้ UI อัปเดตการ์ดได้โดยไม่ต้องเรียกซ้ำ
        $after = ServiceProbe::read($executor, $unit);

        return [
            'service' => $unit,
            'action' => $this->verb(),
            'before' => $before['status'],
            'after' => $after['status'],
            'state' => $after,
            'message' => sprintf('%s บริการ %s เรียบร้อยแล้ว', $this->actionLabel(), ServiceCatalog::label($unit))
        ];
    }

    /**
     * หาสาเหตุจริงจาก journal แทนที่จะส่งประโยคของ systemd ต่อไปเฉย ๆ
     *
     * ข้อความที่ systemd คืนมาคือ *"Job for nginx.service failed because the control
     * process exited with error code. See systemctl status..."* ซึ่งไม่ได้บอกอะไรเลย
     * นอกจากให้ไปหาต่อเอง — ผู้ดูแลที่ทำงานผ่านหน้าเว็บมักไม่มีเทอร์มินัลอยู่ตรงหน้า
     *
     * กรณีที่พบบ่อยที่สุดคือพอร์ตชนกัน (เปิด nginx ทั้งที่ Apache ถือ 80/443 อยู่)
     * ซึ่งบอกได้ครบว่าใครถืออะไรอยู่ และต้องทำอะไรต่อ
     */
    private function explainFailure(Executor $executor, string $unit): string
    {
        $log = $this->recentLog($executor, $unit);

        if ($log === '') {
            return '';
        }

        // nginx: "bind() to 0.0.0.0:80 failed (98: Address already in use)"
        // apache: "(98)Address already in use: AH00072: make_sock: could not bind to address"
        if (preg_match('/bind\(\)? to [^\s]*?:(\d+) failed \(98/', $log, $m) === 1
            || preg_match('/\(98\)Address already in use.*?:(\d+)/', $log, $m) === 1) {
            $port = (int) $m[1];

            return sprintf(
                "\n\nสาเหตุ: พอร์ต %d ถูกใช้งานอยู่แล้ว%s\n\n"
                . 'เว็บเซิร์ฟเวอร์สองตัวถือพอร์ตเดียวกันไม่ได้ — หยุดตัวที่ถืออยู่ก่อน '
                . 'หรือถ้าต้องการใช้ทั้งคู่ ให้ตั้ง `webserver` เป็น `nginx-proxy` '
                . 'ซึ่งให้ nginx รับพอร์ต 80/443 แล้วส่งต่อให้ Apache ที่ 127.0.0.1:8080',
                $port,
                $this->whoHoldsPort($executor, $port),
            );
        }

        return "\n\nรายละเอียดจาก journal:\n" . $log;
    }

    /**
     * บรรทัดที่เป็นความผิดพลาดจริงจาก journal ของ unit นั้น
     *
     * ตัดเฉพาะบรรทัดที่มีเนื้อหา — journal ใส่บรรทัดคำอธิบายของ systemd เอง
     * (ขึ้นต้นด้วย ░░) ปนมาด้วย ซึ่งยาวกว่าสาเหตุจริงหลายเท่า
     */
    private function recentLog(Executor $executor, string $unit): string
    {
        try {
            $journalctl = BinaryPath::resolve($executor, self::JOURNALCTL_PATHS, 'systemd');
        } catch (ExecutionFailed) {
            return '';
        }

        $result = $executor->exec(
            [$journalctl, '-u', $unit, '-n', '30', '--no-pager', '-o', 'cat'],
            timeout: 15,
        );

        $lines = [];
        foreach (explode("\n", $result->stdout) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '░')) {
                continue;
            }

            $lines[] = $line;
        }

        // เอาเฉพาะท้าย ๆ ซึ่งเป็นรอบล่าสุด — เก่ากว่านั้นคือความพยายามครั้งก่อนที่แก้ไปแล้ว
        return implode("\n", array_slice($lines, -6));
    }

    /** ชื่อโปรเซสที่ถือพอร์ตอยู่ — ว่างเปล่าถ้าหาไม่ได้ ดีกว่าเดา */
    private function whoHoldsPort(Executor $executor, int $port): string
    {
        try {
            $ss = BinaryPath::resolve($executor, self::SS_PATHS, 'iproute2');
        } catch (ExecutionFailed) {
            return '';
        }

        $result = $executor->exec([$ss, '-ltnpH'], timeout: 10);

        foreach (explode("\n", $result->stdout) as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];

            // ช่องที่ 4 คือที่อยู่ฝั่งเรา — ช่อง peer เป็น 0.0.0.0:* เสมอจึงต้องไม่ดูช่องนั้น
            if (!isset($fields[3]) || !str_ends_with($fields[3], ':' . $port)) {
                continue;
            }

            if (preg_match('/users:\(\("([^"]+)"/', $line, $m) === 1) {
                return sprintf(' โดย %s', $m[1]);
            }
        }

        return '';
    }

    protected function actionLabel(): string
    {
        return match ($this->verb()) {
            'start' => 'เริ่ม',
            'stop' => 'หยุด',
            'restart' => 'รีสตาร์ต',
            'reload' => 'โหลดค่าตั้งใหม่ของ',
            default => $this->verb(),
        };
    }
}
