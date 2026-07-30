<?php

namespace App\Enums;

enum CaseStatus: string
{
    case Open = 'open';
    case Investigating = 'investigating';
    case Actioned = 'actioned';
    case Resolved = 'resolved';
    case Closed = 'closed';
}
