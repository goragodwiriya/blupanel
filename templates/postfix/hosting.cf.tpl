# ---------------------------------------------------------------------------
# รับเมลเข้ากล่องจริง — PLAN-MAIL เฟส M1
#
# ส่วนนี้จะมีอยู่ก็ต่อเมื่อมีโดเมนที่เปิดเมลอย่างน้อยหนึ่งโดเมนเท่านั้น
# ---------------------------------------------------------------------------

# โดเมนที่รับเมลให้ ต้องอยู่ใน virtual_mailbox_domains **ไม่ใช่** mydestination —
# สองค่านี้ใส่โดเมนเดียวกันพร้อมกันไม่ได้ Postfix จะฟ้องแล้วไม่รับเมลของโดเมนนั้นเลย
virtual_mailbox_domains = hash:/etc/postfix/vdomains
virtual_mailbox_maps = hash:/etc/postfix/vmailbox
virtual_alias_maps = hash:/etc/postfix/valias

# ส่งต่อให้ Dovecot เขียนลงกล่อง ไม่ให้ Postfix เขียนไฟล์เอง — Dovecot เป็นคนเดียว
# ที่รู้เรื่องโควตา, ดัชนีของ IMAP และการสร้างโฟลเดอร์มาตรฐาน
virtual_transport = lmtp:unix:private/dovecot-lmtp

# ให้ Dovecot เป็นคนตอบว่ารหัสผ่านถูกไหม — จะได้มีที่เก็บรหัสผ่านที่เดียวทั้งระบบ
smtpd_sasl_type = dovecot
smtpd_sasl_path = private/auth
smtpd_sasl_auth_enable = yes
# ปิดการล็อกอินแบบไม่เข้ารหัส — รหัสผ่านเมลห้ามวิ่งเป็นข้อความเปล่า
smtpd_tls_auth_only = yes

# **บรรทัดนี้คือสิ่งที่กันไม่ให้เป็น open relay**
#
# อ่านจากซ้ายไปขวา หยุดที่กฎแรกที่ตรง: เครื่องนี้เอง → คนที่ล็อกอินแล้ว → ปฏิเสธ
# `reject_unauth_destination` ปิดท้ายเสมอ แปลว่าเมลที่ปลายทางไม่ใช่โดเมนของเรา
# และผู้ส่งไม่ได้ล็อกอิน จะถูกปฏิเสธทันที
smtpd_relay_restrictions =
    permit_mynetworks
    permit_sasl_authenticated
    reject_unauth_destination

# กันผู้ส่งปลอมที่พบบ่อย ก่อนถึงขั้นตอนรับเนื้อเมล
smtpd_helo_required = yes
smtpd_recipient_restrictions =
    permit_mynetworks
    permit_sasl_authenticated
    reject_unknown_recipient_domain
    reject_unauth_destination

# TLS ของฝั่งที่รับเข้า — `may` เพราะเซิร์ฟเวอร์ปลายทางที่ยังไม่รองรับ TLS มีอยู่จริง
# การบังคับ encrypt ที่พอร์ต 25 แปลว่าไม่ได้รับเมลจากคนเหล่านั้นเลย
smtpd_tls_security_level = may
smtpd_tls_cert_file = {{TLS_CERT}}
smtpd_tls_key_file = {{TLS_KEY}}
smtpd_tls_loglevel = 1

# ขนาดกล่องไม่ได้คุมที่นี่ — Dovecot เป็นคนปฏิเสธตอนโควตาเต็ม เพื่อให้ผู้ส่งได้รู้
virtual_mailbox_limit = 0

# ส่งเมลให้ rspamd ตรวจและเซ็น DKIM ก่อนออกจากเครื่อง (PLAN-MAIL เฟส M3)
#
# `milter_default_action = accept` แปลว่า **rspamd ล่มแล้วเมลยังส่งออกได้ปกติ** แค่
# ไม่มีลายเซ็น · ทางเลือกอื่นคือหยุดรับ-ส่งเมลทั้งเครื่องเมื่อโปรแกรมกรองล่ม ซึ่งเป็น
# การแลกที่ไม่คุ้มสำหรับเครื่องโฮสติ้ง
smtpd_milters = inet:127.0.0.1:11332
non_smtpd_milters = $smtpd_milters
milter_protocol = 6
milter_default_action = accept
milter_mail_macros = i {mail_addr} {client_addr} {client_name} {auth_authen}
