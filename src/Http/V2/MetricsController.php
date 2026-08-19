<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Agent\AgentException;
use Phpcp\Domain\MetricsHistoryRepository;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * The machine's resource numbers — `/api/v2/metrics`
 *
 * `GET /metrics` reads the current value once · `GET /metrics/stream` is an SSE
 * feed sending a new value every 2 seconds — the **second exception to "every
 * endpoint answers JSON"**, after `files/download` — SSE is `text/event-stream`
 * per its own standard, and the browser reconnects automatically when it drops,
 * which is exactly why it was chosen over WebSocket in the first place (every
 * event sent out is still JSON regardless)
 *
 * `GET /metrics/history` reads historical values from the `metrics_history`
 * table (phase E6) — doesn't go through the agent, because it's reading the
 * panel's own table, not touching the machine (same as the audit log)
 */
final class MetricsController extends ApiController
{
    private const INTERVAL_SECONDS = 2;
    private const MAX_DURATION = 1800;     // 30 minutes, then let the browser reconnect on its own

    /**
     * Selectable range => [how many seconds back, one graph point's width in seconds, label format]
     *
     * **The point count is decided here, not left for the graph to trim** —
     * `GraphComponent` only draws `data.slice(0, maxDataPoints)`, which is **the
     * first points of the set, not the most recent** · sending all 1,440 per-minute
     * points of a 24-hour range would make the graph draw only the first 20
     * minutes of the set and sit stuck there the whole time (looks like the graph
     * "isn't moving" even though live data keeps arriving), and every range's
     * labels would look identical, since they're always the set's earliest
     * minutes no matter which range was chosen
     *
     * A side effect is the response shrinking from thousands of points down to
     * dozens — but the main reason is **one point per genuinely readable time
     * span**, which is the one thing that makes the range choice meaningful
     *
     * @var array<string,array{0:int,1:int,2:string}>
     */
    private const RANGES = [
        /*
         * The one range that combines nothing at all — one point is one row
         * `metrics.record` wrote, so its value is a reading, not a peak
         *
         * Exists to answer "how is the machine right now," which longer ranges
         * can't: they state the worst moment of each interval but not the shape
         * of the last few minutes · the live number bar up top can only say
         * "right now," not that it spiked and came back down ten minutes ago
         */
        '20m' => [1200, 60, 'H:i'],            // 20 points · every minute (raw resolution)
        '1h' => [3600, 300, 'H:i'],            // 12 points · every 5 minutes
        '6h' => [21600, 1800, 'H:i'],          // 12 points · every half hour
        '24h' => [86400, 3600, 'H:i'],         // 24 points · every hour
        '7d' => [604800, 43200, 'j/n H:i'],    // 14 points · every half day
        '30d' => [2592000, 86400, 'j/n'],      // 30 points · every day
        '1y' => [31536000, 2592000, 'M'],      // 12 points · every 30 days
    ];

    public function index(Request $request): Response
    {
        return $this->ok($this->agent()->data('system.metrics', [], $this->ctx->actor($request)));
    }

    /**
     * Historical values for the graph — PLAN-V2 phase E6
     *
     * Picks the resolution tier itself based on the requested range
     * (minute/hour/day), so the screen only sends `range` and never needs to know
     * how the data is bucketed — if the screen chose it itself, the day the
     * bucketing policy changes would mean chasing down every call site
     */
    public function history(Request $request): Response
    {
        $range = (string) $request->get('range', '24h');

        if (!isset(self::RANGES[$range])) {
            return $this->problem(
                ApiProblem::ValidationError,
                $this->t('Unknown range') . ' — ' . $this->t('Allowed: ') . implode(', ', array_keys(self::RANGES)),
                ['range' => 'The value sent is not in the list'],
            );
        }

        [$seconds, $step, $format] = self::RANGES[$range];
        $bucket = MetricsHistoryRepository::bucketForRange($seconds);
        $since = time() - $seconds;

        $repository = new MetricsHistoryRepository($this->app->db());
        $rows = $repository->summarise($repository->range($bucket, $since), $step);

        // **The graph draws peaks, not averages** — it exists to answer "when was
        // something wrong," and an average erases exactly that: a 7-day point
        // covers 12 hours, so a machine pinned at 100% for twenty minutes reads
        // as a 3% bump · the averages stay recorded (`summarise()` still returns
        // them) but nothing asks for them, because "the machine averaged 9% this
        // week" answers a question nobody opened this screen to ask
        //
        // Except when one point IS one stored sample: there is no interval to
        // take a maximum over, so the value is the reading itself and calling it
        // a peak would be a lie about what was measured
        $peaks = $step > MetricsHistoryRepository::BUCKETS[$bucket][0];

        return $this->ok(
            $this->toSeries($rows, $format, $peaks),
            [
                'range' => $range,
                'bucket' => $bucket,
                'since' => $since,
                // One point's width — the screen uses this to explain to the user what average they're looking at
                'step' => $step,
                'points' => count($rows),
                // Says whether the values are peaks or single readings, so the
                // screen can state which without re-deriving the rule from `step`
                'peak' => $peaks,
                // The screen uses this to tell the user why the graph is empty, instead of just showing a blank box
                'collecting' => $rows === [],
            ],
        );
    }

    /**
     * Convert database rows into the `[{name, data:[{label, value}]}]` shape
     *
     * **This is the shape Now.js's `GraphComponent` reads directly via `data-url`**
     * — so a template can declare a graph with attributes alone, no data-converting
     * JS needed, per `js/pages.js`'s rule that "nothing may exist that a
     * data-attribute could do instead"
     *
     * **Not REST tied to any one chart library** — `{name, data:[{label,value}]}`
     * is the generic "several labeled series" shape, and it's the contract this
     * project's framework already uses on every page (see adminframework's
     * `api/v1/quarterly-sales`) · a caller wanting raw numbers can still read
     * `value` directly, and `meta` states the full context
     *
     * @param list<array<string,mixed>> $rows
     * @param bool $peaks draw each interval's maximum — false only when a point covers exactly one stored sample
     * @return list<array{name:string,data:list<array{label:string,value:float}>}>
     */
    private function toSeries(array $rows, string $format, bool $peaks = false): array
    {
        // The label format comes with the chosen range, not the storage tier —
        // the same tier serves several ranges (7 days and 30 days both read from
        // the hourly tier, but need different-looking labels)
        $labels = array_map(
            static fn (array $row): string => date($format, (int) $row['bucket_at']),
            $rows,
        );

        $series = [];

        foreach ([
            ['CPU', 'cpu'],
            ['Memory', 'memory'],
            ['Disk', 'disk'],
        ] as [$name, $metric]) {
            // The name says which of the two it is — the legend is the only place
            // a reader can find out, and a peak line labelled plainly "CPU" would
            // have them reading a worst case as if it were the usual state
            $column = $peaks ? $metric . '_peak' : $metric . '_percent';
            $points = [];

            foreach ($rows as $index => $row) {
                $points[] = ['label' => $labels[$index], 'value' => round((float) $row[$column], 1)];
            }

            $series[] = [
                'name' => $peaks
                    ? $this->t('{metric} (peak)', ['metric' => $this->t($name)])
                    : $this->t($name),
                'data' => $points,
            ];
        }

        return $series;
    }

    /**
     * Stream live values — moved from `Controller\Api\StreamController` when the HTML UI was removed
     *
     * Easy-to-miss things, all already handled: close the output buffer before
     * starting · check the browser is still there every round (otherwise a stale
     * connection permanently holds a PHP-FPM slot) · stop on its own after three
     * consecutive agent failures · force-close at 30 minutes and let the browser
     * reconnect itself, which is the very capability that made SSE the choice
     * over WebSocket in the first place
     */
    public function stream(Request $request): Response
    {
        $actor = $this->ctx->actor($request);
        $agent = $this->agent();

        // Headers are sent by hand because the stream must start before the
        // request cycle ends, so a normal Response can't be used
        header('Content-Type: text/event-stream; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');    // turns off nginx's buffering, if a proxy sits in between
        header('Connection: keep-alive');

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        // The browser uses this value to decide when to reconnect if it drops
        echo 'retry: 5000' . "\n\n";
        flush();

        $deadline = time() + self::MAX_DURATION;
        $failures = 0;

        while (time() < $deadline) {
            // The browser's tab is already closed — release the FPM slot immediately
            if (connection_aborted() === 1) {
                break;
            }

            try {
                $data = $agent->data('system.metrics', [], $actor);
                $failures = 0;

                $this->send('metrics', $data);
            } catch (AgentException $e) {
                $failures++;
                $this->send('error', ['message' => $e->getMessage()]);

                // The agent has failed several times in a row — stop the stream and
                // let the browser reconnect on its own, better than looping against
                // a dead socket every 2 seconds forever
                if ($failures >= 3) {
                    break;
                }
            }

            sleep(self::INTERVAL_SECONDS);
        }

        $this->send('bye', ['reason' => $this->t('The connection timed out — please reload the page')]);

        // Answers with an empty response, since the content was all sent during the stream
        return Response::noContent();
    }

    /** @param array<string,mixed> $data */
    private function send(string $event, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        echo 'event: ' . $event . "\n";
        echo 'data: ' . ($json === false ? '{}' : $json) . "\n\n";

        flush();
    }
}
