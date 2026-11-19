<?php

namespace App\Enums;

enum SplitRole: string
{
    case Provider = 'provider';
    case Freelancer = 'freelancer';
    case Platform = 'platform';
}
