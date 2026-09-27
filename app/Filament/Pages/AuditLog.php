<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\PanelNavigationGroup;
use App\Support\Dates\LocalizedDate;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

/**
 * RULE #8 — the audit trail, made readable.
 *
 * Requirements 9.5, 9.6.
 *
 * Admin-only (the `audit.view` ability is granted to Admin alone in the D-10
 * matrix). Deliberately read-only: there is no edit or delete action anywhere on
 * this page, because an audit trail an administrator can alter is not evidence of
 * anything. Even a "tidy up old entries" bulk action would undermine the point,
 * so pruning is not offered at all.
 */
class AuditLog extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 55;

    protected string $view = 'filament.pages.audit-log';

    public static function getNavigationLabel(): string
    {
        return __('cms.audit.title');
    }

    public function getTitle(): string
    {
        return __('cms.audit.title');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return PanelNavigationGroup::System;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('audit.view') ?? false;
    }

    /**
     * Every event this log records, value => label (item 55).
     *
     * The filter used to list five events by their raw English names and knew nothing of `restored`
     * or `destroyed` — events the trash introduced — so an auditor had no way to filter for "what was
     * permanently deleted", the one question the trash makes worth asking.
     *
     * @return array<string, string>
     */
    public static function eventLabels(): array
    {
        $labels = [];

        foreach (['created', 'updated', 'deleted', 'restored', 'destroyed', 'published', 'archived', 'denied'] as $event) {
            $labels[$event] = __("cms.audit.events.{$event}");
        }

        return $labels;
    }

    /**
     * The label for one event, falling back to the raw name for an event nothing here knows about —
     * a package writing its own, say — so the column shows something true rather than nothing.
     */
    public static function eventLabel(?string $event): ?string
    {
        if ($event === null) {
            return null;
        }

        return self::eventLabels()[$event] ?? $event;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Activity::query()->with(['causer', 'subject']))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('cms.audit.when'))
                    // Seconds are kept: this is the forensic view, and two writes
                    // in the same minute have to be orderable by eye.
                    ->formatStateUsing(fn (?CarbonInterface $state): ?string => LocalizedDate::format(
                        $state,
                        'date_time_seconds',
                    ))
                    ->sortable(),

                TextColumn::make('causer.name')
                    ->label(__('cms.audit.who'))
                    // A system-initiated write (a queued job, a console command)
                    // has no causer. Saying so is better than an empty cell that
                    // reads as missing data.
                    ->placeholder(__('cms.audit.system'))
                    ->searchable(),

                TextColumn::make('event')
                    ->label(__('cms.audit.event'))
                    ->formatStateUsing(fn (?string $state): ?string => self::eventLabel($state))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'created', 'published', 'restored' => 'success',
                        'updated' => 'info',
                        // A trash is reversible; a destruction is not. Colouring them alike would
                        // hide the one distinction an auditor reads this column for.
                        'deleted', 'archived' => 'warning',
                        'destroyed', 'denied' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('subject_type')
                    ->label(__('cms.audit.subject'))
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? '—'
                        : class_basename($state))
                    ->description(fn (Activity $record): ?string => $record->subject_id === null
                        ? null
                        : '#'.$record->subject_id),

                TextColumn::make('description')
                    ->label(__('cms.audit.description'))
                    ->limit(60)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label(__('cms.audit.event'))
                    ->options(fn (): array => self::eventLabels()),

                SelectFilter::make('subject_type')
                    ->label(__('cms.audit.subject'))
                    ->options(fn (): array => Activity::query()
                        ->whereNotNull('subject_type')
                        ->distinct()
                        ->pluck('subject_type', 'subject_type')
                        ->map(fn (string $type): string => class_basename($type))
                        ->all()),

                Filter::make('denials')
                    ->label(__('cms.audit.denials'))
                    // Requirement 9.2 — authorisation denials are logged. Surfacing
                    // them as a filter is what makes them useful: a burst of
                    // denials on one account is the signal worth noticing.
                    ->query(fn (Builder $query): Builder => $query->where('event', 'denied')),
            ])
            ->recordActions([
                Action::make('changes')
                    ->label(__('cms.audit.view_changes'))
                    ->icon('heroicon-o-arrows-right-left')
                    ->modalHeading(__('cms.audit.changes_heading'))
                    ->modalSubmitAction(false)
                    ->modalContent(fn (Activity $record) => view('filament.pages.audit-changes', [
                        'activity' => $record,
                        'old' => $record->properties['old'] ?? [],
                        'new' => $record->properties['attributes'] ?? [],
                    ])),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }
}
