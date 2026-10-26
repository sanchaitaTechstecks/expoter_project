<?php

namespace App\Utility;

use App\Models\Upload;
use Illuminate\Http\UploadedFile;

class PrescriptionUtility
{
    /**
     * Check if any product in the cart requires a prescription
     *
     * @param  iterable  $carts  cart items (each having product_id)
     * @return boolean
     */
    public static function cartRequiresPrescription($carts)
    {
        $productIds = collect($carts)->pluck('product_id')->unique()->filter()->all();

        if (count($productIds) == 0) {
            return false;
        }

        return \App\Models\Product::whereIn('id', $productIds)
            ->where('prescription_required', 1)
            ->exists();
    }

    /**
     * Store an uploaded prescription image (single file upload)
     *
     * @param  UploadedFile  $file
     * @param  int|null  $userId
     * @return Upload|null
     */
    public static function storePrescriptionImage($file, $userId = null)
    {
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, $allowedExtensions)) {
            return null;
        }

        $upload = new Upload;
        $upload->file_original_name = null;

        $arr = explode('.', $file->getClientOriginalName());
        for ($i = 0; $i < count($arr) - 1; $i++) {
            $upload->file_original_name .= $i == 0 ? $arr[$i] : '.' . $arr[$i];
        }

        $path = $file->store('uploads/all', 'local');

        try {
            if (get_setting('disable_image_optimization') != 1 && extension_loaded('gd')) {
                $img = \Image::make($file->getRealPath())->encode();
                $height = $img->height();
                $width = $img->width();
                if ($width > $height && $width > 1500) {
                    $img->resize(1500, null, function ($constraint) {
                        $constraint->aspectRatio();
                    });
                } elseif ($height > 1500) {
                    $img->resize(null, 800, function ($constraint) {
                        $constraint->aspectRatio();
                    });
                }
                $img->save(base_path('public/') . $path);
                clearstatcache();
            }
        } catch (\Exception $e) {
            // keep original file if optimization fails
        }

        if (env('FILESYSTEM_DRIVER') == 's3') {
            \Storage::disk('s3')->put(
                $path,
                file_get_contents(base_path('public/') . $path),
                ['visibility' => 'public']
            );
            if (file_exists(base_path('public/') . $path)) {
                unlink(base_path('public/') . $path);
            }
        }

        $upload->extension = $extension;
        $upload->file_name = $path;
        $upload->user_id = $userId;
        $upload->type = 'image';
        $upload->file_size = $file->getSize();
        $upload->save();

        return $upload;
    }

    /**
     * Resolve the prescription upload from the request.
     * Supports a direct file upload (prescription_image) or an already
     * uploaded file id / path (prescription_upload_id) e.g. from the
     * aiz-uploader or the mobile app base64 upload endpoint.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int|null  $userId
     * @return Upload|null  null when nothing valid was provided
     */
    public static function resolveUploadFromRequest($request, $userId = null)
    {
        if ($request->hasFile('prescription_image')) {
            return self::storePrescriptionImage($request->file('prescription_image'), $userId);
        }

        $uploadId = $request->prescription_upload_id;
        if (is_array($uploadId)) {
            $uploadId = $uploadId[0] ?? null;
        }
        //aiz-uploader sends comma separated ids inside the hidden input
        if (is_string($uploadId) && str_contains($uploadId, ',')) {
            $uploadId = explode(',', $uploadId)[0];
        }

        if (filled($uploadId) && is_numeric($uploadId)) {
            return Upload::find($uploadId);
        }

        return null;
    }
}
