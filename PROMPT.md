# PHP Server Control Panel UI Design Prompt

Design and build a modern, clean, secure, and professional **PHP Server Control Panel** similar in concept to Plesk, but with a simpler and more developer-friendly user experience.

The interface must be designed for managing a Linux server primarily used for PHP web hosting.

## Important Language Requirement

The entire user interface must be in **Thai language**.

* All navigation labels must be Thai.
* All buttons must be Thai.
* All headings must be Thai.
* All form labels must be Thai.
* All status messages must be Thai.
* All notifications and alerts must be Thai.
* All sample data should use realistic Thai-language UI content.

Technical terms that are commonly understood by developers may remain in English when appropriate, such as:

* PHP
* Nginx
* Apache
* MySQL
* MariaDB
* PHP-FPM
* SSL
* DNS
* CPU
* RAM
* Disk
* SSH

Do not translate technical product names unnecessarily.

---

# Design Goals

The UI should feel like a modern professional server management platform.

Design principles:

* Simple
* Clean
* Fast
* Secure
* Professional
* Developer-friendly
* Easy to understand
* Responsive
* Desktop-first but fully usable on tablets
* Avoid unnecessary visual complexity
* Prioritize information hierarchy and usability

Use a modern dashboard layout with:

* Fixed sidebar navigation
* Top header
* Breadcrumb navigation
* Main content area
* Responsive layout
* Cards
* Tables
* Status badges
* Charts where useful
* Modal dialogs
* Confirmation dialogs
* Toast notifications

Use a professional neutral visual style suitable for a server administration system.

Do not make the interface look like a generic SaaS marketing website.

The design should communicate reliability, security, and technical control.

---

# Main Navigation Structure

Separate the system into two major areas:

## 1. Hosting

This section manages websites and hosting resources.

Menu:

* ภาพรวม
* เว็บไซต์
* โดเมน
* SSL Certificates
* PHP
* ฐานข้อมูล
* ตัวจัดการไฟล์
* งานอัตโนมัติ (Cron Jobs)
* สำรองข้อมูล

## 2. Server

This section manages the actual server infrastructure.

Menu:

* ภาพรวมเซิร์ฟเวอร์
* Services
* ความปลอดภัย
* Firewall
* SSH
* Logs
* ผู้ใช้งานระบบ
* การตั้งค่าเซิร์ฟเวอร์

The distinction between **Hosting** and **Server** must be visually clear.

Hosting is about websites and applications.

Server is about infrastructure and system services.

---

# Dashboard

Create a main dashboard showing an overview of the server and hosting environment.

Display:

* สถานะเซิร์ฟเวอร์
* CPU Usage
* RAM Usage
* Disk Usage
* Network Usage
* Server Uptime
* จำนวนเว็บไซต์
* จำนวนโดเมน
* จำนวนฐานข้อมูล
* จำนวน PHP Versions
* SSL Certificates
* Backup Status

Show service status cards:

* Nginx
* Apache
* PHP-FPM
* MariaDB
* Cron

Use clear status indicators:

* ทำงานปกติ
* หยุดทำงาน
* มีปัญหา
* ต้องดำเนินการ

Show recent activity:

* การเข้าสู่ระบบ
* การสร้างเว็บไซต์
* การเปลี่ยน PHP Version
* การติดตั้ง SSL
* การสำรองข้อมูล
* การเปลี่ยนแปลงระบบ

---

# Hosting Management

## Websites

Create a website management page.

Display a table with:

* ชื่อเว็บไซต์
* โดเมน
* PHP Version
* SSL
* สถานะ
* พื้นที่ใช้งาน
* วันที่สร้าง
* การดำเนินการ

Actions:

* จัดการ
* เปิดเว็บไซต์
* แก้ไข
* ระงับ
* ลบ

Website detail page should contain:

* ภาพรวม
* โดเมน
* PHP
* SSL
* ฐานข้อมูล
* ไฟล์
* Logs
* Backup

---

# Domain Management

Allow management of:

* Domains
* Subdomains
* Domain Aliases
* Redirects

Display DNS records:

* A
* AAAA
* CNAME
* MX
* TXT
* CAA

Use a clean table interface for DNS management.

---

# PHP Management

Create a dedicated PHP management section.

Display installed PHP versions:

* PHP 8.4
* PHP 8.3
* PHP 8.2
* PHP 8.1

Each PHP version should show:

* Version
* Status
* PHP-FPM Status
* Number of websites using this version
* Installed extensions

Allow:

* Enable PHP Version
* Disable PHP Version
* Install PHP Extension
* Remove PHP Extension
* Configure PHP Settings

For each website, allow selecting a PHP version independently.

Example:

example.com → PHP 8.4

legacy.example.com → PHP 7.4

Make it clear that PHP Version Management belongs to Hosting, while the actual PHP-FPM process is managed under Server Services.

---

# Database Management

Support:

* MySQL
* MariaDB

Display:

* Database Name
* Database User
* Size
* Status
* Website
* Created Date

Actions:

* Create Database
* Create User
* Edit Permissions
* Delete
* Import
* Export

---

# File Manager

Create a modern file manager.

Features:

* Upload
* Download
* Create File
* Create Folder
* Rename
* Copy
* Move
* Delete
* ZIP
* Unzip
* Edit Text Files
* File Permissions

Use a familiar file manager interface.

Support drag-and-drop file upload.

---

# SSL Certificates

Create a dedicated SSL management page.

Display:

* Domain
* Certificate Status
* Issuer
* Expiration Date
* Auto Renewal Status

Actions:

* ติดตั้ง SSL
* ต่ออายุ
* เปิดใช้งาน HTTPS
* บังคับ HTTPS
* ลบ Certificate

Support Let's Encrypt.

Show warnings when certificates are close to expiration.

---

# Backup

Create a simple backup management interface.

Support:

* Website Backup
* Database Backup
* Configuration Backup

Display:

* Backup Name
* Backup Type
* Size
* Created Date
* Status

Actions:

* Create Backup
* Restore
* Download
* Delete

---

# Server Management

The Server section must be clearly separated from Hosting.

The user should understand that this section manages the underlying Linux server rather than individual websites.

---

# Services

Create a dedicated **Services Management** page.

This page is responsible for managing server-level services.

Display services in a table or card layout:

* Nginx
* Apache
* PHP-FPM 8.4
* PHP-FPM 8.3
* MariaDB
* Cron

Each service should display:

* Service Name
* Status
* Version
* Uptime
* Last Started
* Resource Usage

Actions:

* Start
* Stop
* Restart
* Reload

Show clear warnings before performing dangerous operations.

For example:

"การหยุดบริการนี้อาจทำให้เว็บไซต์ที่เกี่ยวข้องไม่สามารถใช้งานได้"

The Services page must be independent from Hosting.

However, the UI should show relationships between them.

Example:

Nginx

เว็บไซต์ที่ใช้งาน:

* example.com
* demo.com

PHP-FPM 8.4

เว็บไซต์ที่ใช้งาน:

* example.com
* shop.com

MariaDB

ฐานข้อมูลที่ใช้งาน:

* example_db
* shop_db

This provides visibility without mixing Hosting Management and Server Service Management.

---

# Security

Create a dedicated Security Center.

Display:

* สถานะความปลอดภัย
* Firewall Status
* SSH Status
* Failed Login Attempts
* SSL Status
* File Permission Issues
* Security Recommendations

Use a security score.

Example:

ความปลอดภัยของเซิร์ฟเวอร์

92 / 100

Show actionable recommendations.

Example:

* ปิดการเข้าสู่ระบบ SSH ด้วย Root
* เปิดใช้งาน Firewall
* อัปเดต PHP Version
* เปิดใช้งาน HTTPS

---

# Firewall

Provide a simple firewall management interface.

Display:

* เปิดใช้งาน Firewall
* รายการ Ports
* Allowed IPs
* Blocked IPs

Actions:

* เพิ่มกฎ
* แก้ไข
* ลบ
* เปิดใช้งาน
* ปิดใช้งาน

Avoid making firewall configuration unnecessarily complex.

---

# SSH

Display SSH security settings:

* SSH Status
* Root Login
* Password Authentication
* SSH Key Authentication
* SSH Port

Use clear warnings for dangerous configurations.

---

# Logs

Provide access to:

* Access Logs
* Error Logs
* PHP Logs
* System Logs
* Login Logs
* Audit Logs

Support:

* Search
* Filter
* Date Range
* Log Level

Use a terminal-like log viewer for technical logs.

---

# System Users

Manage server users separately from hosting websites.

Display:

* Username
* Role
* Status
* Last Login

Roles:

* ผู้ดูแลระบบ
* ผู้ดูแลเซิร์ฟเวอร์
* ผู้ดูแลเว็บไซต์

Use role-based permissions.

---

# UI Architecture

The sidebar should visually group the navigation.

Example:

```text
แดชบอร์ด

HOSTING
เว็บไซต์
โดเมน
SSL Certificates
PHP
ฐานข้อมูล
ตัวจัดการไฟล์
งานอัตโนมัติ
สำรองข้อมูล

SERVER
ภาพรวมเซิร์ฟเวอร์
Services
ความปลอดภัย
Firewall
SSH
Logs
ผู้ใช้งานระบบ
การตั้งค่า
```

Use section labels to make the separation obvious.

---

# Important UX Rule

Never mix infrastructure management with hosting management unnecessarily.

For example:

The Website page should show:

"PHP Version: 8.4"

But it should not expose low-level PHP-FPM process controls directly.

The Services page should manage:

"PHP-FPM 8.4"

and show which websites depend on it.

This creates a clean separation:

Hosting Layer:

Website → Domain → PHP Version → Database → SSL → Files

Server Layer:

Nginx → PHP-FPM → MariaDB → Cron → Firewall → SSH

The interface should allow users to navigate between these layers when necessary, while keeping the concepts separate.
