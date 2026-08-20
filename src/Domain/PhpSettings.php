<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\ValidationError;

/**
 * The PHP values an admin is allowed to change from the web page
 *
 * ## Why this class exists at all
 *
 * Every one of these values used to be a literal inside a template file, so the
 * only way to change `upload_max_filesize` for one customer was to edit
 * `templates/fpm/pool.conf.tpl` — which changes it for **everyone on the
 * machine**, and gets overwritten by the next update. Three of them
 * (`memory_limit`, `upload_max_filesize`, `pm.max_children`) even existed as
 * properties on {@see Site}, but {@see Site::fromRow()} never read them from any
 * column, so they could only ever hold their default — a setting that looked
 * configurable in the code and was not configurable at all in reality.
 *
 * ## Why these values and not "every ini directive"
 *
 * Every directive here is one that a real hosting customer asks for by name
 * (a big upload, a slow import, a form with hundreds of fields). What is
 * deliberately **not** here is `open_basedir` and `disable_functions`: those two
 * lines are the entire boundary between one customer and the next (ARCHITECTURE
 * §11), and a screen that can widen them is a screen that can hand one customer
 * another customer's files. They stay literals in the template where nothing but
 * a code change can reach them.
 *
 * ## The two places these values land
 *
 *   per hosting account  columns on `users`, written into that account's FPM
 *                        pool for every PHP version it uses
 *   the panel itself     `panel.php.*` in the settings table, patched into the
 *                        panel's own pool and its Apache `LimitRequestBody`
 *
 * Both go through this one class, so "what is a legal value" is answered in a
 * single place and cannot drift between the two screens.
 */
final readonly class PhpSettings
{
    /**
     * Every field, with the rule for its value
     *
     * The key is the name used **everywhere**: the API field, the `php_`-prefixed
     * column on `users`, and the `panel.php.` settings key. One name end to end
     * means a field added here needs no lookup table anywhere else.
     *
     * `min`/`max` are deliberately wide. This is an admin-only screen on the
     * admin's own machine — the job of a limit here is to catch a typo (a stray
     * zero, a negative) and to keep a value inside what the software behind it
     * can actually hold, not to second-guess how much RAM the admin bought.
     *
     * @var array<string,array{kind:string,default:int|string,min?:int,max?:int,unit:string,ini:string}>
     */
    public const FIELDS = [
        'memory_limit_mb' => [
            'kind' => 'int', 'default' => 256, 'min' => 16, 'max' => 16384,
            'unit' => 'MB', 'ini' => 'memory_limit',
        ],
        'upload_max_mb' => [
            /*
             * 2048 MB is not an arbitrary round number — Apache's
             * `LimitRequestBody` is a signed 32-bit byte count, so anything past
             * 2 GB stops meaning what it says · nginx would take more, but a
             * limit that behaves differently depending on which web server the
             * machine happens to run is worse than one honest ceiling
             */
            'kind' => 'int', 'default' => 64, 'min' => 1, 'max' => 2048,
            'unit' => 'MB', 'ini' => 'upload_max_filesize',
        ],
        'post_max_mb' => [
            'kind' => 'int', 'default' => 64, 'min' => 1, 'max' => 2048,
            'unit' => 'MB', 'ini' => 'post_max_size',
        ],
        'max_execution_time' => [
            // 0 = no limit, which is PHP's own meaning for it — kept, because a
            // long import is exactly the job people need it for
            'kind' => 'int', 'default' => 120, 'min' => 0, 'max' => 86400,
            'unit' => 'seconds', 'ini' => 'max_execution_time',
        ],
        'max_input_time' => [
            // -1 = follow max_execution_time, PHP's own meaning again
            'kind' => 'int', 'default' => 120, 'min' => -1, 'max' => 86400,
            'unit' => 'seconds', 'ini' => 'max_input_time',
        ],
        'max_input_vars' => [
            'kind' => 'int', 'default' => 3000, 'min' => 100, 'max' => 1000000,
            'unit' => '', 'ini' => 'max_input_vars',
        ],
        'max_file_uploads' => [
            'kind' => 'int', 'default' => 20, 'min' => 1, 'max' => 1000,
            'unit' => '', 'ini' => 'max_file_uploads',
        ],
        'session_lifetime' => [
            'kind' => 'int', 'default' => 1440, 'min' => 60, 'max' => 604800,
            'unit' => 'seconds', 'ini' => 'session.gc_maxlifetime',
        ],
        'display_errors' => [
            'kind' => 'bool', 'default' => 0, 'unit' => '', 'ini' => 'display_errors',
        ],
        'allow_url_fopen' => [
            'kind' => 'bool', 'default' => 0, 'unit' => '', 'ini' => 'allow_url_fopen',
        ],
        'timezone' => [
            // Empty = don't write the directive at all, so PHP keeps using the
            // machine's own timezone · writing an empty date.timezone would make
            // every date call warn, which is worse than not setting it
            'kind' => 'timezone', 'default' => '', 'unit' => '', 'ini' => 'date.timezone',
        ],
        'max_children' => [
            // FPM, not PHP — but it belongs on the same screen, because it is the
            // other half of the answer to "why did this site get slow"
            'kind' => 'int', 'default' => 5, 'min' => 1, 'max' => 500,
            'unit' => '', 'ini' => '',
        ],
    ];

    /** Fields whose ini directive is a flag (on/off), not a value */
    private const FLAGS = ['display_errors', 'allow_url_fopen'];

    /**
     * How much longer FPM waits before killing a worker than PHP waits before
     * giving up on its own
     *
     * PHP's own timeout produces a fatal error with a stack trace in the log;
     * FPM's produces a 502 with nothing to explain it. Deriving one from the
     * other means raising `max_execution_time` genuinely raises the time
     * available — previously `request_terminate_timeout` was a hardcoded 120s, so
     * a longer `max_execution_time` bought nothing at all and the failure came
     * back as a blank 502.
     */
    private const TERMINATE_GRACE = 30;

    public function __construct(
        public int $memoryLimitMb = 256,
        public int $uploadMaxMb = 64,
        public int $postMaxMb = 64,
        public int $maxExecutionTime = 120,
        public int $maxInputTime = 120,
        public int $maxInputVars = 3000,
        public int $maxFileUploads = 20,
        public int $sessionLifetime = 1440,
        public bool $displayErrors = false,
        public bool $allowUrlFopen = false,
        public string $timezone = '',
        public int $maxChildren = 5,
    ) {
    }

    /** What a hosting account gets when nobody has chosen anything */
    public static function defaults(): self
    {
        return new self();
    }

    /**
     * What the panel's own pool ships with — smaller, and deliberately so
     *
     * The panel runs `pm = static`, so its children are all alive all the time
     * and `memory_limit × max_children` is a ceiling the machine has to be able
     * to hold at once · a customer's pool is `ondemand` and mostly idle.
     *
     * These numbers must stay identical to the literals in
     * `templates/panel/panel-pool.conf.tpl` and `templates/panel/httpd.conf.tpl`,
     * because those files are what a machine actually runs until an admin
     * changes something — {@see \Phpcp\Driver\Php\PanelPhpTuning} is what keeps
     * the two honest, and a test pins them together.
     */
    public static function panelDefaults(): self
    {
        return new self(
            memoryLimitMb: 128,
            uploadMaxMb: 32,
            postMaxMb: 32,
            maxExecutionTime: 120,
            maxInputTime: 120,
            maxChildren: 4,
        );
    }

    /**
     * Reads whichever fields are present, keeping the rest of `$base`
     *
     * A missing key means "not sent, so not changed" — never "reset to default."
     * That distinction is the whole reason a PATCH-shaped form can exist for
     * these at all.
     *
     * @param array<string,mixed> $values
     */
    public static function fromArray(array $values, ?self $base = null): self
    {
        $base ??= self::defaults();
        $current = $base->toArray();
        $clean = [];

        foreach (self::FIELDS as $field => $rule) {
            $given = $values[$field] ?? null;

            $clean[$field] = $given === null || $given === ''
                // An empty string is a real value for the timezone (= unset it),
                // but for a number it is an untouched form field
                ? ($rule['kind'] === 'timezone' && array_key_exists($field, $values) ? '' : $current[$field])
                : self::assertValue($field, $given);
        }

        return self::build($clean);
    }

    /**
     * The values as stored on a `users` row (`php_memory_limit_mb`, …)
     *
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row, string $prefix = 'php_'): self
    {
        $values = [];

        foreach (array_keys(self::FIELDS) as $field) {
            $key = $prefix . $field;

            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $values[$field] = $row[$key];
            }
        }

        // Never validated on the way out of the database — a row written before
        // a limit was tightened must still load, or the account becomes
        // unreachable from the screen that could fix it
        return self::relaxed($values);
    }

    /**
     * The values as stored in the settings table (`panel.php.memory_limit_mb`, …)
     *
     * @param array<string,string> $settings
     */
    public static function fromSettings(array $settings, string $prefix = 'panel.php.'): self
    {
        $values = [];

        foreach (array_keys(self::FIELDS) as $field) {
            if (array_key_exists($prefix . $field, $settings)) {
                $values[$field] = $settings[$prefix . $field];
            }
        }

        return self::relaxed($values, self::panelDefaults());
    }

    /** @return array<string,int|string> field => value, ready for the API or for storage */
    public function toArray(): array
    {
        return [
            'memory_limit_mb' => $this->memoryLimitMb,
            'upload_max_mb' => $this->uploadMaxMb,
            'post_max_mb' => $this->postMaxMb,
            'max_execution_time' => $this->maxExecutionTime,
            'max_input_time' => $this->maxInputTime,
            'max_input_vars' => $this->maxInputVars,
            'max_file_uploads' => $this->maxFileUploads,
            'session_lifetime' => $this->sessionLifetime,
            'display_errors' => $this->displayErrors ? 1 : 0,
            'allow_url_fopen' => $this->allowUrlFopen ? 1 : 0,
            'timezone' => $this->timezone,
            'max_children' => $this->maxChildren,
        ];
    }

    /**
     * The same values keyed by column name, for writing a `users` row
     *
     * @return array<string,int|string>
     */
    public function toColumns(string $prefix = 'php_'): array
    {
        $columns = [];

        foreach ($this->toArray() as $field => $value) {
            $columns[$prefix . $field] = $value;
        }

        return $columns;
    }

    /**
     * The same values keyed by settings key, for the panel's own values
     *
     * @return array<string,string>
     */
    public function toSettings(string $prefix = 'panel.php.'): array
    {
        $values = [];

        foreach ($this->toArray() as $field => $value) {
            $values[$prefix . $field] = (string) $value;
        }

        return $values;
    }

    /**
     * ini directive => the value to write, in the order they should appear
     *
     * A flag's value is the literal `on`/`off` FPM expects for `php_admin_flag`;
     * everything else is for `php_admin_value`. `date.timezone` is absent
     * entirely when it has not been set — see the note on that field.
     *
     * @return array<string,string>
     */
    public function iniDirectives(): array
    {
        $values = $this->toArray();
        $directives = [];

        foreach (self::FIELDS as $field => $rule) {
            if ($rule['ini'] === '') {
                continue;                       // pm.max_children is not an ini directive
            }

            if ($field === 'timezone') {
                if ($this->timezone !== '') {
                    $directives['date.timezone'] = $this->timezone;
                }

                continue;
            }

            if (in_array($field, self::FLAGS, true)) {
                $directives[$rule['ini']] = $values[$field] === 1 ? 'on' : 'off';

                continue;
            }

            $directives[$rule['ini']] = $rule['unit'] === 'MB'
                ? $values[$field] . 'M'
                : (string) $values[$field];
        }

        return $directives;
    }

    /** Is this ini directive one this screen owns? — used when patching a file that already exists */
    public static function isManagedDirective(string $ini): bool
    {
        foreach (self::FIELDS as $rule) {
            if ($rule['ini'] !== '' && $rule['ini'] === $ini) {
                return true;
            }
        }

        return false;
    }

    /** Whether that directive is written as `php_admin_flag` rather than `php_admin_value` */
    public static function isFlag(string $ini): bool
    {
        foreach (self::FLAGS as $field) {
            if (self::FIELDS[$field]['ini'] === $ini) {
                return true;
            }
        }

        return false;
    }

    /** How long FPM waits before killing the worker — 0 stays 0, meaning never */
    public function requestTerminateTimeout(): int
    {
        return $this->maxExecutionTime === 0 ? 0 : $this->maxExecutionTime + self::TERMINATE_GRACE;
    }

    /**
     * The largest request body the web server in front must let through
     *
     * Always the larger of the two, never just `upload_max_filesize` — a POST
     * carries the file **plus** its form fields, so a web server sized to the
     * file alone answers 413 to an upload PHP would have accepted, and does it
     * before the request ever reaches PHP, where the log that would explain it lives.
     */
    public function bodyLimitMb(): int
    {
        return max($this->uploadMaxMb, $this->postMaxMb);
    }

    /**
     * The rule PHP itself enforces, stated before it can cause confusion
     *
     * `post_max_size` smaller than `upload_max_filesize` is accepted silently by
     * PHP and then quietly caps every upload at the smaller number — the admin
     * sets 512M, sees uploads still fail at 64M, and has nothing to read that
     * says why.
     *
     * @throws ValidationError
     */
    public function assertConsistent(): void
    {
        if ($this->postMaxMb < $this->uploadMaxMb) {
            throw new ValidationError(sprintf(
                'post_max_size (%d MB) must not be smaller than upload_max_filesize (%d MB), otherwise PHP caps every upload at the smaller value without saying so',
                $this->postMaxMb,
                $this->uploadMaxMb,
            ));
        }
    }

    /**
     * Which fields differ, and what each changed from — goes into the audit log
     *
     * @return array<string,array{from:int|string,to:int|string}>
     */
    public function diff(self $previous): array
    {
        $before = $previous->toArray();
        $after = $this->toArray();
        $changes = [];

        foreach ($after as $field => $value) {
            if ($before[$field] !== $value) {
                $changes[$field] = ['from' => $before[$field], 'to' => $value];
            }
        }

        return $changes;
    }

    /**
     * One value, checked against its own rule
     *
     * @throws ValidationError
     */
    public static function assertValue(string $field, mixed $value): int|string
    {
        $rule = self::FIELDS[$field] ?? throw new ValidationError("Unknown PHP setting {$field}");

        if ($rule['kind'] === 'timezone') {
            return self::assertTimezone($value);
        }

        if ($rule['kind'] === 'bool') {
            return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true) ? 1 : 0;
        }

        // Rejected rather than cast — `(int) 'abc'` is 0, and 0 is a legal value
        // for some of these fields, so a typo would save silently as "no limit"
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d{1,9}$/', trim($value)) === 1)) {
            throw new ValidationError(sprintf('%s must be a whole number', self::label($field)));
        }

        $number = (int) $value;

        if ($number < $rule['min'] || $number > $rule['max']) {
            throw new ValidationError(sprintf(
                '%s must be between %d and %d%s',
                self::label($field),
                $rule['min'],
                $rule['max'],
                $rule['unit'] === '' ? '' : ' ' . $rule['unit'],
            ));
        }

        return $number;
    }

    /**
     * A timezone PHP genuinely knows — anything else would make every date call warn
     *
     * @throws ValidationError
     */
    public static function assertTimezone(mixed $value): string
    {
        if (!is_string($value)) {
            throw new ValidationError('Timezone must be a name such as Asia/Bangkok, or empty to follow the machine');
        }

        $timezone = trim($value);

        if ($timezone === '') {
            return '';
        }

        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new ValidationError("Unknown timezone {$timezone} — use a name such as Asia/Bangkok, or leave it empty to follow the machine");
        }

        return $timezone;
    }

    /** The ini directive this field writes — the name an admin already knows it by */
    public static function label(string $field): string
    {
        $ini = self::FIELDS[$field]['ini'] ?? '';

        return $ini !== '' ? $ini : 'pm.' . $field;
    }

    /**
     * Values from storage, clamped rather than rejected
     *
     * @param array<string,mixed> $values
     */
    private static function relaxed(array $values, ?self $base = null): self
    {
        $base ??= self::defaults();
        $current = $base->toArray();
        $clean = [];

        foreach (self::FIELDS as $field => $rule) {
            $given = $values[$field] ?? null;

            if ($given === null) {
                $clean[$field] = $current[$field];

                continue;
            }

            $clean[$field] = match ($rule['kind']) {
                'timezone' => is_string($given) ? trim($given) : '',
                'bool' => in_array($given, [true, 1, '1', 'true', 'on'], true) ? 1 : 0,
                default => max($rule['min'], min($rule['max'], (int) $given)),
            };
        }

        return self::build($clean);
    }

    /** @param array<string,int|string> $values every field present and already checked */
    private static function build(array $values): self
    {
        return new self(
            memoryLimitMb: (int) $values['memory_limit_mb'],
            uploadMaxMb: (int) $values['upload_max_mb'],
            postMaxMb: (int) $values['post_max_mb'],
            maxExecutionTime: (int) $values['max_execution_time'],
            maxInputTime: (int) $values['max_input_time'],
            maxInputVars: (int) $values['max_input_vars'],
            maxFileUploads: (int) $values['max_file_uploads'],
            sessionLifetime: (int) $values['session_lifetime'],
            displayErrors: (int) $values['display_errors'] === 1,
            allowUrlFopen: (int) $values['allow_url_fopen'] === 1,
            timezone: (string) $values['timezone'],
            maxChildren: (int) $values['max_children'],
        );
    }
}
