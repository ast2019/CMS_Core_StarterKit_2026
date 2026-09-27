<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\NotifyFrontendOfChange;
use App\Models\Category;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\Page;
use Illuminate\Database\Eloquent\Model;

/**
 * Dispatches a frontend webhook when something with a public URL changes.
 *
 * Separate from DeliveryCacheObserver even though both watch the same writes, because
 * they answer to different failure modes: busting a cache tag is local, synchronous and
 * cannot fail, while calling a third party is queued, retried and may be switched off
 * entirely. Folding the second into the first would put a queue dispatch inside a method
 * whose contract is "this always works".
 *
 * WHICH MODELS. Only the four that have public URLs, which is the same set UrlBuilder
 * knows how to route. A MediaAsset or a Setting change also alters what the API returns,
 * but there is no page for it — a frontend cannot revalidate "the alt text" — so those
 * are left to the cache observer and the frontend's own max-age.
 */
class FrontendWebhookObserver
{
    /**
     * @var list<class-string<Model>>
     */
    public const WATCHED = [
        Content::class,
        Page::class,
        Gallery::class,
        Category::class,
    ];

    public function saved(Model $model): void
    {
        $this->notify($model, 'saved');
    }

    public function deleted(Model $model): void
    {
        $this->notify($model, 'deleted');
    }

    /**
     * Restoring brings a page back, which the frontend must rebuild just as it had to
     * when the page went away.
     */
    public function restored(Model $model): void
    {
        $this->notify($model, 'restored');
    }

    private function notify(Model $model, string $event): void
    {
        /*
         * Checked BEFORE dispatching, so a deployment with no webhook configured — the
         * default — does not queue a job per editor save that will only discard itself.
         * The job re-checks, because configuration can change while work is queued.
         */
        if (! NotifyFrontendOfChange::isConfigured()) {
            return;
        }

        NotifyFrontendOfChange::dispatch($model::class, $model->getKey(), $event);
    }
}
