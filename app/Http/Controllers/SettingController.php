<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSettingsRequest;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends Controller
{
    public function edit(): Response
    {
        $defaults = UpdateSettingsRequest::defaults();

        $settings = [];
        foreach ($defaults as $key => $default) {
            $settings[$key] = Setting::getValue($key, $default);
        }

        return Inertia::render('Settings/Edit', [
            'settings' => $settings,
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        foreach ($request->validated() as $key => $value) {
            Setting::setValue($key, $value, UpdateSettingsRequest::storageGroup($key));
        }

        return back()->with('success', 'Ayarlar saxlanıldı.');
    }
}
