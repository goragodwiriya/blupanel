# ---------------------------------------------------------------------------
# Accepts mail into real mailboxes — PLAN-MAIL phase M1
#
# This section only exists when at least one domain has mail enabled
# ---------------------------------------------------------------------------

# A domain being accepted for must be in virtual_mailbox_domains, **never**
# mydestination — these two can never both list the same domain, Postfix refuses and stops accepting mail for that domain entirely
virtual_mailbox_domains = hash:/etc/postfix/vdomains
virtual_mailbox_maps = hash:/etc/postfix/vmailbox
virtual_alias_maps = hash:/etc/postfix/valias

# Forwards to Dovecot to write into the mailbox, never lets Postfix write the
# file itself — Dovecot is the only one that knows about quotas, IMAP indexes, and creating the standard folders
virtual_transport = lmtp:unix:private/dovecot-lmtp

# Lets Dovecot answer whether a password is correct — so there's one single place the whole system stores passwords
smtpd_sasl_type = dovecot
smtpd_sasl_path = private/auth
smtpd_sasl_auth_enable = yes
# Disables unencrypted login — a mail password must never travel as plain text
smtpd_tls_auth_only = yes

# **This line is what prevents becoming an open relay**
#
# Read left to right, stopping at the first rule that matches: this machine
# itself → someone already authenticated → refuse · `reject_unauth_destination`
# always closes the list, meaning mail whose destination isn't one of our own
# domains, from a sender who isn't authenticated, gets refused immediately
smtpd_relay_restrictions =
    permit_mynetworks
    permit_sasl_authenticated
    reject_unauth_destination

# Blocks the most common forged senders, before ever reaching the step of accepting the mail's content
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
