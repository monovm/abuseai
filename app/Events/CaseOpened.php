<?php

namespace App\Events;

use App\Models\AbuseCase;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CaseOpened
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public AbuseCase $case,
    ) {}
}
