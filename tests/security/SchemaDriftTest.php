<?php

declare(strict_types=1);

/**
 * SQL ในโค้ดต้องอ้างคอลัมน์ที่มีอยู่จริงในสคีมาหลัง migration ทุกตัว
 *
 * **เจอจากการใช้งานจริง (2026-08-14):** migration 0021 ลบ `sites.name` ทิ้ง เทสต์
 * ผ่านครบ 744 ข้อ แต่บนเครื่องจริงหน้าฐานข้อมูลพังทันที — `db.list` ยังเลือก
 * `s.name AS site_name` อยู่ (ค่าที่ไม่มีใครใช้ด้วยซ้ำ) · SQLite ตอบ "no such column"
 * capability ตายกลางทาง แล้วหน้าเว็บขึ้น AGENT_UNAVAILABLE ซึ่งชี้ไปผิดทางสนิท
 *
 * **ทำไมเทสต์อื่นจับไม่ได้:** ไม่มีเทสต์ไหนเรียก `db.list` เพราะมันต้องมี MariaDB
 * และ Executor จริง · คำสั่งอ่านรายการทั้งกลุ่มเป็นแบบนั้นเหมือนกันหมด แปลว่า
 * "คอลัมน์หายแต่ query ยังอ้างอยู่" หลุดออกไปถึงเครื่องจริงได้เสมอ ไม่ว่าจะเขียน
 * เทสต์ของแต่ละ capability ครบแค่ไหน
 *
 * เทสต์นี้จึงไม่ทดสอบ capability ทีละตัว แต่เทียบ **SQL ทุกบรรทัดในโค้ด** กับสคีมา
 * ที่ migration สร้างจริง — ไม่ต้องมี root ไม่ต้องมี MariaDB และครอบคลุมโค้ดที่
 * ยังไม่มีเทสต์ของตัวเองด้วย
 */

group('สคีมา — SQL ในโค้ดต้องตรงกับตารางจริง');

/**
 * คอลัมน์ของทุกตารางในฐานข้อมูลที่ migrate ครบแล้ว
 *
 * @return array<string,list<string>>
 */
function schemaColumns(): array
{
    static $columns = null;

    if ($columns !== null) {
        return $columns;
    }

    $db = migratedDb();
    $columns = [];

    foreach ($db->all("SELECT name FROM sqlite_master WHERE type = 'table'") as $row) {
        $table = (string) $row['name'];
        $columns[$table] = array_map(
            static fn (array $info): string => (string) $info['name'],
            $db->all('PRAGMA table_info("' . $table . '")'),
        );
    }

    return $columns;
}

/**
 * ทุกก้อนข้อความในซอร์สที่หน้าตาเป็น SQL — ทั้งที่ใช้ ' และ "
 *
 * @return list<string>
 */
function sqlLiterals(string $source): array
{
    // คอมเมนต์ออกก่อน — คำอธิบายที่พูดถึงคอลัมน์เก่าไม่ควรทำให้เทสต์แดง
    $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $source);

    preg_match_all(
        '~([\'"])((?:(?!\1).)*?\b(?:SELECT|FROM|JOIN|UPDATE|DELETE)\b(?:(?!\1).)*?)\1~is',
        $code,
        $matches,
    );

    return $matches[2];
}

test('ทุกคอลัมน์ที่ SQL อ้างถึงต้องมีอยู่จริงในสคีมาหลัง migration', static function (): void {
    $columns = schemaColumns();
    $problems = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PHPCP_ROOT . '/src'));

    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        foreach (sqlLiterals((string) file_get_contents($file->getPathname())) as $sql) {
            /*
             * ชื่อย่อของตารางในก้อนนี้ — **หนึ่งชื่อย่อชี้ได้หลายตาราง**
             *
             * subquery ใช้ชื่อย่อซ้ำกับชั้นนอกได้ตามปกติ (`FROM cron_jobs c` ในก้อน
             * เดียวกับ `FROM certificates c`) · ถ้าเก็บแค่ตารางล่าสุดจะฟ้องผิดทันที
             */
            $aliases = [];

            preg_match_all(
                '~\b(?:FROM|JOIN)\s+([A-Za-z_]\w*)\s*(?:\bAS\s+)?([A-Za-z_]\w*)?~i',
                $sql,
                $sources,
                PREG_SET_ORDER,
            );

            foreach ($sources as $found) {
                $table = $found[1];

                // ตารางชั่วคราวหรือ subquery ที่ไม่รู้จัก — ตรวจไม่ได้ ข้ามไป
                if (!isset($columns[$table])) {
                    continue;
                }

                $alias = $found[2] ?? '';

                // `FROM sites WHERE ...` — คำถัดไปเป็นคำสั่ง ไม่ใช่ชื่อย่อ
                $keywords = ['ON', 'WHERE', 'SET', 'LEFT', 'INNER', 'CROSS', 'JOIN', 'GROUP', 'ORDER', 'LIMIT', 'VALUES', 'UNION'];

                if ($alias === '' || in_array(strtoupper($alias), $keywords, true)) {
                    $alias = $table;
                }

                $aliases[$alias][] = $table;
            }

            if ($aliases === []) {
                continue;
            }

            preg_match_all('~\b([A-Za-z_]\w*)\.([A-Za-z_]\w*)\b~', $sql, $references, PREG_SET_ORDER);

            foreach ($references as [$reference, $alias, $column]) {
                if (!isset($aliases[$alias])) {
                    continue;
                }

                foreach ($aliases[$alias] as $table) {
                    if (in_array($column, $columns[$table], true)) {
                        continue 2;
                    }
                }

                $problems[] = str_replace(PHPCP_ROOT . '/', '', $file->getPathname())
                    . ' อ้าง ' . $reference . ' (ตาราง ' . implode('/', $aliases[$alias]) . ')';
            }
        }
    }

    assertSame(
        [],
        array_values(array_unique($problems)),
        'คอลัมน์นี้ไม่มีอยู่แล้ว — migration ลบไปแต่ query ยังอ้างอยู่ ผลคือ capability ตายบนเครื่องจริง',
    );
});

test('คอลัมน์ที่ migration 0021 ลบต้องไม่เหลืออยู่จริง ๆ', static function (): void {
    // ยืนยันว่าเทสต์ข้างบนกำลังเทียบกับสคีมาที่ลบคอลัมน์ไปแล้วจริง ไม่ใช่สคีมาเก่า
    $columns = schemaColumns();

    assertTrue(!in_array('name', $columns['sites'], true), 'sites.name ต้องถูกลบไปแล้ว');
    assertTrue(!in_array('display_name', $columns['users'], true), 'users.display_name ต้องถูกลบไปแล้ว');
});

test('capability ที่พังต้องรายงานว่าโค้ดพัง ไม่ใช่ว่า agent ไม่พร้อม', static function (): void {
    /*
     * รหัส `internal_error` เคยตกไปที่ `default` ของ `Client::exceptionFor()` ซึ่งคือ
     * `TransportError` · หน้าเว็บจึงขึ้น AGENT_UNAVAILABLE (503 "ลองใหม่แล้วหายได้")
     * ตอนที่ SQL อ้างคอลัมน์ที่ถูกลบไปแล้ว — ผู้ดูแลไปไล่ socket กับรีสตาร์ต agent
     * ทั้งที่ agent ไม่เคยมีปัญหา และลองใหม่กี่ครั้งก็ได้ผลเหมือนเดิม
     */
    $reflection = new ReflectionMethod(Phpcp\Agent\Client::class, 'exceptionFor');
    $reflection->setAccessible(true);

    $crashed = $reflection->invoke(null, 'internal_error', 'เกิดข้อผิดพลาดภายในระบบ');

    assertSame(
        Phpcp\Agent\InternalError::class,
        $crashed::class,
        'โค้ดใน agent พัง ต้องได้ exception ของตัวเอง ไม่ใช่ TransportError',
    );

    assertSame(
        500,
        Phpcp\Http\ApiProblem::fromAgentException($crashed)->status(),
        '500 ไม่ใช่ 503 — "ลองใหม่" ไม่ใช่คำแนะนำที่ถูกต้องเมื่อโค้ดเป็นฝ่ายพัง',
    );

    // ติดต่อ agent ไม่ได้จริง ๆ ยังต้องเป็น 503 เหมือนเดิม — นั่นคือกรณีที่ลองใหม่แล้วหายได้
    assertSame(
        503,
        Phpcp\Http\ApiProblem::fromAgentException(new Phpcp\Agent\TransportError('agent ไม่ตอบ'))->status(),
        'ปัญหาชั่วคราวของการเชื่อมต่อต้องยังเป็น 503',
    );
});
