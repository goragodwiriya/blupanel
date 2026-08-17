[Unit]
Description=PHP Control Panel — privileged agent (layer 2)
Documentation=file://{{INSTALL_DIR}}/docs/ARCHITECTURE.md
After=network.target
Before=phpcp-web.service

# Notifies when the agent enters the failed state — the one place this can genuinely be done (PLAN-V2 phase E6)
#
# When the agent dies, nothing else in the system can send a notification at
# all: `alert.check` is a capability that itself runs through the agent, and
# phpcp-scheduler is locked down to AF_UNIX (can't fire Telegram) and
# NoNewPrivileges (can't queue mail) · systemd is therefore the one thing that still sees this event
#
# Works together with Restart=always below exactly right: a crash that
# restarts itself = never enters the failed state = never wakes anyone up ·
# only once restarts loop enough to hit StartLimitBurst does it genuinely become failed, which is the case that needs someone to look
OnFailure=phpcp-alert@%n.service

[Service]
Type=simple
# -d pcre.jit=0 is required because MemoryDenyWriteExecute=yes below forbids
# requesting memory that's both writable and executable at once, which is
# exactly what PCRE JIT needs — without disabling it, PHP spews a "Allocation
# of JIT memory failed" warning every single time preg_match runs (every
# request that reaches Agent\Protocol), filling the journal with the same
# repeated message until the real error can't be found at all · disabling
# JIT here is better than loosening MemoryDenyWriteExecute, since the agent's own regexes are short and never the bottleneck
ExecStart={{PHP_BIN}} -d pcre.jit=0 {{INSTALL_DIR}}/bin/phpcp-agentd
Restart=always
RestartSec=2
TimeoutStopSec=10

# Group=phpcp makes /run/phpcp root:phpcp 0750, per ARCHITECTURE §13
# Leaving it as root:root would make layer 1 completely unable to reach agent.sock
Group={{PANEL_GROUP}}
RuntimeDirectory=phpcp
RuntimeDirectoryMode=0750
# Never delete this line — all three services share RuntimeDirectory=phpcp
# systemd's own default is to delete /run/phpcp when a service stops, which
# means restarting any one of the three services would delete the other two's
# sockets along with it (agent.sock, panel-fpm.sock) — the result is the
# panel answering 503 or "cannot reach the agent" while every service still shows active
RuntimeDirectoryPreserve=yes

# ---------------------------------------------------------------------------
# The agent must be root, since it's the one layer that runs with elevated privileges
# But every capability not genuinely needed is dropped entirely — even if the
# agent were fully compromised, it still couldn't load a kernel module, edit sysctl, or touch a user's /home
# ---------------------------------------------------------------------------
NoNewPrivileges=yes
# ProtectHome=no + InaccessiblePaths=/root — not loosening security, aiming it correctly instead
#
# `ProtectHome=yes` makes /home, /root, and /run/user appear **empty** from
# this service's point of view · ever since customer homes moved to /home,
# the agent could never create or touch anyone's home at all —
# `site.create` failed with "could not create the directory: /home/<user>" every single time
#
# `ReadWritePaths=/home` **can't unlock this** — confirmed by testing on a
# real machine (2026-08-14): systemd hides /home while building the mount namespace, before ReadWritePaths ever takes effect
#
# So ProtectHome is disabled and /root is blocked directly instead — the one
# of those three paths the panel genuinely never has a reason to touch ·
# /home is the panel's own core work, blocking it would mean blocking the panel from working at all
ProtectHome=no
InaccessiblePaths=/root
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectKernelLogs=yes
ProtectControlGroups=yes
ProtectClock=yes
ProtectHostname=yes
RestrictNamespaces=yes
RestrictRealtime=yes
RestrictSUIDSGID=no
LockPersonality=yes
MemoryDenyWriteExecute=yes
# AF_NETLINK is required — sendmail/Postfix calls getifaddrs() through netlink
# Without it: fatal: inet_addr_local[getifaddrs]: Address family not supported by protocol
RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6 AF_NETLINK
SystemCallArchitectures=native
SystemCallFilter=@system-service @privileged
# CAP_NET_ADMIN is required for ufw — both reading status and editing rules
# talk to the kernel's netfilter entirely · without it, `ufw status` answers
# "Could not fetch rule set generation id: Permission denied," and the
# Firewall page reports it as off even though it's genuinely on (readable=false)
#
# CAP_NET_BIND_SERVICE is required for `nginx -t` — checking nginx's config
# **genuinely opens a listening socket**, it never only checks syntax ·
# without this capability, it answers "bind() to 0.0.0.0:80 failed (13:
# Permission denied)" even though the line right before it already said
# "syntax is ok" — the result is every config write getting rolled back with
# nothing wrong in the file at all (genuinely hit this while switching web servers from the screen, 2026-08-11)
CapabilityBoundingSet=CAP_CHOWN CAP_DAC_OVERRIDE CAP_FOWNER CAP_SETUID CAP_SETGID CAP_KILL CAP_SYS_ADMIN CAP_NET_ADMIN CAP_NET_BIND_SERVICE
# Must also be ambient, not just in the bounding set — NoNewPrivileges=yes
# means a capability doesn't pass down to a child process on its own, and ufw/iptables/nginx are all child processes
AmbientCapabilities=CAP_NET_ADMIN CAP_NET_BIND_SERVICE

# Note: RestrictSUIDSGID must be no, since the agent has to setuid down to a
# website's owner before touching a user's file (ARCHITECTURE §4.4), which lowers privilege, never raises it

[Install]
WantedBy=multi-user.target
