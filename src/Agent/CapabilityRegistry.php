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
use Phpcp\Agent\Capability\Fail2banSet;
use Phpcp\Agent\Capability\NeverBanSet;
use Phpcp\Agent\Capability\ProtectionOverview;
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
 * The registry of every capability the agent will perform — ARCHITECTURE §4.3
 *
 * A fixed allowlist declared right in the code — no directory scanning, no
 * dynamic dispatch. A name not on this list is rejected immediately, no fallback.
 *
 * Why not autoload by name: if an attacker could write a file into
 * src/Agent/Capability/ (say, through a file-manager hole), scanning the
 * directory would instantly become a code-execution path.
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
            // Read-only
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
            ProtectionOverview::class,
            SettingsGet::class,

            // Scheduled measurement jobs run by the scheduler — never touch the machine, only write the panel's own cache tables
            DiskUsage::class,
            DiskQuotaCheck::class,
            MetricsRecord::class,
            AlertCheck::class,
            CertSync::class,

            // Changes the system — needs service.control permission and is audit-logged every time
            ServiceStart::class,
            ServiceStop::class,
            ServiceRestart::class,
            ServiceReload::class,
            PanelJailSet::class,
            NeverBanSet::class,
            Fail2banSet::class,
            PanelJailUnban::class,

            // Hosting — creating and managing websites
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

            // Extra config an admin writes by hand — the generated file can be read, but only its own custom file can be edited
            ConfigFileRead::class,
            SiteCustomConfig::class,
            SiteResetOwner::class,

            // DNS — talks to the real BIND9 (PLAN-V2 Phase E3)
            DnsZoneWrite::class,
            DnsReload::class,
            DnsConfigRead::class,
            DnsCustomConfig::class,
            DnsZoneRead::class,
            DnsZoneImport::class,
            PanelCertSet::class,

            // Databases
            DbCreate::class,
            DbDrop::class,
            DbUserPassword::class,
            DbAccountCredentials::class,
            DbAccountRotate::class,

            // Automated jobs
            CronSync::class,

            // Backup and restore
            BackupCreate::class,
            BackupRun::class,
            BackupList::class,
            BackupRestore::class,
            BackupDelete::class,
            BackupPush::class,
            BackupHostKeyScan::class,
            BackupPrune::class,
            BackupDestinationTest::class,

            // Settings, notifications, and outbound mail
            SettingsSet::class,
            NotifyTest::class,
            MailApply::class,
            MailTest::class,

            // Mail hosting — real mailboxes on this machine (PLAN-MAIL Phase M1)
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

            // The outbound mail queue — answers "why didn't the mail arrive" without an ssh into the machine
            MailQueueList::class,
            MailQueueAction::class,
            MailMessage::class,

            // SSL — issuing, renewing, and switching a website's HTTPS mode
            SslIssue::class,
            SslRenew::class,
            SslSetMode::class,
            SslDelete::class,

            // Firewall — every command that narrows access to the machine must be confirmed within a time window
            FirewallRuleAdd::class,
            FirewallRuleDelete::class,
            FirewallEnable::class,
            FirewallDisable::class,

            // The machine's hostname — Postfix and certificates reference this name
            HostnameSet::class,

            // SSH + the auto-recovery mechanism
            SshConfigGet::class,
            SshConfigSet::class,
            RollbackConfirm::class,
            RollbackRun::class,

            // File manager — needs file.manage permission and only ever operates within a website's own scope
            FileWrite::class,
            FileMkdir::class,
            FileMove::class,
            FileDelete::class,
            FileChmod::class,
            FileZip::class,
            FileUnzip::class,
            FileUpload::class,

            // Customers
            CustomerCreate::class,
            CustomerQuotaUpdate::class,
            CustomerSiteAttach::class,
            CustomerLayoutSet::class,
            ExpiryCheck::class,

            // SFTP — one hosting account = one login (PLAN-V2 Phase E4)
            SftpEnable::class,
            SftpDisable::class,
        ];
    }

    /** @param class-string<Capability> $class */
    public function register(string $class): void
    {
        if (!is_subclass_of($class, Capability::class)) {
            throw new \LogicException("{$class} does not implement Capability");
        }

        $name = $class::name();
        if (isset($this->map[$name]) && $this->map[$name] !== $class) {
            throw new \LogicException("Duplicate capability name: {$name}");
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
            throw new UnknownCapability("Unknown command: {$name}");
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
