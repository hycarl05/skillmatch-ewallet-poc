<?php

namespace App\Enums;

enum PayoutMode: string
{
    case Instant = 'instant';
    case Deferred = 'deferred';
}
