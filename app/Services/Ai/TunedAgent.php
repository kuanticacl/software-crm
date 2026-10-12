<?php

namespace App\Services\Ai;

use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\HasProviderOptions;

class TunedAgent extends AnonymousAgent implements HasProviderOptions
{
    use TunesAgent;
}
