<?php

namespace Tests;

use Watchtower\Laravel\Transport\Frame;
use Watchtower\Laravel\Transport\Protocol;

final class SidecarReply
{
    public static function wire(): string
    {
        return Frame::encode([
            'type' => Protocol::TYPE_WELCOME,
            'accepted' => true,
            'protocol_version' => Protocol::VERSION,
            'session_id' => 'sess_test',
            'max_batch' => Protocol::MAX_BATCH,
        ]).Frame::encode([
            'type' => Protocol::TYPE_ACK,
            'sequence' => 1,
            'accepted' => 1,
            'rejected' => 0,
        ]);
    }
}
