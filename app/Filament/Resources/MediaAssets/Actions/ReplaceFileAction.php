<?php

declare(strict_types=1);

namespace App\Filament\Resources\MediaAssets\Actions;

use App\Filament\Tables\GuardedDeleteActions;
use App\Models\MediaAsset;
use App\Services\Content\UsageInspector;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Item 12 — swap the file an asset holds, having been told where it is used first.
 *
 * Every record attached to an asset shows its file, so replacing it changes all of them at once,
 * published pages included, and the previous file is deleted: the media collection holds one file.
 * The form's upload field is locked on edit so this is the only way to do it, and this is the only
 * way that says so before it happens.
 *
 * What the swap resets, and why:
 *  - mime_type, size, width, height are re-read from the new file (MediaAsset::fileMetadata()).
 *  - A video's duration and dimensions are cleared, because they describe the old file. When
 *    ffprobe is installed ExtractVideoMetadata fills them from the new one; it only ever fills empty
 *    fields, so without the clear it would keep the old values for ever.
 *  - A video's poster frame is NOT touched: it is chosen, not derived. The warning asks the editor
 *    to check it.
 *
 * The save is unconditional (touch() when nothing measurable changed). The file's URL changes with
 * every replacement, and it is the asset's `saved` event that clears the Delivery cache and tells
 * the frontend; skipping it when the new file happened to have the same size and dimensions would
 * leave cached pages pointing at a file that has been deleted.
 */
final class ReplaceFileAction
{
    public static function make(): Action
    {
        return Action::make('replaceFile')
            ->label(__('cms.media.replace.action'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->authorize('update')
            ->visible(fn (MediaAsset $record): bool => ! $record->trashed())
            ->modalHeading(__('cms.media.replace.heading'))
            ->modalDescription(fn (MediaAsset $record): string => self::warning($record))
            ->modalSubmitActionLabel(__('cms.media.replace.submit'))
            ->schema(fn (MediaAsset $record): array => [
                FileUpload::make('file')
                    ->label(__('cms.field.file'))
                    ->required()
                    ->acceptedFileTypes(MediaAsset::mimeTypesFor((string) $record->type))
                    ->rule(fn (): Closure => MediaAsset::fileContentRule((string) $record->type))
                    ->maxSize(10 * 1024)
                    // RULE #9 — local disk from config; moved into the library's layout below.
                    ->disk(self::disk())
                    ->directory('media-library/uploads'),
            ])
            ->action(function (MediaAsset $record, array $data, Action $action): void {
                self::replace($record, $data['file'] ?? null);

                Notification::make()
                    ->title(__('cms.media.replace.done'))
                    ->success()
                    ->send();

                // The page's own copy of the file field still shows the old file.
                $livewire = $action->getLivewire();

                if (method_exists($livewire, 'refreshReplacedFile')) {
                    $livewire->refreshReplacedFile();
                }
            });
    }

    /**
     * Put the uploaded file in place of the asset's current one.
     */
    public static function replace(MediaAsset $asset, mixed $upload): void
    {
        $path = is_array($upload) ? Arr::first($upload) : $upload;

        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages(['file' => __('cms.validation.media_file_required')]);
        }

        $previous = $asset->getFirstMedia('file')?->file_name;

        /*
         * The measurements of the OLD file are cleared BEFORE the new one is added, and quietly.
         * Before, because adding the media is what fires MediaHasBeenAddedEvent and so
         * ExtractVideoMetadata, which fills only empty fields: cleared afterwards, the probe could
         * already have run against the old values, skipped them, and the clear then left the fields
         * empty for good. Quietly, because this is half of one change; the save below is the one
         * that is audited and that clears the Delivery cache.
         */
        $asset->forceFill([
            'width' => null,
            'height' => null,
            ...($asset->isVideo() ? ['duration_seconds' => null] : []),
        ])->saveQuietly();

        // singleFile(): adding to the collection removes the previous file from the row and the disk.
        $media = $asset->addMediaFromDisk($path, self::disk())->toMediaCollection('file');

        // Re-read: the probe may already have written the new video's measurements.
        $asset->refresh()->load('media');

        $asset->forceFill($asset->fileMetadata());

        $asset->isDirty() ? $asset->save() : $asset->touch();

        /*
         * A row of its own in the audit trail. The model's `updated` row only lists the columns that
         * changed, and a replacement of the same size and dimensions changes none of them — which
         * would leave "the file everybody sees was swapped" recorded as an empty edit.
         */
        activity('cms')
            ->performedOn($asset)
            ->causedBy(auth()->user())
            ->event('updated')
            // The shape the audit page's "view changes" diff reads.
            ->withProperties([
                'old' => ['file' => $previous],
                'attributes' => ['file' => $media->file_name],
            ])
            ->log('MediaAsset.file_replaced');
    }

    /**
     * Where the asset is used, in the same words as the delete confirmation, and what a
     * replacement will and will not change.
     */
    public static function warning(MediaAsset $asset): string
    {
        $inspector = app(UsageInspector::class);
        $usage = $inspector->usage($asset);

        $lines = [
            $usage === []
                ? __('cms.media.replace.unused')
                : __('cms.media.replace.used', ['usage' => GuardedDeleteActions::describeUsage($usage)]),
        ];

        if ($inspector->isSiteLogo($asset)) {
            $lines[] = __('cms.media.replace.logo');
        }

        $lines[] = __('cms.media.usage.caveat');
        $lines[] = __('cms.media.replace.previous_deleted');

        if ($asset->isVideo()) {
            $lines[] = __('cms.media.replace.video');
        }

        return implode(' ', $lines);
    }

    private static function disk(): string
    {
        return (string) config('cms.media.disk', 'public');
    }
}
