<?php

namespace App\Services\Ai;

use App\Models\AiProvider;
use App\Models\Setting;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\StructuredAnonymousAgent;

/**
 * Comportamiento de la IA configurable por la empresa: instrucciones propias (para todo o solo para el Agent),
 * creatividad, largo de respuesta, pasos del Agent y nivel de razonamiento (modelos GPT-5 / o-series).
 * Las reglas base de cada función (seguridad, herramientas, formato) se mantienen siempre.
 */
class AiTuning
{
    public const TEMPERATURES = ['' => 'Predeterminada del modelo', '0.2' => 'Precisa (0,2)', '0.5' => 'Equilibrada (0,5)', '0.8' => 'Creativa (0,8)', '1.1' => 'Muy creativa (1,1)'];

    public const REASONING = ['' => 'Predeterminado', 'low' => 'Bajo (más rápido)', 'medium' => 'Medio', 'high' => 'Alto (más preciso, más lento)'];

    /** @return array{instructions: string, agent_instructions: string, temperature: string, reasoning_effort: string, max_tokens: ?int, agent_max_steps: ?int, transcription_prompt: string} */
    public static function settings(): array
    {
        return [
            'instructions' => (string) Setting::get('ai.instructions', ''),
            'agent_instructions' => (string) Setting::get('ai.agent_instructions', ''),
            'temperature' => (string) Setting::get('ai.temperature', ''),
            'reasoning_effort' => (string) Setting::get('ai.reasoning_effort', ''),
            'max_tokens' => is_numeric(Setting::get('ai.max_tokens')) ? (int) Setting::get('ai.max_tokens') : null,
            'agent_max_steps' => is_numeric(Setting::get('ai.agent_max_steps')) ? (int) Setting::get('ai.agent_max_steps') : null,
            'transcription_prompt' => (string) Setting::get('ai.transcription_prompt', ''),
        ];
    }

    /** Devuelve el agente con los ajustes aplicados (los agentes propios que no son anónimos se dejan igual). */
    public function tune(Agent $agent, string $feature, AiProvider $provider): Agent
    {
        if (! $agent instanceof AnonymousAgent) {
            return $agent;
        }

        $s = self::settings();
        // AgentWeb atiende a público externo: no recibe las instrucciones generales de la empresa (pensadas para uso interno).
        $extra = $feature === 'agentweb' ? '' : trim($s['instructions']."\n\n".($feature === 'agent' ? $s['agent_instructions'] : ''));
        $instructions = $agent->instructions.($extra !== '' ? "\n\nInstrucciones adicionales de la empresa (respétalas, salvo que contradigan las reglas anteriores):\n".$extra : '');

        if ($agent instanceof StructuredAnonymousAgent) {
            $tuned = new TunedStructuredAgent($instructions, $agent->messages, $agent->tools);
            $tuned->schema = $agent->schema;
        } else {
            $tuned = new TunedAgent($instructions, $agent->messages, $agent->tools);
        }

        $reasoning = self::isReasoningModel((string) $provider->model);
        // Los modelos de razonamiento no aceptan temperatura: ahí se usa el nivel de razonamiento.
        if ($feature === 'agentweb') {
            // Respuestas precisas y cortas, sin depender de los ajustes generales.
            if (! $reasoning) {
                $tuned->tunedTemperature = 0.3;
            }
            $tuned->tunedMaxTokens = 700;
        } elseif (! $reasoning && is_numeric($s['temperature'])) {
            $tuned->tunedTemperature = max(0.0, min(2.0, (float) $s['temperature']));
        }
        if ($feature !== 'agentweb' && $s['max_tokens']) {
            $tuned->tunedMaxTokens = max(256, min(32000, $s['max_tokens']));
        }
        if ($feature === 'agent' && $s['agent_max_steps']) {
            $tuned->tunedMaxSteps = max(2, min(30, $s['agent_max_steps']));
        }
        if ($reasoning && $provider->driver === 'openai' && array_key_exists($s['reasoning_effort'], self::REASONING) && $s['reasoning_effort'] !== '') {
            $tuned->tunedOpenAiOptions = ['reasoning' => ['effort' => $s['reasoning_effort']]];
        }

        return $tuned;
    }

    /** Misma regla que usa el SDK para los modelos de razonamiento de OpenAI. */
    public static function isReasoningModel(string $model): bool
    {
        return (str_starts_with($model, 'gpt-5') && ! str_starts_with($model, 'gpt-5-chat'))
            || str_starts_with($model, 'gpt-6') || str_starts_with($model, 'o1') || str_starts_with($model, 'o3') || str_starts_with($model, 'o4-mini');
    }
}
