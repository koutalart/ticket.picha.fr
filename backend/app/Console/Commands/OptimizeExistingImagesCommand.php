<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\Generated\ImageDomainObjectAbstract;
use HiEvents\Models\Image;
use HiEvents\Services\Infrastructure\Image\ImageOptimizationService;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Str;
use Throwable;

class OptimizeExistingImagesCommand extends Command
{
    protected $signature = 'images:optimize
                            {--id=* : Only these image ids}
                            {--type=* : Only these image types (e.g. EVENT_COVER)}
                            {--min-kb=200 : Only images heavier than this size}
                            {--dry-run : Show what would be done without changing anything}';

    protected $description = 'Re-encode existing images (WebP for photos, JPEG for share images, resized logos). Original files are kept.';

    public function __construct(
        private readonly FilesystemManager $filesystemManager,
        private readonly ImageOptimizationService $imageOptimizationService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $query = Image::query()
            ->whereNull('deleted_at')
            ->where(ImageDomainObjectAbstract::SIZE, '>', (int) $this->option('min-kb') * 1024);

        if ($ids = $this->option('id')) {
            $query->whereIn('id', $ids);
        }
        if ($types = $this->option('type')) {
            $query->whereIn(ImageDomainObjectAbstract::TYPE, $types);
        }

        $savedBytes = 0;
        foreach ($query->orderBy('id')->get() as $image) {
            try {
                $disk = $this->filesystemManager->disk($image->disk);
                $optimized = $this->imageOptimizationService->optimize(
                    contents: (string) $disk->get($image->path),
                    mimeType: (string) $image->mime_type,
                    imageType: (string) $image->type,
                );

                if ($optimized === null) {
                    $this->line(sprintf('#%d %s: already optimal', $image->id, $image->path));

                    continue;
                }

                $newSize = strlen($optimized->contents);
                $this->line(sprintf(
                    '#%d %s: %d KB -> %d KB (%s %dx%d)',
                    $image->id,
                    $image->path,
                    intdiv((int) $image->size, 1024),
                    intdiv($newSize, 1024),
                    $optimized->mimeType,
                    $optimized->width,
                    $optimized->height,
                ));
                $savedBytes += (int) $image->size - $newSize;

                if ($dryRun) {
                    continue;
                }

                $filename = pathinfo($image->filename, PATHINFO_FILENAME).'-'.Str::random(5).'.'.$optimized->extension;
                $directory = dirname($image->path);
                $path = ($directory === '.' ? '' : $directory.'/').$filename;
                $disk->put($path, $optimized->contents, ['visibility' => 'public']);

                $image->update([
                    ImageDomainObjectAbstract::FILENAME => $filename,
                    ImageDomainObjectAbstract::PATH => $path,
                    ImageDomainObjectAbstract::SIZE => $newSize,
                    ImageDomainObjectAbstract::MIME_TYPE => $optimized->mimeType,
                    ImageDomainObjectAbstract::WIDTH => $optimized->width,
                    ImageDomainObjectAbstract::HEIGHT => $optimized->height,
                ]);
            } catch (Throwable $exception) {
                $this->error(sprintf('#%d %s: %s', $image->id, $image->path, $exception->getMessage()));
            }
        }

        $this->info(sprintf('%s %d KB', $dryRun ? 'Would save' : 'Saved', intdiv(max(0, $savedBytes), 1024)));

        return self::SUCCESS;
    }
}
