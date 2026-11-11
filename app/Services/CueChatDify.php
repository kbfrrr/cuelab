<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CueChatDify
{
    public const FIELDS = ['date_text', 'start_time_text', 'duration_text', 'table_text'];

    private const INTENTS = ['rates', 'hours', 'equipment', 'location', 'policy', 'reservation', 'booking_status', 'clarify', 'unsupported'];

    /**
     * @param  array<string, string>  $draft
     * @return array{intent: string, details: array<string, string>}|null
     */
    public function classify(string $message, string $user, array $draft): ?array
    {
        $data = $this->call('workflows/run', 'intake_key', [
            'user' => $user,
            'inputs' => [
                'message' => $message,
                'booking_context' => $draft === [] ? '' : json_encode($draft, JSON_THROW_ON_ERROR),
            ],
        ]);
        $result = data_get($data, 'data.outputs.result');
        if (data_get($data, 'data.status') !== 'succeeded'
            || ! is_array($result) || ($result['version'] ?? null) !== '1'
            || ! in_array($result['intent'] ?? null, self::INTENTS, true)
            || ! is_array($result['details'] ?? null)) {
            return null;
        }

        $details = [];
        foreach (self::FIELDS as $field) {
            $value = $result['details'][$field] ?? '';
            if (! is_string($value) || mb_strlen($value) > 100) {
                return null;
            }
            if (trim($value) !== '') {
                $details[$field] = trim($value);
            }
        }

        return ['intent' => $result['intent'], 'details' => $details];
    }

    public function faq(string $message, string $user): ?string
    {
        $data = $this->call('chat-messages', 'faq_key', ['user' => $user, 'inputs' => (object) [], 'query' => $message]);
        $answer = $data['answer'] ?? null;

        // The Chatflow returns a stored FAQ answer or a standard referral.
        // Rates, live availability, hours and holds belong to the website.
        if (! is_string($answer) || trim($answer) === '' || mb_strlen($answer) > 1200
            || preg_match('/\b(?:PHP\s*\d[\d,.]*|\d+\s*(?:AM|PM|minutes?)|availability|holds?|contact CueLab staff|booking page)\b|₱|https?:\/\//iu', $answer)) {
            return null;
        }

        return trim($answer);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $endpoint, string $credential, array $payload): array
    {
        $key = config('services.dify.'.$credential);
        if (! is_string($key) || $key === '') {
            return [];
        }

        try {
            $response = Http::withToken($key)->acceptJson()->connectTimeout(3)->timeout(10)
                ->post(rtrim(config('services.dify.base_url'), '/').'/'.$endpoint, [
                    ...$payload,
                    'response_mode' => 'blocking',
                ]);
        } catch (ConnectionException) {
            Log::warning('CueChat Dify connection failed', ['endpoint' => $endpoint]);

            return [];
        }

        if (! $response->successful()) {
            Log::warning('CueChat Dify request failed', ['endpoint' => $endpoint, 'status' => $response->status()]);

            return [];
        }

        return is_array($response->json()) ? $response->json() : [];
    }
}
