<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Exceptions\BusinessException;
use App\Models\Banner;
use App\Support\BannerImage;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Manages promotional banner lifecycle, file storage, reordering, and scheduling.
 */
final class BannerService
{
    /**
     * Store a new banner and its associated image asset.
     * Deletes stored image if database transaction fails.
     */
    public function create(array $data, UploadedFile $image): Banner
    {
        $path = $this->storeImage($image);
        try {
            return DB::transaction(function () use ($data, $path): Banner {
                $banner = Banner::create([...$this->normalize($data), 'image_path' => $path]);
                DB::afterCommit(fn () => StoreCatalogService::forgetStorefrontCaches());

                return $banner;
            });
        } catch (\Throwable $e) {
            $this->deleteIfUnreferenced($path);
            throw $e;
        }
    }

    /**
     * Update banner attributes and optionally replace its image.
     */
    public function update(Banner $banner, array $data, ?UploadedFile $image): Banner
    {
        $newPath = $image ? $this->storeImage($image) : null;
        try {
            return DB::transaction(function () use ($banner, $data, $newPath): Banner {
                $current = Banner::query()->lockForUpdate()->findOrFail($banner->id);
                $oldPath = $current->image_path;
                $current->update($this->normalize($newPath ? [...$data, 'image_path' => $newPath] : $data));
                DB::afterCommit(function () use ($newPath, $oldPath): void {
                    StoreCatalogService::forgetStorefrontCaches();
                    if ($newPath && $oldPath !== $newPath) {
                        $this->deleteIfUnreferenced($oldPath);
                    }
                });

                return $current->refresh();
            });
        } catch (\Throwable $e) {
            if ($newPath) {
                $this->deleteIfUnreferenced($newPath);
            }
            throw $e;
        }
    }

    /**
     * Delete banner record and prune its image asset after transaction commit.
     */
    public function delete(Banner $banner): void
    {
        DB::transaction(function () use ($banner): void {
            $current = Banner::query()->lockForUpdate()->findOrFail($banner->id);
            $path = $current->image_path;
            $current->delete();
            DB::afterCommit(function () use ($path): void {
                StoreCatalogService::forgetStorefrontCaches();
                $this->deleteIfUnreferenced($path);
            });
        });
    }

    /**
     * Update or toggle the active status of a banner.
     */
    public function setActive(Banner $banner, ?bool $active): Banner
    {
        return DB::transaction(function () use ($banner, $active): Banner {
            $current = Banner::query()->lockForUpdate()->findOrFail($banner->id);
            $current->update(['is_active' => $active ?? ! $current->is_active]);
            DB::afterCommit(fn () => StoreCatalogService::forgetStorefrontCaches());

            return $current;
        });
    }

    /**
     * Reorder banner display positions sequentially.
     *
     * @param list<int> $ids
     */
    public function reorder(string $position, array $ids): void
    {
        DB::transaction(function () use ($position, $ids): void {
            $ordered = array_values(array_unique(array_map('intval', $ids)));
            // Lock the affected rows in a deterministic id order so concurrent
            // reorders cannot interleave or deadlock while assigning sort orders.
            Banner::query()
                ->whereIn('id', $ordered)
                ->where('position', $position)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            foreach ($ordered as $i => $id) {
                Banner::where('id', $id)->where('position', $position)->update(['sort_order' => $i + 1]);
            }
            DB::afterCommit(fn () => StoreCatalogService::forgetStorefrontCaches());
        });
    }

    /**
     * Remove physical image file if no other banner record references it.
     */
    private function deleteIfUnreferenced(?string $path): void
    {
        if ($path && ! Banner::query()->where('image_path', $path)->exists()) {
            try {
                Storage::disk(BannerImage::disk())->delete($path);
            } catch (\Throwable $error) {
                report($error);
            }
        }
    }

    /**
     * Normalize banner fields and convert schedule dates to application timezone.
     */
    private function normalize(array $data): array
    {
        unset($data['image']);
        if (($data['link_type'] ?? 'none') === 'none') {
            $data['link_value'] = null;
        }
        if (isset($data['link_value'])) {
            $data['link_value'] = trim((string) $data['link_value']);
        }
        foreach (['kicker', 'headline', 'headline_bold', 'description', 'cta_label', 'bg_color', 'starts_at', 'ends_at'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === '') {
                $data[$key] = null;
            }
        }
        foreach (['starts_at', 'ends_at'] as $key) {
            if (! empty($data[$key])) {
                $data[$key] = CarbonImmutable::parse($data[$key])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
            }
        }

        return $data;
    }

    /**
     * Persist uploaded image file using UUID to prevent collisions.
     */
    private function storeImage(UploadedFile $file): string
    {
        // Never trust the client-supplied name or Symfony's guess: derive the
        // extension from the sniffed MIME type and reject anything else.
        $extension = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default => throw new BusinessException('صيغة صورة البانر غير مدعومة.', [], 422),
        };

        $path = $file->storeAs('banners', Str::uuid().'.'.$extension, BannerImage::disk());
        if ($path === false) {
            throw new \RuntimeException('The banner image could not be stored.');
        }

        return $path;
    }
}
