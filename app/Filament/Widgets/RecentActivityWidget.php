<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Support\Dates\LocalizedDate;
use Carbon\CarbonInterface;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * The last few audited writes, on the landing page.
 *
 * Not a replacement for the AuditLog page — this has no filters, no diff modal and
 * no pagination beyond the first few rows. It exists so that "what changed while I
 * was away" is answerable without navigating anywhere, which is the question an
 * editor opens the panel with.
 *
 * Admin-only, like the page it summarises: `audit.view` is granted to Admin alone
 * in the D-10 matrix, and a widget is not a reason to widen that.
 */
class RecentActivityWidget extends TableWidget
{
    protected static ?int $sort = 40;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('audit.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('cms.dashboard.recent_activity'))
            ->query(fn (): Builder => Activity::query()->with(['causer', 'subject']))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('cms.audit.when'))
                    ->formatStateUsing(fn (?CarbonInterface $state): ?string => LocalizedDate::format($state)),

                TextColumn::make('causer.name')
                    ->label(__('cms.audit.who'))
                    // A queued job or console command has no causer; naming the
                    // system beats an empty cell that reads as missing data.
                    ->placeholder(__('cms.audit.system')),

                TextColumn::make('event')
                    ->label(__('cms.audit.event'))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'created', 'published' => 'success',
                        'updated' => 'info',
                        'deleted' => 'danger',
                        'archived' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('subject_type')
                    ->label(__('cms.audit.subject'))
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? '—'
                        : class_basename($state)),
            ])
            // Ordered by the raw UTC column: the Persian rendering above is
            // presentation, and sorting on it would order rows by the text of a
            // date rather than by when things happened.
            ->defaultSort('created_at', 'desc')
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5);
    }
}
