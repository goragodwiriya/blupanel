[Unit]
Description=PHP Control Panel — จับเวลาให้ phpcp-scheduler ทำงานทุกนาที
Documentation=file://{{INSTALL_DIR}}/docs/PLAN-V2.md

[Timer]
Unit=phpcp-scheduler.service
# ทุกนาทีตรง — ความละเอียดของ cron expression ในตาราง scheduled_jobs คือนาที
OnCalendar=*:0/1
# ยิงรอบแรกหลังบูต 1 นาที ไม่ต้องรอถึงนาทีถัดไปตามปฏิทิน — เครื่องที่เพิ่งกลับมา
# อาจมีรายการรอยืนยันที่หมดเวลาไปแล้วตั้งแต่ก่อนดับ ซึ่งต้องถูกคืนค่าให้เร็วที่สุด
OnBootSec=1min
# ไม่กระจายเวลา — งานนี้ต้องตรงนาที ไม่ใช่ "ประมาณนั้น"
AccuracySec=1s
RandomizedDelaySec=0
# รอบที่พลาดไปตอนเครื่องดับให้ยิงทันทีที่กลับมา
Persistent=true

[Install]
WantedBy=timers.target
