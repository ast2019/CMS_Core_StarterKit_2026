<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContactSubmissions\Schemas;

use App\Models\ContactSubmission;
use App\Support\Dates\LocalizedDate;
use Carbon\CarbonInterface;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Read-only. ContactSubmissionPolicy forbids create and update: a submission is a
 * record of what a visitor actually sent, and an editable copy of it is no longer
 * evidence of anything.
 */
class ContactSubmissionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextEntry::make('name')->label(__('cms.field.name')),
                    TextEntry::make('email')->label(__('cms.field.email')),
                    TextEntry::make('phone')->label(__('cms.field.phone')),
                    TextEntry::make('subject')->label(__('cms.field.subject')),
                    TextEntry::make('created_at')
                        ->label(__('cms.field.publish_date'))
                        ->formatStateUsing(fn (?CarbonInterface $state): ?string => LocalizedDate::format($state)),
                    TextEntry::make('read_at')
                        ->label(__('cms.field.read_at'))
                        ->formatStateUsing(fn (?CarbonInterface $state): ?string => LocalizedDate::format($state))
                        ->placeholder(__('cms.table.unread')),
                ]),

            Section::make(__('cms.field.message'))
                ->schema([
                    TextEntry::make('message')
                        ->label('')
                        ->columnSpanFull(),
                ]),

            Section::make(__('cms.section.diagnostics'))
                ->collapsed()
                ->columns(2)
                ->schema([
                    /*
                     * Item 16 — WHY this message is in the spam list, on the screen where the
                     * editor decides whether it should be.
                     *
                     * Without it the recovery path ended on a page that gave no hint the row
                     * was flagged, so a genuine enquiry and a caught bot looked identical.
                     * The reason is the difference between "our honeypot name collides with
                     * autofill" and "our frontend stopped sending the timing field" — two
                     * different bugs with two different fixes.
                     *
                     * Visible only when set: an empty "spam reason" row on every legitimate
                     * message would invite the question it cannot answer.
                     */
                    TextEntry::make('spam_reason')
                        ->label(__('cms.field.spam_reason'))
                        ->badge()
                        ->color('warning')
                        ->formatStateUsing(fn (ContactSubmission $record): ?string => $record->spamReasonLabel())
                        ->visible(fn (ContactSubmission $record): bool => $record->spam_reason !== null)
                        ->columnSpanFull(),

                    // Retained for abuse investigation and rate-limit forensics.
                    TextEntry::make('ip_address')->label('IP'),
                    TextEntry::make('user_agent')->label(__('cms.field.user_agent')),
                ]),
        ]);
    }
}
