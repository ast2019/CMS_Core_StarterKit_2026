<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Redirect;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One redirect, already collapsed to its final destination.
 *
 * Requirement 7.5, and RULE #3 — the OpenAPI document is derived from resource
 * classes, so a redirect payload assembled inline in the controller would be
 * undocumented and therefore incomplete work.
 *
 * Shared by both redirect endpoints (the by-path lookup and the full export) so a
 * frontend parses one shape. Deliberately NOT exposed: `hits`, `last_hit_at`,
 * `created_by` and the `source` morph. Those are editorial and operational data — who
 * created a redirect and how often it fires is nobody's business on a public endpoint,
 * and a hit count is a cheap traffic oracle.
 *
 * @mixin Redirect
 */
class RedirectResource extends JsonResource
{
    private ?string $destination = null;

    private ?int $statusCode = null;

    private int $hops = 1;

    /**
     * Attach the COLLAPSED destination resolved by RedirectResolver.
     *
     * Passed in rather than computed here because collapsing needs the whole redirect
     * map, and a resource that fetched it per row would turn a 100-row export into 100
     * cache reads. The row's own `to_path` remains the fallback for a caller that has
     * not resolved anything, so the resource is never wrong — only less useful.
     */
    public function collapsedTo(string $destination, int $statusCode, int $hops): self
    {
        $this->destination = $destination;
        $this->statusCode = $statusCode;
        $this->hops = $hops;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $to = $this->destination ?? $this->to_path;

        return [
            'from' => $this->from_path,
            'to' => $to,

            // The HTTP status the frontend must emit, not the enum name: 301 and 302
            // are the contract, and `permanent` would make every consumer write the
            // same mapping table.
            'status' => $this->statusCode ?? $this->type->statusCode(),

            /*
             * How many stored redirects were traversed to reach `to`. 1 means this row
             * pointed straight at the destination. Exposed because it is the only way a
             * frontend can tell that `to` is not the `to_path` an editor typed, which
             * otherwise looks like a bug when someone compares the API against the
             * panel.
             */
            'hops' => $this->hops,

            /*
             * Whether the frontend should carry the incoming query string across.
             * False when the destination defines its own, so that a deliberate
             * `?sort=latest` is not overwritten by the visitor's `?sort=oldest`.
             * Stated in the payload rather than left to each frontend to re-derive,
             * because it is a rule of this redirect engine, not a rendering choice.
             */
            'preserve_query' => ! str_contains($to, '?'),
        ];
    }
}
