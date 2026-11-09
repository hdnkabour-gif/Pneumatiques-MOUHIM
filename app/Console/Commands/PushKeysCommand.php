<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/** Generates the keys for phone notifications: copy them into the Railway variables, never into the code. */
class PushKeysCommand extends Command
{
    protected $signature = 'push:keys';

    protected $description = 'Generate VAPID keys for phone (Web Push) notifications';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->warn('Add both lines to the Railway variables of the web service. Keep the private key secret.');

        return self::SUCCESS;
    }
}
