<?php

declare(strict_types=1);

namespace App\Actions\Product\Sync;

use App\Models\ProductVariation;
use Illuminate\Support\Facades\Storage;

/**
 * SyncProductImagesAction
 *
 * Handles full lifecycle of product variation images:
 *
 * OPERATIONS:
 * - DELETE: permanently removes image from storage and DB
 * - UPDATE: partially updates metadata only (no file changes)
 * - CREATE: uploads new file and creates DB record
 *
 * RULES:
 * - delete=true overrides all other operations
 * - images are hard deleted (no soft delete)
 * - file upload occurs only during creation
 * - update does NOT re-upload files
 * - no deduplication of images is enforced
 *
 * DEFAULT IMAGE:
 * - system does NOT enforce single default image
 * - multiple images may be marked is_default=true
 *
 * STORAGE:
 * - Files stored in public disk under product/variation path
 *
 * SIDE EFFECTS:
 * - file system + DB must remain in sync
 */
final class SyncProductImagesAction
{
    public function execute(ProductVariation $variation, array $images): void
    {

        if (empty($images)) {
            return;
        }



        $toDelete = collect($images)
            ->where('delete', true)
            ->pluck('id')
            ->filter();

        if ($toDelete->isNotEmpty()) {
            $variation->images()
                ->whereIn('id', $toDelete)
                ->get()
                ->each(function ($img) {
                    Storage::disk('public')->delete($img->image_path);
                    $img->delete();
                });
        }

        foreach ($images as $imageData) {

            //  skip deleted items from update/create flow
            if (!empty($imageData['delete']) && !empty($imageData['id'])) {
                continue;
            }

            if (!empty($imageData['id'])) {
                $image = $variation->images()->find($imageData['id']);
                if (!$image) continue;

                $image->update([
                    'type'       => $imageData['type'] ?? $image->type,
                    'side'       => $imageData['side'] ?? null,
                    'is_default' => $imageData['is_default'] ?? false,
                    'sort_order' => $imageData['sort_order'] ?? 0,
                ]);

                continue;
            }

            if (!isset($imageData['file'])) continue;

            $file = $imageData['file'];
            $path = $file->store("products/{$variation->product_id}/variations", 'public');

            $variation->images()->create([
                'image_path' => $path,
                'type'       => $imageData['type'],
                'side'       => $imageData['side'] ?? null,
                'is_default' => $imageData['is_default'] ?? false,
                'sort_order' => $imageData['sort_order'] ?? 0,
            ]);
        }
    }
}
