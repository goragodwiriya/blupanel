<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\SiteRepository;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Security\Permissions;

/**
 * ฐานร่วมของ endpoint ฝั่ง Hosting ที่ผูกกับเว็บไซต์
 *
 * รวมกฎ "ผู้ดูแลเว็บไซต์เห็นเฉพาะเว็บของตัวเอง" ไว้ที่เดียว — กฎนี้ต้องบังคับ
 * ที่ระดับ query ไม่ใช่ที่หน้าจอ (SECURITY §2.5 กัน IDOR) และต้องถูกเรียกจากทุก endpoint
 * ที่รับ `site_id` มาจากผู้ใช้ · การมีที่เดียวทำให้ตรวจได้ว่าครบทุกจุดจริง
 *
 * ตรวจที่นี่ **เพิ่มเติมจาก** ที่ agent ตรวจ ไม่ใช่แทนกัน: permission ตอบได้แค่ว่า
 * "แก้เว็บไซต์ได้ไหม" ไม่ได้ตอบว่า "แก้เว็บไซต์ตัวไหนได้"
 */
abstract class HostingController extends ApiController
{
    protected function sites(): SiteRepository
    {
        return new SiteRepository($this->app->db());
    }

    /** id ของเจ้าของที่ต้องใช้กรองรายการ — null = เห็นทั้งหมด */
    protected function scopeOwner(): ?int
    {
        return $this->ctx->role() === Permissions::WEBADMIN ? $this->ctx->userId() : null;
    }

    protected function mayAccessSite(int $siteId): bool
    {
        if ($this->ctx->role() !== Permissions::WEBADMIN) {
            return true;
        }

        return $this->sites()->isOwnedBy($siteId, $this->ctx->userId());
    }

    /**
     * โหลดเว็บไซต์ที่ผู้เรียกมีสิทธิ์เห็น — คืน null ถ้าไม่มีสิทธิ์หรือไม่มีอยู่จริง
     *
     * ตอบ 404 เหมือนกันทั้งสองกรณีโดยเจตนา: ถ้าตอบ 403 เมื่อ "มีอยู่แต่ไม่ใช่ของคุณ"
     * ลูกค้ารายหนึ่งจะไล่เดา id เพื่อดูว่ามีเว็บไซต์อะไรอยู่บนเครื่องบ้างได้
     *
     * @return array<string,mixed>|null
     */
    protected function findSite(int $siteId): ?array
    {
        if ($siteId < 1 || !$this->mayAccessSite($siteId)) {
            return null;
        }

        return $this->sites()->find($siteId);
    }

    protected function siteNotFound(): \Phpcp\Kernel\Response
    {
        return $this->problem(ApiProblem::NotFound, 'Website not found');
    }
}
