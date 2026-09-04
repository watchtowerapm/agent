<?php

namespace Tests\Unit\Hooks;

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as MailerSentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;
use Watchtower\Laravel\Hooks\MailListener;

class MailListenerTest extends TestCase
{
    public function test_it_gracefully_handles_exceptions(): void
    {
        $thrownInMailSensor = false;
        $this->core->sensor->mailSensor = function () use (&$thrownInMailSensor): void {
            $thrownInMailSensor = true;

            throw new RuntimeException('Whoops!');
        };
        $event = new MessageSent(new SentMessage(new MailerSentMessage(
            new RawMessage('Hello world'), new Envelope(new Address('watchtower@example.test'), [new Address('tim@laravel.com')])
        )));

        $handler = new MailListener($this->core);
        $handler($event);

        $this->assertTrue($thrownInMailSensor);
        $this->assertSame(1, $this->core->executionState->exceptions);
    }
}
