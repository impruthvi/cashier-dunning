<?php

namespace Impruthvi\CashierDunning\Tests\Support;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The other door a dunning policy reaches a customer through.
 *
 * Applications that do not call the mailer directly usually notify instead, and
 * a guard that only understood `MessageSending` would let those through while
 * reporting that nothing was delivered.
 */
class DunningNotification extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->line('Your payment failed.');
    }
}
