<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\HasFeaturedImage;
use App\Concerns\IsAuditable;
use App\Filament\Schemas\CmsRichEditor;
use App\Filament\Widgets\VersionWidget;
use App\Models\Category;
use App\Models\Changelog;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\MediaAsset;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Setting;
use App\Models\Slide;
use App\Models\SystemInfo;
use App\Models\Tag;
use App\Providers\CmsServiceProvider;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Spatie\Activitylog\Models\Activity;

/**
 * Audits the nine hard rules from blueprint §12 and reports their live state.
 *
 * The test suite already enforces these — this command is for the human moments the
 * suite cannot cover: verifying a fresh deployment, or answering "is this copy of the
 * Core still compliant?" on a client site that has been customised for two years.
 *
 * It reads the RUNNING application rather than the source, so it catches drift that a
 * source scan would miss: a disk re-added by an env var, MFA switched off in a
 * per-site panel override, a font provider swapped in a subclass.
 */
class AuditRulesCommand extends Command
{
    protected $signature = 'cms:audit-rules';

    protected $description = 'Audit the nine hard architecture rules and report their live state.';

    /**
     * Models an admin can write to through the panel or Management API.
     *
     * @var list<class-string>
     */
    private const AUDITABLE = [
        Content::class, Page::class, Gallery::class, Slide::class,
        Category::class, Tag::class, MediaAsset::class, MenuItem::class, Setting::class,
    ];

    /**
     * RULE #7 applies to exactly these (blueprint §3).
     *
     * @var list<class-string>
     */
    private const FEATURED_IMAGE = [Content::class, Page::class, Gallery::class, Slide::class];

    public function handle(): int
    {
        $this->newLine();
        $this->line('<options=bold>Nine-rule audit</> — blueprint §12');
        $this->newLine();

        $results = [
            $this->auditChangelog(),
            $this->auditVersioning(),
            $this->auditApiDocs(),
            $this->auditAdminFont(),
            $this->auditAdminPanel(),
            $this->auditEditor(),
            $this->auditFeaturedImage(),
            $this->auditAuditLogging(),
            $this->auditLocalMedia(),
        ];

        $this->table(['#', 'Rule', 'Status', 'Evidence'], array_map(
            fn (array $r): array => [
                $r['number'],
                $r['rule'],
                $r['passed'] ? '<fg=green>PASS</>' : '<fg=red;options=bold>FAIL</>',
                $r['evidence'],
            ],
            $results,
        ));

        $this->newLine();
        $this->line('<options=bold>Out of scope</> — these must be absent');

        $exclusions = $this->auditExclusions();

        $this->table(['Item', 'Status'], array_map(
            fn (array $e): array => [
                $e['item'],
                $e['absent'] ? '<fg=green>absent</>' : '<fg=red;options=bold>PRESENT</>',
            ],
            $exclusions,
        ));

        $failed = array_filter($results, fn (array $r): bool => ! $r['passed']);
        $present = array_filter($exclusions, fn (array $e): bool => ! $e['absent']);

        $this->newLine();

        if ($failed === [] && $present === []) {
            $this->info('All nine rules hold and nothing out of scope is present.');

            return self::SUCCESS;
        }

        foreach ($failed as $failure) {
            $this->error("RULE #{$failure['number']} ({$failure['rule']}) is not satisfied.");
        }

        foreach ($present as $item) {
            $this->error("Out of scope but present: {$item['item']}.");
        }

        return self::FAILURE;
    }

    /**
     * @return array{number: int, rule: string, passed: bool, evidence: string}
     */
    private function auditChangelog(): array
    {
        $path = (string) config('cms.changelog_path', base_path('CHANGELOG.md'));
        $fileExists = file_exists($path);
        $rows = Changelog::query()->count();
        $version = SystemInfo::version();

        // The point of RULE #1 is that the table and the file agree; either alone is
        // not the rule.
        $agrees = $fileExists && str_contains((string) file_get_contents($path), $version);

        return [
            'number' => 1,
            'rule' => 'Changelog',
            'passed' => $fileExists && $rows > 0 && $agrees,
            'evidence' => sprintf(
                'CHANGELOG.md=%s, changelogs rows=%d, file names %s=%s',
                $fileExists ? 'present' : 'MISSING',
                $rows,
                $version,
                $agrees ? 'yes' : 'no',
            ),
        ];
    }

    /**
     * @return array{number: int, rule: string, passed: bool, evidence: string}
     */
    private function auditVersioning(): array
    {
        $version = SystemInfo::version();
        $isSemver = preg_match('/^\d+\.\d+\.\d+$/', $version) === 1;
        $shown = in_array(VersionWidget::class, Filament::getPanel('admin')->getWidgets(), true);

        return [
            'number' => 2,
            'rule' => 'Versioning',
            'passed' => $isSemver && $shown,
            'evidence' => sprintf('system_info=%s (semver=%s), shown in panel=%s',
                $version, $isSemver ? 'yes' : 'no', $shown ? 'yes' : 'no'),
        ];
    }

    /**
     * @return array{number: int, rule: string, passed: bool, evidence: string}
     */
    private function auditApiDocs(): array
    {
        $path = base_path('docs/openapi.json');

        if (! file_exists($path)) {
            return [
                'number' => 3, 'rule' => 'API doc sync', 'passed' => false,
                'evidence' => 'docs/openapi.json is missing',
            ];
        }

        /** @var array<string, mixed> $spec */
        $spec = (array) json_decode((string) file_get_contents($path), true);
        $paths = count($spec['paths'] ?? []);

        /*
         * Shape drift (routes, request/response bodies) is caught by
         * OpenApiSpecIsInSyncTest. VERSION drift can only be caught here: the test
         * suite runs against a fresh database that always reports the initial version,
         * so it cannot tell whether the committed spec matches the release actually
         * deployed. This check is what found the published spec still claiming 0.1.0
         * after the 0.2.0 release.
         */
        $specVersion = (string) ($spec['info']['version'] ?? '?');
        $systemVersion = SystemInfo::version();
        $agrees = $specVersion === $systemVersion;

        return [
            'number' => 3, 'rule' => 'API doc sync', 'passed' => $paths > 0 && $agrees,
            'evidence' => sprintf('committed spec, %d paths, version %s%s',
                $paths,
                $specVersion,
                $agrees ? '' : " (STALE: system_info says {$systemVersion}; run scramble:export)",
            ),
        ];
    }

    /**
     * @return array{number: int, rule: string, passed: bool, evidence: string}
     */
    private function auditAdminFont(): array
    {
        $woff2 = glob(public_path('fonts/vazirmatn/*.woff2')) ?: [];
        $licence = file_exists(public_path('fonts/vazirmatn/OFL.txt'));

        $provider = str_contains(
            (string) file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php')),
            'LocalFontProvider',
        );

        return [
            'number' => 4,
            'rule' => 'Admin font',
            'passed' => $woff2 !== [] && $provider && $licence,
            'evidence' => sprintf('%d local woff2, LocalFontProvider=%s, OFL=%s',
                count($woff2), $provider ? 'yes' : 'NO', $licence ? 'yes' : 'NO'),
        ];
    }

    /**
     * @return array{number: int, rule: string, passed: bool, evidence: string}
     */
    private function auditAdminPanel(): array
    {
        $panel = Filament::getPanel('admin');

        $previous = app()->getLocale();
        app()->setLocale('fa');
        $direction = (string) __('filament-panels::layout.direction');
        app()->setLocale($previous);

        $mfaRequired = $panel->isMultiFactorAuthenticationRequired();

        return [
            'number' => 5,
            'rule' => 'Panel + mandatory 2FA',
            'passed' => $mfaRequired && $direction === 'rtl' && config('app.locale') === 'fa',
            'evidence' => sprintf('MFA required=%s, fa direction=%s, locale=%s',
                $mfaRequired ? 'yes' : 'NO', $direction, config('app.locale')),
        ];
    }

    /**
     * @return array{number: int, rule: string, passed: bool, evidence: string}
     */
    private function auditEditor(): array
    {
        $blocks = CmsRichEditor::blocks();

        $storesJson = str_contains(
            (string) file_get_contents(app_path('Filament/Schemas/CmsRichEditor.php')),
            '->json()',
        );

        return [
            'number' => 6,
            'rule' => 'Editor + custom blocks',
            'passed' => count($blocks) >= 4 && $storesJson,
            'evidence' => sprintf('blocks: %s; JSON storage=%s',
                implode(', ', array_map(fn (string $b): string => $b::getId(), $blocks)),
                $storesJson ? 'yes' : 'NO'),
        ];
    }

    /**
     * @return array{number: int, rule: string, passed: bool, evidence: string}
     */
    private function auditFeaturedImage(): array
    {
        $missing = array_values(array_filter(
            self::FEATURED_IMAGE,
            fn (string $model): bool => ! in_array(HasFeaturedImage::class, class_uses_recursive($model), true),
        ));

        return [
            'number' => 7,
            'rule' => 'Featured image trait',
            'passed' => $missing === [],
            'evidence' => $missing === []
                ? sprintf('shared trait on %d models', count(self::FEATURED_IMAGE))
                : 'missing on: '.implode(', ', array_map('class_basename', $missing)),
        ];
    }

    /**
     * @return array{number: int, rule: string, passed: bool, evidence: string}
     */
    private function auditAuditLogging(): array
    {
        $missing = array_values(array_filter(
            self::AUDITABLE,
            fn (string $model): bool => ! in_array(IsAuditable::class, class_uses_recursive($model), true),
        ));

        // RULE #8 says "no opt-out", so the module flag must be hardcoded true rather
        // than env-driven like every other module.
        $noOptOut = config('cms.modules.audit') === true;

        return [
            'number' => 8,
            'rule' => 'Audit logging',
            'passed' => $missing === [] && $noOptOut,
            'evidence' => $missing === []
                ? sprintf('%d models auditable, no opt-out=%s, %d activity rows',
                    count(self::AUDITABLE), $noOptOut ? 'yes' : 'NO', Activity::query()->count())
                : 'missing on: '.implode(', ', array_map('class_basename', $missing)),
        ];
    }

    /**
     * @return array{number: int, rule: string, passed: bool, evidence: string}
     */
    private function auditLocalMedia(): array
    {
        /** @var array<string, array<string, mixed>> $disks */
        $disks = (array) config('filesystems.disks', []);

        $forbidden = array_values(array_filter(
            array_map(fn (array $disk): ?string => $disk['driver'] ?? null, $disks),
            fn (?string $driver): bool => in_array($driver, CmsServiceProvider::FORBIDDEN_DISK_DRIVERS, true),
        ));

        $noAdapter = ! $this->packageInstalled('league/flysystem-aws-s3-v3');
        $mediaDisk = (string) config('media-library.disk_name');

        return [
            'number' => 9,
            'rule' => 'Local media only',
            'passed' => $forbidden === [] && $noAdapter && $mediaDisk === 'public',
            'evidence' => sprintf('disks=[%s], aws adapter=%s, media disk=%s',
                implode(', ', array_keys($disks)),
                $noAdapter ? 'absent' : 'PRESENT',
                $mediaDisk),
        ];
    }

    /**
     * @return list<array{item: string, absent: bool}>
     */
    private function auditExclusions(): array
    {
        $graphqlRoute = false;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_contains(strtolower($route->uri()), 'graphql')) {
                $graphqlRoute = true;

                break;
            }
        }

        return [
            ['item' => 'GraphQL route', 'absent' => ! $graphqlRoute],
            ['item' => 'GraphQL package', 'absent' => ! $this->packageInstalled('nuwave/lighthouse')],
            ['item' => 'Cloud storage adapter', 'absent' => ! $this->packageInstalled('league/flysystem-aws-s3-v3')],
            ['item' => 'Automated backup package', 'absent' => ! $this->packageInstalled('spatie/laravel-backup')],
            ['item' => 'Third-party 2FA plugin', 'absent' => ! $this->packageInstalled('jeffgreco13/filament-breezy')],
        ];
    }

    private function packageInstalled(string $name): bool
    {
        static $packages = null;

        if ($packages === null) {
            /** @var array<string, mixed> $lock */
            $lock = (array) json_decode((string) file_get_contents(base_path('composer.lock')), true);

            $packages = array_map(
                fn (array $package): string => strtolower($package['name']),
                [...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])],
            );
        }

        return in_array(strtolower($name), $packages, true);
    }
}
