<?php

namespace App\Services\Push;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Sends the in-app notifications to the user's phones too (§16.1). Does
 * nothing while the VAPID keys are not configured; a failed push never
 * breaks the operation that triggered it.
 */
class PushNotifier
{
    public function enabled(): bool
    {
        return filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key'));
    }

    public function send(User $user, string $title, ?string $body = null, string $url = '/admin'): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $subscriptions = DB::table('push_subscriptions')->where('user_id', $user->id)->get();
        if ($subscriptions->isEmpty()) {
            return 0;
        }

        try {
            $push = new WebPush(['VAPID' => [
                'subject' => config('services.webpush.subject'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ]], ['TTL' => 3600], 10);
            $payload = json_encode(['title' => $title, 'body' => strip_tags((string) $body), 'url' => $url], JSON_UNESCAPED_UNICODE);
            foreach ($subscriptions as $s) {
                $push->queueNotification(Subscription::create(['endpoint' => $s->endpoint, 'publicKey' => $s->public_key, 'authToken' => $s->auth_token]), $payload);
            }
            $sent = 0;
            foreach ($push->flush() as $report) {
                if ($report->isSuccess()) {
                    $sent++;
                } elseif ($report->isSubscriptionExpired()) {
                    DB::table('push_subscriptions')->where('endpoint', $report->getEndpoint())->delete();
                }
            }

            return $sent;
        } catch (Throwable $e) {
            Log::warning('Push notification failed', ['error' => $e->getMessage()]);

            return 0;
        }
    }
}
