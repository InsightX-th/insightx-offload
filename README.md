# InsightX Offload (`insightx-offload`)

ปลั๊กอินจัดการและย้ายคลังไฟล์สื่อ (Media Library) ของ WordPress ไปยัง S3-Compatible Object Storage (เช่น **MinIO, Amazon S3, Cloudflare R2, DigitalOcean Spaces, Garage, Google Cloud Storage**) พร้อมระบบ URL Rewrite, ระบบย้ายค่าย Storage (Migrate), **Assets Pull** (เสิร์ฟ CSS/JS ผ่าน CDN), **Server-Side Bulk Job Engine** และ **WP-CLI Integration** — ทั้งหมดรองรับภาษาไทยสมบูรณ์

---

## 📌 สารบัญ (Table of Contents)

1. [ฟีเจอร์เด่น (Features)](#-ฟีเจอร์เด่น-features)
2. [คู่มือการใช้งานผ่าน Web Admin](#-คู่มือการใช้งานผ่าน-web-admin)
   - [2.1 การตั้งค่าการเชื่อมต่อ (Connection)](#21-การตั้งค่าการเชื่อมต่อ-connection)
   - [2.2 การตั้งค่าจัดเก็บและเสิร์ฟไฟล์ (Media & Delivery)](#22-การตั้งค่าจัดเก็บและเสิร์ฟไฟล์-media--delivery)
   - [2.3 เครื่องมือประมวลผลงานเบื้องหลัง (Bulk Management Tools)](#23-เครื่องมือประมวลผลงานเบื้องหลัง-bulk-management-tools)
   - [2.4 การย้ายคลังไฟล์ระหว่าง Storage (Migrate)](#24-การย้ายคลังไฟล์ระหว่าง-storage-migrate)
   - [2.5 การเสิร์ฟไฟล์ CSS/JS ผ่าน CDN (Assets Pull)](#25-การเสิร์ฟไฟล์-cssjs-ผ่าน-cdn-assets-pull)
3. [คู่มือการใช้งานผ่าน WP-CLI (`wp isxm`)](#-คู่มือการใช้งานผ่าน-wp-cli-wp-isxm)
   - [3.1 ภาพรวมคำสั่ง CLI](#31-ภาพรวมคำสั่ง-cli)
   - [3.2 รายละเอียดแต่ละคำสั่ง](#32-รายละเอียดแต่ละคำสั่ง)
   - [3.3 การจัดการ Background Job ผ่าน CLI & System Cron](#33-การจัดการ-background-job-ผ่าน-cli--system-cron)
4. [ความปลอดภัยและประสิทธิภาพ (Security & Performance)](#-ความปลอดภัยและประสิทธิภาพ-security--performance)
5. [การรับอนุญาตใช้งาน (License)](#-การรับอนุญาตใช้งาน-license)

---

## 🚀 ฟีเจอร์เด่น (Features)

| ฟีเจอร์ | รายละเอียด |
| --- | --- |
| **Multi-Provider Support** | รองรับ S3-Compatible Storage 7 รูปแบบ: AWS S3, MinIO, Cloudflare R2, DigitalOcean Spaces, Garage Storage, Google Cloud Storage (GCS) และ Custom S3 |
| **Offload Media** | อัปโหลดภาพและไฟล์สื่อขึ้น Object Storage อัตโนมัติทันทีที่มีการอัปโหลดผ่าน WordPress |
| **Remove Local Media** | ลบไฟล์บนเว็บเซิร์ฟเวอร์หลักหลัง Offload สำเร็จ เพื่อประหยัดพื้นที่ดิสก์ |
| **One-Pass DB URL Rewrite** | อัปเดต URL รูปในฐานข้อมูล (`post_content`, `postmeta`, `options`) แบบรวดเดียวตอนจบงาน ไม่โหลดสแกนทั้งตารางทุก batch เหมาะกับเว็บที่มีไฟล์ 80k+ |
| **Deliver Offloaded Media** | เปลี่ยนแปลง URL การแสดงผลรูปภาพให้ชี้ไปยัง Storage หรือ CDN พร้อมตัวเลือก Force HTTPS |
| **Assets Pull** | เสิร์ฟไฟล์ CSS และ JS ของธีมและปลั๊กอินผ่าน CDN โดยตรงโดยไม่ต้องอัปโหลดไฟล์ขึ้น Bucket |
| **Server-Side Job Engine** | งาน Bulk ทั้งหมดรันฝั่งเซิร์ฟเวอร์ (Loopback / WP-Cron) ปิดแท็บเบราว์เซอร์ได้ งานไม่หลุด แสดงเวลาที่ใช้ไปแล้ว (Elapsed Time) และเวลาคงเหลือ (ETA) แบบ Real-time |
| **Sync & Diagnostic** | สแกนเปรียบเทียบ Meta ใน DB กับไฟล์บน Bucket จริง เพื่อตรวจหาไฟล์ค้าง หรือ Orphan Objects พร้อมหน้าทดสอบการเชื่อมต่อแบบละเอียดยิบ |
| **Full WP-CLI Support** | สั่งงาน ควบคุม และเช็กสถานะผ่าน Command Line ครบทุกฟังก์ชัน รองรับการตั้ง Crontab |

---

## 🖥️ คู่มือการใช้งานผ่าน Web Admin

เข้าสู่หน้าการตั้งค่าได้ที่เมนู **InsightX Offload** หรือ **สื่อ → InsightX Offload** ในหน้าหลังบ้าน WordPress

### 2.1 การตั้งค่าการเชื่อมต่อ (Connection)
1. ไปที่แท็บ **การเชื่อมต่อ (Connection)**
2. เลือก Storage Provider ที่ต้องการใช้งาน (เช่น *MinIO*, *AWS S3*, *Cloudflare R2*)
3. กรอกข้อมูลการเชื่อมต่อ:
   - **Endpoint URL:** (เช่น `https://s3.example.com` หรือ MinIO Endpoint)
   - **Bucket Name:** ชื่อ Bucket ที่สร้างไว้
   - **Region:** เช่น `us-east-1` หรือ `auto`
   - **Access Key ID** & **Secret Access Key**
4. กดปุ่ม **บันทึกการตั้งค่า** ระบบจะทำการทดสอบการเชื่อมต่อ (Connection Test) โดยอัตโนมัติทันที

### 2.2 การตั้งค่าจัดเก็บและเสิร์ฟไฟล์ (Media & Delivery)
1. ไปที่แท็บ **สื่อ (Media)**
2. **การตั้งค่าจัดเก็บไฟล์ (Storage Settings):**
   - **Offload Media:** เปิดใช้งานเพื่อให้อัปโหลดไฟล์สื่อใหม่ขึ้น Storage อัตโนมัติ
   - **Remove Local Media:** เปิดใช้งานเมื่อต้องการลบไฟล์สื่อออกจากเซิร์ฟเวอร์หลักหลังอัปโหลดสำเร็จ
   - **Path Prefix / Year-Month / Object Version:** กำหนดโครงสร้างโฟลเดอร์สำหรับจัดเก็บไฟล์
3. **การเสิร์ฟไฟล์ (Delivery Settings):**
   - **Deliver Offloaded Media:** เปิดใช้งานเพื่อให้รูปภาพแสดงผลผ่าน URL ของ Storage หรือ CDN
   - **CDN Domain:** ระบุ Domain CDN (ถ้ามี) เช่น `https://cdn.mysite.com`
   - **Force HTTPS:** บังคับใช้งาน HTTPS สำหรับ URL สื่อทั้งหมด

### 2.3 เครื่องมือประมวลผลงานเบื้องหลัง (Bulk Management Tools)
ไปที่แท็บ **เครื่องมือ (Tools)** เพื่อประมวลผลไฟล์สื่อที่มีอยู่เดิมในคลัง:
- **Offload media ที่เหลือ:** อัปโหลดไฟล์สื่อทั้งหมดที่ยังไม่อยู่บน Storage
- **ลองใหม่เฉพาะที่ Offload ไม่ผ่าน:** ประมวลผลซ้ำเฉพาะไฟล์ที่เคยอัปโหลดล้มเหลว
- **ดาวน์โหลดไฟล์กลับจาก bucket:** ดาวน์โหลดไฟล์ทั้งหมดจาก Storage กลับมายังเซิร์ฟเวอร์หลัก
- **ลบไฟล์ทั้งหมดออกจาก bucket:** ลบไฟล์ทั้งหมดออกจาก Storage
- **ตรวจสอบและอัปเดต WooCommerce Downloadable Products:** ปรับแต่งสิทธิ์และความปลอดภัยของไฟล์สินค้าดาวน์โหลดใน WooCommerce

> ⏱️ **ระบบแสดงผลเวลา (Time Tracking):**  
> แต่ละ Card จะแสดง Progress Bar เปอร์เซ็นต์ความคืบหน้า เวลาที่ใช้ไปแล้ว (`ใช้เวลาไปแล้ว X นาที Y วินาที`) และเวลาคงเหลือโดยประมาณ (`ETA`) แบบ Real-time

### 2.4 การย้ายคลังไฟล์ระหว่าง Storage (Migrate)
1. ไปที่แท็บ **ย้ายข้อมูล (Migrate)**
2. ตั้งค่า **Source Storage** (ต้นทาง) และ **Destination Storage** (ปลายทาง)
3. ระบบจะทำการย้ายไฟล์สื่อระหว่าง Storage และอัปเดต URL ในฐานข้อมูลโดยไม่ส่งผลกระทบต่อไฟล์ต้นทาง

### 2.5 การเสิร์ฟไฟล์ CSS/JS ผ่าน CDN (Assets Pull)
1. ไปที่แท็บ **ทรัพยากร (Assets Pull)**
2. เปิดใช้งาน **Rewrite Asset URLs** และระบุ **CDN Domain**
3. ระบบจะแปลง URL ของไฟล์ `.css` และ `.js` ในธีมและปลั๊กอินให้เสิร์ฟผ่าน CDN เพื่อเพิ่มความเร็วในการโหลดหน้าเว็บ

---

## 💻 คู่มือการใช้งานผ่าน WP-CLI (`wp isxm`)

ปลั๊กอินรองรับการทำงานผ่าน WP-CLI เต็มรูปแบบ เหมาะสำหรับการรันงานขนาดใหญ่ (ระดับหลายหมื่นถึงหลายแสนรูป) หรือการตั้ง Cron Job บนเซิร์ฟเวอร์

### 3.1 ภาพรวมคำสั่ง CLI

```bash
wp isxm status              # ตรวจสอบสรุปสถานะการ Offload ทั้งหมด
wp isxm offload             # อัปโหลดไฟล์สื่อขึ้น Storage
wp isxm download            # ดาวน์โหลดไฟล์สื่อกลับจาก Storage
wp isxm remove              # ลบไฟล์สื่อออกจาก Storage
wp isxm sync                # สแกนและซิงค์ข้อมูล DB กับ Storage จริง
wp isxm migrate             # ย้ายคลังไฟล์ระหว่าง Storage
wp isxm connection <cmd>    # จัดการและทดสอบการเชื่อมต่อ Storage
wp isxm backfill            # สร้าง/ซิงค์ Ledger ประวัติไฟล์ค้าง
wp isxm job <subcommand>    # ตรวจสอบและควบคุม Server-Side Job Engine
```

### 3.2 รายละเอียดแต่ละคำสั่ง

#### 📌 1. `wp isxm status`
แสดงสรุปจำนวนไฟล์สื่อทั้งหมด, จำนวนไฟล์ที่อยู่บน Storage, ไฟล์ที่ไม่ผ่าน และสถานะการเชื่อมต่อ

```bash
wp isxm status
```
*ตัวอย่าง Output:*
```text
Attachments: 17279 total, 0 on bucket (0%), 0 partial, 17279 pending, 16298 failed
Retry just the failures with: wp isxm offload --failed-only
```

---

#### 📌 2. `wp isxm offload`
ประมวลผลอัปโหลดไฟล์สื่อขึ้น Storage

```bash
# อัปโหลดไฟล์สื่อทั้งหมดที่ยังไม่ได้ Offload
wp isxm offload

# กำหนดขนาด Batch ในการประมวลผลแต่ละรอบ (ค่าเริ่มต้น: 50)
wp isxm offload --batch=100

# ลองใหม่อีกครั้งเฉพาะรายการที่เคยล้มเหลว (Failed items)
wp isxm offload --failed-only
```

---

#### 📌 3. `wp isxm download`
ดาวน์โหลดไฟล์สื่อจาก Storage กลับมายัง Local Server

```bash
wp isxm download --batch=50
```

---

#### 📌 4. `wp isxm remove`
ลบไฟล์สื่อออกจาก Storage

```bash
wp isxm remove --batch=50
```

---

#### 📌 5. `wp isxm sync`
สแกนเปรียบเทียบ metadata ใน DB กับไฟล์บน Bucket จริง

```bash
# Dry-run สแกนดูผลลัพธ์โดยยังไม่แก้ไข DB หรือลบไฟล์
wp isxm sync

# ลบ metadata ค้างและลบ Orphan Objects ที่ไม่อยู่ใน DB ออกจาก Bucket
wp isxm sync --apply --delete-orphans
```

---

#### 📌 6. `wp isxm connection`
จัดการและทดสอบการเชื่อมต่อ Storage

```bash
# ทดสอบการเชื่อมต่อ Storage ปัจจุบัน
wp isxm connection test

# ตั้งค่า Storage Connection ผ่าน CLI
wp isxm connection set --provider=minio --endpoint="https://s3.example.com" --bucket="my-bucket" --access-key="KEY" --secret-key="SECRET"
```

---

### 3.3 การจัดการ Background Job ผ่าน CLI & System Cron

เมื่อมีการสั่งงานผ่านหน้า Admin หรือผ่าน CLI ระบบ Job Engine จะบันทึกสถานะลงใน Database เพื่อให้ทนทานต่อการปิดแท็บเบราว์เซอร์ คุณสามารถใช้คำสั่งชุด `wp isxm job` เพื่อควบคุมและติดตามงานได้ดังนี้:

#### 📌 1. `wp isxm job list`
แสดงตารางสถานะของ Job ทั้งหมดในระบบ:

```bash
wp isxm job list
```
*ตัวอย่าง Output:*
```text
+--------------+---------+----------+---------+--------+---------+---------------------+
| tool         | state   | progress | percent | errors | pending | updated             |
+--------------+---------+----------+---------+--------+---------+---------------------+
| offload      | idle    |          |         |        |         |                     |
| remove       | running | 526/527  | 99%     |        |         | 2026-08-18 10:35 UTC|
| wc_downloads | idle    |          |         |        |         |                     |
+--------------+---------+----------+---------+--------+---------+---------------------+
```

#### 📌 2. `wp isxm job start / pause / resume / cancel`
สั่งการ Job แต่ละตัวผ่าน CLI:

```bash
# สั่งเริ่ม Job (เช่น offload, remove, download)
wp isxm job start offload

# สั่งหยุดพัก Job ชั่วคราว
wp isxm job pause offload

# สั่งรัน Job ต่อจากจุดเดิม
wp isxm job resume offload --watch

# สั่งยกเลิก Job
wp isxm job cancel offload --yes
```

#### 📌 3. การตั้ง System Cron สำหรับ Background Runner (`wp isxm job run`)
หากเซิร์ฟเวอร์ปิดกั้น Loopback HTTP Requests คุณสามารถตั้งค่า **Crontab** บนเซิร์ฟเวอร์เพื่อให้รัน Runner เบื้องหลังได้โดยอัตโนมัติ:

```bash
# รัน Runner 1 รอบประมวลผล (เหมาะสำหรับใส่ใน Crontab)
* * * * * cd /path/to/wordpress && wp isxm job run --once > /dev/null 2>&1
```

---

## 🔒 ความปลอดภัยและประสิทธิภาพ (Security & Performance)

- **การเข้ารหัส Secret Key (AES-256-CBC):** Secret Key ทั้งหมดถูกเข้ารหัสอย่างปลอดภัยด้วย `AES-256-CBC` โดยใช้ Salt เฉพาะของแต่ละเว็บไซต์ (`wp_salt('auth')`) ก่อนเก็บบันทึกลงในฐานข้อมูล
- **One-Pass DB URL Rewrite:** เมื่อสั่ง Offload คลังภาพขนาดใหญ่ ระบบจะรวบรวมรายการและทำการ Rewrite URL ลงใน `post_content` และ `postmeta` เพียงรอบเดียวเมื่อจบกระบวนการ เพื่อป้องกันความล่าช้าจากการ Update DB ทีละแถว
- **Path Traversal Protection:** ตรวจสอบความปลอดภัยของชื่อไฟล์และพาทเพื่อป้องกันช่องโหว่ Path Traversal (`../`, backslash, control chars)
- **Prepared Statements:** ทุกการ Query ฐานข้อมูลผ่าน `$wpdb->prepare()` และ `esc_like()` ตามมาตรฐานความปลอดภัยสูงสุดของ WordPress

---

## 📄 การรับอนุญาตใช้งาน (License)

GPLv3 or later.  
© 2026 **InsightX** — ผลงานพัฒนาขึ้นใหม่ตามมาตรฐานสากล สงวนลิขสิทธิ์ตามกฎหมาย
