#!/usr/bin/env bash
#
# ยิงเมลจริงใส่เครื่องที่ติดตั้งแล้ว — PLAN-MAIL เฟส M1
#
# **ทำไมต้องยิงจริง:** เทสต์ในโปรเซสพิสูจน์ได้แค่ว่า "เราเขียนไฟล์ตั้งค่าถูกตามที่
# เราคิด" ไม่ได้พิสูจน์ว่า Postfix กับ Dovecot ตีความไฟล์นั้นเหมือนที่เราคิด ·
# เรื่องเมลโดยเฉพาะ ความต่างระหว่างสองอย่างนั้นคือ "เมลหาย" กับ "เครื่องส่งสแปม"
#
# รันในคอนเทนเนอร์ที่ติดตั้ง panel แล้ว:  bash docker/acceptance-mail.sh
set -uo pipefail

DOMAIN="${MAIL_TEST_DOMAIN:-mail.test}"
BOX="postbox@${DOMAIN}"
PASS="Mailbox-Test-Pass-99"

pass=0
fail=0

ok()   { printf '  \033[32m✔\033[0m %s\n' "$1"; pass=$((pass + 1)); }
bad()  { printf '  \033[31m✘\033[0m %s\n' "$1"; fail=$((fail + 1)); }
head_() { printf '\n\033[1m%s\033[0m\n' "$1"; }

# ---------------------------------------------------------------------------
head_ "1. เตรียมโดเมนและกล่อง"

phpcp mail:enable "$DOMAIN" >/tmp/mail-enable.log 2>&1 \
  && ok "เปิดเมลของ $DOMAIN" \
  || { bad "เปิดเมลไม่สำเร็จ: $(tail -2 /tmp/mail-enable.log)"; }

# รหัสที่รู้ค่าไว้ทดสอบล็อกอิน — ปกติไม่ระบุแล้วให้ระบบสุ่มให้ ซึ่งปลอดภัยกว่า
phpcp mail:box-add "$BOX" --quota=50 --password="$PASS" >/tmp/mail-box.log 2>&1 \
  && ok "สร้างกล่อง $BOX" \
  || bad "สร้างกล่องไม่สำเร็จ: $(tail -2 /tmp/mail-box.log)"

doveadm auth test "$BOX" "$PASS" 2>&1 | grep -q "auth succeeded" \
  && ok "รหัสผ่านที่ตั้งไว้ใช้ล็อกอินได้จริง" \
  || bad "Dovecot ไม่ยอมรับรหัสผ่านที่ระบบสร้าง — แฮชผิดรูปแบบหรือไฟล์ผู้ใช้อ่านไม่ได้"

# ---------------------------------------------------------------------------
head_ "2. ส่งเมลเข้าเครื่องนี้แล้วต้องถึงกล่อง"

php -r '
$box = $argv[1];
$fp = @stream_socket_client("tcp://127.0.0.1:25", $errno, $err, 10);
if (!$fp) { fwrite(STDERR, "ต่อพอร์ต 25 ไม่ได้: $err\n"); exit(1); }
$read = function () use ($fp) { $line = fgets($fp, 2048); while ($line !== false && preg_match("/^\d{3}-/", $line)) { $line = fgets($fp, 2048); } return (string) $line; };
$send = function (string $cmd) use ($fp, $read) { fwrite($fp, $cmd . "\r\n"); return $read(); };
$read();
$send("HELO tester.invalid");
$send("MAIL FROM:<sender@outside.invalid>");
$rcpt = $send("RCPT TO:<" . $box . ">");
if (!str_starts_with($rcpt, "250")) { fwrite(STDERR, "ปลายทางถูกปฏิเสธ: $rcpt"); exit(1); }
$send("DATA");
fwrite($fp, "Subject: acceptance\r\nFrom: sender@outside.invalid\r\nTo: {$box}\r\n\r\nhello from acceptance\r\n.\r\n");
$done = $read();
$send("QUIT");
exit(str_starts_with($done, "250") ? 0 : 1);
' "$BOX" 2>/tmp/mail-send.log \
  && ok "เซิร์ฟเวอร์รับเมลจากภายนอกไว้แล้ว" \
  || bad "ส่งเมลเข้าไม่สำเร็จ: $(cat /tmp/mail-send.log)"

# LMTP ส่งต่อให้ Dovecot แบบ asynchronous — รอให้เขียนลงกล่องจริง
for _ in 1 2 3 4 5 6 7 8 9 10; do
  COUNT="$(doveadm search -u "$BOX" ALL 2>/dev/null | wc -l)"
  [ "${COUNT:-0}" -ge 1 ] && break
  sleep 1
done

[ "${COUNT:-0}" -ge 1 ] \
  && ok "เมลอยู่ในกล่องจริง ($COUNT ฉบับ)" \
  || bad "เมลไม่ถึงกล่อง — ดู /var/log/mail.log"

# ---------------------------------------------------------------------------
head_ "3. ต้องไม่เป็น open relay"

# ปลายทางที่ไม่ใช่โดเมนของเรา และผู้ส่งไม่ได้ล็อกอิน = ต้องถูกปฏิเสธ
#
# **ต้องยิงจากที่อยู่ที่ไม่ใช่ loopback** — `permit_mynetworks` อนุญาต 127.0.0.1 อยู่แล้ว
# โดยตั้งใจ (สคริปต์บนเครื่องต้องส่งเมลได้) การทดสอบจาก localhost จึงผ่านเสมอไม่ว่า
# ค่าตั้งจะถูกหรือผิด — ซึ่งเป็นการทดสอบที่ให้ความมั่นใจแบบผิด ๆ
OWN_IP="$(hostname -i 2>/dev/null | awk '{print $1}')"

php -r '
$host = $argv[1] ?: "127.0.0.1";
$fp = @stream_socket_client("tcp://" . $host . ":25", $errno, $err, 10);
if (!$fp) { exit(2); }
$read = function () use ($fp) { $line = fgets($fp, 2048); while ($line !== false && preg_match("/^\d{3}-/", $line)) { $line = fgets($fp, 2048); } return (string) $line; };
$read();
fwrite($fp, "HELO spammer.invalid\r\n"); $read();
fwrite($fp, "MAIL FROM:<spam@spammer.invalid>\r\n"); $read();
fwrite($fp, "RCPT TO:<victim@gmail.com>\r\n");
$rcpt = $read();
fwrite($fp, "QUIT\r\n");
// ต้องเป็น 5xx เท่านั้น — 250 แปลว่าเครื่องนี้ยอมส่งเมลให้คนแปลกหน้า
exit(str_starts_with($rcpt, "5") ? 0 : 1);
' "$OWN_IP" 2>/dev/null
RELAY_RC=$?
case "$RELAY_RC" in
  0) ok "ปฏิเสธการส่งต่อให้คนที่ไม่ได้ล็อกอิน" ;;
  # แยกให้ชัดว่า "ต่อไม่ได้" ไม่ใช่ "เป็น open relay" — รายงานผิดเรื่องนี้อันตรายพอกัน
  2) bad "ต่อพอร์ต 25 ไม่ได้ — ตรวจ open relay ไม่ได้ (Postfix ไม่ได้รันอยู่?)" ;;
  *) bad "**เครื่องนี้เป็น open relay** — ห้ามปล่อยขึ้นเครื่องจริงเด็ดขาด" ;;
esac

# ---------------------------------------------------------------------------
head_ "4. IMAP ต้องล็อกอินได้และเห็นเมล"

php -r '
[$script, $box, $pass] = $argv;
$context = stream_context_create(["ssl" => ["verify_peer" => false, "verify_peer_name" => false]]);
$fp = @stream_socket_client("ssl://127.0.0.1:993", $errno, $err, 10, STREAM_CLIENT_CONNECT, $context);
if (!$fp) { fwrite(STDERR, "ต่อ IMAPS ไม่ได้: $err\n"); exit(1); }
fgets($fp, 2048);
fwrite($fp, "a1 LOGIN \"" . $box . "\" \"" . $pass . "\"\r\n");
$line = "";
while (($chunk = fgets($fp, 2048)) !== false) { $line .= $chunk; if (str_starts_with($chunk, "a1 ")) { break; } }
if (!str_contains($line, "a1 OK")) { fwrite(STDERR, "ล็อกอินไม่ผ่าน: $line"); exit(1); }
fwrite($fp, "a2 SELECT INBOX\r\n");
$select = "";
while (($chunk = fgets($fp, 2048)) !== false) { $select .= $chunk; if (str_starts_with($chunk, "a2 ")) { break; } }
fwrite($fp, "a3 LOGOUT\r\n");
exit(str_contains($select, "a2 OK") ? 0 : 1);
' "$BOX" "$PASS" 2>/tmp/mail-imap.log \
  && ok "ล็อกอิน IMAPS และเปิด INBOX ได้" \
  || bad "IMAP ใช้ไม่ได้: $(cat /tmp/mail-imap.log)"

# พอร์ตที่ไม่เข้ารหัสต้องไม่มีใครฟัง
if ss -ltnH 2>/dev/null | grep -qE ':(110|143)\s'; then
  bad "ยังเปิดพอร์ต 110/143 ที่ไม่เข้ารหัสอยู่"
else
  ok "ไม่มีพอร์ต IMAP/POP3 แบบไม่เข้ารหัส"
fi

# ---------------------------------------------------------------------------
head_ "5. ลบกล่องแล้วต้องหายจริง"

phpcp mail:box-del "$BOX" >/tmp/mail-del.log 2>&1 \
  && ok "ลบกล่องแล้ว" \
  || bad "ลบกล่องไม่สำเร็จ: $(tail -2 /tmp/mail-del.log)"

[ -d "/srv/phpcp/mail/${DOMAIN}/postbox" ] \
  && bad "ไฟล์เมลยังอยู่บนดิสก์หลังลบกล่อง" \
  || ok "ไฟล์เมลถูกลบตามไปด้วย"

grep -q "$BOX" /etc/postfix/vmailbox 2>/dev/null \
  && bad "กล่องที่ลบแล้วยังอยู่ในตารางของ Postfix — ยังรับเมลต่อได้" \
  || ok "กล่องหายจากตารางของ Postfix แล้ว"

# ---------------------------------------------------------------------------
printf '\n\033[1mผ่าน %d · ไม่ผ่าน %d\033[0m\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
