<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Executor;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Kernel\Mode;

/**
 * โหมดทดสอบ — ARCHITECTURE §6.3
 *
 * ทำสองอย่าง:
 *   1. แมปทุก path เข้า prefix  → เขียนไฟล์ config ได้จริงโดยไม่แตะ /etc ของเครื่อง
 *   2. จำลองคำสั่งที่เปลี่ยนสถานะระบบ (systemctl, useradd, ufw, certbot)
 *
 * คำสั่งที่ไม่มีตัวจำลอง "รันจริง" โดยเจตนา เพราะ path ถูกแมปแล้วจึงปลอดภัย
 * ตัวอย่างสำคัญ: apache2ctl -t ตรวจ vhost ที่เพิ่ง generate — ส่วนที่บั๊กบ่อยที่สุด
 * จึงยังถูกทดสอบด้วยของจริงแม้อยู่ในโหมดทดสอบ
 */
final class SandboxExecutor implements Executor
{
    /** path เหล่านี้ไม่ถูกแมป เพราะต้องอ่านของจริงหรือเป็นที่อยู่ของ binary */
    private const PASSTHROUGH = [
        '/proc',
        '/sys',
        '/dev',
        '/usr/bin',
        '/usr/sbin',
        '/bin',
        '/sbin',
        '/usr/local/bin',
        '/usr/local/sbin',
        '/usr/share/zoneinfo',
        '/etc/os-release'
    ];

    /** @var list<Simulator> */
    private array $simulators;

    private SandboxState $state;

    /** @var list<string> */
    private array $simulated = [];

    public function __construct(
        private readonly string $prefix,
        private readonly RealExecutor $real = new RealExecutor(),
    ) {
        $this->state = new SandboxState($prefix);
        $this->simulators = [
            new SystemctlSimulator(),
            new SystemUserSimulator(),
            // จำเป็นด้านความปลอดภัย ไม่ใช่แค่ความสะดวก — ถ้าไม่จำลอง คำสั่งฐานข้อมูล
            // จะไปสร้าง/ลบฐานข้อมูลบน MariaDB จริงของเครื่องในโหมดทดสอบ
            new MariaDbSimulator(),
            // ด้วยเหตุผลเดียวกัน — /usr/sbin เป็น passthrough คำสั่ง ufw จริงจึงเปิด
            // firewall ของเครื่องที่กำลังทดสอบอยู่ได้ถ้าไม่ดักไว้ตรงนี้
            new UfwSimulator(),
            // ด้วยเหตุผลเดียวกัน — ถ้าไม่ดัก การทดสอบจะยิงคำขอจริงไปยัง Let's Encrypt
            // ซึ่งกินโควตาที่จำกัดต่อโดเมนและอาจล็อกโดเมนจริงของผู้ใช้
            new CertbotSimulator(),
        ];
    }

    public function mode(): Mode
    {
        return Mode::Sandbox;
    }

    public function isSimulated(): bool
    {
        return true;
    }

    /**
     * @return mixed
     */
    public function simulatedCommands(): array
    {
        return $this->simulated;
    }

    /**
     * @return mixed
     */
    public function state(): SandboxState
    {
        return $this->state;
    }

    /**
     * แมป absolute path เข้า prefix — เมธอดนี้ต้อง idempotent
     * เพราะบาง capability เรียก path() เองแล้วส่งต่อให้ readFile() ซึ่งแมปซ้ำ
     */
    public function path(string $absolutePath): string
    {
        if ($absolutePath === '' || !str_starts_with($absolutePath, '/')) {
            return $absolutePath;
        }

        if (str_starts_with($absolutePath, $this->prefix.'/') || $absolutePath === $this->prefix) {
            return $absolutePath;
        }

        foreach (self::PASSTHROUGH as $keep) {
            if ($absolutePath === $keep || str_starts_with($absolutePath, $keep.'/')) {
                return $absolutePath;
            }
        }

        return $this->prefix.$absolutePath;
    }

    /**
     * @param array $argv
     * @param int $timeout
     * @param string $cwd
     * @param string|null $stdin
     * @return mixed
     */
    public function exec(
        array $argv,
        int $timeout = 30,
        ?string $cwd = null,
        ?string $stdin = null,
    ): ExecResult {
        if ($argv === []) {
            throw new ExecutionFailed('คำสั่งว่างเปล่า');
        }

        foreach ($this->simulators as $simulator) {
            if ($simulator->handles($argv[0])) {
                $result = $simulator->simulate($argv, $this->state, $stdin);
                $this->simulated[] = $result->commandLine();

                return $result;
            }
        }

        return $this->real->exec($argv, $timeout, $cwd === null ? null : $this->path($cwd), $stdin);
    }

    /**
     * @param string $path
     * @return mixed
     */
    public function readFile(string $path): string
    {
        return $this->real->readFile($this->path($path));
    }

    /**
     * @param string $path
     * @param string $content
     * @param int $mode
     */
    public function writeFile(string $path, string $content, int $mode = 0644): void
    {
        $this->real->writeFile($this->path($path), $content, $mode);
    }

    /**
     * @param string $path
     * @return mixed
     */
    public function exists(string $path): bool
    {
        return $this->real->exists($this->path($path));
    }

    /**
     * @param string $path
     * @param int $mode
     */
    public function makeDirectory(string $path, int $mode = 0755): void
    {
        $this->real->makeDirectory($this->path($path), $mode);
    }

    /**
     * @param string $path
     * @return mixed
     */
    public function diskSpace(string $path): array
    {
        // พื้นที่ดิสก์รายงานของจริงเสมอ — กราฟบนแดชบอร์ดจึงมีความหมายแม้อยู่ในโหมดทดสอบ
        return $this->real->diskSpace($path);
    }

    /**
     * @param string $path
     * @return mixed
     */
    public function realPath(string $path): ?string
    {
        return $this->real->realPath($this->path($path));
    }

    /**
     * @param string $path
     * @return mixed
     */
    public function listDirectory(string $path): array
    {
        return $this->real->listDirectory($this->path($path));
    }

    /**
     * @param string $path
     * @return mixed
     */
    public function stat(string $path): ?array
    {
        return $this->real->stat($this->path($path));
    }

    /**
     * @param string $from
     * @param string $to
     */
    public function rename(string $from, string $to): void
    {
        $this->real->rename($this->path($from), $this->path($to));
    }

    /**
     * @param string $from
     * @param string $to
     */
    public function copyPath(string $from, string $to): void
    {
        $this->real->copyPath($this->path($from), $this->path($to));
    }

    /**
     * @param string $path
     */
    public function removePath(string $path): void
    {
        $this->real->removePath($this->path($path));
    }

    /**
     * @param string $path
     * @param int $mode
     */
    public function changeMode(string $path, int $mode): void
    {
        $this->real->changeMode($this->path($path), $mode);
    }

    /**
     * @param array $sources
     * @param string $base
     * @param string $archive
     * @return mixed
     */
    public function zip(array $sources, string $base, string $archive): array
    {
        return $this->real->zip(
            array_map($this->path(...), $sources),
            $this->path($base),
            $this->path($archive),
        );
    }

    /**
     * @param string $archive
     * @param string $destination
     * @return mixed
     */
    public function unzip(string $archive, string $destination): array
    {
        return $this->real->unzip($this->path($archive), $this->path($destination));
    }

    /**
     * โหมดทดสอบไม่มีผู้ใช้ระบบของเว็บไซต์อยู่จริง จึงลดสิทธิ์ไม่ได้และไม่จำเป็น —
     * ไฟล์ทั้งหมดอยู่ใต้ prefix ที่เป็นของผู้ทดสอบเองอยู่แล้ว
     *
     * การตรวจ path ยังทำงานเหมือนโหมดจริงทุกประการ เทสต์เรื่อง traversal จึงมีความหมาย
     */
    public function asUser(?string $systemUser, callable $work): array
    {
        // null = ขอบเขตระดับเซิร์ฟเวอร์ ทำงานด้วยสิทธิ์ของ agent เอง
        if ($systemUser === null || $systemUser === '') {
            return $work();
        }

        return $work();
    }
}
