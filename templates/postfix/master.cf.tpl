# ไฟล์นี้สร้างโดย PHP Server Control Panel — ห้ามแก้ด้วยมือ
# สร้างเมื่อ : {{GENERATED_AT}}
#
# บริการที่ Postfix เปิด · ช่อง: service type private unpriv chroot wakeup maxproc command
#
# **ทำไมต้องเขียนทั้งไฟล์แทนที่จะแก้ด้วย `postconf -M`**
# `postconf -M` แก้ไฟล์นี้เข้าที่ทีละบรรทัด ซึ่งย้อนกลับไม่ได้ถ้าขั้นตอนถัดไปล้ม ·
# ทั้งระบบยึดหลักเดียวกันหมด: เขียนไฟล์ใหม่ทั้งไฟล์ผ่าน ConfigTransaction แล้วให้
# `postfix check` เป็นคนตัดสินว่าจะ commit หรือคืนของเดิม

smtp      inet  n       -       y       -       -       smtpd
pickup    unix  n       -       y       60      1       pickup
cleanup   unix  n       -       y       -       0       cleanup
qmgr      unix  n       -       n       300     1       qmgr
tlsmgr    unix  -       -       y       1000?   1       tlsmgr
rewrite   unix  -       -       y       -       -       trivial-rewrite
bounce    unix  -       -       y       -       0       bounce
defer     unix  -       -       y       -       0       bounce
trace     unix  -       -       y       -       0       bounce
verify    unix  -       -       y       -       1       verify
flush     unix  n       -       y       1000?   0       flush
proxymap  unix  -       -       n       -       -       proxymap
proxywrite unix -       -       n       -       1       proxymap
smtp      unix  -       -       y       -       -       smtp
relay     unix  -       -       y       -       -       smtp
showq     unix  n       -       y       -       -       showq
error     unix  -       -       y       -       -       error
retry     unix  -       -       y       -       -       error
discard   unix  -       -       y       -       -       discard
local     unix  -       n       n       -       -       local
virtual   unix  -       n       n       -       -       virtual
lmtp      unix  -       -       y       -       -       lmtp
anvil     unix  -       -       y       -       1       anvil
scache    unix  -       -       y       -       1       scache
postlog   unix-dgram n  -       n       -       1       postlogd
{{SUBMISSION_SECTION}}
