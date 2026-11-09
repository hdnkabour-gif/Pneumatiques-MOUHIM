<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PushSubscriptionController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:500', 'starts_with:https://'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);
        DB::table('push_subscriptions')->updateOrInsert(['endpoint' => $data['endpoint']], [
            'user_id' => $request->user()->id, 'public_key' => $data['keys']['p256dh'], 'auth_token' => $data['keys']['auth'],
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }
}
