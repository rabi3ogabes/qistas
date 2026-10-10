<?php

namespace App\Notifications\Push;

use App\Models\PushToken;

/** Delivers one push to one phone. Bound in AppServiceProvider: Firebase once the owner gives its key, else the log. */
interface PushSender
{
    /** True when the push was handed over; false when it was not (a phone found gone is revoked by the sender). */
    public function send(PushToken $token, PushMessage $message): bool;
}
