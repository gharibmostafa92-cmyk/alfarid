<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Banner;
use App\Support\BannerImage;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class BannerService
{
    public function create(array $data, UploadedFile $image): Banner
    {
        $path = $this->storeImage($image);
        try {
            return DB::transaction(fn () => Banner::create([...$this->normalize($data), 'image_path' => $path]));
        } catch (\Throwable $e) {
            $this->deleteIfUnreferenced($path);
            throw $e;
        }
    }

    public function update(Banner $banner, array $data, ?UploadedFile $image): Banner
    {
        $newPath = $image ? $this->storeImage($image) : null;
        try {
            return DB::transaction(function () use ($banner, $data, $newPath): Banner {
                $current = Banner::query()->lockForUpdate()->findOrFail($banner->id);
                $oldPath = $current->image_path;
                $current->update($this->normalize($newPath ? [...$data, 'image_path' => $newPath] : $data));
                if ($newPath && $oldPath !== $newPath) {
                    DB::afterCommit(fn () => $this->deleteIfUnreferenced($oldPath));
                }

                return $current->refresh();
            });
        } catch (\Throwable $e) {
            if ($newPath) {
                $this->deleteIfUnreferenced($newPath);
            }
            throw $e;
        }
    }

    public function delete(Banner $banner): void
    {
        DB::transaction(function () use ($banner): void {
            $current = Banner::query()->lockForUpdate()->findOrFail($banner->id);
            $path = $current->image_path;
            $current->delete();
            DB::afterCommit(fn () => $this->deleteIfUnreferenced($path));
        });
    }

    public function setActive(Banner $banner, ?bool $active): Banner
    {
        return DB::transaction(function () use ($banner, $active): Banner {
            $current = Banner::query()->lockForUpdate()->findOrFail($banner->id);
            $current->update(['is_active' => $active ?? ! $current->is_active]);

            return $current;
        });
    }

    /** @param list<int> $ids */
    public function reorder(string $position, array $ids): void
    {
        DB::transaction(function () use ($position, $ids): void {
            foreach (array_values($ids) as $i => $id) {
                Banner::where('id', $id)->where('position', $position)->update(['sort_order' => $i + 1]);
            }
        });
    }

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

    private function storeImage(UploadedFile $file): string
    {
        $path = $file->storeAs('banners', Str::uuid().'.'.$file->extension(), BannerImage::disk());
        if ($path === false) {
            throw new \RuntimeException('The banner image could not be stored.');
        }

        return $path;
    }
}
