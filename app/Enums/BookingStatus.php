<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Waitlist = 'waitlist';
    case Booked = 'booked';
    case Confirmed = 'confirmed';
    case Arrived = 'arrived';
    case Selesai = 'selesai';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';
    case Rescheduled = 'rescheduled';
}
