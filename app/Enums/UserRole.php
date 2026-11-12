<?php

namespace App\Enums;

enum UserRole: string
{
    case EMPLOYEE = 'employee';
    case MANAGER = 'manager';
    case OPERATIONS = 'operations';
}
