<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Dates\LocalizedDate;
use App\Support\Plural;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('cms.field.name'))
                    ->searchable(),

                TextColumn::make('email')
                    ->label(__('cms.field.email'))
                    ->searchable()
                    ->extraAttributes(['class' => 'cms-ltr']),

                /*
                 * Item 52 — which accounts are still in use. "Three weeks ago" rather than a date,
                 * because the question is how long, and an account nobody has used since spring is
                 * one to deactivate. The exact time is in the audit log.
                 */
                TextColumn::make('last_login_at')
                    ->label(__('cms.field.last_login'))
                    ->formatStateUsing(fn (?CarbonInterface $state): ?string => LocalizedDate::human($state))
                    ->placeholder(__('cms.field.never_signed_in'))
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('role')
                    ->label(__('cms.field.role'))
                    ->badge()
                    ->formatStateUsing(fn (UserRole $state): string => $state->label()),

                IconColumn::make('is_active')
                    ->label(__('cms.field.is_active'))
                    ->boolean(),

                IconColumn::make('mfa')
                    ->label(__('cms.field.two_factor'))
                    /*
                     * Whether this account has finished enrolling an authenticator. MFA
                     * is mandatory (RULE #5), so an account showing false cannot reach
                     * the panel past the login screen — which is exactly what an admin
                     * needs to see when someone reports being locked out.
                     */
                    ->boolean()
                    ->getStateUsing(fn (User $record): bool => $record->hasCompletedMfaEnrolment()),

                TextColumn::make('created_at')
                    ->label(__('cms.audit.when'))
                    ->formatStateUsing(fn (?CarbonInterface $state): ?string => LocalizedDate::format($state))
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label(__('cms.field.role'))
                    ->options(fn (): array => collect(UserRole::cases())
                        ->mapWithKeys(fn (UserRole $role): array => [$role->value => $role->label()])
                        ->all()),

                TernaryFilter::make('is_active')
                    ->label(__('cms.filter.active')),
            ])
            ->recordActions([
                EditAction::make(),

                /*
                 * Item 33 — the one that mattered. MFA is mandatory, so an editor who
                 * loses their phone is locked out completely, and until now the only
                 * remedy was an UPDATE against the database by hand. Clearing the secret
                 * sends them back through enrolment at their next sign-in.
                 *
                 * Admin-only via the user.manage ability, and never on your own account:
                 * resetting your own second factor while signed in is how an
                 * administrator locks themselves out of the panel that holds the button.
                 */
                Action::make('resetTwoFactor')
                    ->label(__('cms.action.reset_two_factor'))
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('cms.action.reset_two_factor_confirm'))
                    ->visible(fn (User $record): bool => $record->hasCompletedMfaEnrolment()
                        && $record->isNot(auth()->user())
                        && (auth()->user()?->can('user.manage') ?? false))
                    ->action(function (User $record): void {
                        /*
                         * Both halves, or the account is not actually reset: a recovery
                         * code left behind is still a working second factor, which would
                         * make this button look like it worked while changing nothing for
                         * someone who lost the phone AND the codes.
                         */
                        $record->forceFill([
                            'app_authentication_secret' => null,
                            'app_authentication_recovery_codes' => null,
                        ])->save();

                        /*
                         * Logged EXPLICITLY, because User does not use IsAuditable —
                         * that trait is on the content models — so a save here writes no
                         * activity row on its own. An earlier version of this claimed the
                         * model write "lands in the audit trail (RULE #8)", which was
                         * simply false.
                         *
                         * It has to be recorded: stripping another account's second
                         * factor is the most attack-relevant thing this panel can do, and
                         * a compromised admin session could otherwise clear MFA across
                         * every account leaving no trace. Both columns are redacted from
                         * logged values anyway, so the row is who/whom/when — which is
                         * the whole question here.
                         */
                        activity('cms')
                            ->performedOn($record)
                            ->causedBy(auth()->user())
                            ->event('two_factor_reset')
                            ->log('two_factor_reset');

                        Notification::make()
                            ->title(__('cms.action.reset_two_factor_done', ['name' => $record->name]))
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::setActiveBulkAction('deactivate', false),
                    self::setActiveBulkAction('activate', true),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Switch several accounts on or off at once.
     *
     * Deactivating is the safe way to remove someone's access: User::canAccessPanel()
     * checks is_active, so the account stops working immediately while its audit history
     * keeps pointing at a real user. Deleting would leave the trail naming a row that no
     * longer exists.
     */
    private static function setActiveBulkAction(string $name, bool $active): BulkAction
    {
        return BulkAction::make($name)
            ->label(__('cms.action.'.$name.'_selected'))
            ->icon($active ? 'heroicon-o-check-circle' : 'heroicon-o-no-symbol')
            ->color($active ? 'success' : 'warning')
            ->requiresConfirmation()
            ->visible(fn (): bool => auth()->user()?->can('user.manage') ?? false)
            ->action(function (Collection $records) use ($active): void {
                $changed = 0;
                $skipped = 0;

                // Re-checked inside the handler, not only in visible(): the rest of this
                // diff authorises per record, and a guard that exists only in the render
                // path is a different guarantee from one that runs before the write.
                if (! (auth()->user()?->can('user.manage') ?? false)) {
                    return;
                }

                foreach ($records as $record) {
                    /** @var User $record */

                    // Never your own account: deactivating yourself signs you out of the
                    // screen holding the button, and there may be no other admin.
                    if ($record->is(auth()->user())) {
                        $skipped++;

                        continue;
                    }

                    if ($record->is_active === $active) {
                        continue;
                    }

                    $record->forceFill(['is_active' => $active])->save();

                    // Explicit, for the same reason as the two-factor reset above:
                    // deactivation is how access is revoked, and User is not auditable.
                    activity('cms')
                        ->performedOn($record)
                        ->causedBy(auth()->user())
                        ->event($active ? 'activated' : 'deactivated')
                        ->log($active ? 'activated' : 'deactivated');

                    $changed++;
                }

                $notification = Notification::make()
                    ->title(Plural::choice('cms.action.'.($active ? 'activate' : 'deactivate').'_selected_done', $changed));

                $skipped > 0
                    ? $notification->body(__('cms.action.own_account_skipped'))->warning()
                    : $notification->success();

                $notification->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
