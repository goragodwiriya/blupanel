# ไฟล์นี้สร้างโดย PHP Server Control Panel — ห้ามแก้ด้วยมือ
#
# ค่าเดียวที่ทุก vhost ของโหมด nginx-proxy ใช้ร่วมกัน จึงต้องอยู่ในไฟล์กลาง
# ชื่อขึ้นต้นด้วย 00- เพื่อให้ถูกอ่านก่อน vhost ทุกไฟล์
#
# $connection_upgrade ส่งค่า "upgrade" เฉพาะคำขอที่ขอยกระดับเป็น WebSocket จริง
# นอกนั้นส่ง "" เพื่อให้ nginx ใช้ keep-alive กับ backend ได้ตามปกติ —
# การส่ง $http_connection ดิบ ๆ แทนจะทำให้การเชื่อมต่อถูกปิดทุกคำขอ

map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      '';
}
