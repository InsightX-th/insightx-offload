=== InsightX Offload ===
Contributors: insightx
Tags: offload, s3, minio, cloudflare r2, digitalocean spaces, cdn, migrate, media, wp-cli
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.2.6
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
* Secret Key เข้ารหัส AES-256-GCM (Authenticated Encryption) เก็บในฐานข้อมูล
  — ค่าเดิม (AES-256-CBC) ยังอ่านได้อัตโนมัติ

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

= 0.2.6 =
* เพิ่มตัวเลือก "ไม่สร้างรูปขนาดย่อ" — ปิดการสร้าง thumbnail/medium/large/1536/2048 ของ
  WordPress ทั้งหมด (รวมขนาดของ WooCommerce และธีม) อัปโหลดหนึ่งครั้งได้ไฟล์เดียวขึ้น bucket
  เก็บไฟล์ต้นฉบับไว้ตามเดิมโดยไม่ย่อเป็น -scaled เหมาะกับเว็บที่ดึงรูปแบบ headless แล้วไปย่อเอง
  ปิดไว้เป็นค่าเริ่มต้น มีผลกับไฟล์ที่อัปโหลดใหม่เท่านั้น (คำเตือน: เปิดแล้ว srcset จะหาย
  และทุกจุดที่ขอรูปเล็กจะได้ไฟล์เต็มแทน)

= 0.2.5 =
* แก้บั๊ก: ไฟล์ที่ถูก offload ไปวางที่ root ของ bucket (ปิด Prefix + ระบุประเภทเนื้อหาไม่ได้ ทำให้
  base_key เป็นค่าว่าง) ถูกทุกจุดของปลั๊กอินตีความว่า "ยังไม่ได้ offload" เพราะเช็คด้วย empty()
  แทน isset() — ผลคือ URL ไม่เปลี่ยนไปเสิร์ฟจาก bucket, การเขียน URL ถาวรลง DB ไม่ทำงาน,
  Sync มองไม่เห็นไฟล์ที่มีอยู่จริง
* แก้ URL ที่ได้จากไฟล์ root ของ bucket มี slash คู่ (เช่น .../bucket//file.jpg)

= 0.2.4 =
* เพิ่มการแยกโฟลเดอร์บน bucket ตามประเภทเนื้อหา — สินค้า ไฟล์ดาวน์โหลดสินค้า บทความ โปรโมชั่น
  หมวดหมู่สินค้า และแบรนด์ แยกคนละโฟลเดอร์ ตั้งชื่อโฟลเดอร์เองได้ทั้งหมด (ปิดไว้เป็นค่าเริ่มต้น
  เว็บที่ใช้อยู่เดิม path ไม่เปลี่ยน)
* ไฟล์ที่ระบุประเภทได้จะข้าม Year & Month กับ Object Version อัตโนมัติ ทำให้ได้ path สั้นอ่านง่าย
  เช่น `products/ชื่อสินค้า/ไฟล์.jpg` — ส่วนไฟล์ที่ระบุประเภทไม่ได้ยังใช้สองอย่างนั้นตามเดิม
  ซึ่งเป็นสิ่งเดียวที่กันไฟล์ชื่อซ้ำข้ามเดือนไม่ให้ทับกัน
* ระบุประเภทได้ทั้งตอนอัปโหลดปกติและตอน Bulk Offload / WP-CLI ที่ไม่มีหน้าอ้างอิง
* เปลี่ยนชื่อโฟลเดอร์หรือปิดฟีเจอร์ ไม่ย้ายไฟล์ที่อัปโหลดไปแล้ว — path เดิมถูกบันทึกต่อไฟล์อยู่แล้ว
* แก้เลขเวอร์ชันในไฟล์ปลั๊กอินที่ยังค้างเป็น 0.2.2 ให้ตรงกับส่วนหัวปลั๊กอิน

= 0.2.3 =
* Offload เร็วขึ้นหลายเท่า โดยเฉพาะเว็บที่ bucket อยู่ไกล — เดิมทุกไฟล์เปิดการเชื่อมต่อใหม่หมด
  ไฟล์แนบหนึ่งรายการมีทั้งไฟล์ต้นฉบับและ thumbnail อีกหลายขนาด จึงเสียเวลาไปกับการจับมือ
  (TCP/TLS handshake) มากกว่าการส่งไฟล์จริง ตอนนี้ใช้การเชื่อมต่อเดิมต่อเนื่องทั้งชุด
* ข้ามการคำนวณ checksum ของไฟล์ตอนเซ็นคำขอเมื่อต่อผ่าน https (UNSIGNED-PAYLOAD) —
  เดิมต้องอ่านไฟล์ทั้งไฟล์เพิ่มอีกหนึ่งรอบก่อนส่ง ซึ่งกินเวลามากกับไฟล์วิดีโอขนาดใหญ่
  ใช้ filter `isxs_sign_upload_payload` เปิดกลับได้ ถ้าปลายทางไม่รองรับ
* การลองใหม่เมื่อปลายทางตอบ error ชั่วคราว จะไม่รอข้ามรอบการทำงานอีกต่อไป — เดิมรอ 1 และ 3 วินาที
  ต่อไฟล์ ทำให้รอบหนึ่งหมดเวลาไปกับการรอเพียงไม่กี่รายการ
* แก้เลขเวอร์ชันในไฟล์ปลั๊กอินที่ยังค้างเป็น 0.2.1 ตั้งแต่รุ่น 0.2.2 ซึ่งทำให้ตัวตรวจอัปเดต
  เด้งแจ้งเวอร์ชันใหม่ซ้ำแม้ติดตั้งแล้ว

= 0.2.2 =
* แจ้งเตือนอัปเดตในหน้า Plugins ของ WordPress เองแล้ว โดยอ่านจาก GitHub Releases —
  เดิมต้องดาวน์โหลด zip มาอัปโหลดเองทุกครั้ง เพราะปลั๊กอินไม่ได้อยู่บน wordpress.org
  และไม่มีอะไรบอก WordPress ว่ามีเวอร์ชันใหม่
  หมายเหตุ: เวอร์ชันนี้ยังต้องติดตั้งด้วยมือครั้งสุดท้าย ตัวตรวจอัปเดตถึงจะเริ่มทำงาน

= 0.2.1 =
* แก้ปัญหา "ไม่พบไฟล์ต้นฉบับบนเซิร์ฟเวอร์" ทั้งที่ไฟล์ยังอยู่ในโฟลเดอร์ uploads — เว็บที่ย้ายมาจาก
  เซิร์ฟเวอร์อื่นมักมี path เต็มของเครื่องเก่าค้างใน `_wp_attached_file` ตอนนี้ปลั๊กอินแปลง path
  ให้อ้างอิงโฟลเดอร์ uploads ปัจจุบันเสมอ ทั้งตอน Offload, Download กลับ, Migrate และ Sync
* แยกกรณี "meta ของไฟล์แนบเสียหาย" ออกจาก "ไฟล์หาย" เป็นคนละข้อความ เพราะวิธีแก้ต่างกัน
* Sync: badge บอกผลการตรวจโดยตรง — เขียวเมื่อตรงกันทั้งหมด แดงเมื่อพบรายการไม่ตรงกันหรือซิงก์ไม่สำเร็จ
  และจำผลไว้ข้ามการโหลดหน้า (เดิม badge ค้างเป็นสีเทาจนกว่าจะรีเฟรช)
* Sync: ซ่อนแถบความคืบหน้าเมื่อตรวจเสร็จ แทนที่จะค้างเต็มแถบคู่กับ "0 รายการ"
* ปุ่ม "ยกเลิก" ของทุกเครื่องมือเป็นสีแดง ให้ต่างจากปุ่ม "หยุด" ชัดเจน
* Secret Key now encrypted with AES-256-GCM (format ENC3) — with Auth tag that detects value tampering
  (previously AES-256-CBC had no tag) — old values (legacy format) will still be read automatically
* Loopback runner token will automatically rotate when the site URL changes (domain change,
  http → https) — the old token will no longer be usable, and jobs in progress will automatically
  resume via Healthcheck

= 0.2.0 =
* Sync card แสดง badge สถานะการตรวจสอบล่าสุด (ตรวจล่าสุดวันนี้ / X วันที่แล้ว) หน้าปุ่ม แทนข้อความใต้คำอธิบาย
* ติดตามเวลาที่ตรวจ Sync ครั้งล่าสุด พร้อมแจ้งเตือนเมื่อไม่ได้ตรวจนานเกิน 7 วัน (stale)

= 0.1.0 =
* UI ใหม่แบบ Offload Media: header + Offload Status dropdown + แท็บ
  สื่อ/ทรัพยากร/เครื่องมือ/ย้ายข้อมูล/ช่วยเหลือ
* ฟีเจอร์ใหม่ Assets Pull (Rewrite Asset URLs ผ่าน CDN + Force HTTPS)
* เพิ่มการแสดงผลเวลาที่ใช้ไปแล้ว (Elapsed Time) ในทุก Bulk Tool Card
* รองรับ WP-CLI เต็มรูปแบบผ่านคำสั่ง `wp isxm` (status, job list, offload, download, remove, sync, migrate)
* URL Preview เต็มรูปแบบ (Scheme/Domain/Prefix/Year-Month/Version/Filename)
* PHP class prefix ใช้ ISXM_ — CLI ใช้ `wp isxm`
