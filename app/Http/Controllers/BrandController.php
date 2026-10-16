<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BrandController extends Controller
{
    /**
     * The clinic logo, streamed by the app so it works without the public/storage symlink.
     * Public on purpose: the login page shows it. The ?v= in logo_url() changes when the logo does.
     */
    public function logo(): BinaryFileResponse
    {
        $path = setting('hospital_logo');
        abort_unless($path && Storage::disk('public')->exists($path), 404);

        return response()->file(Storage::disk('public')->path($path), ['Cache-Control' => 'public, max-age=604800']);
    }

    /** The picture on the login page (public, like the logo). */
    public function loginImage(): BinaryFileResponse
    {
        $path = setting('login_image');
        abort_unless($path && Storage::disk('public')->exists($path), 404);

        return response()->file(Storage::disk('public')->path($path), ['Cache-Control' => 'public, max-age=604800']);
    }
}
