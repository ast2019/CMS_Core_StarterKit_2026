<?php

namespace App\Filament\Resources\ContactSubmissions;

use App\Filament\Resources\ContactSubmissions\Pages\ListContactSubmissions;
use App\Filament\Resources\ContactSubmissions\Pages\ViewContactSubmission;
use App\Filament\Resources\ContactSubmissions\Schemas\ContactSubmissionForm;
use App\Filament\Resources\ContactSubmissions\Tables\ContactSubmissionsTable;
use App\Models\ContactSubmission;
use App\Support\Dates\LocalizedDate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

class ContactSubmissionResource extends Resource
{
    protected static ?string $model = ContactSubmission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    /**
     * Directly under the dashboard, ahead of every group.
     */
    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return __('cms.resource.contact_submission');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cms.resource.contact_submissions');
    }

    /**
     * Item 58 — the inbox belongs to nobody's group.
     *
     * It used to sit under "System", between Redirects and the Audit Log. That is where an
     * administrator looks for infrastructure, not where an editor looks for messages from readers,
     * and every role that can read the inbox is an editorial one. Ungrouped items render directly
     * under the dashboard, which is the position an inbox has in every tool people already use.
     */
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return null;
    }

    /**
     * Unread messages, on the menu item itself (item 58).
     *
     * Before this the count existed only on the dashboard, so an editor working anywhere else in the
     * panel had no way to know a reader had written in.
     *
     * notSpam() for the same reason as the dashboard stat: a flagged message is unread and stays
     * unread, so counting it would show a number nobody can clear by reading the inbox.
     *
     * CACHED, because a navigation badge runs on every page render for every user — and this panel
     * already has two badges that do an uncached COUNT on each render (item 38), which is not a
     * pattern to extend. The cache is invalidated by ContactSubmission's saved/deleted events, so it
     * is exact rather than eventually right: marking a message read clears the key in the same
     * request, and the badge the editor sees next is the new count. The TTL is only a backstop for
     * rows written without model events.
     *
     * Null rather than "0" when there is nothing unread, so the badge disappears instead of shouting
     * a zero at every page view.
     */
    public static function getNavigationBadge(): ?string
    {
        $unread = (int) Cache::remember(
            ContactSubmission::UNREAD_COUNT_CACHE_KEY,
            now()->addMinutes(10),
            fn (): int => ContactSubmission::query()->notSpam()->unread()->count(),
        );

        return $unread > 0 ? LocalizedDate::number($unread) : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('cms.dashboard.unread_messages');
    }

    /**
     * Requirement 1.1 — a disabled module registers no Filament resource.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('cms.modules.contact', true);
    }

    public static function canAccess(): bool
    {
        return config('cms.modules.contact', true) && parent::canAccess();
    }

    /**
     * Submissions arrive from the public contact form, never from the panel.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return ContactSubmissionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContactSubmissionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContactSubmissions::route('/'),
            'view' => ViewContactSubmission::route('/{record}'),
        ];
    }
}
