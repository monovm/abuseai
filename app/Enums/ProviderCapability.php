<?php

namespace App\Enums;

/**
 * Operator actions a provider may expose. The case-detail UI shows a
 * button only when the active provider declares the matching capability,
 * so absent integrations don't lead to "click → 405 Method Not Allowed"
 * dead-ends.
 */
enum ProviderCapability: string
{
    case Lookup = 'lookup';            // IP → service record
    case Suspend = 'suspend';
    case Unsuspend = 'unsuspend';
    case Terminate = 'terminate';
    case Reboot = 'reboot';
    case OpenTicket = 'open_ticket';   // billing-system support ticket
}
