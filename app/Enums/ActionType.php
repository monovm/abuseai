<?php

namespace App\Enums;

enum ActionType: string
{
    case Opened = 'opened';
    case StatusChanged = 'status_changed';
    case Assigned = 'assigned';
    case NoteAdded = 'note_added';
    case Suspended = 'suspended';
    case Unsuspended = 'unsuspended';
    case IpBlocked = 'ip_blocked';
    case IpUnblocked = 'ip_unblocked';
    case EmailSent = 'email_sent';
    case Escalated = 'escalated';
    case AiScored = 'ai_scored';
    case AiDrafted = 'ai_drafted';
    case AppealReceived = 'appeal_received';
    case AppealDecided = 'appeal_decided';
    case Closed = 'closed';
}
