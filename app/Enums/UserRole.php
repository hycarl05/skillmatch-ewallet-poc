<?php

namespace App\Enums;

enum UserRole: string
{
    case Individual = 'individual';
    case CompanyHr = 'company_hr';
    case Staff = 'staff';
    case Provider = 'provider';
    case Freelancer = 'freelancer';
    case Platform = 'platform';

    public function canBuy(): bool
    {
        return in_array($this, [self::Individual, self::CompanyHr, self::Provider], true);
    }

    public function sellsTraining(): bool
    {
        return in_array($this, [self::Provider, self::Freelancer], true);
    }
}
