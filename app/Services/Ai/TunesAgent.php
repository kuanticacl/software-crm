<?php

namespace App\Services\Ai;

use Laravel\Ai\Enums\Lab;

/**
 * Parámetros ajustables desde «Inteligencia artificial → Comportamiento». El SDK los lee de estos métodos
 * (temperature, maxTokens, maxSteps, providerOptions); null deja el valor por defecto del proveedor.
 */
trait TunesAgent
{
    public ?float $tunedTemperature = null;

    public ?int $tunedMaxTokens = null;

    public ?int $tunedMaxSteps = null;

    /** @var array<string, mixed> */
    public array $tunedOpenAiOptions = [];

    public function temperature(): ?float
    {
        return $this->tunedTemperature;
    }

    public function maxTokens(): ?int
    {
        return $this->tunedMaxTokens;
    }

    public function maxSteps(): ?int
    {
        return $this->tunedMaxSteps;
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        return ($provider instanceof Lab ? $provider->value : $provider) === 'openai' ? $this->tunedOpenAiOptions : [];
    }
}
