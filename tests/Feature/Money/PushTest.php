<?php

use App\Filament\Pages\Today;
use App\Services\Push\PushNotifier;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\VAPID;

it('stays silent while the keys are not configured', function () {
    config(['services.webpush.public_key' => null, 'services.webpush.private_key' => null]);
    $owner = owner();
    expect(app(PushNotifier::class)->enabled())->toBeFalse()
        ->and(app(PushNotifier::class)->send($owner, 'x'))->toBe(0);
    $this->actingAs($owner)->get(Today::getUrl())->assertOk()->assertDontSee('mouhimEnablePush = async');
});

it('registers the phone and pushes every in-app notification to it', function () {
    $keys = VAPID::createVapidKeys(); // throwaway test keys
    config(['services.webpush.public_key' => $keys['publicKey'], 'services.webpush.private_key' => $keys['privateKey']]);
    $owner = owner();
    $this->actingAs($owner);

    $this->get(Today::getUrl())->assertOk()->assertSee('mouhimEnablePush = async', false)->assertSee(__('push.enable'));
    $this->postJson(route('push.subscribe'), ['endpoint' => 'https://push.example.test/abc', 'keys' => ['p256dh' => 'BKey', 'auth' => 'auth']])->assertOk();
    $this->postJson(route('push.subscribe'), ['endpoint' => 'http://insecure.test/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']])->assertStatus(422);
    expect(DB::table('push_subscriptions')->where('user_id', $owner->id)->count())->toBe(1);

    $sent = [];
    $this->mock(PushNotifier::class, function ($mock) use (&$sent) {
        $mock->shouldReceive('send')->andReturnUsing(function ($user, $title) use (&$sent) {
            $sent[] = $title;

            return 1;
        });
        $mock->shouldReceive('enabled')->andReturnTrue();
    });
    Notification::make()->title('Écart de caisse à M01')->sendToDatabase($owner);

    expect($sent)->toBe(['Écart de caisse à M01']);
});

it('refuses anonymous subscriptions', function () {
    $this->postJson('/push/subscribe', ['endpoint' => 'https://push.example.test/abc'])->assertUnauthorized();
});
