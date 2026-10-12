<?php

namespace App\Services\Ai;

use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\StructuredAnonymousAgent;

class TunedStructuredAgent extends StructuredAnonymousAgent implements HasProviderOptions
{
    use TunesAgent;
}
