<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Banner;
use App\Support\BannerImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
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
            Storage::disk(BannerImage::disk())->delete($path);
            throw $e;
        }
    }

    public function update(Banner $banner, array $data, ?UploadedFile $image): Banner
    {
        $old = null;
        if ($image) {
            $data['image_path'] = $this->storeImage($image);
            $old = $banner->image_path;
        }
        try {
            DB::transaction(fn () => $banner->update($this->normalize($data)));
        } catch (\Throwable $e) {
            if ($image) {
                Storage::disk(BannerImage::disk())->delete($data['image_path']);
            }
            throw $e;
        }
        if ($old) {
            Storage::disk(BannerImage::disk())->delete($old);
        }

        return $banner->refresh();
    }

    public function delete(Banner $banner): void
    {
        $path = $banner->image_path;
        $banner->delete();
        if ($path) {
            Storage::disk(BannerImage::disk())->delete($path);
        }
    }

    /** @param list<int> $ids */
    public function reorder(string $position, array $ids): void
    {
        DB::transaction(function () use ($position, $ids): void {
            foreach (array_values($ids) as $i => $id) {
                Banner::where('id', $id)->where('position', $position)->update(['sort_order' => $i + 1]);
            }
        });
        Cache::forget('store_home_data');
        Cache::forget('illuminate:cache:flexible:created:store_home_data');
    }

    private function normalize(array $d): array
    {
        unset($d['image']);
        if (in_array($d['link_type'] ?? 'none', ['none'], true)) {
            $d['link_value'] = null;
        }
        if (isset($d['link_value'])) {
            $d['link_value'] = trim((string) $d['link_value']);
        }
        foreach (['kicker', 'headline', 'headline_bold', 'description', 'cta_label', 'bg_color', 'starts_at', 'ends_at'] as $k) {
            if (array_key_exists($k, $d) && $d[$k] === '') {
                $d[$k] = null;
            }
        }

        return $d;
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
