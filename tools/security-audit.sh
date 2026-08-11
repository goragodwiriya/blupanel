#!/usr/bin/env bash
# =============================================================================
# Web Hosting Production Security Audit — Safe / Non-destructive
#
# Usage:
#   ./security_audit.sh <target-url> [--aggressive] [--yes] [--no-nmap]
#
#   --aggressive   เปิดการทดสอบ HTTP methods ที่เขียนข้อมูล (PUT/DELETE) และ nmap -sV
#   --yes          ข้ามการยืนยันสิทธิ์ (สำหรับ cron/CI)
#   --no-nmap      ไม่รัน nmap แม้จะติดตั้งอยู่
#   --auth-checks  ตรวจ CSRF, rate limit และคุณสมบัติคุกกี้ของ panel
#                  **กินโควตาการล็อกอินของ IP นี้ไปหลายนาที** จึงไม่เปิดโดยอัตโนมัติ
#   --json-out=P   คัดลอก report.json ไปไว้ที่ P ด้วย (ให้ panel อ่านไปแสดงผล)
#   --user=U --password-file=P
#                  บัญชีสำหรับตรวจคุณสมบัติคุกกี้ session ซึ่งออกตอนล็อกอินสำเร็จเท่านั้น
#                  ไม่ใส่ = ข้ามการตรวจนั้นไป (ไม่ใช่ผ่าน)
#
# Exit codes: 0 = ไม่พบ HIGH/CRITICAL, 2 = พบ HIGH/CRITICAL, 1 = ใช้งานผิดพลาด
# =============================================================================

set -uo pipefail

# -------------------- Args --------------------
TARGET=""
AGGRESSIVE=0
ASSUME_YES=0
USE_NMAP=1
AUTH_CHECKS=0
JSON_OUT=
AUTH_USER=
AUTH_PASSWORD_FILE=

while [[ $# -gt 0 ]]; do
    case "$1" in
        --aggressive) AGGRESSIVE=1 ;;
        --yes|-y)     ASSUME_YES=1 ;;
        --no-nmap)    USE_NMAP=0 ;;
        --auth-checks) AUTH_CHECKS=1 ;;
        --json-out=*) JSON_OUT="${1#*=}" ;;
        --user=*) AUTH_USER="${1#*=}" ;;
        --password-file=*) AUTH_PASSWORD_FILE="${1#*=}" ;;
        -h|--help)
            sed -n '2,14p' "$0"; exit 0 ;;
        -*) echo "Unknown option: $1" >&2; exit 1 ;;
        *)  TARGET="$1" ;;
    esac
    shift
done

TARGET="${TARGET:-https://127.0.0.1:8443}"
[[ "$TARGET" =~ ^https?:// ]] || TARGET="https://$TARGET"
TARGET="${TARGET%/}"

# -------------------- Parse & validate host/port --------------------
# (ต้องทำก่อนใช้ทุกจุด และต้อง validate เพราะค่านี้ถูกส่งเข้า openssl/nmap)
if [[ "$TARGET" == https://* ]]; then SCHEME="https"; else SCHEME="http"; fi

HOSTPORT="${TARGET#*://}"
HOSTPORT="${HOSTPORT%%/*}"
HOSTPORT="${HOSTPORT##*@}"          # ตัด user:pass@ ถ้ามี

if [[ "$HOSTPORT" == \[*\]* ]]; then          # IPv6 literal
    HOST="${HOSTPORT%%]*}"; HOST="${HOST#[}"
    PORT_PART="${HOSTPORT##*]}"
    PORT="${PORT_PART#:}"
else
    HOST="${HOSTPORT%%:*}"
    if [[ "$HOSTPORT" == *:* ]]; then PORT="${HOSTPORT##*:}"; else PORT=""; fi
fi

if [[ -z "$PORT" ]]; then
    [[ "$SCHEME" == "https" ]] && PORT=443 || PORT=80
fi

if ! [[ "$HOST" =~ ^[A-Za-z0-9._:-]+$ ]]; then
    echo "ERROR: hostname ไม่ถูกต้อง: $HOST" >&2; exit 1
fi
if ! [[ "$PORT" =~ ^[0-9]{1,5}$ ]] || (( PORT < 1 || PORT > 65535 )); then
    echo "ERROR: port ไม่ถูกต้อง: $PORT" >&2; exit 1
fi

# -------------------- Setup --------------------
REPORT_DIR="./audit_$(date +%Y%m%d_%H%M%S)"
mkdir -p "$REPORT_DIR" || exit 1
chmod 700 "$REPORT_DIR"                       # รายงานมีข้อมูลอ่อนไหว

JSON_FILE="$REPORT_DIR/report.json"
HTML_FILE="$REPORT_DIR/report.html"
NMAP_OUT="$REPORT_DIR/nmap.txt"
TSV_FILE="$REPORT_DIR/.results.tsv"
: > "$TSV_FILE"

CURL_TIMEOUT=8
CONNECT_TIMEOUT=4
UA="SecurityAudit/2.0 (+non-destructive)"

CURL_BASE=(curl -k -s --connect-timeout "$CONNECT_TIMEOUT" -m "$CURL_TIMEOUT" -A "$UA")

CRITICAL=0; HIGH=0; MEDIUM=0; LOW=0; INFO=0

echo "======================================================"
echo " Web Hosting Production Security Audit"
echo " Target     : $TARGET"
echo " Host:Port  : $HOST:$PORT"
echo " Mode       : $([[ $AGGRESSIVE -eq 1 ]] && echo 'Aggressive' || echo 'Safe / Non-destructive')"
echo " Report Dir : $REPORT_DIR"
echo "======================================================"
echo

if [[ $ASSUME_YES -eq 0 && -t 0 ]]; then
    read -r -p "ยืนยันว่าคุณมีสิทธิ์สแกนเป้าหมายนี้? [y/N] " ans
    [[ "$ans" =~ ^[Yy]$ ]] || { echo "ยกเลิก"; exit 1; }
    echo
fi

# -------------------- Helpers --------------------
tsv_field() { printf '%s' "${1-}" | tr '\t\n\r' '   '; }

add() {
    local severity="$1" category="$2" check="$3" detail="${4-}" rec="${5-}"
    printf '%s\t%s\t%s\t%s\t%s\n' \
        "$severity" "$(tsv_field "$category")" "$(tsv_field "$check")" \
        "$(tsv_field "$detail")" "$(tsv_field "$rec")" >> "$TSV_FILE"
    case "$severity" in
        CRITICAL) CRITICAL=$((CRITICAL+1)) ;;
        HIGH)     HIGH=$((HIGH+1)) ;;
        MEDIUM)   MEDIUM=$((MEDIUM+1)) ;;
        LOW)      LOW=$((LOW+1)) ;;
        *)        INFO=$((INFO+1)) ;;
    esac
}

trim() { sed 's/^[[:space:]]*//; s/[[:space:]]*$//'; }

# ดึงค่า header (case-insensitive, ตัวแรกที่เจอ) จากบล็อก header ที่ส่งเข้ามาทาง stdin
header_value() {
    awk -v key="$1" '
        BEGIN { k = tolower(key); found = 0 }
        found { next }
        {
            p = index($0, ":")
            if (p > 0 && tolower(substr($0, 1, p-1)) == k) {
                v = substr($0, p+1)
                gsub(/^[ \t\r]+|[ \t\r]+$/, "", v)
                print v; found = 1
            }
        }'
}

has_header() { header_value "$1" | grep -q . ; }

# -------------------- 1. Connectivity & fingerprint --------------------
echo "[1/10] Connectivity & Fingerprint"

read -r HTTP_CODE FINAL_URL <<<"$("${CURL_BASE[@]}" -L -o /dev/null -w '%{http_code} %{url_effective}' "$TARGET" 2>/dev/null || echo '000 -')"
HTTP_CODE="${HTTP_CODE:-000}"

# ตาม redirect แล้วเก็บ header ของ "ปลายทาง" (บล็อกสุดท้าย) ไม่ใช่หน้า 301
ALL_HEADERS="$("${CURL_BASE[@]}" -I -L "$TARGET" 2>/dev/null | tr -d '\r')"
HEADERS="$(printf '%s\n' "$ALL_HEADERS" | awk 'BEGIN{RS=""} {b=$0} END{print b}')"

if [[ "$HTTP_CODE" =~ ^[23] ]]; then
    REACHABLE=1
    add "INFO" "Connectivity" "HTTP Status" "HTTP $HTTP_CODE → $FINAL_URL"
elif [[ "$HTTP_CODE" == "000" ]]; then
    REACHABLE=0
    add "HIGH" "Connectivity" "HTTP Status" "เชื่อมต่อไม่ได้ (timeout / connection refused)" \
        "ตรวจสอบว่า service ทำงานอยู่ที่ $HOST:$PORT และ firewall เปิดให้เข้าถึง"
else
    REACHABLE=1
    add "MEDIUM" "Connectivity" "HTTP Status" "HTTP $HTTP_CODE → $FINAL_URL" \
        "ตรวจสอบว่า status code นี้เป็นพฤติกรรมที่ตั้งใจ"
fi

SERVER="$(printf '%s\n' "$HEADERS" | header_value "Server")"
POWERED="$(printf '%s\n' "$HEADERS" | header_value "X-Powered-By")"
ASPNET="$(printf '%s\n' "$HEADERS" | header_value "X-AspNet-Version")"

TITLE="$("${CURL_BASE[@]}" -L "$TARGET" 2>/dev/null \
        | tr -d '\r\n' \
        | grep -oiE '<title[^>]*>[^<]*' \
        | head -1 | sed -E 's/<title[^>]*>//I' | trim)"

echo "    → HTTP Code : $HTTP_CODE"
echo "    → Server    : ${SERVER:-<hidden>}"

add "INFO" "Fingerprint" "Server Header" "${SERVER:-ไม่เปิดเผย}"
add "INFO" "Fingerprint" "Page Title" "${TITLE:-N/A}"

if [[ -n "$SERVER" ]]; then
    # เปิดเผยเลขเวอร์ชันอันตรายกว่าเปิดเผยแค่ชื่อ product
    if printf '%s' "$SERVER" | grep -qE '[0-9]+\.[0-9]+'; then
        add "MEDIUM" "Fingerprint" "Server Version Disclosure" "เปิดเผยเวอร์ชัน: $SERVER" \
            "nginx: server_tokens off; / Apache: ServerTokens Prod + ServerSignature Off"
    else
        add "LOW" "Fingerprint" "Server Disclosure" "เปิดเผย: $SERVER" "ซ่อน Server header ถ้าทำได้"
    fi
fi
[[ -n "$POWERED" ]] && add "MEDIUM" "Fingerprint" "X-Powered-By" "เปิดเผย: $POWERED" \
    "PHP: expose_php=Off / Express: app.disable('x-powered-by')"
[[ -n "$ASPNET" ]] && add "MEDIUM" "Fingerprint" "X-AspNet-Version" "เปิดเผย: $ASPNET" \
    "ลบผ่าน <httpRuntime enableVersionHeader=\"false\" />"

# -------------------- 2. TLS certificate --------------------
echo "[2/10] TLS Certificate"

if [[ "$SCHEME" != "https" ]]; then
    add "HIGH" "SSL/TLS" "HTTPS" "ไม่ได้ใช้ HTTPS" "เปิด HTTPS พร้อม redirect 301 และ HSTS"
elif ! command -v openssl >/dev/null 2>&1; then
    add "INFO" "SSL/TLS" "Status" "ไม่พบ openssl — ข้ามการตรวจ TLS"
else
    CERT_PEM="$(printf '' | timeout 10 openssl s_client -connect "$HOST:$PORT" -servername "$HOST" 2>/dev/null \
                | openssl x509 2>/dev/null)"

    if [[ -z "$CERT_PEM" ]]; then
        add "HIGH" "SSL/TLS" "Handshake" "TLS handshake ล้มเหลว" "ตรวจสอบใบรับรองและ service ที่พอร์ต $PORT"
    else
        SUBJECT="$(printf '%s' "$CERT_PEM" | openssl x509 -noout -subject 2>/dev/null | sed 's/^subject= *//')"
        ISSUER="$(printf '%s' "$CERT_PEM" | openssl x509 -noout -issuer 2>/dev/null | sed 's/^issuer= *//')"
        CERT_END="$(printf '%s' "$CERT_PEM" | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2-)"
        SANS="$(printf '%s' "$CERT_PEM" | openssl x509 -noout -ext subjectAltName 2>/dev/null | tail -n +2 | trim)"
        SIGALG="$(printf '%s' "$CERT_PEM" | openssl x509 -noout -text 2>/dev/null \
                 | awk -F': *' '/Signature Algorithm/ {print $2; exit}')"

        add "INFO" "SSL/TLS" "Certificate Subject" "${SUBJECT:-Unknown}"
        add "INFO" "SSL/TLS" "Certificate Issuer" "${ISSUER:-Unknown}"
        add "INFO" "SSL/TLS" "Certificate Expiry" "${CERT_END:-Unknown}"
        [[ -n "$SANS" ]] && add "INFO" "SSL/TLS" "SAN" "$SANS"

        # อายุคงเหลือ — ใช้ -checkend ซึ่งพกพาได้กว่าการ parse วันที่
        if ! printf '%s' "$CERT_PEM" | openssl x509 -noout -checkend 0 >/dev/null 2>&1; then
            add "CRITICAL" "SSL/TLS" "Certificate Validity" "ใบรับรองหมดอายุแล้ว ($CERT_END)" "ต่ออายุทันที"
        elif ! printf '%s' "$CERT_PEM" | openssl x509 -noout -checkend 604800 >/dev/null 2>&1; then
            add "CRITICAL" "SSL/TLS" "Certificate Validity" "หมดอายุภายใน 7 วัน ($CERT_END)" "ต่ออายุทันที / ตรวจสอบ auto-renew"
        elif ! printf '%s' "$CERT_PEM" | openssl x509 -noout -checkend 2592000 >/dev/null 2>&1; then
            add "HIGH" "SSL/TLS" "Certificate Validity" "หมดอายุภายใน 30 วัน ($CERT_END)" "ตั้ง auto-renew และ monitoring"
        else
            add "INFO" "SSL/TLS" "Certificate Validity" "ยังไม่หมดอายุ (ถึง $CERT_END)"
        fi

        if printf '%s' "$SIGALG" | grep -qiE 'sha1|md5'; then
            add "HIGH" "SSL/TLS" "Signature Algorithm" "อ่อนแอ: $SIGALG" "ออกใบรับรองใหม่ด้วย SHA-256 ขึ้นไป"
        else
            add "INFO" "SSL/TLS" "Signature Algorithm" "${SIGALG:-Unknown}"
        fi

        # ตรวจ trust chain จริง (ไม่ใช้ -k) — จุดที่สคริปต์เดิมมองไม่เห็นเลย
        TRUST_ERR="$(curl -sS -o /dev/null --connect-timeout "$CONNECT_TIMEOUT" -m "$CURL_TIMEOUT" \
                     -A "$UA" "$TARGET" 2>&1 >/dev/null)"
        if [[ -z "$TRUST_ERR" ]]; then
            add "INFO" "SSL/TLS" "Certificate Trust" "ผ่านการตรวจสอบ (chain + hostname ถูกต้อง)"
        else
            add "HIGH" "SSL/TLS" "Certificate Trust" "ไม่ผ่าน: $(printf '%s' "$TRUST_ERR" | head -2 | tr '\n' ' ')" \
                "ตรวจสอบ intermediate chain / ชื่อโดเมนใน SAN / self-signed"
        fi
    fi
fi

# -------------------- 3. TLS protocol versions --------------------
echo "[3/10] TLS Protocol Versions"

if [[ "$SCHEME" == "https" ]] && command -v openssl >/dev/null 2>&1; then
    OSSL_HELP="$(openssl s_client -help 2>&1)"
    ENABLED_PROTOS=()
    # เจรจาแบบปกติจะได้เวอร์ชันสูงสุดเสมอ ต้องยิงทดสอบทีละเวอร์ชันถึงจะรู้ว่าตัวเก่ายังเปิดอยู่ไหม
    for p in ssl3 tls1 tls1_1 tls1_2 tls1_3; do
        printf '%s' "$OSSL_HELP" | grep -q -- "-$p" || continue
        if printf '' | timeout 8 openssl s_client -"$p" -connect "$HOST:$PORT" -servername "$HOST" \
             >/dev/null 2>&1; then
            ENABLED_PROTOS+=("$p")
            case "$p" in
                ssl3) add "CRITICAL" "SSL/TLS" "SSLv3 Enabled" "เซิร์ฟเวอร์ยอมรับ SSLv3 (POODLE)" "ปิดทันที" ;;
                tls1) add "HIGH" "SSL/TLS" "TLS 1.0 Enabled" "เซิร์ฟเวอร์ยอมรับ TLS 1.0" "ปิด — ไม่ผ่าน PCI DSS แล้ว" ;;
                tls1_1) add "HIGH" "SSL/TLS" "TLS 1.1 Enabled" "เซิร์ฟเวอร์ยอมรับ TLS 1.1" "ปิด — deprecated (RFC 8996)" ;;
                tls1_2) add "INFO" "SSL/TLS" "TLS 1.2" "รองรับ (ดี)" ;;
                tls1_3) add "INFO" "SSL/TLS" "TLS 1.3" "รองรับ (ดีที่สุด)" ;;
            esac
        fi
    done
    if [[ ${#ENABLED_PROTOS[@]} -eq 0 ]]; then
        add "INFO" "SSL/TLS" "Protocol Probe" "ทดสอบไม่สำเร็จ (อาจถูก firewall กั้น)"
    else
        add "INFO" "SSL/TLS" "Enabled Protocols" "${ENABLED_PROTOS[*]}"
    fi
fi

# -------------------- 4. Security headers --------------------
echo "[4/10] Security Headers"

check_header() {
    local name="$1" sev="$2" rec="$3" val
    val="$(printf '%s\n' "$HEADERS" | header_value "$name")"
    if [[ -n "$val" ]]; then
        add "INFO" "Headers" "$name" "$val"
        printf '%s' "$val"
    else
        add "$sev" "Headers" "$name" "Missing" "$rec"
    fi
}

HSTS="$(check_header "Strict-Transport-Security" "HIGH" "เพิ่ม: max-age=31536000; includeSubDomains; preload")"
CSP="$(check_header "Content-Security-Policy" "MEDIUM" "เริ่มจาก default-src 'self' แล้วค่อยผ่อนตามที่จำเป็น")"
XCTO="$(check_header "X-Content-Type-Options" "MEDIUM" "เพิ่ม: nosniff")"
XFO="$(check_header "X-Frame-Options" "MEDIUM" "ใช้ frame-ancestors ใน CSP หรือ X-Frame-Options: DENY")"
check_header "Referrer-Policy" "LOW" "แนะนำ: strict-origin-when-cross-origin" >/dev/null
check_header "Permissions-Policy" "LOW" "ปิดฟีเจอร์ที่ไม่ได้ใช้ เช่น geolocation=(), camera=()" >/dev/null
check_header "Cross-Origin-Opener-Policy" "LOW" "แนะนำ: same-origin" >/dev/null

if [[ -n "$HSTS" ]]; then
    MAXAGE="$(printf '%s' "$HSTS" | grep -oiE 'max-age=[0-9]+' | head -1 | cut -d= -f2)"
    if [[ -n "$MAXAGE" ]] && (( MAXAGE < 15552000 )); then
        add "MEDIUM" "Headers" "HSTS max-age" "สั้นเกินไป: $MAXAGE วินาที" "ตั้งอย่างน้อย 15552000 (180 วัน), แนะนำ 31536000"
    fi
    printf '%s' "$HSTS" | grep -qi 'includeSubDomains' || \
        add "LOW" "Headers" "HSTS includeSubDomains" "ไม่ได้ตั้ง" "เพิ่ม includeSubDomains ถ้าทุก subdomain รองรับ HTTPS"
fi

if [[ -n "$CSP" ]]; then
    printf '%s' "$CSP" | grep -qi "unsafe-inline" && \
        add "MEDIUM" "Headers" "CSP unsafe-inline" "CSP อนุญาต unsafe-inline" "ใช้ nonce หรือ hash แทน"
    printf '%s' "$CSP" | grep -qi "unsafe-eval" && \
        add "MEDIUM" "Headers" "CSP unsafe-eval" "CSP อนุญาต unsafe-eval" "เลี่ยง eval() ในโค้ดฝั่ง client"
fi

if [[ -n "$XCTO" ]] && ! printf '%s' "$XCTO" | grep -qi 'nosniff'; then
    add "MEDIUM" "Headers" "X-Content-Type-Options" "ค่าไม่ถูกต้อง: $XCTO" "ต้องเป็น nosniff"
fi

# CORS misconfiguration
CORS_HDRS="$("${CURL_BASE[@]}" -I -H "Origin: https://audit-probe.example.com" "$TARGET" 2>/dev/null | tr -d '\r')"
ACAO="$(printf '%s\n' "$CORS_HDRS" | header_value "Access-Control-Allow-Origin")"
ACAC="$(printf '%s\n' "$CORS_HDRS" | header_value "Access-Control-Allow-Credentials")"
if [[ "$ACAO" == "https://audit-probe.example.com" && "$ACAC" == "true" ]]; then
    add "CRITICAL" "CORS" "Origin Reflection" "สะท้อน Origin ใดก็ได้ พร้อม credentials=true" \
        "ใช้ allowlist ของ origin ที่เชื่อถือได้เท่านั้น"
elif [[ "$ACAO" == "*" && "$ACAC" == "true" ]]; then
    add "HIGH" "CORS" "Wildcard + Credentials" "ACAO=* ร่วมกับ credentials=true" "ระบุ origin ที่เจาะจง"
elif [[ "$ACAO" == "https://audit-probe.example.com" ]]; then
    add "MEDIUM" "CORS" "Origin Reflection" "สะท้อน Origin ใดก็ได้" "ใช้ allowlist"
elif [[ -n "$ACAO" ]]; then
    add "INFO" "CORS" "Access-Control-Allow-Origin" "$ACAO"
fi

# -------------------- 5. HTTP methods --------------------
echo "[5/10] HTTP Methods"

if [[ $REACHABLE -eq 1 ]]; then
    OPT_HDRS="$("${CURL_BASE[@]}" -I -X OPTIONS "$TARGET" 2>/dev/null | tr -d '\r')"
    ALLOW="$(printf '%s\n' "$OPT_HDRS" | header_value "Allow")"
    [[ -z "$ALLOW" ]] && ALLOW="$(printf '%s\n' "$OPT_HDRS" | header_value "Access-Control-Allow-Methods")"
    if [[ -n "$ALLOW" ]]; then
        add "INFO" "Methods" "Allow (OPTIONS)" "$ALLOW"
        printf '%s' "$ALLOW" | grep -qiE '\b(PUT|DELETE|PATCH)\b' && \
            add "MEDIUM" "Methods" "Write Methods Advertised" "โฆษณา method เขียนข้อมูล: $ALLOW" \
                "จำกัดเฉพาะ endpoint ที่จำเป็น และบังคับ authentication"
    fi

    # TRACE ปลอดภัยที่จะทดสอบเสมอ (read-only)
    TRACE_CODE="$("${CURL_BASE[@]}" -o /dev/null -w '%{http_code}' -X TRACE "$TARGET" 2>/dev/null || echo 000)"
    if [[ "$TRACE_CODE" == "200" ]]; then
        add "MEDIUM" "Methods" "TRACE" "เปิดใช้งาน (HTTP 200) — Cross-Site Tracing" "ปิด TraceEnable / ไม่ proxy method TRACE"
    else
        add "INFO" "Methods" "TRACE" "ไม่เปิด (HTTP $TRACE_CODE)"
    fi

    if [[ $AGGRESSIVE -eq 1 ]]; then
        for method in PUT DELETE PATCH; do
            code="$("${CURL_BASE[@]}" -o /dev/null -w '%{http_code}' -X "$method" "$TARGET" 2>/dev/null || echo 000)"
            if [[ "$code" =~ ^(200|201|202|204)$ ]]; then
                add "HIGH" "Methods" "$method" "ยอมรับโดยไม่ยืนยันตัวตน (HTTP $code)" "ปิด method นี้หรือบังคับ auth"
            else
                add "INFO" "Methods" "$method" "ไม่เปิด (HTTP $code)"
            fi
        done
    else
        add "INFO" "Methods" "PUT/DELETE/PATCH" "ข้ามใน safe mode — ใช้ --aggressive เพื่อทดสอบ"
    fi
else
    add "INFO" "Methods" "Status" "ข้าม (เชื่อมต่อเป้าหมายไม่ได้)"
fi

# -------------------- 6. Sensitive paths --------------------
echo "[6/10] Sensitive Paths"

probe() {  # คืนค่า: <http_code> <bytes>
    "${CURL_BASE[@]}" -o /dev/null -w '%{http_code} %{size_download}' "$1" 2>/dev/null || echo "000 0"
}

if [[ $REACHABLE -eq 1 ]]; then
    # baseline สำหรับตรวจจับ soft-404 (SPA มักตอบ 200 + index.html ให้ทุก path)
    read -r BASE_CODE BASE_SIZE <<<"$(probe "${TARGET}/audit-probe-$RANDOM$RANDOM-nonexistent")"
    if [[ "$BASE_CODE" == "200" ]]; then
        add "INFO" "Paths" "Soft-404 Detection" "เป้าหมายตอบ HTTP 200 ให้ path ที่ไม่มีอยู่จริง (baseline ${BASE_SIZE}B) — ผลจะถูกกรองด้วยขนาด"
    fi

    PATHS=("/.env" "/.env.local" "/.env.production" "/.git/HEAD" "/.git/config"
           "/.svn/entries" "/.DS_Store" "/backup.zip" "/backup.tar.gz" "/db.sql" "/dump.sql"
           "/phpinfo.php" "/info.php" "/.htaccess" "/web.config" "/composer.json"
           "/package.json" "/docker-compose.yml" "/server-status" "/server-info"
           "/admin" "/wp-admin" "/wp-config.php.bak" "/cpanel" "/phpmyadmin"
           "/actuator/env" "/actuator/health" "/api/swagger.json" "/swagger-ui.html"
           "/robots.txt" "/.well-known/security.txt" "/sitemap.xml")

    for path in "${PATHS[@]}"; do
        read -r code size <<<"$(probe "${TARGET}${path}")"
        case "$code" in
            200)
                # ถ้าขนาดตรงกับ baseline soft-404 แสดงว่าไม่ได้มีไฟล์นี้จริง
                if [[ "$BASE_CODE" == "200" && "$size" == "$BASE_SIZE" ]]; then
                    continue
                fi
                case "$path" in
                    /robots.txt|/sitemap.xml|/.well-known/security.txt|/actuator/health)
                        add "INFO" "Paths" "$path" "เข้าถึงได้ (${size}B) — ปกติ" ;;
                    /.env*|/.git/*|*.sql|/backup*|/wp-config.php.bak|/actuator/env)
                        add "CRITICAL" "Paths" "$path" "เข้าถึงได้แบบสาธารณะ (${size}B) — อาจรั่วข้อมูลลับ" \
                            "บล็อกทันทีที่ web server และหมุนเวียน credential ที่อาจรั่ว" ;;
                    *)
                        add "HIGH" "Paths" "$path" "เข้าถึงได้ (HTTP 200, ${size}B)" "จำกัดสิทธิ์หรือปิดการเข้าถึง" ;;
                esac
                ;;
            401|403)
                add "LOW" "Paths" "$path" "มีอยู่แต่ถูกป้องกัน (HTTP $code)" "ยืนยันว่าไม่มีการรั่วผ่าน path อื่น" ;;
            500|501|503)
                add "LOW" "Paths" "$path" "เซิร์ฟเวอร์ error (HTTP $code)" "ตรวจสอบว่าไม่มี stack trace รั่วออกมา" ;;
        esac
        sleep 0.1
    done

    # directory listing
    DIRLIST="$("${CURL_BASE[@]}" -L "${TARGET}/" 2>/dev/null | grep -ciE 'index of /|<title>directory listing' || true)"
    [[ "${DIRLIST:-0}" -gt 0 ]] && add "HIGH" "Paths" "Directory Listing" "พบ directory listing เปิดอยู่" \
        "nginx: autoindex off; / Apache: Options -Indexes"
else
    add "INFO" "Paths" "Status" "ข้าม (เชื่อมต่อเป้าหมายไม่ได้)"
fi

# -------------------- 7. Cookies --------------------
echo "[7/10] Cookie Flags"

# ต้องใช้ GET (แอปส่วนใหญ่ set cookie ตอน GET ไม่ใช่ HEAD) และดูทุก hop
COOKIE_HDRS="$("${CURL_BASE[@]}" -L -D - -o /dev/null "$TARGET" 2>/dev/null | tr -d '\r')"
COOKIE_LINES="$(printf '%s\n' "$COOKIE_HDRS" | grep -i '^set-cookie:' || true)"

if [[ -n "$COOKIE_LINES" ]]; then
    while IFS= read -r line; do
        [[ -z "$line" ]] && continue
        cname="$(printf '%s' "$line" | sed -E 's/^[Ss]et-[Cc]ookie:[[:space:]]*//' | cut -d= -f1 | trim)"
        # ตรวจทีละ cookie — ของเดิมตรวจรวมกัน ทำให้ cookie ที่ไม่ปลอดภัยหลุดรอด
        printf '%s' "$line" | grep -qi ';[[:space:]]*secure' \
            || add "MEDIUM" "Cookies" "$cname — Secure" "ไม่มี flag Secure" "เพิ่ม Secure เพื่อไม่ให้ส่งผ่าน HTTP"
        printf '%s' "$line" | grep -qi ';[[:space:]]*httponly' \
            || add "MEDIUM" "Cookies" "$cname — HttpOnly" "ไม่มี flag HttpOnly" "เพิ่ม HttpOnly เพื่อกัน JavaScript อ่าน cookie"
        if printf '%s' "$line" | grep -qi 'samesite=none'; then
            add "MEDIUM" "Cookies" "$cname — SameSite" "SameSite=None" "ใช้ Lax หรือ Strict ถ้าไม่จำเป็นต้อง cross-site"
        elif ! printf '%s' "$line" | grep -qi 'samesite='; then
            add "LOW" "Cookies" "$cname — SameSite" "ไม่ได้ระบุ SameSite" "ระบุ SameSite=Lax เป็นอย่างน้อย"
        fi
    done <<< "$COOKIE_LINES"
else
    add "INFO" "Cookies" "Set-Cookie" "ไม่พบ cookie"
fi

# -------------------- 8. Nmap --------------------
echo "[8/10] Nmap Scan"

if [[ $USE_NMAP -eq 1 ]] && command -v nmap >/dev/null 2>&1; then
    PORTLIST="$(printf '21\n22\n23\n25\n53\n80\n110\n143\n443\n445\n465\n587\n993\n995\n1433\n3306\n3389\n5432\n6379\n8080\n8443\n9200\n27017\n%s\n' "$PORT" | sort -un | paste -sd, -)"
    [[ $EUID -eq 0 ]] && SCAN_TYPE="-sS" || SCAN_TYPE="-sT"
    [[ $AGGRESSIVE -eq 1 ]] && VERSION_FLAG="-sV" || VERSION_FLAG=""

    echo "    → กำลังสแกน ($SCAN_TYPE${VERSION_FLAG:+ $VERSION_FLAG})..."
    timeout 180 nmap $SCAN_TYPE $VERSION_FLAG -Pn -T3 --open -p "$PORTLIST" \
        --script "http-title,http-headers,http-methods,ssl-cert" \
        -oN "$NMAP_OUT" "$HOST" >/dev/null 2>&1

    if [[ -s "$NMAP_OUT" ]]; then
        OPEN_COUNT="$(grep -cE '^[0-9]+/tcp[[:space:]]+open' "$NMAP_OUT" 2>/dev/null)" || OPEN_COUNT=0
        add "INFO" "Nmap" "Open Ports" "พบ ${OPEN_COUNT:-0} พอร์ตเปิด"

        # process substitution — ไม่ใช่ pipe เพื่อไม่ให้ add() ไปทำงานใน subshell แล้วผลหาย
        while IFS= read -r line; do
            [[ -z "$line" ]] && continue
            pnum="${line%%/*}"
            case "$pnum" in
                3306|5432|6379|27017|9200|1433)
                    add "HIGH" "Nmap" "Database Port Exposed" "$line" \
                        "ไม่ควรเปิดฐานข้อมูลสู่อินเทอร์เน็ต — ผูกกับ 127.0.0.1 หรือใช้ firewall/VPN" ;;
                23)
                    add "CRITICAL" "Nmap" "Telnet Exposed" "$line" "ปิด telnet ใช้ SSH แทน" ;;
                3389)
                    add "HIGH" "Nmap" "RDP Exposed" "$line" "จำกัดด้วย VPN หรือ IP allowlist" ;;
                *)
                    add "INFO" "Nmap" "Open Port" "$line" ;;
            esac
        done < <(grep -E '^[0-9]+/tcp[[:space:]]+open' "$NMAP_OUT" 2>/dev/null | head -25)
    else
        add "INFO" "Nmap" "Status" "สแกนไม่สำเร็จหรือ timeout"
    fi
elif [[ $USE_NMAP -eq 0 ]]; then
    add "INFO" "Nmap" "Status" "ข้ามตามที่ระบุ (--no-nmap)"
else
    echo "    → ไม่พบ nmap (ข้าม)"
    add "INFO" "Nmap" "Status" "ไม่ได้ติดตั้ง nmap — ข้าม"
fi

# -------------------- 9. Panel authentication & session --------------------
#
# หมวดนี้พิสูจน์คำสัญญาใน docs/SECURITY.md §2.2–2.3 จากภายนอก — เป็นข้อที่พังแล้วเจ็บ
# ที่สุดและตรวจได้โดยไม่ต้องมีบัญชีเลย
#
# **เปิดด้วย --auth-checks เท่านั้น** เพราะการทดสอบ rate limit กินโควตาการล็อกอินของ
# IP นี้ไปหลายนาที (5 ครั้งรัว เติมกลับ 1 ครั้งต่อนาที) ซึ่งจะกวนคนที่กำลังใช้งานอยู่จริง
echo "[9/10] Panel Authentication & Session"

if [[ $AUTH_CHECKS -eq 0 ]]; then
    echo "    → ข้าม (เปิดด้วย --auth-checks)"
    add "INFO" "Panel" "Auth Checks" "ไม่ได้ตรวจ — เปิดด้วย --auth-checks" \
        "ควรรันอย่างน้อยหลังทุกการอัปเดตที่แตะชั้น middleware หรือ session"
elif [[ $REACHABLE -eq 0 ]]; then
    add "INFO" "Panel" "Auth Checks" "ข้ามเพราะเชื่อมต่อเป้าหมายไม่ได้"
else
    PANEL_JAR="$REPORT_DIR/.panel-cookies"
    : > "$PANEL_JAR"

    # `GET /api/v2/session` คือจุด bootstrap ของ SPA — เรียกได้โดยไม่ต้องล็อกอิน
    # และเป็นที่ที่ CSRF token ออกมา (หน้าล็อกอินแบบ HTML ไม่มีแล้วตั้งแต่ 2026-08-08)
    LOGIN_HEADERS="$("${CURL_BASE[@]}" -c "$PANEL_JAR" -D - -o "$REPORT_DIR/.session.json" "$TARGET/api/v2/session" 2>/dev/null | tr -d '\r')"

    # --- คุกกี้ session ---------------------------------------------------
    # ชื่อขึ้นต้นด้วย __Host- บังคับให้เบราว์เซอร์ยอมรับเฉพาะเมื่อมี Secure, Path=/
    # และ **ไม่มี Domain** — คุกกี้จึงถูกผูกกับ origin เดียวเท่านั้น เว็บย่อยของโดเมน
    # เดียวกันเขียนทับไม่ได้ ซึ่งเป็นการโจมตีที่ SameSite อย่างเดียวกันไม่ได้
    #
    # panel ออกคุกกี้ **ตอนล็อกอินสำเร็จเท่านั้น** ไม่ใช่ตอนเปิดหน้าล็อกอิน — ถ้าไม่ได้
    # บัญชีมา การตรวจสามข้อข้างล่างจะไม่มีวันได้ทำงานเลย ซึ่งอันตรายกว่าไม่มีการตรวจ
    # เพราะรายงานจะดูเหมือนผ่าน · จึงต้องรายงานให้ชัดว่า "ไม่ได้ตรวจ" ไม่ใช่เงียบไป
    SET_COOKIE="$(printf '%s\n' "$LOGIN_HEADERS" | grep -i '^set-cookie:' | head -1)"

    if [[ -z "$SET_COOKIE" && -n "$AUTH_USER" && -r "${AUTH_PASSWORD_FILE:-/nonexistent}" ]]; then
        LOGIN_TOKEN="$(grep -o '"csrf_token":"[^"]*"' "$REPORT_DIR/.session.json" 2>/dev/null \
            | head -1 | cut -d'"' -f4)"

        # JSON ต้อง escape รหัสผ่านให้ถูก ไม่งั้นรหัสที่มี " หรือ \ จะทำให้คำขอเสียรูป
        # แล้วรายงานจะบอกว่า "ล็อกอินไม่สำเร็จ" ทั้งที่รหัสถูก
        LOGIN_BODY="$(AUTH_USER="$AUTH_USER" AUTH_PASSWORD_FILE="$AUTH_PASSWORD_FILE" python3 -c '
import json, os
password = open(os.environ["AUTH_PASSWORD_FILE"], encoding="utf-8").read().strip()
print(json.dumps({"username": os.environ["AUTH_USER"], "password": password}))
' 2>/dev/null)"

        SET_COOKIE="$("${CURL_BASE[@]}" -b "$PANEL_JAR" -c "$PANEL_JAR" -D - -o /dev/null \
            -X POST "$TARGET/api/v2/session" \
            -H 'Content-Type: application/json' \
            -H "X-CSRF-Token: $LOGIN_TOKEN" \
            --data-raw "$LOGIN_BODY" 2>/dev/null \
            | tr -d '\r' | grep -i '^set-cookie:' | head -1)"

        [[ -z "$SET_COOKIE" ]] && add "INFO" "Panel" "Session Cookie" \
            "ล็อกอินด้วยบัญชีที่ให้มาแล้วแต่ไม่ได้คุกกี้ — ตรวจชื่อผู้ใช้และรหัสผ่าน"
    fi

    if [[ -z "$SET_COOKIE" ]]; then
        add "MEDIUM" "Panel" "Session Cookie" \
            "ยังไม่ได้ตรวจคุณสมบัติคุกกี้ session (Secure/HttpOnly/SameSite/__Host-)" \
            "ส่ง --user และ --password-file มาด้วย — คุกกี้ออกตอนล็อกอินสำเร็จเท่านั้น"
    else
        # Secure และคำนำหน้า __Host- ตั้งไม่ได้บน http:// โดยนิยาม — รายงานเป็นปัญหา
        # ซ้ำกับข้อ "ไม่ได้ใช้ HTTPS" จะทำให้รายงานมีของปลอมปนจนคนอ่านเลิกเชื่อทั้งฉบับ
        if printf '%s' "$SET_COOKIE" | grep -qi 'Secure'; then
            add "INFO" "Panel" "Cookie Secure" "มี"
        elif [[ "$SCHEME" != "https" ]]; then
            add "INFO" "Panel" "Cookie Secure" "ไม่มี — เป้าหมายไม่ได้ใช้ HTTPS จึงตั้งไม่ได้" \
                "แก้ที่ต้นเหตุคือเปิด HTTPS (ดูข้อ SSL/TLS)"
        else
            add "HIGH" "Panel" "Cookie Secure" "คุกกี้ session ไม่มีแฟล็ก Secure" \
                "ไม่มี Secure = คุกกี้ถูกส่งข้าม http ได้ ผู้ที่ดักกลางทางขโมย session ได้ทันที"
        fi

        if printf '%s' "$SET_COOKIE" | grep -qi 'HttpOnly'; then
            add "INFO" "Panel" "Cookie HttpOnly" "มี"
        else
            add "HIGH" "Panel" "Cookie HttpOnly" "คุกกี้ session ไม่มีแฟล็ก HttpOnly" \
                "ตั้งใน SessionStore — ไม่มี HttpOnly = JavaScript อ่าน session ได้"
        fi

        if printf '%s' "$SET_COOKIE" | grep -qiE 'samesite=strict'; then
            add "INFO" "Panel" "Cookie SameSite" "Strict"
        else
            add "HIGH" "Panel" "Cookie SameSite" "ไม่ได้ตั้ง SameSite=Strict" \
                "Strict คือด่านที่กัน CSRF ร่วมกับ token — Lax ยังปล่อย GET ข้ามเว็บ"
        fi

        if printf '%s' "$SET_COOKIE" | grep -qi 'set-cookie:[[:space:]]*__Host-'; then
            add "INFO" "Panel" "Cookie __Host- Prefix" "มี"
        elif [[ "$SCHEME" != "https" ]]; then
            add "INFO" "Panel" "Cookie __Host- Prefix" "ไม่มี — คำนำหน้านี้ต้องมี Secure จึงใช้บน http ไม่ได้"
        else
            add "MEDIUM" "Panel" "Cookie __Host- Prefix" "ชื่อคุกกี้ไม่ได้ขึ้นต้นด้วย __Host-" \
                "คำนำหน้านี้ทำให้เว็บย่อยของโดเมนเดียวกันเขียนทับคุกกี้ไม่ได้"
        fi
    fi

    # --- CSRF -------------------------------------------------------------
    # POST ที่ไม่มี token ต้องถูกปฏิเสธ **ก่อน** ถึงตรรกะล็อกอิน
    #
    # ต้องใช้ cookie jar สะอาด · ถ้ายิงด้วย session ที่ล็อกอินอยู่ ผลจะอ่านไม่ตรง
    # กับสิ่งที่ตั้งใจตรวจ — ผลบวกลวงแบบนี้ทำให้ทั้งรายงานเชื่อไม่ได้
    CSRF_JAR="$REPORT_DIR/.csrf-cookies"
    : > "$CSRF_JAR"

    CSRF_CODE="$("${CURL_BASE[@]}" -b "$CSRF_JAR" -c "$CSRF_JAR" -o /dev/null -w '%{http_code}' \
        -X POST -H 'Content-Type: application/json' \
        --data-raw '{"username":"x","password":"y"}' \
        "$TARGET/api/v2/session" 2>/dev/null || echo 000)"

    # 429 แปลว่าคำขอถูกตัดที่ตัวจำกัดอัตราก่อนถึงด่าน CSRF — **ยังไม่ได้ตรวจ** ไม่ใช่ไม่ผ่าน
    # (เกิดได้เมื่อรันตัวตรวจซ้ำ ๆ เพราะโควตาล็อกอินเติมกลับช้า) · การรายงานว่าไม่ผ่าน
    # ในกรณีนี้คือผลบวกลวง ซึ่งทำให้คนอ่านเลิกเชื่อรายงานทั้งฉบับ
    case "$CSRF_CODE" in
        419|403) add "INFO" "Panel" "CSRF Protection" "POST ที่ไม่มี token ถูกปฏิเสธ ($CSRF_CODE)" ;;
        000)     add "INFO" "Panel" "CSRF Protection" "ทดสอบไม่ได้ (เชื่อมต่อล้มเหลว)" ;;
        429)     add "MEDIUM" "Panel" "CSRF Protection" \
                     "ยังไม่ได้ตรวจ — ถูกตัดที่ตัวจำกัดอัตราก่อนถึงด่าน CSRF" \
                     "รอสักครู่แล้วรันซ้ำ · โควตาล็อกอินเติมกลับ 1 ครั้งต่อนาที" ;;
        *)       add "CRITICAL" "Panel" "CSRF Protection" \
                     "POST ที่ไม่มี CSRF token ได้ $CSRF_CODE แทนที่จะถูกปฏิเสธ" \
                     "เว็บอื่นสั่งงานแทนผู้ใช้ที่ล็อกอินอยู่ได้ — ตรวจ CsrfProtection middleware" ;;
    esac

    # --- phpMyAdmin -------------------------------------------------------
    # ตั้งแต่เปลี่ยนเป็น auth_type=signon ผู้ที่ยังไม่ได้ล็อกอิน panel ต้องเข้าไม่ถึง
    PMA_CODE="$("${CURL_BASE[@]}" -o "$REPORT_DIR/.pma.html" -w '%{http_code}' "$TARGET/phpmyadmin/" 2>/dev/null || echo 000)"

    if [[ "$PMA_CODE" == "200" ]] && grep -qi 'name="pma_username"' "$REPORT_DIR/.pma.html" 2>/dev/null; then
        add "HIGH" "Panel" "phpMyAdmin Login Form" \
            "phpMyAdmin แสดงฟอร์มล็อกอินให้ผู้ที่ยังไม่ได้ล็อกอิน panel" \
            "ใช้ auth_type = signon เพื่อให้เข้าได้เฉพาะผ่าน panel — ฟอร์มที่เปิดทิ้งไว้คือเป้าให้เดารหัส"
    elif [[ "$PMA_CODE" == "000" ]]; then
        add "INFO" "Panel" "phpMyAdmin" "ไม่ตอบสนอง (อาจไม่ได้ติดตั้ง)"
    else
        add "INFO" "Panel" "phpMyAdmin" "ไม่แสดงฟอร์มล็อกอินให้คนนอก (HTTP $PMA_CODE)"
    fi

    # --- Rate limit -------------------------------------------------------
    # ยิงล็อกอินผิดด้วยชื่อที่ไม่มีอยู่จริง — ระบบไม่นับความล้มเหลวให้บัญชีใด จึงไม่ล็อก
    # บัญชีของใคร แต่โควตาระดับ IP จะถูกใช้ไป ซึ่งเป็นราคาที่ --auth-checks จ่าย
    echo "    → ทดสอบ rate limit (ใช้โควตาล็อกอินของ IP นี้)"
    PROBE_USER="audit-probe-$RANDOM"
    RATE_HIT=0

    for _ in 1 2 3 4 5 6 7; do
        RC="$("${CURL_BASE[@]}" -b "$PANEL_JAR" -o /dev/null -w '%{http_code}' \
            -X POST -d "username=$PROBE_USER&password=wrong" "$TARGET/api/v2/session" 2>/dev/null || echo 000)"
        [[ "$RC" == "429" ]] && { RATE_HIT=1; break; }
    done

    if [[ $RATE_HIT -eq 1 ]]; then
        add "INFO" "Panel" "Login Rate Limit" "ทำงาน — ถูกจำกัดหลังพยายามซ้ำหลายครั้ง"
    else
        add "HIGH" "Panel" "Login Rate Limit" "ยิงล็อกอินผิด 7 ครั้งติดโดยไม่โดนจำกัดเลย" \
            "เปิด RateLimit middleware — ไม่มีตัวนี้แปลว่าเดารหัสได้ไม่จำกัดจำนวนครั้ง"
    fi

    rm -f "$REPORT_DIR/.login.html" "$REPORT_DIR/.pma.html" "$PANEL_JAR" "$CSRF_JAR"
fi
echo

# -------------------- 10. Reports --------------------
echo "[10/10] Generating Reports"

python3 - "$TSV_FILE" "$JSON_FILE" "$HTML_FILE" "$TARGET" "$HOST" "$PORT" "$AGGRESSIVE" << 'PY'
import json, html, sys, io
from datetime import datetime

tsv, json_file, html_file, target, host, port, aggressive = sys.argv[1:8]

order = {"CRITICAL": 0, "HIGH": 1, "MEDIUM": 2, "LOW": 3, "INFO": 4}
results = []
with io.open(tsv, encoding="utf-8") as f:
    for line in f:
        line = line.rstrip("\n")
        if not line:
            continue
        parts = line.split("\t")
        parts += [""] * (5 - len(parts))
        sev, cat, chk, det, rec = parts[:5]
        results.append({
            "severity": sev if sev in order else "INFO",
            "category": cat, "check": chk,
            "detail": det, "recommendation": rec,
        })

results.sort(key=lambda r: (order[r["severity"]], r["category"]))

summary = {k.lower(): 0 for k in order}
for r in results:
    summary[r["severity"].lower()] += 1

data = {
    "target": target,
    "host": host,
    "port": int(port),
    "mode": "aggressive" if aggressive == "1" else "safe",
    "scan_time": datetime.now().astimezone().isoformat(timespec="seconds"),
    "summary": summary,
    "results": results,
}
with io.open(json_file, "w", encoding="utf-8") as f:
    json.dump(data, f, ensure_ascii=False, indent=2)

colors = {"CRITICAL": "#ef4444", "HIGH": "#f97316", "MEDIUM": "#eab308",
          "LOW": "#3b82f6", "INFO": "#64748b"}

rows = []
for r in results:
    c = colors[r["severity"]]
    rec = r["recommendation"] or "—"
    rows.append(
        '<tr data-sev="{sev}">'
        '<td><span class="pill" style="background:{c}">{sev}</span></td>'
        '<td>{cat}</td><td>{chk}</td><td>{det}</td><td class="rec">{rec}</td></tr>'.format(
            sev=r["severity"], c=c,
            cat=html.escape(r["category"]), chk=html.escape(r["check"]),
            det=html.escape(r["detail"]), rec=html.escape(rec)))

s = data["summary"]
cards = "".join(
    '<button class="card" data-filter="{k}"><div class="num" style="color:{c}">{n}</div>'
    '<div class="lbl">{label}</div></button>'.format(
        k=k.upper(), c=colors[k.upper()], n=s[k], label=k.capitalize())
    for k in ["critical", "high", "medium", "low", "info"])

doc = """<!DOCTYPE html>
<html lang="th"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Security Audit — {host}</title>
<style>
*{{box-sizing:border-box}}
body{{font-family:system-ui,-apple-system,"Noto Sans Thai",sans-serif;background:#0f172a;color:#e2e8f0;padding:2rem;margin:0;line-height:1.5}}
.container{{max-width:1200px;margin:0 auto}}
header{{background:#1e293b;border-radius:16px;padding:2rem;border:1px solid #334155}}
h1{{color:#38bdf8;margin:0 0 .75rem;font-size:1.5rem}}
.meta{{color:#94a3b8;font-size:.9rem}}
.summary{{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:1rem;margin:1.5rem 0}}
.card{{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:1.1rem;text-align:center;cursor:pointer;color:inherit;font:inherit;transition:border-color .15s}}
.card:hover,.card.active{{border-color:#38bdf8}}
.num{{font-size:1.9rem;font-weight:700;line-height:1}}
.lbl{{font-size:.8rem;color:#94a3b8;margin-top:.35rem}}
.note{{background:#1e293b;border-left:4px solid #38bdf8;padding:.9rem 1rem;margin:1.25rem 0;border-radius:0 8px 8px 0;font-size:.88rem;color:#cbd5e1}}
table{{width:100%;border-collapse:collapse;background:#1e293b;border-radius:12px;overflow:hidden;font-size:.9rem}}
th{{background:#0f172a;color:#94a3b8;padding:.85rem 1rem;text-align:left;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em}}
td{{padding:.8rem 1rem;border-top:1px solid #334155;vertical-align:top;word-break:break-word}}
.pill{{color:#fff;padding:2px 9px;border-radius:9999px;font-size:11px;font-weight:600;white-space:nowrap}}
.rec{{color:#94a3b8}}
tr.hidden{{display:none}}
footer{{margin-top:2rem;text-align:center;color:#64748b;font-size:.82rem}}
</style></head><body><div class="container">
<header>
<h1>Web Hosting Security Audit</h1>
<div class="meta">
<strong>Target:</strong> {target}<br>
<strong>Host:Port:</strong> {host}:{port}<br>
<strong>Scan Time:</strong> {time}<br>
<strong>Mode:</strong> {mode}
</div></header>
<div class="note">รายงานนี้เป็นการตรวจสอบเชิงสังเกตแบบไม่ทำลายระบบ ผลลัพธ์สะท้อนสิ่งที่ตรวจพบจากภายนอกเท่านั้น
ไม่ทดแทนการตรวจสอบโค้ดหรือ penetration test เต็มรูปแบบ · คลิกการ์ดด้านล่างเพื่อกรองผล</div>
<div class="summary">{cards}</div>
<table><thead><tr><th>Severity</th><th>Category</th><th>Check</th><th>Detail</th><th>Recommendation</th></tr></thead>
<tbody id="tb">{rows}</tbody></table>
<footer>Generated {gen} · {total} checks</footer>
</div>
<script>
let active=null;
document.querySelectorAll('.card').forEach(c=>c.addEventListener('click',()=>{{
  const f=c.dataset.filter.toUpperCase();
  active = (active===f) ? null : f;
  document.querySelectorAll('.card').forEach(x=>x.classList.toggle('active',active&&x.dataset.filter.toUpperCase()===active));
  document.querySelectorAll('#tb tr').forEach(r=>r.classList.toggle('hidden',active&&r.dataset.sev!==active));
}}));
</script>
</body></html>""".format(
    target=html.escape(target), host=html.escape(host), port=html.escape(port),
    time=html.escape(data["scan_time"]),
    mode="Aggressive" if aggressive == "1" else "Safe / Non-destructive",
    cards=cards, rows="".join(rows),
    gen=datetime.now().strftime("%Y-%m-%d %H:%M"), total=len(results))

with io.open(html_file, "w", encoding="utf-8") as f:
    f.write(doc)
print("    → HTML report created")
PY

PY_STATUS=$?
rm -f "$TSV_FILE"

echo
echo "======================================================"
echo " เสร็จสิ้น"
echo " Critical: $CRITICAL | High: $HIGH | Medium: $MEDIUM | Low: $LOW | Info: $INFO"

# ส่งออก JSON ให้ panel เอาไปแสดงผล — **ตั้งใจให้เป็น JSON ไม่ใช่ HTML**
# รายงานฝังเนื้อหาที่ได้จากเป้าหมายที่สแกน (header, ตัวอย่าง response) ถ้า panel เสิร์ฟ
# HTML ก้อนนั้นจาก origin ของตัวเอง บั๊ก escaping ในอนาคตแม้จุดเดียวจะกลายเป็น XSS
# ในโดเมนของ panel ซึ่งเท่ากับยึด panel ได้ · ให้ panel อ่าน JSON แล้ว escape เองดีกว่า
if [[ -n "$JSON_OUT" ]]; then
    if cp "$JSON_FILE" "$JSON_OUT" 2>/dev/null; then
        chmod 640 "$JSON_OUT" 2>/dev/null || true
        echo " JSON        : $JSON_OUT"
    else
        echo " JSON        : เขียน $JSON_OUT ไม่ได้ (ตรวจสิทธิ์)" >&2
    fi
fi
echo " JSON : $JSON_FILE"
echo " HTML : $HTML_FILE"
echo "======================================================"
echo " เปิดรายงาน: xdg-open \"$HTML_FILE\""

[[ $PY_STATUS -ne 0 ]] && { echo "คำเตือน: สร้างรายงานไม่สมบูรณ์" >&2; exit 1; }
if (( CRITICAL > 0 || HIGH > 0 )); then exit 2; fi
exit 0