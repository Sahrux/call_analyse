<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSettingsRequest;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

class SettingController extends Controller
{
    public function show(): JsonResponse
    {
        $defaults = UpdateSettingsRequest::defaults();
        $settings = [];

        foreach ($defaults as $key => $default) {
            $settings[$key] = Setting::getValue($key, $default);
        }

        return response()->json(['settings' => $settings]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        foreach ($request->validated() as $key => $value) {
            Setting::setValue($key, $value, UpdateSettingsRequest::storageGroup($key));
        }

        return response()->json(['message' => 'Ayarlar saxlanıldı.']);
    }
}
