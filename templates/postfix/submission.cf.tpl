
# ---------------------------------------------------------------------------
# ทางที่ผู้ใช้ส่งเมลออก — มีอยู่เฉพาะตอนเปิดรับเมลเข้า (PLAN-MAIL เฟส M1)
#
# **ไม่ใช่พอร์ต 25** · พอร์ต 25 มีไว้ให้เซิร์ฟเวอร์เมลอื่นส่งเมลมาหาเรา ส่วนคนที่
# ส่งเมลออกใช้ 587 (STARTTLS) หรือ 465 (TLS ตั้งแต่แรก) และ **ต้องล็อกอินเสมอ**
# ---------------------------------------------------------------------------

submission inet n       -       y       -       -       smtpd
  -o syslog_name=postfix/submission
  # บังคับ TLS ที่นี่ต่างจากพอร์ต 25 — ผู้ใช้ของเราตั้งค่าโปรแกรมเมลเองได้
  # ไม่ต้องเผื่อเซิร์ฟเวอร์เก่าที่ยังไม่รองรับ TLS เหมือนฝั่งรับ
  -o smtpd_tls_security_level=encrypt
  -o smtpd_sasl_auth_enable=yes
  -o smtpd_tls_auth_only=yes
  # ไม่มีข้อยกเว้นให้ mynetworks ที่นี่ — ต้องล็อกอินอย่างเดียว
  -o smtpd_relay_restrictions=permit_sasl_authenticated,reject
  -o smtpd_recipient_restrictions=permit_sasl_authenticated,reject
  # ผู้ส่งต้องเป็นที่อยู่ของตัวเอง — กันกล่องที่ถูกยึดปลอมเป็นคนอื่นในโดเมนเดียวกัน
  -o smtpd_sender_restrictions=reject_sender_login_mismatch
  -o smtpd_sender_login_maps=hash:/etc/postfix/vmailbox

smtps     inet  n       -       y       -       -       smtpd
  -o syslog_name=postfix/smtps
  -o smtpd_tls_wrappermode=yes
  -o smtpd_sasl_auth_enable=yes
  -o smtpd_relay_restrictions=permit_sasl_authenticated,reject
  -o smtpd_recipient_restrictions=permit_sasl_authenticated,reject
  -o smtpd_sender_restrictions=reject_sender_login_mismatch
  -o smtpd_sender_login_maps=hash:/etc/postfix/vmailbox
