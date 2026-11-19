<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';                 // money collected by the gateway
    case Completed = 'completed';       // trainer marked the training as delivered
    case Confirmed = 'confirmed';       // buyer confirmed (or auto-confirmed); money released
    case Failed = 'failed';
    case Refunded = 'refunded';
}
