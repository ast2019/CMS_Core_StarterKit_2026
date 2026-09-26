<?php

declare(strict_types=1);

namespace App\Filament\Pages;

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

    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.pages.audit-log';

    public static function getNavigationLabel(): string
    {
        return __('cms.audit.title');
    }

    public function getTitle(): string
    {
        return __('cms.audit.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('cms.nav.system');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('audit.view') ?? false;
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
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'info',
                        'deleted' => 'danger',
                        'published' => 'success',
                        'archived' => 'warning',
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
                    ->options([
                        'created' => 'created',
                        'updated' => 'updated',
                        'deleted' => 'deleted',
                        'published' => 'published',
                        'archived' => 'archived',
                    ]),

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
