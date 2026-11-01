<?php

declare(strict_types=1);

namespace App\Ollama;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Tenký klient nativního API Ollamy (/api/embed, /api/chat, /api/show, /api/ps).
 *
 * Záměrně bez abstrakce: každá volba, která ovlivňuje výsledek (num_ctx, truncate,
 * temperature, seed), se posílá explicitně a vrací se i metriky, které Ollama počítá.
 */
final class OllamaClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $ollamaUrl,
    ) {
    }

    /**
     * @param list<string> $inputs
     * @param bool $truncate výchozí hodnota Ollamy je true = příliš dlouhý vstup se potichu ořízne;
     *                       my posíláme false, ať dlouhý úryvek skončí chybou
     *
     * @return array{vectors: list<list<float>>, prompt_tokens: int, total_ms: float, load_ms: float, wall_ms: float}
     */
    public function embed(string $model, array $inputs, bool $truncate = false, array $options = []): array
    {
        $body = ['model' => $model, 'input' => $inputs, 'truncate' => $truncate];
        if ([] !== $options) {
            $body['options'] = $options;
        }

        $started = hrtime(true);
        $data = $this->post('/api/embed', $body);

        return [
            'vectors' => $data['embeddings'],
            'prompt_tokens' => (int) ($data['prompt_eval_count'] ?? 0),
            'total_ms' => self::ms($data['total_duration'] ?? 0),
            'load_ms' => self::ms($data['load_duration'] ?? 0),
            'wall_ms' => round((hrtime(true) - $started) / 1e6, 1),
        ];
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed>|null $format JSON schéma pro strukturovaný výstup
     *
     * @return array{content: string, prompt_tokens: int, output_tokens: int, total_ms: float, load_ms: float, prompt_ms: float, eval_ms: float, wall_ms: float}
     */
    public function chat(string $model, array $messages, array $options = [], ?array $format = null, ?string $keepAlive = null): array
    {
        $body = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'think' => false,
            'options' => $options + ['temperature' => 0, 'seed' => 42],
        ];
        if (null !== $format) {
            $body['format'] = $format;
        }
        if (null !== $keepAlive) {
            $body['keep_alive'] = $keepAlive;
        }

        $started = hrtime(true);
        $data = $this->post('/api/chat', $body);

        return [
            'content' => trim($data['message']['content'] ?? ''),
            'prompt_tokens' => (int) ($data['prompt_eval_count'] ?? 0),
            'output_tokens' => (int) ($data['eval_count'] ?? 0),
            'total_ms' => self::ms($data['total_duration'] ?? 0),
            'load_ms' => self::ms($data['load_duration'] ?? 0),
            'prompt_ms' => self::ms($data['prompt_eval_duration'] ?? 0),
            'eval_ms' => self::ms($data['eval_duration'] ?? 0),
            'wall_ms' => round((hrtime(true) - $started) / 1e6, 1),
        ];
    }

    /** Vyhodí model z paměti (keep_alive 0), aby šlo měřit načítání. */
    public function unload(string $model, bool $embedding = false): void
    {
        $embedding
            ? $this->post('/api/embed', ['model' => $model, 'input' => [], 'keep_alive' => 0])
            : $this->post('/api/chat', ['model' => $model, 'messages' => [], 'keep_alive' => 0]);
    }

    /** @return array<string, mixed> */
    public function info(string $model): array
    {
        $show = $this->post('/api/show', ['model' => $model]);
        $digest = null;
        foreach ($this->get('/api/tags')['models'] as $m) {
            if (\in_array($m['name'], [$model, $model.':latest'], true)) {
                $digest = $m['digest'];
            }
        }
        $ctx = null;
        foreach ($show['model_info'] ?? [] as $key => $value) {
            if (str_ends_with($key, '.context_length')) {
                $ctx = $value;
            }
        }

        return [
            'model' => $model,
            'digest' => $digest,
            'parameter_size' => $show['details']['parameter_size'] ?? null,
            'quantization' => $show['details']['quantization_level'] ?? null,
            'family' => $show['details']['family'] ?? null,
            'context_length_modelu' => $ctx,
        ];
    }

    /** @return list<array<string, mixed>> modely aktuálně v paměti (GPU/CPU) */
    public function ps(): array
    {
        return $this->get('/api/ps')['models'] ?? [];
    }

    public function version(): string
    {
        return $this->get('/api/version')['version'];
    }

    /** @return array<string, mixed> */
    private function post(string $path, array $body): array
    {
        return $this->http->request('POST', rtrim($this->ollamaUrl, '/').$path, ['json' => $body, 'timeout' => 900])->toArray();
    }

    /** @return array<string, mixed> */
    private function get(string $path): array
    {
        return $this->http->request('GET', rtrim($this->ollamaUrl, '/').$path)->toArray();
    }

    private static function ms(int|float $ns): float
    {
        return round($ns / 1e6, 1);
    }
}
