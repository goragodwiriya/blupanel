// Additional BIND9 settings — this file belongs to the machine's admin
//
// The panel never overwrites this file · edit it from the panel's domain
// page, or edit this file directly, either works
//
// This file lives in BIND's own directory, never under /etc/phpcp the way
// another service's custom file does, because named drops its root
// privileges the moment it starts and can never read a file under /etc/phpcp at all
//
// -----------------------------------------------------------------------------
// What's different from another service's custom file — read before writing
// -----------------------------------------------------------------------------
// 1. **Never delete this file — delete its content instead**
//    named.conf.local has an include line pointing at this file, and BIND's
//    own `include` **doesn't tolerate a missing file** — there's no
//    include_try like Dovecot has, or IncludeOptional like Apache has · the
//    moment this file is missing, named won't start on its next restart (the
//    one currently running is fine, since it already read the value) — this
//    usually turns up during a reboot with nobody watching · an empty file is safe
//
// 2. **A value written later doesn't win over the existing one — it's an error**
//    Unlike Postfix/Dovecot/Apache, where "duplicate" is defined as
//    "overwrite" · for BIND, declaring a zone twice or duplicate options
//    makes named-checkconf fail, and the whole settings change gets reverted
//
// 3. `options { }` can only exist once per machine, and named.conf.options
//    already uses it — a machine-level value like forwarders, recursion, or
//    listen-on can never be written here (but a zone-level value can, see the examples below)
//
// 4. The zones for domains managed through the panel live in
//    named.conf.local, which the panel writes as a whole file — never
//    declare them again here · this file is for zones the panel doesn't manage
//
// 5. **Never write a secret key (TSIG) directly into this file**
//    This file is readable by everyone on the machine, like every other file
//    in this directory · keep `key { }` in a separate file set to `chmod 640`
//    and `chown root:bind`, then include it in from here
//
// 6. After saving, `named-checkconf` must pass before this takes effect ·
//    even once it passes, it still needs to be confirmed, or the system reverts it back on its own (both this file and the include line together)
// -----------------------------------------------------------------------------

// Examples — remove the // in front of the line you want to use

// A group of IPs reusable in the rules below — the name must not collide with any/none/localhost/localnets
//acl "secondary_dns" {
//    203.0.113.10;
//    198.51.100.20;
//};

// Sends a zone for a secondary DNS to fetch and keep (AXFR) — only set this on a zone the panel doesn't manage
//zone "internal.example" {
//    type master;
//    file "/etc/bind/zones/internal.example.zone";
//    allow-transfer { "secondary_dns"; };
//    notify yes;
//};

// Receives a zone from another machine (we're the secondary) — the directory must be writable by bind
//zone "example.org" {
//    type slave;
//    masters { 203.0.113.1; };
//    file "/var/cache/bind/example.org.zone";
//};

// Sends queries for one domain to an internal DNS instead of walking up from the internet's root
//zone "corp.internal" {
//    type forward;
//    forward only;
//    forwarders { 10.0.0.53; };
//};

// Keeps a more detailed log while troubleshooting — `logging` can also only exist once per machine
// Turn it back off when done, since the file grows very fast on a machine with many incoming queries
//logging {
//    channel query_log {
//        file "/var/log/named/query.log" versions 3 size 20m;
//        severity info;
//        print-time yes;
//    };
//    category queries { query_log; };
//};
