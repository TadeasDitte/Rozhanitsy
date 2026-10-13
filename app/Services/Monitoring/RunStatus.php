<?php

namespace App\Services\Monitoring;

enum RunStatus: string
{
    case Succeeded = 'succeeded';
    case Warning = 'warning';
    case Failed = 'failed';

    /**
     * Discord embed sidebar color.
     */
    public function color(): int
    {
        return match ($this) {
            self::Succeeded => 0x2ECC71,
            self::Warning => 0xF1C40F,
            self::Failed => 0xE74C3C,
        };
    }
}
