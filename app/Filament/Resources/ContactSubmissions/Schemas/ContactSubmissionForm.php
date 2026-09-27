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
                ->columns(3)
                ->schema([
                    TextEntry::make('form.title')
                        ->label(__('cms.forms.form'))
                        ->state(fn (ContactSubmission $record): ?string => $record->form?->getTranslation('title', app()->getLocale()))
                        ->badge()
                        ->color('gray'),
                    TextEntry::make('created_at')
                        ->label(__('cms.field.publish_date'))
                        ->formatStateUsing(fn (?CarbonInterface $state): ?string => LocalizedDate::format($state)),
                    TextEntry::make('read_at')
                        ->label(__('cms.field.read_at'))
                        ->formatStateUsing(fn (?CarbonInterface $state): ?string => LocalizedDate::format($state))
                        ->placeholder(__('cms.table.unread')),
                ]),

            /*
             * Item 15 — what the visitor sent, one entry per answered field, labelled from the
             * form's schema in the reader's locale (FormSchema::describe). Built per record
             * because the fields are data: two messages from two forms have nothing in common
             * but the section they are shown in.
             *
             * Long answers keep their line breaks — a message split into paragraphs by its
             * writer should not reach the editor as one run-on line.
             */
            Section::make(__('cms.forms.submission'))
                ->columns(2)
                ->schema(fn (ContactSubmission $record): array => array_map(
                    fn (array $entry): TextEntry => TextEntry::make("payload_entry_{$entry['key']}")
                        ->label($entry['label'])
                        ->state($entry['value'])
                        ->columnSpan($entry['multiline'] ? 'full' : 1)
                        ->extraAttributes($entry['multiline'] ? ['class' => 'whitespace-pre-line'] : []),
                    $record->readablePayload(),
                )),

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
