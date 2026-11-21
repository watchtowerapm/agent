<?php

namespace Tests;

use Watchtower\Laravel\Transport\Frame;
use Watchtower\Laravel\Transport\Mac;
use Watchtower\Laravel\Transport\Protocol;

use function config;

final class SidecarReply
{
    /** The nonce the application sends in tests (see TestCase::setUp()). */
    public const CLIENT_NONCE = '00112233445566778899aabbccddeeff';

    public const SERVER_NONCE = 'ffeeddccbbaa99887766554433221100';

    public static function key(): string
    {
        return Mac::key((string) config('watchtower.token'));
    }

    public static function wire(): string
    {
        return self::welcome().self::ack();
    }

    /**
     * A WELCOME signed with the application's token, as a real sidecar would send it.
     */
    public static function welcome(int $maxBatch = Protocol::MAX_BATCH): string
    {
        return Frame::encode([
            'type' => Protocol::TYPE_WELCOME,
            'accepted' => true,
            'protocol_version' => Protocol::VERSION,
            'session_id' => 'sess_test',
            'max_batch' => $maxBatch,
            'nonce' => self::SERVER_NONCE,
            'mac' => Mac::welcome(self::key(), self::CLIENT_NONCE, 'sess_test', self::SERVER_NONCE),
        ]);
    }

    public static function ack(int $accepted = 1): string
    {
        return Frame::encode([
            'type' => Protocol::TYPE_ACK,
            'sequence' => 1,
            'accepted' => $accepted,
            'rejected' => 0,
        ]);
    }
}
