<?php

declare(strict_types=1);

/**
 * เส้นทางของโปรแกรมภายนอกที่ driver ฮาร์ดโค้ดไว้ ต้องชี้ไปที่ไฟล์ที่มีอยู่จริง
 *
 * **ที่มา (2026-08-10):** เฟส E3 ฮาร์ดโค้ด `/usr/sbin/named-checkzone` ทั้งที่ Debian/Ubuntu
 * วางไว้ที่ `/usr/bin/` — เทสต์ 426 ข้อผ่านหมด **รวมเทสต์ที่ยิง `named-checkzone` ตัวจริง 6 ข้อ**
 * แต่ทุกการซิงก์ล้มทันทีบนเซิร์ฟเวอร์จริง เพราะเทสต์พวกนั้นเรียกโปรแกรมผ่าน **PATH**
 * ไม่ได้เรียกผ่านค่าคงที่ในโค้ด — ตรวจถูกคนละอย่างกับที่ production ใช้
 *
 * **หัวใจของการออกแบบเทสต์ชุดนี้: แยก "path ผิด" ออกจาก "ไม่ได้ติดตั้งโปรแกรมนี้"**
 *
 * ถ้าเจอโปรแกรมชื่อนั้นใน `PATH` แต่ path ที่โค้ดใช้ไม่มีไฟล์ → **นั่นคือบั๊ก** ให้เทสต์แดง
 * ถ้าไม่เจอทั้งสองที่ → เครื่องนี้ไม่ได้ติดตั้งโปรแกรมนั้น (เช่น CI ที่ไม่มี certbot/BIND9)
 * ให้ข้ามไป ไม่ใช่ทำให้เทสต์แดงในเรื่องที่ไม่เกี่ยวกับความถูกต้องของโค้ด
 *
 * อ่านค่าคงที่จากคลาสจริงผ่าน reflection โดยเจตนา ไม่คัดลอกรายการมาไว้ในเทสต์ —
 * ถ้าคัดลอก เทสต์จะยังเขียวตอนที่โค้ดเปลี่ยน path ไปเป็นค่าผิด ซึ่งทำให้เทสต์ไร้ประโยชน์
 */

group('BinaryPath — เส้นทางโปรแกรมที่ฮาร์ดโค้ดต้องตรงกับเครื่องจริง');

/**
 * คลาสที่ฮาร์ดโค้ดเส้นทางโปรแกรมไว้เป็นค่าคงที่ พร้อมรายชื่อค่าคงที่ที่ถือว่าเป็น
 * "ทางเลือกของโปรแกรมเดียวกัน" (ผ่านถ้าเจออย่างน้อยหนึ่งตัว)
 *
 * @return array<class-string,list<list<string>>> คลาส => กลุ่มของชื่อค่าคงที่
 */
function binaryPathGroups(): array
{
    return [
        Phpcp\Agent\Capability\DiskUsage::class      => [['DU']],
        Phpcp\Agent\Capability\DiskQuotaCheck::class => [['DU']],
        Phpcp\Driver\BackupManager::class            => [['TAR']],
        Phpcp\Driver\Backup\SftpDestination::class   => [['SFTP'], ['SSH']],
        Phpcp\Driver\Backup\RsyncDestination::class  => [['RSYNC'], ['SSH']],
        Phpcp\Driver\Firewall\UfwDriver::class       => [['BINARY']],
        Phpcp\Driver\Ssl\CertbotManager::class       => [['BINARY'], ['OPENSSL']],
        Phpcp\Driver\Mail\MailManager::class         => [['POSTFIX'], ['POSTMAP']],
        Phpcp\Driver\Ssh\SftpAccessManager::class    => [['GROUPADD'], ['USERMOD'], ['GPASSWD'], ['CHPASSWD'], ['SSHD'], ['SYSTEMCTL_PATHS']],
        // คู่ที่มี fallback อยู่แล้ว — ผ่านถ้าเจอตัวใดตัวหนึ่ง
        Phpcp\Driver\Db\MariaDbManager::class        => [['CLIENT', 'CLIENT_FALLBACK'], ['DUMP', 'DUMP_FALLBACK']],
        // ค่าคงที่ที่เป็น "รายการเส้นทาง" อยู่แล้ว (ทางเลือกในตัว) — ตรวจแบบเดียวกัน
        Phpcp\Driver\Dns\BindZoneManager::class      => [['CHECKZONE_PATHS'], ['CHECKCONF_PATHS'], ['RNDC_PATHS']],
    ];
}

/**
 * อ่านค่าคงที่ (รวม private) จากคลาสจริง — คืนเป็นรายการเสมอ
 *
 * บางคลาสเก็บเส้นทางเดียว (`private const DU = '/usr/bin/du'`) บางคลาสเก็บเป็นรายการ
 * ทางเลือกในตัวอยู่แล้ว (`CHECKZONE_PATHS`) — ทั้งสองแบบมีความหมายเดียวกันสำหรับเทสต์นี้
 * คือ "เส้นทางที่ยอมรับได้ของโปรแกรมตัวนี้"
 *
 * @return list<string>
 */
function constantPaths(string $class, string $name): array
{
    $value = (new ReflectionClass($class))->getConstant($name);

    if (is_array($value)) {
        return array_values(array_map(strval(...), $value));
    }

    return $value === false || $value === null ? [] : [(string) $value];
}

/** โปรแกรมชื่อนี้มีอยู่ที่ไหนสักแห่งใน PATH หรือไม่ */
function existsInPath(string $binaryName): bool
{
    $output = [];
    $code = 0;
    exec('command -v ' . escapeshellarg($binaryName) . ' 2>/dev/null', $output, $code);

    return $code === 0;
}

test('ทุกเส้นทางที่ driver ฮาร์ดโค้ด ต้องมีไฟล์อยู่จริง — ถ้าโปรแกรมนั้นติดตั้งอยู่บนเครื่องนี้', static function (): void {
    $checked = 0;
    $skipped = [];

    foreach (binaryPathGroups() as $class => $groups) {
        foreach ($groups as $constantNames) {
            $paths = [];
            foreach ($constantNames as $name) {
                $paths = [...$paths, ...constantPaths($class, $name)];
            }
            $paths = array_values(array_filter($paths, static fn (string $p): bool => $p !== ''));

            assertTrue($paths !== [], "อ่านค่าคงที่ของ {$class} ไม่ได้: " . implode(',', $constantNames));

            $found = array_values(array_filter($paths, static fn (string $p): bool => is_file($p)));

            if ($found !== []) {
                $checked++;

                continue;
            }

            // ไม่เจอที่ path ที่โค้ดใช้ — เป็นบั๊กเฉพาะเมื่อโปรแกรมนั้น "มีอยู่บนเครื่อง" จริง
            $name = basename($paths[0]);

            if (!existsInPath($name)) {
                $skipped[] = $name;   // เครื่องนี้ไม่ได้ติดตั้ง ไม่ใช่เรื่องความถูกต้องของโค้ด

                continue;
            }

            $shortClass = substr((string) strrchr($class, '\\'), 1);
            assertTrue(false, sprintf(
                "%s ใช้เส้นทาง %s แต่ไม่มีไฟล์นั้นจริง ทั้งที่ `%s` มีอยู่ใน PATH ของเครื่องนี้ "
                . '— แปลว่าเส้นทางที่ฮาร์ดโค้ดไว้ผิด distro (แบบเดียวกับบั๊ก named-checkzone ของเฟส E3)',
                $shortClass,
                implode(' หรือ ', $paths),
                $name,
            ));
        }
    }

    assertTrue($checked > 0, 'ต้องตรวจได้อย่างน้อยหนึ่งเส้นทาง มิฉะนั้นเทสต์นี้ไม่ได้ตรวจอะไรเลย');

    if ($skipped !== []) {
        // ไม่ใช่ความล้มเหลว แต่ต้องเห็นว่าข้ามอะไรไป จะได้ไม่เข้าใจผิดว่าตรวจครบแล้ว
        fwrite(STDERR, '      (ข้าม ' . count($skipped) . ' ตัวที่เครื่องนี้ไม่ได้ติดตั้ง: ' . implode(', ', $skipped) . ")\n");
    }
});

test('เส้นทางที่ฮาร์ดโค้ดต้องเป็น absolute path เสมอ ไม่พึ่ง PATH ของ process', static function (): void {
    // agent รันเป็น root — การเรียกโปรแกรมด้วยชื่อล้วนแล้วให้ระบบไปหาใน PATH เอง
    // เปิดช่องให้ยัดโปรแกรมปลอมไว้ในไดเรกทอรีที่มาก่อนใน PATH แล้วได้สิทธิ์ root ทันที
    foreach (binaryPathGroups() as $class => $groups) {
        foreach ($groups as $constantNames) {
            foreach ($constantNames as $name) {
                foreach (constantPaths($class, $name) as $path) {
                    if ($path === '') {
                        continue;
                    }

                    assertTrue(
                        str_starts_with($path, '/'),
                        "{$class}::{$name} = '{$path}' ต้องเป็นเส้นทางเต็มที่ขึ้นต้นด้วย / ไม่ใช่ชื่อโปรแกรมล้วน",
                    );
                }
            }
        }
    }
});
