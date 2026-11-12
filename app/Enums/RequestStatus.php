<?php

namespace App\Enums;

enum RequestStatus: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case RETURNED = 'returned';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
}
