<?php

namespace App\Enums;

enum SplitStatus: string
{
    case Pending = 'pending';                    // order not paid yet
    case Held = 'held';                          // deferred mode: waiting for training completion
    case Released = 'released';                  // released, waiting for the payout to go through
    case PaidOut = 'paid_out';                   // money is in the recipient's bank / gateway account
    case Reversed = 'reversed';                  // refunded before it reached the recipient
    case Owed = 'owed';                          // HRDC commission the provider owes SkillMatch, kept from their next payout
    case ClawbackRequired = 'clawback_required'; // refunded after it reached the recipient: they owe it back
}
