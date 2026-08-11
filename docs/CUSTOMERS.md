# Customer Management — ระบบลูกค้าสำหรับขาย web hosting

> เอกสารอธิบายระบบลูกค้าที่ซื้อบริการ web hosting ผ่าน panel

---

## 1. โครงสร้าง

ระบบลูกค้าแยกจากผู้ใช้ panel (users) โดยสิ้นเชิง:

| ประเภท | คำอธิบาย | ตัวอย่าง |
|--------|----------|---------|
| **ผู้ใช้ panel** | คนที่ล็อกอินเข้า panel ได้ | superadmin, sysadmin, webadmin |
| **ลูกค้า** | ผู้ที่ซื้อบริการ web hosting | customer_a, customer_b, customer_c |

### ตารางฐานข้อมูล

#### `customers`
| คอลัมน์ | ประเภท | คำอธิบาย |
|---------|--------|----------|
| id | INTEGER | รหัสลูกค้า |
| username | TEXT | ชื่อผู้ใช้สำหรับ login (a-z, 0-9, . _ -) |
| password_hash | TEXT | รหัสผ่าน (Argon2id hash) |
| display_name | TEXT | ชื่อที่แสดง (เช่น ชื่อบริษัท) |
| email | TEXT | อีเมลติดต่อ |
| status | TEXT | สถานะ: active, suspended, expired |
| quota_domains | INTEGER | โควตาโดเมน (default: 10) |
| quota_subdomains | INTEGER | โควตา subdomain (default: 20) |
| quota_aliases | INTEGER | โควตา alias (default: 50) |
| quota_emails | INTEGER | โควตา email (default: 100) |
| quota_databases | INTEGER | โควตา database (default: 10) |
| quota_ftp_users | INTEGER | โควตา FTP users (default: 5) |
| expiry_at | INTEGER | วันหมดอายุ (unix timestamp, null = ไม่มี) |
| created_at | INTEGER | วันที่สร้าง |
| updated_at | INTEGER | วันที่แก้ไขล่าสุด |

#### `customer_sites`
| คอลัมน์ | ประเภท | คำอธิบาย |
|---------|--------|----------|
| customer_id | INTEGER | ลูกค้าที่เชื่อม |
| site_id | INTEGER | เว็บไซต์ที่เชื่อม |
| (primary key) | | customer_id + site_id |

#### `expiry_notifications`
| คอลัมน์ | ประเภท | คำอธิบาย |
|---------|--------|----------|
| id | INTEGER | รหัสการแจ้งเตือน |
| customer_id | INTEGER | ลูกค้า |
| days_before | INTEGER | แจ้งเตือนล่วงหน้ากี่วัน |
| notified_at | INTEGER | เคยแจ้งเมื่อไรแล้ว |
| created_at | INTEGER | วันที่สร้าง |

---

## 2. โควตา (Quota)

แต่ละลูกค้ามีโควตาสำหรับทรัพยากรต่างๆ:

- **Domains** - จำนวนโดเมนหลักที่สร้างได้
- **Subdomains** - จำนวน subdomain ที่สร้างได้
- **Aliases** - จำนวน alias ที่สร้างได้
- **Emails** - จำนวน email account (ยังไม่ implement)
- **Databases** - จำนวน database ที่สร้างได้
- **FTP Users** - จำนวน FTP user (ยังไม่ implement)

### การตรวจสอบโควตา

เวลาสร้างทรัพยากร (site, domain, database) ระบบจะ:

1. ตรวจสอบว่าลูกค้ายังใช้งานได้ (status = active + expiry_at > now)
2. นับจำนวนทรัพยากรที่ลูกค้าใช้อยู่
3. ตรวจสอบว่าใช้ร่วมกับโควตาเกินหรือไม่
4. ถ้าเกิน = ปฏิเสธพร้อมข้อความภาษาไทย

### ตัวอย่างข้อผิดพลาด

```
โควตาโดเมนเต็ม (ใช้ 10/10)
โควตา subdomain เต็ม (ใช้ 20/20)
โควตาฐานข้อมูลเต็ม (ใช้ 10/10)
```

---

## 3. วันหมดอายุ (Expiry)

ลูกค้าแต่ละคนสามารถมีวันหมดอายุได้:

- **ไม่มีวันหมดอายุ** - `expiry_at = NULL` (ไม่จำกัดเวลา)
- **มีวันหมดอายุ** - `expiry_at = timestamp` (ต้องตั้งวันที่ในอนาคต)

### การตรวจสอบวันหมดอายุ

ระบบตรวจสอบทุกครั้งที่:

- ลูกค้าพยายามเข้าสู่ระบบ
- ลูกค้าพยายามสร้างทรัพยากรใหม่
- Cron job `expiry.check` ทำงาน

### การแจ้งเตือน

ก่อนวันหมดอายุ 3 ช่วงเวลา ระบบจะแจ้งเตือน:

| วันก่อนหมดอายุ | การกระทำ |
|----------------|----------|
| 30 วัน | ส่งอีเมลแจ้งเตือนครั้งแรก |
| 7 วัน | ส่งอีเมลแจ้งเตือนครั้งที่ 2 |
| 1 วัน | ส่งอีเมลแจ้งเตือนสุดท้าย |

เมื่อวันหมดอายุผ่านไปแล้ว:

- เปลี่ยน status เป็น 'expired'
- ไม่สามารถสร้างทรัพยากรใหม่ได้
- ไม่สามารถเข้าสู่ระบบได้

---

## 4. การจัดการลูกค้า

### 4.1 สร้างลูกค้าใหม่ (Admin)

```
POST /customers
```

**Parameters:**
- `username` - ชื่อผู้ใช้ (3-32 ตัว อักษรตัวแรก)
- `display_name` - ชื่อที่แสดง
- `email` - อีเมล
- `quota_domains` - โควตาโดเมน (default: 10)
- `quota_subdomains` - โควตา subdomain (default: 20)
- `quota_aliases` - โควตา alias (default: 50)
- `quota_emails` - โควตา email (default: 100)
- `quota_databases` - โควตา database (default: 10)
- `quota_ftp_users` - โควตา FTP (default: 5)
- `expiry_at` - วันหมดอายุ (YYYY-MM-DD, ว่าง = ไม่มี)

**Response:**
```json
{
  "id": 1,
  "username": "customer_a",
  "display_name": "บริษัท ABC จำกัด",
  "email": "contact@abc.co.th",
  "quota_domains": 10,
  "quota_subdomains": 20,
  ...
  "message": "สร้างลูกค้า customer_a แล้ว"
}
```

### 4.2 อัปเดตโควตา (Admin)

```
POST /customers/{id}/quota
```

**Parameters:**
- `quota_domains` - (optional)
- `quota_subdomains` - (optional)
- `quota_aliases` - (optional)
- `quota_emails` - (optional)
- `quota_databases` - (optional)
- `quota_ftp_users` - (optional)

**Response:**
```json
{
  "id": 1,
  "username": "customer_a",
  "changes": {
    "quota_domains": {"from": 10, "to": 20},
    "quota_databases": {"from": 10, "to": 20}
  },
  "message": "อัปเดตโควตาของ customer_a แล้ว"
}
```

### 4.3 อัปเดตวันหมดอายุ (Admin)

```
POST /customers/{id}/expiry
```

**Parameters:**
- `expiry_at` - วันหมดอายุ (YYYY-MM-DD) หรือทิ้งว่างไว้

**Response:**
```json
{
  "id": 1,
  "message": "ตั้งวันหมดอายุของ customer_a เป็น 2026-12-31 แล้ว"
}
```

### 4.4 เปลี่ยนสถานะ (Admin)

```
POST /customers/{id}/status
```

**Parameters:**
- `status` - active, suspended, expired

### 4.5 ล้างรหัสผ่าน (Admin)

```
POST /customers/{id}/password
```

**Response:**
```json
{
  "message": "รหัสผ่านถูกตั้งใหม่แล้ว"
}
```

### 4.6 เชื่อมเว็บไซต์ให้ลูกค้า (Admin)

```
POST /customers/{id}/site-attach
```

**Parameters:**
- `site_ids` - array ของ site_id

**Response:**
```json
{
  "customer_id": 1,
  "attached_count": 2,
  "results": [
    {"site_id": 5, "status": "attached", "message": "..."},
    {"site_id": 6, "status": "attached", "message": "..."}
  ]
}
```

---

## 5. CustomerRepository

คลาสหลักในการจัดการลูกค้า:

```php
use Phpcp\Domain\CustomerRepository;

$customers = new CustomerRepository($db);

// ค้นหา
$customer = $customers->find($id);
$customer = $customers->findByUsername($username);
$all = $customers->all();

// สร้าง
$id = $customers->create(
    $username, $password, $displayName, $email,
    $quotaDomains, $quotaSubdomains, $quotaAliases,
    $quotaEmails, $quotaDatabases, $quotaFtpUsers,
    $expiryAt
);

// อัปเดต
$customers->setPassword($id, $plainPassword);
$customers->updateQuota($id, $quotaDomains, $quotaSubdomains, ...);
$customers->updateExpiry($id, $expiryAt);
$customers->setStatus($id, $status);

// เชื่อมเว็บไซต์
$customers->attachSite($customerId, $siteId);
$customers->detachSite($customerId, $siteId);
$siteIds = $customers->getSiteIds($customerId);
$sites = $customers->getSites($customerId);

// ตรวจสอบสถานะ
$status = $customers->checkStatus($id); // ['ok' => bool, 'message' => string]

// นับจำนวน
$count = $customers->countByStatus($status);
$expiring = $customers->countExpiring(30); // ใกล้หมดอายุ 30 วัน
```

---

## 6. QuotaChecker

ตัวตรวจสอบโควตา:

```php
use Phpcp\Domain\CustomerRepository;
use Phpcp\Domain\QuotaChecker;

$customers = new CustomerRepository($db);
$quota = new QuotaChecker($customers);

// ดึงข้อมูล
$usage = $quota->getUsage($customerId);
$quotaData = $quota->getQuota($customerId);

// ตรวจสอบ
$result = $quota->canCreate($customerId, 'domain', 1);
// ['ok' => bool, 'message' => string, 'used' => int, 'limit' => int]

// ตรวจสอบหลายทรัพยากร
$results = $quota->canCreateMultiple($customerId, [
    'domain' => 1,
    'database' => 1,
]);

// สถานะลูกค้า
$status = $quota->checkCustomerStatus($customerId);
// ['ok' => bool, 'message' => string, 'status' => string|null, 'expiry_at' => int|null]
```

---

## 7. Capabilities

### CustomerCreate
```
POST /api/customers/create
permission: customer.manage
summary: สร้างลูกค้าใหม่พร้อมโควตา
```

### CustomerQuotaUpdate
```
POST /api/customers/quota_update
permission: customer.manage
summary: อัปเดตโควตาของลูกค้า
```

### CustomerSiteAttach
```
POST /api/customers/site_attach
permission: customer.manage
summary: เชื่อมเว็บไซต์ให้ลูกค้าเป็นเจ้าของ
```

### ExpiryCheck
```
POST /api/expiry/check
permission: customer.view
summary: ตรวจสอบวันหมดอายุและส่งการแจ้งเตือน
```

---

## 8. สิทธิ์ (Permissions)

| Permission | คำอธิบาย |
|------------|----------|
| `customer.view` | ดูข้อมูลลูกค้าได้ |
| `customer.manage` | สร้าง/แก้ไข/ลบลูกค้าได้ |

---

## 9. ตัวอย่างการใช้งาน

### สร้างลูกค้ารายใหม่

```php
$customers = new CustomerRepository($db);

$id = $customers->create(
    'customer_new',
    'S3cureP@ssw0rd!',
    'บริษัท XYZ จำกัด',
    'contact@xyz.co.th',
    10,  // quota_domains
    20,  // quota_subdomains
    50,  // quota_aliases
    100, // quota_emails
    10,  // quota_databases
    5,   // quota_ftp_users
    strtotime('2026-12-31') // expiry_at
);
```

### ตรวจสอบก่อนสร้างเว็บไซต์

```php
$quota = new QuotaChecker($customers);

$customer = $customers->find(1);
if ($customer === null) {
    throw new Exception('ไม่พบลูกค้า');
}

$status = $quota->checkCustomerStatus(1);
if (!$status['ok']) {
    throw new Exception($status['message']);
}

$result = $quota->canCreate(1, 'domain');
if (!$result['ok']) {
    throw new Exception($result['message']);
}

// สร้างเว็บไซต์ต่อ...
```

### Cron job สำหรับตรวจสอบหมดอายุ

```bash
# /etc/cron.d/phpcp-expiry
0 3 * * * root /usr/local/bin/phpcp capability:run expiry.check
```

---

## 10. การอัปเดตข้อมูล

### อัปเดตโควตา

```php
$customers->updateQuota($customerId, $quotaDomains, $quotaSubdomains, ...);
```

ถ้าต้องการอัปเดตเฉพาะบางฟิลด์ ให้ส่ง `null` สำหรับฟิลด์ที่ไม่ต้องการเปลี่ยน

### อัปเดตวันหมดอายุ

```php
// ตั้งวันหมดอายุ
$customers->updateExpiry($customerId, strtotime('2026-12-31'));

// ยกเลิกวันหมดอายุ
$customers->updateExpiry($customerId, null);
```

### เปลี่ยนสถานะ

```php
$customers->setStatus($customerId, 'active');    // เปิดใช้งาน
$customers->setStatus($customerId, 'suspended'); // ระงับชั่วคราว
$customers->setStatus($customerId, 'expired');   // หมดอายุ
```

---

## 11. ความแตกต่างจาก Users

| ลักษณะ | Users | Customers |
|---------|-------|-----------|
| ตาราง | `users` | `customers` |
| บทบาท | superadmin, sysadmin, webadmin | (ไม่มีบทบาท) |
| การเข้า panel | ล็อกอินได้ | ไม่สามารถล็อกอิน panel ได้ |
| ใช้งาน | จัดการ panel | ซื้อบริการ web hosting |
| เว็บไซต์ | อาจมีหรือไม่มี | มีเว็บไซต์ของตัวเอง |
| โควตา | ไม่มี | มีโควตาทรัพยากร |
| วันหมดอายุ | ไม่มี | มีได้ |
