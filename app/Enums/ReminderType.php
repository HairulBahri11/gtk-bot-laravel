<?php

namespace App\Enums;

enum ReminderType: string
{
    case H1 = 'h1';
    case ThreeHours = '3jam';
    case OneHour = '1jam';
}
