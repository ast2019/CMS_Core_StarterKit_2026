<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContactSubmissions\Schemas;

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
                        ->dateTime('Y-m-d H:i'),
                    TextEntry::make('read_at')
                        ->label(__('cms.field.read_at'))
                        ->dateTime('Y-m-d H:i')
                        ->placeholder(__('cms.table.unread')),
                ]),

            Section::make(__('cms.field.message'))
                ->schema([
                    TextEntry::make('message')
                        ->label('')
                        ->columnSpanFull(),
                ]),

            Section::make('Diagnostics')
                ->collapsed()
                ->columns(2)
                ->schema([
                    // Retained for abuse investigation and rate-limit forensics.
                    TextEntry::make('ip_address')->label('IP'),
                    TextEntry::make('user_agent')->label('User agent'),
                ]),
        ]);
    }
}
