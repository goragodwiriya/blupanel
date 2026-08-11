<?php

declare(strict_types=1);

namespace Phpcp\Agent\Executor;

/**
 * จำลอง certbot ในโหมด sandbox
 *
 * จำเป็นด้านความปลอดภัยเหมือน UfwSimulator และ MariaDbSimulator:
 * /usr/bin เป็น passthrough ของ SandboxExecutor ถ้าไม่ดักไว้ การทดสอบจะยิงคำขอจริง
 * ไปยัง Let's Encrypt ทุกครั้ง ซึ่งกินโควตาที่จำกัดต่อโดเมน (5 ใบต่อสัปดาห์)
 * และอาจล็อกโดเมนจริงของผู้ใช้ไม่ให้ขอใบได้ไปหลายวัน
 *
 * ไม่จำลอง openssl เพราะ openssl ทำงานกับไฟล์ในพื้นที่ที่ถูกแมปแล้ว ไม่ติดต่อภายนอก —
 * ปล่อยให้รันจริงจะได้ทดสอบการอ่านวันหมดอายุด้วยของจริง (ARCHITECTURE §6.3)
 */
final class CertbotSimulator implements Simulator
{
    public function handles(string $binary): bool
    {
        return basename($binary) === 'certbot';
    }

    public function simulate(array $argv, SandboxState $state, ?string $stdin = null): ExecResult
    {
        $command = $argv[1] ?? '';

        return match ($command) {
            'certonly' => $this->certonly($argv, $state),
            'renew' => $this->renew($argv, $state),
            'delete' => $this->delete($argv, $state),
            'certificates' => $this->certificates($argv, $state),
            default => $this->fail($argv, "sandbox: ยังไม่รองรับคำสั่ง certbot {$command}"),
        };
    }

    /**
     * ออกใบรับรองปลอมด้วย openssl จริง
     *
     * ต้องเป็นไฟล์ PEM ที่ถูกต้องจริง ๆ ไม่ใช่ไฟล์เปล่า เพราะ CertbotManager
     * อ่านวันหมดอายุด้วย `openssl x509` และ Apache ต้องโหลดไฟล์นี้ได้ตอน configtest
     * ถ้าจำลองด้วยไฟล์ปลอม การทดสอบจะผ่านทั้งที่เส้นทางจริงพัง
     */
    private function certonly(array $argv, SandboxState $state): ExecResult
    {
        $name = $this->option($argv, '--cert-name');
        $domains = $this->domains($argv);

        if ($name === '' && $domains !== []) {
            $name = $domains[0];
        }

        if ($name === '') {
            return $this->fail($argv, 'sandbox: ไม่ได้ระบุโดเมน');
        }

        $dir = $state->prefix() . '/etc/letsencrypt/live/' . $name;

        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            return $this->fail($argv, "sandbox: สร้างไดเรกทอรี {$dir} ไม่ได้");
        }

        $alt = implode(',', array_map(static fn (string $d): string => 'DNS:' . $d, $domains ?: [$name]));

        // 90 วันเท่าของจริง เพื่อให้การทดสอบเรื่องวันหมดอายุใช้ตัวเลขชุดเดียวกับ production
        $result = (new RealExecutor())->exec([
            '/usr/bin/openssl', 'req', '-x509', '-nodes',
            '-newkey', 'rsa:2048', '-days', '90',
            '-keyout', $dir . '/privkey.pem',
            '-out', $dir . '/fullchain.pem',
            '-subj', '/CN=' . $name,
            '-addext', 'subjectAltName=' . $alt,
            // ให้เหมือนใบจริงของ Let's Encrypt — ไม่ใช่ใบ CA
            '-addext', 'basicConstraints=critical,CA:FALSE',
            '-addext', 'keyUsage=critical,digitalSignature,keyEncipherment',
            '-addext', 'extendedKeyUsage=serverAuth',
        ], 60);

        if (!$result->ok()) {
            return $this->fail($argv, 'sandbox: สร้างใบรับรองจำลองไม่สำเร็จ — ' . trim($result->stderr));
        }

        @chmod($dir . '/privkey.pem', 0600);

        $certificates = $state->read('certificates');
        $certificates[$name] = ['domains' => $domains ?: [$name], 'issued_at' => time()];
        $state->write('certificates', $certificates);

        return $this->out($argv, sprintf(
            "Successfully received certificate.\nCertificate is saved at: %s/fullchain.pem\n",
            $dir,
        ));
    }

    private function renew(array $argv, SandboxState $state): ExecResult
    {
        $name = $this->option($argv, '--cert-name');
        $certificates = $state->read('certificates');

        if ($name === '' || !isset($certificates[$name])) {
            return $this->fail($argv, "sandbox: ไม่พบใบรับรองชื่อ {$name}");
        }

        if (!in_array('--force-renewal', $argv, true)) {
            // certbot จริงข้ามใบที่ยังไม่ใกล้หมดอายุ แล้วออกด้วยรหัส 0 —
            // ต้องจำลองพฤติกรรมนี้ด้วย ไม่งั้นเทสต์จะไม่เจอกรณี "กดต่ออายุแล้วไม่มีอะไรเกิดขึ้น"
            return $this->out($argv, "Certificate not yet due for renewal; no action taken.\n");
        }

        $issue = array_values(array_filter($argv, static fn (string $a): bool => $a !== 'renew' && $a !== '--force-renewal'));
        array_splice($issue, 1, 0, 'certonly');

        foreach ($certificates[$name]['domains'] as $domain) {
            array_push($issue, '-d', $domain);
        }

        return $this->certonly($issue, $state);
    }

    private function delete(array $argv, SandboxState $state): ExecResult
    {
        $name = $this->option($argv, '--cert-name');
        $certificates = $state->read('certificates');

        if ($name === '' || !isset($certificates[$name])) {
            return $this->fail($argv, "sandbox: ไม่พบใบรับรองชื่อ {$name}");
        }

        $dir = $state->prefix() . '/etc/letsencrypt/live/' . $name;

        foreach (['fullchain.pem', 'privkey.pem'] as $file) {
            @unlink($dir . '/' . $file);
        }

        @rmdir($dir);

        unset($certificates[$name]);
        $state->write('certificates', $certificates);

        return $this->out($argv, "Deleted all files relating to certificate {$name}.\n");
    }

    private function certificates(array $argv, SandboxState $state): ExecResult
    {
        $lines = ['Found the following certs:'];

        foreach ($state->read('certificates') as $name => $info) {
            $lines[] = '  Certificate Name: ' . $name;
            $lines[] = '    Domains: ' . implode(' ', $info['domains']);
        }

        return $this->out($argv, implode("\n", $lines) . "\n");
    }

    private function option(array $argv, string $name): string
    {
        $at = array_search($name, $argv, true);

        return $at === false ? '' : (string) ($argv[$at + 1] ?? '');
    }

    /** @return list<string> */
    private function domains(array $argv): array
    {
        $domains = [];
        $count = count($argv);

        for ($i = 0; $i < $count; $i++) {
            if ($argv[$i] === '-d' && isset($argv[$i + 1])) {
                $domains[] = $argv[$i + 1];
            }
        }

        return $domains;
    }

    private function out(array $argv, string $stdout): ExecResult
    {
        return new ExecResult($argv, 0, $stdout, '', 120, simulated: true);
    }

    private function fail(array $argv, string $message): ExecResult
    {
        return new ExecResult($argv, 1, '', $message . "\n", 20, simulated: true);
    }
}
