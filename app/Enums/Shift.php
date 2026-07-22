<?php

namespace App\Enums;

enum Shift: string
{
    case Pagi = 'pagi';
    case Sore = 'sore';
    case Malam = 'malam';

    public function label(): string
    {
        return match ($this) {
            self::Pagi => 'Pagi',
            self::Sore => 'Sore',
            self::Malam => 'Malam',
        };
    }
}
