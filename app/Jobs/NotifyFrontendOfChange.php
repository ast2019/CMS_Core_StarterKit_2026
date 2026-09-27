<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Seo\UrlBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tells the frontend that a record changed, so it can rebuild the affected pages.
 *
 * WHY THIS EXISTS AT ALL.
 *
 * A Next.js frontend caches rendered pages — that is the point of it — and nothing in
 * this Core could tell it when a page stopped being correct. The options without a
 * webhook are both bad: revalidate on a fixed timer, so an editor's correction waits out
 * an interval chosen in advance and unrelated to when anything actually changed, or
 * render every request, throwing away the performance the framework exists to provide.
 * Both are visible to readers, and the second is visible to whoever pays for the
 * servers.
 *
 * Adding HTTP validators (AddDeliveryCacheValidators) made fetching cheap, but cheap is
 * not the same as prompt: a frontend still has to decide to ask. This is what makes it
 * ask at the right moment.
 *
 * WHY IT SENDS URLS AND NOT A PAYLOAD.
 *
 * The body names what changed and where it lives — the public URL per locale — and
 * nothing else. The frontend then re-fetches through the Delivery API, which is the one
 * source of truth for what a record looks like. Pushing content in the webhook would
 * create a second, weaker copy of that API, arriving out of order under retry, with no
 * way to express the locale fallbacks and module gates the real endpoints implement.
 *
 * WHY IT IS SIGNED.
 *
 * The receiver is a public endpoint that triggers work, so an unsigned one is a free
 * cache-purge lever for anybody who finds the URL. The signature is an HMAC over the
 * exact bytes sent, with a timestamp header so a receiver can reject a replayed body.
 * A deployment with no secret configured sends nothing rather than sending unsigned
 * requests — a silent downgrade to no authentication is worse than a feature that is
 * plainly switched off.
 *
 * WHY IT IS QUEUED AND RETRIED.
 *
 * The frontend may be mid-deploy, rate-limiting, or briefly down, and none of that
 * should fail an editor's save or block the request they are waiting on. Retries are
 * spaced because the usual cause of a failure is a deployment in progress, which
 * resolves in minutes rather than seconds.
 */
class NotifyFrontendOfChange implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /**
     * Spaced for the failure that actually happens: the frontend is being redeployed.
     * Seconds would exhaust the budget inside one deploy; this spans about ten minutes.
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 600];

    /**
     * Public readonly rather than private: this is a value object carried to a queue, and
     * what it carries is worth reading — by a test asserting the right event was queued,
     * and by anyone inspecting a failed job.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function __construct(
        public readonly string $modelClass,
        public readonly int|string $modelKey,
        public readonly string $event,
    ) {}

    /**
     * Whether a deployment has configured anywhere to send to.
     *
     * Checked before dispatching as well as here, so a site with no webhook configured —
     * the default — does not queue a job per save that will only discard itself.
     */
    public static function isConfigured(): bool
    {
        return self::endpoints() !== [] && self::secret() !== null;
    }

    /**
     * @return list<string>
     */
    public static function endpoints(): array
    {
        $configured = config('cms.webhooks.endpoints');

        if (! is_string($configured) || trim($configured) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $configured)),
            static fn (string $url): bool => str_starts_with($url, 'http'),
        ));
    }

    public static function secret(): ?string
    {
        $secret = config('cms.webhooks.secret');

        return is_string($secret) && trim($secret) !== '' ? trim($secret) : null;
    }

    public function handle(UrlBuilder $urls): void
    {
        $secret = self::secret();
        $endpoints = self::endpoints();

        /*
         * Re-checked here, not just at dispatch: the job may have been queued before an
         * operator removed the configuration, and sending unsigned is not a fallback.
         */
        if ($secret === null || $endpoints === []) {
            return;
        }

        $payload = $this->payload($urls);

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            // Unencodable payload is a bug in this class, not a transient fault, so it
            // is logged and dropped rather than retried three more times.
            Log::warning('Frontend webhook payload could not be encoded.', [
                'model' => $this->modelClass,
                'key' => $this->modelKey,
            ]);

            return;
        }

        $timestamp = (string) now()->getTimestamp();

        /*
         * The timestamp is inside the signed string, not merely sent beside it. Signing
         * the body alone would let an attacker who captured one request replay it
         * forever with a fresh timestamp header, which is the thing the header is
         * supposed to prevent.
         */
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        foreach ($endpoints as $endpoint) {
            $this->send($endpoint, $body, $timestamp, $signature);
        }
    }

    /**
     * What changed, and where it lives.
     *
     * @return array<string, mixed>
     */
    private function payload(UrlBuilder $urls): array
    {
        $record = $this->modelClass::query()->find($this->modelKey);

        $payload = [
            'event' => $this->event,
            'resource' => $this->resourceName(),
            'id' => $this->modelKey,
            'occurred_at' => now()->toIso8601String(),
            'urls' => [],
        ];

        if ($record === null) {
            /*
             * Hard-deleted between the dispatch and the run. The event is still worth
             * sending — a page that no longer exists is exactly what a frontend needs to
             * stop serving — it just has no URLs left to name.
             */
            return $payload;
        }

        $urlsByLocale = [];

        foreach ((array) config('cms.locales.supported', []) as $locale) {
            if (! is_string($locale)) {
                continue;
            }

            $url = $urls->canonicalFor($record, $locale);

            if ($url !== null) {
                $urlsByLocale[$locale] = $url;
            }
        }

        $payload['urls'] = $urlsByLocale;

        return $payload;
    }

    /**
     * The model's public name, matching the Delivery API's vocabulary.
     *
     * `content` is called `news` on the API (GET /api/v1/news), and a webhook that named
     * it something a frontend developer cannot find in the route list is a puzzle for no
     * reason.
     */
    private function resourceName(): string
    {
        return match (class_basename($this->modelClass)) {
            'Content' => 'news',
            default => strtolower(class_basename($this->modelClass)),
        };
    }

    private function send(string $endpoint, string $body, string $timestamp, string $signature): void
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-CMS-Event' => $this->event,
                'X-CMS-Timestamp' => $timestamp,
                'X-CMS-Signature' => 'sha256='.$signature,
            ])
                ->connectTimeout((int) config('cms.webhooks.connect_timeout', 5))
                ->timeout((int) config('cms.webhooks.timeout', 10))
                ->withBody($body, 'application/json')
                ->post($endpoint);
        } catch (ConnectionException) {
            // Unreachable: the frontend is down or mid-deploy. Throwing hands the job
            // back to the queue, which is what the spaced backoff is for.
            throw new ConnectionException("Frontend webhook endpoint [{$endpoint}] could not be reached.");
        }

        if ($response->successful()) {
            return;
        }

        /*
         * A 4xx other than 429 is a configuration error — a wrong URL, a rejected
         * signature — and will be just as wrong on the fourth attempt, so it is logged
         * once and dropped. Retrying it only delays the operator noticing, and fills the
         * failed_jobs table with a fault no retry can fix.
         */
        if ($response->clientError() && $response->status() !== 429) {
            Log::warning('Frontend webhook was rejected.', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'event' => $this->event,
            ]);

            return;
        }

        // 429 or 5xx: the receiver is asking to be asked again.
        throw new ConnectionException(sprintf(
            'Frontend webhook endpoint [%s] answered %d.',
            $endpoint,
            $response->status(),
        ));
    }
}
