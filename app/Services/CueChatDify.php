<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;

class CueChatDify
{
    public const FIELDS = ['date_text', 'start_time_text', 'duration_text', 'table_text'];

    private const INTENTS = ['rates', 'hours', 'equipment', 'location', 'policy', 'reservation', 'booking_status', 'clarify', 'unsupported'];

    public function __construct(private ClockInterface $clock = new MonotonicClock) {}

    public function startTurn(): CueChatDifyTurn
    {
        return new CueChatDifyTurn($this->clock);
    }

    /**
     * @param  array<string, string>  $draft
     * @return array{intent: string, details: array<string, string>}|null
     */
    public function classify(string $message, string $user, array $draft, CueChatDifyTurn $turn): ?array
    {
        return $this->call('workflows/run', 'intake_key', [
            'user' => $user,
            'inputs' => [
                'message' => $message,
                'booking_context' => $draft === [] ? '' : json_encode($draft, JSON_THROW_ON_ERROR),
            ],
        ], $turn);
    }

    /** An empty answer indicates a valid staff referral. */
    public function faq(string $message, string $user, CueChatDifyTurn $turn): ?string
    {
        return $this->call('chat-messages', 'faq_key', [
            'user' => $user,
            'inputs' => (object) [],
            'query' => $message,
        ], $turn);
    }

    /** @param array<string, mixed> $payload */
    private function call(string $endpoint, string $credential, array $payload, CueChatDifyTurn $turn): array|string|null
    {
        $startedAt = $turn->remaining();
        $key = config('services.dify.'.$credential);
        $attempts = 0;
        $status = null;
        $retryStatus = null;
        $data = [];
        $result = null;
        $outcome = 'missing_key';

        if (is_string($key) && trim($key) !== '') {
            do {
                $remaining = $turn->remaining();
                if ($remaining <= 0) {
                    $outcome = 'deadline_exceeded';
                    break;
                }

                $attempts++;
                $status = null;
                $data = [];
                try {
                    $response = Http::withToken($key)->acceptJson()->withOptions(['allow_redirects' => false])
                        ->connectTimeout(min(3, $remaining))->timeout(min(8.0, $remaining))
                        ->post(rtrim(config('services.dify.base_url'), '/').'/'.$endpoint, [
                            ...$payload,
                            'response_mode' => 'blocking',
                        ]);
                    $status = $response->status();
                    $body = $response->json();
                    $data = is_array($body) ? $body : [];
                    if ($response->successful()) {
                        [$outcome, $result] = $endpoint === 'workflows/run'
                            ? $this->intakeResult($data)
                            : $this->faqResult($data);
                        break;
                    }
                    $outcome = 'http_error';
                    $retryable = in_array($status, [502, 503, 504], true);
                } catch (ConnectionException) {
                    $outcome = 'connection_error';
                    $retryable = true;
                }
                if (! $retryable || ! $turn->waitForRetry()) {
                    break;
                }
                $retryStatus = $status;
            } while (true);

            if ($turn->remaining() <= 0) {
                $outcome = 'deadline_exceeded';
                $result = null;
            }
        }

        $level = in_array($outcome, ['succeeded', 'staff_referral'], true) ? 'info' : 'warning';
        Log::$level('CueChat Dify request', [
            'endpoint' => $endpoint,
            'outcome' => $outcome,
            'elapsed_ms' => (int) round(($startedAt - $turn->remaining()) * 1000),
            'attempts' => $attempts,
            'http_status' => $status,
            'retry_http_status' => $retryStatus,
            'provider_code' => $this->providerCode($data['code'] ?? data_get($data, 'data.error.code')),
            'run_id' => $this->identifier($data['workflow_run_id'] ?? data_get($data, 'data.id')),
            'task_id' => $this->identifier($data['task_id'] ?? null),
        ]);

        return $result;
    }

    /** @param array<string, mixed> $data */
    private function intakeResult(array $data): array
    {
        $status = data_get($data, 'data.status');
        if (in_array($status, ['failed', 'stopped', 'partial-succeeded'], true)) {
            return ['workflow_failed', null];
        }

        $result = data_get($data, 'data.outputs.result');
        if ($status !== 'succeeded' || ! is_array($result) || ($result['version'] ?? null) !== '1'
            || ! in_array($result['intent'] ?? null, self::INTENTS, true) || ! is_array($result['details'] ?? null)) {
            return ['invalid_output', null];
        }

        $details = [];
        foreach (self::FIELDS as $field) {
            $value = $result['details'][$field] ?? '';
            if (! is_string($value) || mb_strlen($value) > 100) {
                return ['invalid_output', null];
            }
            if (trim($value) !== '') {
                $details[$field] = trim($value);
            }
        }

        return ['succeeded', ['intent' => $result['intent'], 'details' => $details]];
    }

    /** @param array<string, mixed> $data */
    private function faqResult(array $data): array
    {
        $answer = $data['answer'] ?? null;
        if (! is_string($answer) || trim($answer) === '' || mb_strlen($answer) > 1200) {
            return ['invalid_output', null];
        }
        $answer = trim($answer);

        if (preg_match('/^(?:I can[’\']t (?:verify that|check availability) here\. Please (?:contact CueLab staff|\[contact CueLab staff\]\(https?:\/\/[^\s)]+\))\.|To request a table, open the \[CueLab booking page\]\(https?:\/\/[^\s)]+\)\. Staff will review your request\.)$/iuD', $answer)) {
            return ['staff_referral', ''];
        }
        // Rates, live availability, hours and holds belong to the website.
        if (preg_match('/\b(?:PHP\s*\d[\d,.]*|\d+\s*(?:AM|PM|minutes?)|availability|holds?|contact CueLab staff|booking page)\b|₱|https?:\/\//iu', $answer)) {
            return ['invalid_output', null];
        }

        return ['succeeded', $answer];
    }

    private function providerCode(mixed $value): ?string
    {
        return in_array($value, [
            'unauthorized', 'forbidden', 'invalid_param', 'invalid_api_key', 'not_workflow_app',
            'not_chat_app', 'app_unavailable', 'not_published', 'provider_not_initialize',
            'provider_quota_exceeded', 'model_currently_not_support', 'completion_request_error',
            'rate_limit_error', 'too_many_requests', 'service_unavailable', 'internal_server_error',
            'resource_exhausted', 'permission_denied', 'invalid_argument', 'deadline_exceeded',
        ], true) ? $value : null;
    }

    private function identifier(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $value) ? $value : null;
    }
}
