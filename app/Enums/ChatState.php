<?php

namespace App\Enums;

enum ChatState: string
{
    case PengumpulanData = 'STATE_1_PENGUMPULAN_DATA';
    case Konfirmasi = 'STATE_2_KONFIRMASI';
    case Done = 'STATE_3_DONE';
}
