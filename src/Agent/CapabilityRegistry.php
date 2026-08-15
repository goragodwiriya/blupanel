<?php

declare (strict_types = 1);

namespace Phpcp\Agent;

use Phpcp\Agent\Capability\FileChmod;
use Phpcp\Agent\Capability\FirewallDisable;
use Phpcp\Agent\Capability\FirewallEnable;
use Phpcp\Agent\Capability\FirewallRuleAdd;
use Phpcp\Agent\Capability\FirewallRuleDelete;
use Phpcp\Agent\Capability\FirewallStatus;
use Phpcp\Agent\Capability\FileDelete;
use Phpcp\Agent\Capability\FileDownload;
use Phpcp\Agent\Capability\FileInfo;
use Phpcp\Agent\Capability\FileList;
use Phpcp\Agent\Capability\FileMkdir;
use Phpcp\Agent\Capability\FileMove;
use Phpcp\Agent\Capability\FileRead;
use Phpcp\Agent\Capability\FileSearch;
use Phpcp\Agent\Capability\FileTree;
use Phpcp\Agent\Capability\FileUnzip;
use Phpcp\Agent\Capability\FileUpload;
use Phpcp\Agent\Capability\FileWrite;
use Phpcp\Agent\Capability\FileZip;
use Phpcp\Agent\Capability\AlertCheck;
use Phpcp\Agent\Capability\CertSync;
use Phpcp\Agent\Capability\CustomerCreate;
use Phpcp\Agent\Capability\CustomerLayoutSet;
use Phpcp\Agent\Capability\CustomerQuotaUpdate;
use Phpcp\Agent\Capability\CustomerSiteAttach;
use Phpcp\Agent\Capability\DiskQuotaCheck;
use Phpcp\Agent\Capability\MetricsRecord;
use Phpcp\Agent\Capability\DiskUsage;
use Phpcp\Agent\Capability\PanelCertSet;
use Phpcp\Agent\Capability\DnsConfigRead;
use Phpcp\Agent\Capability\DnsCustomConfig;
use Phpcp\Agent\Capability\DnsReload;
use Phpcp\Agent\Capability\DnsZoneImport;
use Phpcp\Agent\Capability\DnsZoneRead;
use Phpcp\Agent\Capability\DnsZoneWrite;
use Phpcp\Agent\Capability\ExpiryCheck;
use Phpcp\Agent\Capability\BackupCreate;
use Phpcp\Agent\Capability\RollbackConfirm;
use Phpcp\Agent\Capability\RollbackRun;
use Phpcp\Agent\Capability\SftpDisable;
use Phpcp\Agent\Capability\SftpEnable;
use Phpcp\Agent\Capability\SshConfigGet;
use Phpcp\Agent\Capability\HostnameSet;
use Phpcp\Agent\Capability\SshConfigSet;
use Phpcp\Agent\Capability\BackupDelete;
use Phpcp\Agent\Capability\BackupDestinationTest;
use Phpcp\Agent\Capability\BackupPrune;
use Phpcp\Agent\Capability\BackupHostKeyScan;
use Phpcp\Agent\Capability\BackupList;
use Phpcp\Agent\Capability\BackupPush;
use Phpcp\Agent\Capability\BackupRestore;
use Phpcp\Agent\Capability\BackupRun;
use Phpcp\Agent\Capability\CronSync;
use Phpcp\Agent\Capability\DbAccountCredentials;
use Phpcp\Agent\Capability\DbAccountRotate;
use Phpcp\Agent\Capability\DbCreate;
use Phpcp\Agent\Capability\DbDrop;
use Phpcp\Agent\Capability\DbList;
use Phpcp\Agent\Capability\DbUserPassword;
use Phpcp\Agent\Capability\LogTail;
use Phpcp\Agent\Capability\PhpList;
use Phpcp\Agent\Capability\ServiceReload;
use Phpcp\Agent\Capability\ServiceRestart;
use Phpcp\Agent\Capability\ServiceStart;
use Phpcp\Agent\Capability\SecurityScan;
use Phpcp\Agent\Capability\PanelJailSet;
use Phpcp\Agent\Capability\PanelJailStatus;
use Phpcp\Agent\Capability\PanelJailUnban;
use Phpcp\Agent\Capability\ServiceStatus;
use Phpcp\Agent\Capability\ServiceStop;
use Phpcp\Agent\Capability\SiteAddDomain;
use Phpcp\Agent\Capability\SiteCreate;
use Phpcp\Agent\Capability\ConfigFileRead;
use Phpcp\Agent\Capability\SiteCustomConfig;
use Phpcp\Agent\Capability\SiteDelete;
use Phpcp\Agent\Capability\SiteRemoveDomain;
use Phpcp\Agent\Capability\SiteResetOwner;
use Phpcp\Agent\Capability\SiteResume;
use Phpcp\Agent\Capability\SiteSetDomains;
use Phpcp\Agent\Capability\SiteRateLimitSet;
use Phpcp\Agent\Capability\SiteRateLimitStatus;
use Phpcp\Agent\Capability\SiteRateLimitUnban;
use Phpcp\Agent\Capability\SiteRebuild;
use Phpcp\Agent\Capability\WebserverApply;
use Phpcp\Agent\Capability\WebserverRescan;
use Phpcp\Agent\Capability\SiteSetPhp;
use Phpcp\Agent\Capability\SiteSuspend;
use Phpcp\Agent\Capability\SslDelete;
use Phpcp\Agent\Capability\SslIssue;
use Phpcp\Agent\Capability\SslList;
use Phpcp\Agent\Capability\SslRenew;
use Phpcp\Agent\Capability\SslSetMode;
use Phpcp\Agent\Capability\MailApply;
use Phpcp\Agent\Capability\MailAliasDelete;
use Phpcp\Agent\Capability\MailAliasSet;
use Phpcp\Agent\Capability\MailBoxCreate;
use Phpcp\Agent\Capability\MailBoxUpdate;
use Phpcp\Agent\Capability\MailCert;
use Phpcp\Agent\Capability\MailConfigRead;
use Phpcp\Agent\Capability\MailCustomConfig;
use Phpcp\Agent\Capability\MailMessage;
use Phpcp\Agent\Capability\MailQueueAction;
use Phpcp\Agent\Capability\MailQueueList;
use Phpcp\Agent\Capability\MailReadiness;
use Phpcp\Agent\Capability\MailBoxDelete;
use Phpcp\Agent\Capability\MailDomainSet;
use Phpcp\Agent\Capability\MailTest;
use Phpcp\Agent\Capability\NotifyTest;
use Phpcp\Agent\Capability\SettingsGet;
use Phpcp\Agent\Capability\SettingsSet;
use Phpcp\Agent\Capability\SystemInfo;
use Phpcp\Agent\Capability\SystemMetrics;

/**
 * ทะเบียน capability ทั้งหมดที่ agent ยอมทำ — ARCHITECTURE §4.3
 *
 * เป็น allowlist ตายตัวที่ประกาศไว้ในโค้ด ไม่ scan directory ไม่ทำ dynamic dispatch
 * ชื่อที่ไม่อยู่ในนี้ = ปฏิเสธทันที ไม่มี fallback
 *
 * เหตุผลที่ไม่ใช้ autoload ตามชื่อ: ถ้าผู้โจมตีเขียนไฟล์ลง src/Agent/Capability/ ได้
 * (เช่นผ่านช่องโหว่ file manager) การ scan directory จะกลายเป็นทางรันโค้ดทันที
 */
final class CapabilityRegistry
{
    /** @var array<string,class-string<Capability>> */
    private array $map = [];

    /** @var array<string,Capability> */
    private array $instances = [];

    public function __construct()
    {
        foreach (self::defaults() as $class) {
            $this->register($class);
        }
    }

    /** @return list<class-string<Capability>> */
    public static function defaults(): array
    {
        return [
            // อ่านอย่างเดียว
            SystemMetrics::class,
            SystemInfo::class,
            ServiceStatus::class,
            LogTail::class,
            PhpList::class,
            DbList::class,
            FileList::class,
            FileTree::class,
            FileSearch::class,
            FileInfo::class,
            FileRead::class,
            FileDownload::class,
            FirewallStatus::class,
            SslList::class,
            SecurityScan::class,
            PanelJailStatus::class,
            SettingsGet::class,

            // งานวัดผลตามเวลาของ scheduler — ไม่แตะเครื่อง เขียนแค่ตารางแคชของ panel
            DiskUsage::class,
            DiskQuotaCheck::class,
            MetricsRecord::class,
            AlertCheck::class,
            CertSync::class,

            // เปลี่ยนแปลงระบบ — ต้องมี permission service.control และถูกบันทึก audit ทุกครั้ง
            ServiceStart::class,
            ServiceStop::class,
            ServiceRestart::class,
            ServiceReload::class,
            PanelJailSet::class,
            PanelJailUnban::class,

            // Hosting — สร้างและจัดการเว็บไซต์
            SiteCreate::class,
            SiteRateLimitSet::class,
            SiteRateLimitStatus::class,
            SiteRateLimitUnban::class,
            SiteRebuild::class,
            WebserverApply::class,
            WebserverRescan::class,
            SiteSetPhp::class,
            SiteSetDomains::class,
            SiteAddDomain::class,
            SiteRemoveDomain::class,
            SiteSuspend::class,
            SiteResume::class,
            SiteDelete::class,

            // ค่าตั้งเพิ่มเติมที่ผู้ดูแลเขียนเอง — อ่านไฟล์ที่ generate ได้ แต่แก้ได้เฉพาะไฟล์ของตัวเอง
            ConfigFileRead::class,
            SiteCustomConfig::class,
            SiteResetOwner::class,

            // DNS — เชื่อม BIND9 จริง (PLAN-V2 เฟส E3)
            DnsZoneWrite::class,
            DnsReload::class,
            DnsConfigRead::class,
            DnsCustomConfig::class,
            DnsZoneRead::class,
            DnsZoneImport::class,
            PanelCertSet::class,

            // ฐานข้อมูล
            DbCreate::class,
            DbDrop::class,
            DbUserPassword::class,
            DbAccountCredentials::class,
            DbAccountRotate::class,

            // งานอัตโนมัติ
            CronSync::class,

            // สำรองและกู้คืนข้อมูล
            BackupCreate::class,
            BackupRun::class,
            BackupList::class,
            BackupRestore::class,
            BackupDelete::class,
            BackupPush::class,
            BackupHostKeyScan::class,
            BackupPrune::class,
            BackupDestinationTest::class,

            // ค่าตั้ง การแจ้งเตือน และเมลขาออก
            SettingsSet::class,
            NotifyTest::class,
            MailApply::class,
            MailTest::class,

            // เมลโฮสติ้ง — กล่องจดหมายจริงบนเครื่องนี้ (PLAN-MAIL เฟส M1)
            MailDomainSet::class,
            MailBoxCreate::class,
            MailBoxUpdate::class,
            MailBoxDelete::class,
            MailAliasSet::class,
            MailAliasDelete::class,
            MailReadiness::class,
            MailCert::class,
            MailConfigRead::class,
            MailCustomConfig::class,

            // คิวเมลขาออก — ตอบคำถาม "ทำไมเมลไม่ถึง" โดยไม่ต้อง ssh เข้าเครื่อง
            MailQueueList::class,
            MailQueueAction::class,
            MailMessage::class,

            // SSL — ขอ ต่ออายุ และสลับโหมด HTTPS ของเว็บไซต์
            SslIssue::class,
            SslRenew::class,
            SslSetMode::class,
            SslDelete::class,

            // Firewall — ทุกคำสั่งที่ทำให้เข้าถึงเครื่องได้แคบลงต้องยืนยันภายในเวลา
            FirewallRuleAdd::class,
            FirewallRuleDelete::class,
            FirewallEnable::class,
            FirewallDisable::class,

            // ชื่อโฮสต์ของเครื่อง — Postfix กับใบรับรองอ้างชื่อนี้
            HostnameSet::class,

            // SSH + กลไกคืนค่าอัตโนมัติ
            SshConfigGet::class,
            SshConfigSet::class,
            RollbackConfirm::class,
            RollbackRun::class,

            // ตัวจัดการไฟล์ — ต้องมี permission file.manage และทำงานในสิทธิ์ของเว็บไซต์เท่านั้น
            FileWrite::class,
            FileMkdir::class,
            FileMove::class,
            FileDelete::class,
            FileChmod::class,
            FileZip::class,
            FileUnzip::class,
            FileUpload::class,

            // ลูกค้า
            CustomerCreate::class,
            CustomerQuotaUpdate::class,
            CustomerSiteAttach::class,
            CustomerLayoutSet::class,
            ExpiryCheck::class,

            // SFTP — หนึ่งบัญชีโฮสติ้ง = หนึ่ง login (PLAN-V2 เฟส E4)
            SftpEnable::class,
            SftpDisable::class,
        ];
    }

    /** @param class-string<Capability> $class */
    public function register(string $class): void
    {
        if (!is_subclass_of($class, Capability::class)) {
            throw new \LogicException("{$class} ไม่ได้ implement Capability");
        }

        $name = $class::name();
        if (isset($this->map[$name]) && $this->map[$name] !== $class) {
            throw new \LogicException("มี capability ชื่อ {$name} ซ้ำกัน");
        }

        $this->map[$name] = $class;
    }

    /**
     * @param string $name
     */
    public function has(string $name): bool
    {
        return isset($this->map[$name]);
    }

    /**
     * @param string $name
     */
    public function resolve(string $name): Capability
    {
        if (!isset($this->map[$name])) {
            throw new UnknownCapability("ไม่รู้จักคำสั่ง: {$name}");
        }

        return $this->instances[$name] ??= new $this->map[$name]();
    }

    /** @return list<string> */
    public function names(): array
    {
        $names = array_keys($this->map);
        sort($names);

        return $names;
    }

    /** @return array<string,array{permission:string,mutating:bool,summary:string}> */
    public function describe(): array
    {
        $out = [];
        foreach ($this->names() as $name) {
            $capability = $this->resolve($name);
            $out[$name] = [
                'permission' => $capability->permission(),
                'mutating' => $capability->isMutating(),
                'summary' => $capability->summary()
            ];
        }

        return $out;
    }
}
