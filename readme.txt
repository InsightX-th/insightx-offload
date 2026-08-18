=== InsightX Offload ===
Contributors: insightx
Tags: offload, s3, minio, cloudflare r2, digitalocean spaces, cdn, migrate, media, wp-cli
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Offload media ไปยัง S3-compatible storage พร้อม Assets Pull (CDN), Migrate ระหว่าง provider และ bulk tools

== Description ==

InsightX Offload ช่วยย้าย media ของ WordPress ขึ้น S3-compatible storage
(MinIO, Amazon S3, Cloudflare R2, DigitalOcean Spaces, Garage, Google Cloud
Storage) และเสิร์ฟต่อผ่าน URL ใหม่ พร้อมเครื่องมือ bulk และ WP-CLI ครบวงจร

ฟีเจอร์หลัก:

* Offload Media อัตโนมัติ (ไฟล์หลัก + ทุกขนาด) พร้อม Remove Local Media
* เขียน URL ถาวรลงฐานข้อมูลแบบ one-pass ตอนท้ายงาน (เร็วกับคลังขนาดใหญ่)
* Deliver Offloaded Media + Force HTTPS + Custom CDN domain
* **Assets Pull** — เสิร์ฟ CSS/JS ของธีมและปลั๊กอินผ่าน CDN
* Migrate ระหว่าง provider พร้อมเปลี่ยน URL ในฐานข้อมูลอัตโนมัติ
* Bulk tools ฝั่งเซิร์ฟเวอร์: Offload, Retry failed, Download กลับ, ลบออกจาก
  bucket, WooCommerce downloads — ปิดแท็บได้ กด "ทำต่อ" ต่อจากจุดเดิม + ETA + แสดงเวลาที่ใช้ไปแล้ว (Elapsed Time)
* Sync เทียบ meta กับ bucket จริง (หา meta ค้าง / ไฟล์หาย / orphan)
* หน้าสื่อ: คอลัมน์ Storage, ตัวกรองสถานะ, bulk action
* **Full WP-CLI Integration**: `wp isxm status`, `wp isxm job list`, `wp isxm offload`, `wp isxm job run` รองรับการรันผ่าน Crontab
* Secret Key เข้ารหัส AES-256-CBC เก็บในฐานข้อมูล

== Installation ==

1. อัปโหลดโฟลเดอร์ `insightx-offload` ไปที่ `/wp-content/plugins/`
2. เปิด (Activate) InsightX Offload
3. ไปที่หน้า InsightX Offload → แท็บ การเชื่อมต่อ เพื่อตั้งค่า connection
   (provider / endpoint / bucket / keys) แล้วเลือก provider ที่แท็บ สื่อ
4. เปิด Offload Media แล้วกด "เริ่ม Offload" ที่แท็บ เครื่องมือ หรือรันผ่าน WP-CLI

== Frequently Asked Questions ==

= ไฟล์ asset (CSS/JS) ถูกอัปโหลดขึ้น bucket ไหม? =

ไม่ — ไฟล์ theme/plugin อยู่บนเซิร์ฟเวอร์เหมือนเดิม CDN แค่ cache/พร็อกซีหน้าเว็บ
ต้องตั้ง CDN Domain (เช่น CloudFront ชี้ origin ที่เว็บ) ในแท็บ ทรัพยากร

= งาน offload 80,000+ ไฟล์ใช้เวลานานไหม? =

Bulk Offload เขียน URL ลงฐานข้อมูลแบบหนึ่ง-pass ตอนท้ายงาน (ไม่สแกนทั้งตาราง
ทุก batch) + งานวิ่งฝั่งเซิร์ฟเวอร์พร้อม ETA, แสดงเวลาที่ใช้ไปแล้ว และกู้งานสะดุดอัตโนมัติ

= สามารถรันงานเบื้องหลังผ่าน WP-CLI หรือ Cron Job ได้ไหม? =

ได้ — สามารถใช้คำสั่ง `wp isxm job run --once` ใส่ใน Crontab ของเซิร์ฟเวอร์เพื่อรัน Runner เบื้องหลังได้ทันทีโดยไม่ต้องเปิดหน้าเว็บทิ้งไว้

== Changelog ==

= 0.1.0 =
* UI ใหม่แบบ Offload Media: header + Offload Status dropdown + แท็บ
  สื่อ/ทรัพยากร/เครื่องมือ/ย้ายข้อมูล/ช่วยเหลือ
* ฟีเจอร์ใหม่ Assets Pull (Rewrite Asset URLs ผ่าน CDN + Force HTTPS)
* เพิ่มการแสดงผลเวลาที่ใช้ไปแล้ว (Elapsed Time) ในทุก Bulk Tool Card
* รองรับ WP-CLI เต็มรูปแบบผ่านคำสั่ง `wp isxm` (status, job list, offload, download, remove, sync, migrate)
* URL Preview เต็มรูปแบบ (Scheme/Domain/Prefix/Year-Month/Version/Filename)
* PHP class prefix ใช้ ISXM_ — CLI ใช้ `wp isxm`
