<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        $values = [];
        foreach (array_keys(Setting::DEFAULTS) as $key) {
            $values[$key] = Setting::get($key);
        }

        return view('settings.edit', ['values' => $values]);
    }

    /** Setting key => [folder, name of its "remove" checkbox]. Each picture can be uploaded, replaced or removed. */
    private const IMAGES = [
        'hospital_logo' => ['logos', 'remove_logo'],
        'login_image' => ['login', 'remove_login_image'],
    ];

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $data = $request->safe()->except(['hospital_logo', 'remove_logo', 'login_image', 'remove_login_image']);

        foreach (self::IMAGES as $key => [$folder, $removeBox]) {
            $old = Setting::get($key);

            if ($request->hasFile($key)) {                       // a new upload replaces the old picture
                $data[$key] = $request->file($key)->store($folder, 'public');
            } elseif ($request->boolean($removeBox)) {           // "remove" goes back to the default
                $data[$key] = null;
            } else {
                continue;
            }

            if ($old) {
                Storage::disk('public')->delete($old);
            }
        }

        Setting::setMany($data);

        return back()->with('success', 'Settings saved.');
    }
}
