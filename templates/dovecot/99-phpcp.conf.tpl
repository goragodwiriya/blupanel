# ไฟล์นี้สร้างโดย PHP Server Control Panel — ห้ามแก้ด้วยมือ
# การแก้ไขจะถูกเขียนทับเมื่อมีการเปลี่ยนแปลงกล่องจดหมายผ่านหน้าเว็บ
#
# สร้างเมื่อ : {{GENERATED_AT}}
#
# ชื่อขึ้นต้นด้วย 99- เพื่อให้ถูกอ่านหลังไฟล์ของดิสโทรทุกไฟล์ — ค่าที่ตั้งที่นี่จึงชนะ
# ค่าเริ่มต้นเสมอ โดยไม่ต้องไปแก้ไฟล์ของแพ็กเกจซึ่งจะถูกเขียนทับตอนอัปเกรด

# ไม่มี imap/pop3 แบบไม่เข้ารหัส — รหัสผ่านเมลวิ่งผ่านเน็ตทุกครั้งที่โปรแกรมเมล
# เช็คกล่อง (ทุกไม่กี่นาที) การเปิด 110/143 ไว้คือการประกาศรหัสผ่านให้ทั้งวง
protocols = imap pop3 lmtp
ssl = required

# ใบรับรองของ mail hostname — **ต้องตั้งที่นี่ด้วย ไม่ใช่ที่ Postfix อย่างเดียว**
#
# โปรแกรมเมลต่อ IMAP/POP3 เข้ามาที่ Dovecot ตรง ๆ (993/995) ไม่ได้ผ่าน Postfix เลย ·
# ตั้งใบจริงให้ Postfix ฝ่ายเดียวจึงได้เครื่องที่ "ส่งเมลแล้วไม่มีคำเตือน แต่เปิดกล่อง
# ทีไรก็ขึ้นคำเตือนใบรับรองทุกที" ซึ่งเป็นอาการที่หาสาเหตุยากเป็นพิเศษเพราะครึ่งหนึ่ง
# ของระบบทำงานถูกต้อง
#
# `<` ข้างหน้าเป็นไวยากรณ์ของ Dovecot ที่แปลว่า "อ่านเนื้อหาจากไฟล์นี้"
# ไม่ใช่ส่วนหนึ่งของเส้นทาง
ssl_cert = <{{TLS_CERT}}
ssl_key = <{{TLS_KEY}}

service imap-login {
  inet_listener imap {
    port = 0
  }
  inet_listener imaps {
    port = 993
    ssl = yes
  }
}

service pop3-login {
  inet_listener pop3 {
    port = 0
  }
  inet_listener pop3s {
    port = 995
    ssl = yes
  }
}

# ที่เก็บกล่อง — ทุกกล่องเป็นของ vmail คนเดียว ไม่มีผู้ใช้ระบบต่อหนึ่งกล่อง
# %d = โดเมน · %n = ส่วนหน้า @ (Dovecot แทนค่าให้เอง)
mail_home = {{MAIL_ROOT}}/%d/%n
mail_location = maildir:~/
mail_uid = {{VMAIL_USER}}
mail_gid = {{VMAIL_USER}}

# สร้างโฟลเดอร์มาตรฐานให้ตั้งแต่ล็อกอินครั้งแรก ไม่ใช่รอให้โปรแกรมเมลสร้างเอง
# (โปรแกรมเมลแต่ละตัวตั้งชื่อไม่เหมือนกัน แล้วจะได้ Sent สองอันคนละชื่อ)
namespace inbox {
  inbox = yes

  mailbox Drafts {
    auto = subscribe
    special_use = \Drafts
  }
  mailbox Junk {
    auto = subscribe
    special_use = \Junk
  }
  mailbox Sent {
    auto = subscribe
    special_use = \Sent
  }
  mailbox Trash {
    auto = subscribe
    special_use = \Trash
  }
}

# ผู้ใช้มาจากไฟล์ที่ panel เขียน ไม่ใช่จาก /etc/passwd ของเครื่อง
passdb {
  driver = passwd-file
  args = scheme=ARGON2ID username_format=%u {{USERS_FILE}}
}

userdb {
  driver = passwd-file
  args = username_format=%u {{USERS_FILE}}
}

# โควตาต่อกล่อง — ค่าจริงมาจากไฟล์ผู้ใช้รายบรรทัด (userdb_quota_rule)
#
# **ต้องปฏิเสธตอนรับ ไม่ใช่รับแล้วทิ้ง** — ถ้ากล่องเต็มแล้วเรายังรับเมลไว้แล้วลบทิ้ง
# ผู้ส่งจะเข้าใจว่าส่งถึงแล้ว ซึ่งแย่กว่าการถูกปฏิเสธตั้งแต่แรกมาก
plugin {
  quota = maildir:User quota
  quota_rule = *:storage=1G
  quota_grace = 0%%
}

protocol lmtp {
  mail_plugins = $mail_plugins quota
  # Postfix ส่งที่อยู่เต็มมาให้ — ไม่ตัดโดเมนออก ไม่งั้นกล่องชื่อเดียวกันคนละโดเมนชนกัน
  auth_username_format = %u
}

protocol imap {
  mail_plugins = $mail_plugins quota imap_quota
}

# Postfix คุยกับ Dovecot ผ่าน socket สองอัน: ส่งเมลเข้ากล่อง (lmtp) และถามรหัสผ่าน (auth)
service lmtp {
  unix_listener /var/spool/postfix/private/dovecot-lmtp {
    mode = 0600
    user = postfix
    group = postfix
  }
}

service auth {
  unix_listener /var/spool/postfix/private/auth {
    mode = 0660
    user = postfix
    group = postfix
  }
}

# ---------------------------------------------------------------------------
# ค่าตั้งเพิ่มเติมของผู้ดูแล — อ่านท้ายสุด ค่าที่นั่นจึงชนะค่าข้างบนเสมอ
#
# `!include_try` ไม่ล้มเมื่อยังไม่มีไฟล์ ต่างจาก `!include` ที่ทำให้ Dovecot
# สตาร์ตไม่ขึ้นทั้งตัว · panel ไม่เขียนทับไฟล์ในไดเรกทอรีนี้เลย
# ---------------------------------------------------------------------------
!include_try {{CUSTOM_DIR}}/*.conf
