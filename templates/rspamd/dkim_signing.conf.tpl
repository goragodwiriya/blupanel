# ไฟล์นี้สร้างโดย PHP Server Control Panel — ห้ามแก้ด้วยมือ
# สร้างเมื่อ : {{GENERATED_AT}}
#
# เซ็น DKIM ให้เมลขาออกทุกฉบับที่มีกุญแจของโดเมนนั้นอยู่
#
# **เส้นทางมีตัวแปร `$domain` โดยตั้งใจ** — เพิ่มโดเมนใหม่แค่วางไฟล์กุญแจ ไม่ต้อง
# แก้ไฟล์นี้และไม่ต้อง reload rspamd ทุกครั้งที่ลูกค้าเปิดเมลให้โดเมนใหม่

enabled = true;

# ไม่มีกุญแจของโดเมนไหน = ส่งออกไปโดยไม่เซ็น ไม่ใช่ปฏิเสธไม่ส่ง
# (โดเมนที่ยังไม่เปิดเมลก็ยังต้องส่งเมลแจ้งเตือนของระบบออกได้)
allow_hdrfrom_mismatch = true;
allow_hdrfrom_multiple = false;
allow_username_mismatch = true;
sign_authenticated = true;
sign_local = true;

selector = "{{SELECTOR}}";
path = "{{KEY_DIR}}/$domain.key";

use_domain = "header";
use_esld = false;
