<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContactSubmissions\Tables;

use App\Models\ContactSubmission;
use App\Models\Form;
use App\Support\Dates\LocalizedDate;
use App\Support\Plural;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ContactSubmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * Item 15 — the form column reads each row's form, so it is loaded with the page
             * rather than once per row (PanelQueryBudgetTest).
             */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('form'))
            ->columns([
                IconColumn::make('read_at')
                    ->label(__('cms.field.read_status'))
                    ->boolean()
                    ->trueIcon('heroicon-o-envelope-open')
                    ->falseIcon('heroicon-s-envelope')
                    ->getStateUsing(fn (ContactSubmission $record): bool => $record->isRead()),

                TextColumn::make('name')
                    ->label(__('cms.field.name'))
                    ->searchable(),

                /*
                 * Item 15 — which form the message came through. Only worth reading once a site
                 * has more than the contact form, but a column that appears and disappears with
                 * the number of forms would move every other column about.
                 */
                TextColumn::make('form.title')
                    ->label(__('cms.forms.form'))
                    ->getStateUsing(fn (ContactSubmission $record): ?string => $record->form?->getTranslation('title', app()->getLocale()))
                    ->badge()
                    ->color('gray'),

                /*
                 * What `subject` was before item 15 moved it into the payload: the contact form's
                 * subject where there is one, otherwise the first free-text answer. Searched over
                 * the whole payload, so an editor finds a message by anything the visitor wrote.
                 */
                TextColumn::make('summary')
                    ->label(__('cms.forms.summary'))
                    ->getStateUsing(fn (ContactSubmission $record): ?string => $record->summary())
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::searchPayload($query, $search))
                    ->limit(50),

                TextColumn::make('email')
                    ->label(__('cms.field.email'))
                    ->searchable()
                    ->extraAttributes(['class' => 'cms-ltr'])
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label(__('cms.field.publish_date'))
                    ->formatStateUsing(fn (?CarbonInterface $state): ?string => LocalizedDate::format($state))
                    ->sortable(),

                /*
                 * Which spam check fired. Only meaningful in the spam view, so it is
                 * toggled off by default rather than added to everyone's inbox as a
                 * permanently empty column.
                 *
                 * Worth having at all because it separates an attack from a frontend bug:
                 * a list of `missing_timing` rows that are plainly real enquiries means
                 * the site is not sending the timing field, which is a deployment fix, not
                 * a spam problem.
                 */
                TextColumn::make('spam_reason')
                    ->label(__('cms.field.spam_reason'))
                    ->formatStateUsing(fn (ContactSubmission $record): ?string => $record->spamReasonLabel())
                    ->badge()
                    ->color('warning')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('form_id')
                    ->label(__('cms.forms.form'))
                    ->options(fn (): array => Form::query()
                        ->orderBy('id')
                        ->get()
                        ->mapWithKeys(fn (Form $form): array => [
                            $form->getKey() => (string) $form->getTranslation('title', app()->getLocale()),
                        ])
                        ->all()),

                Filter::make('unread')
                    ->label(__('cms.filter.unread'))
                    ->query(function (Builder $query): Builder {
                        /** @var Builder<ContactSubmission> $query */
                        return $query->unread();
                    }),

                /*
                 * Item 16 — spam is hidden by DEFAULT, not excluded.
                 *
                 * A default on the filter rather than a scope on the resource's base query,
                 * because the editor has to be able to get back to those rows: the checks
                 * are heuristics and a false positive is a lost enquiry unless someone can
                 * look. A base-query scope would make the flagged rows unreachable from the
                 * panel entirely, which is only marginally better than having deleted them.
                 *
                 * `placeholder` is the "everything" option, so the three states read as
                 * inbox / spam / both.
                 */
                TernaryFilter::make('is_spam')
                    ->label(__('cms.filter.spam'))
                    ->placeholder(__('cms.filter.spam_all'))
                    ->trueLabel(__('cms.filter.spam_only'))
                    ->falseLabel(__('cms.filter.spam_excluded'))
                    ->default(false),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('markRead')
                    ->label(__('cms.action.mark_read'))
                    ->icon('heroicon-o-envelope-open')
                    ->visible(fn (ContactSubmission $record): bool => ! $record->isRead())
                    // Authorised explicitly: the policy makes submissions
                    // non-editable, and marking read is the one permitted mutation.
                    ->authorize(fn (ContactSubmission $record): bool => auth()->user()?->can('markRead', $record) ?? false)
                    ->action(fn (ContactSubmission $record) => $record->markRead()),

                /*
                 * Item 16 — the two halves of spam triage, shown one at a time depending on
                 * which list the row is currently in.
                 *
                 * The manual direction matters as much as the automatic one: an editor
                 * reading a message is a far better spam detector than a hidden input, and
                 * without this action their only way to clear an obvious junk enquiry out of
                 * the inbox is to delete it — which is admin-only, and destroys the record.
                 */
                Action::make('markNotSpam')
                    ->label(__('cms.action.mark_not_spam'))
                    ->icon('heroicon-o-inbox-arrow-down')
                    ->visible(fn (ContactSubmission $record): bool => $record->is_spam)
                    ->authorize(fn (ContactSubmission $record): bool => auth()->user()?->can('triageSpam', $record) ?? false)
                    ->action(fn (ContactSubmission $record) => $record->clearSpamFlag()),

                Action::make('markSpam')
                    ->label(__('cms.action.mark_spam'))
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (ContactSubmission $record): bool => ! $record->is_spam)
                    ->authorize(fn (ContactSubmission $record): bool => auth()->user()?->can('triageSpam', $record) ?? false)
                    // 'manual' rather than a check name: the reason column records WHY a row
                    // is flagged, and "a person decided so" is a different and stronger
                    // answer than any of the heuristics.
                    ->action(fn (ContactSubmission $record) => $record->flagAsSpam('manual')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    /*
                     * Item 32 — reading the inbox is the whole workflow, so clearing
                     * twenty messages should be one action rather than twenty.
                     * markRead() is the only mutation the policy permits on a
                     * submission, and it is authorised per record here as it is on the
                     * row action.
                     */
                    BulkAction::make('markRead')
                        ->label(__('cms.action.mark_read_selected'))
                        ->icon('heroicon-o-envelope-open')
                        ->action(function (Collection $records): void {
                            $marked = 0;

                            foreach ($records as $record) {
                                /** @var ContactSubmission $record */
                                if ($record->isRead()) {
                                    continue;
                                }

                                if (! (auth()->user()?->can('markRead', $record) ?? false)) {
                                    continue;
                                }

                                $record->markRead();
                                $marked++;
                            }

                            Notification::make()
                                ->title(Plural::choice('cms.action.mark_read_selected_done', $marked))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make(),
                ]),
            ])
            /*
             * Opted out of the panel-wide filter persistence (item 48). The inbox's spam filter
             * DEFAULTS to hiding spam, and that default is the feature: a persisted "spam only"
             * choice outlived the visit and overrode it, so an editor arriving from the unread badge
             * could see a list of spam while the badge counted real messages. The inbox opens as the
             * inbox every time; sort and search still persist.
             */
            ->persistFiltersInSession(false)
            ->defaultSort('created_at', 'desc');
    }

    /**
     * A case-insensitive substring match over the payload's text.
     *
     * Not a plain `payload LIKE ?`. MySQL compares a JSON column as utf8mb4_bin, so "hello" would
     * not find "Hello" — a regression from the `subject` column this search replaced, which had
     * the table's case-insensitive collation. Casting to CHAR gives the text the connection's
     * collation back; LOWER() on both sides covers SQLite, whose LIKE folds ASCII case only.
     * Persian and Arabic have no case, so they match either way.
     *
     * The match runs over the serialised JSON, keys included: searching for a field key such
     * as `message` matches every row that has that field. A values-only search needs
     * engine-specific JSON functions (JSON_SEARCH / json_each) for one edge case, and was not
     * worth two code paths.
     *
     * @param  Builder<ContactSubmission>  $query
     * @return Builder<ContactSubmission>
     */
    private static function searchPayload(Builder $query, string $search): Builder
    {
        $column = in_array($query->getModel()->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? 'CAST(payload AS CHAR)'
            : 'payload';

        // `!` as the escape character because a backslash needs escaping differently in MySQL
        // and SQLite string literals, and the wildcards in what an editor types are literal.
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));

        return $query->whereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", ["%{$escaped}%"]);
    }
}
