<?php

namespace App\Enums;

enum OnboardingState: string
{
    case NEW = 'NEW';
    case AWAITING_CONSENT = 'AWAITING_CONSENT';
    case AWAITING_AGE = 'AWAITING_AGE';
    case AWAITING_GENDER = 'AWAITING_GENDER';
    case READY_FOR_RECORDINGS = 'READY_FOR_RECORDINGS';
    case COLLECTING = 'COLLECTING';
    case COMPLETED = 'COMPLETED';
}
