<?php

namespace App\Models;

enum UserRole: string
{
    case Owner = 'owner';
    case Clerk = 'clerk';
}
