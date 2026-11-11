<?php

namespace App\Services;

use App\Models\BilliardTable;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingConversation
{
    private const ANY_TABLE = ['any', 'any table', 'no preference', 'kahit ano', 'kahit anong table', 'kahit alin'];

    private const QUESTIONS = [
        'date_text' => 'What date would you like to play?',
        'start_time_text' => 'What start time would you prefer?',
        'duration_text' => 'How long would you like to play?',
        'table_text' => 'Which table would you prefer? You can say “any.”',
    ];

    public function __construct(private ReservationService $reservations) {}

    /** @return array{reply: string, actions: list<array{label: string, url: string}>}|null */
    public function answerStructuredRequest(Session $session, string $question): ?array
    {
        if (! preg_match('/^may table ba (?:sa )?(?<date>.+?) ng (?<time>\d{1,2}(?::\d{2})?\s*(?:am|pm)),\s*(?<duration>[^,]+),\s*(?<table>[^,]+)$/u', $question, $parts)
            || $this->date($parts['date']) === null
            || $this->time($parts['time'], null) === null
            || $this->duration($parts['duration']) === null
            || ! in_array($parts['table'], self::ANY_TABLE, true)) {
            return null;
        }

        return $this->respond($session, [
            'date_text' => $parts['date'],
            'start_time_text' => $parts['time'],
            'duration_text' => $parts['duration'],
            'table_text' => $parts['table'],
        ]);
    }

    /** @return array{reply: string, actions: list<array{label: string, url: string}>}|null */
    public function answerPendingQuestion(Session $session, string $question): ?array
    {
        $draft = $session->get('cuechat.draft');
        if ($draft === null) {
            return null;
        }

        $nextField = array_key_first(array_diff_key(self::QUESTIONS, $draft));
        $value = match ($nextField) {
            'date_text' => preg_replace('/[,.?!]+ (?:is (?:that|it) available|may available ba)$/u', '', $question),
            'start_time_text' => preg_replace('/^(?:i would prefer|i prefer|at) /u', '', $question),
            default => $question,
        };

        return match ($nextField) {
            'date_text' => $this->date($value) === null
                ? null : $this->respond($session, ['date_text' => $value]),
            'start_time_text' => $session->get('cuechat.ambiguous_hour') !== null || $this->time($value, null) === null
                ? null : $this->respond($session, ['start_time_text' => $value]),
            'duration_text' => $this->duration($value) === null
                ? null : $this->respond($session, ['duration_text' => $value]),
            'table_text' => in_array($value, self::ANY_TABLE, true)
                ? $this->respond($session, ['table_text' => $value]) : null,
            default => null,
        };
    }

    /**
     * @param  array<string, string>  $details
     * @return array{reply: string, actions: list<array{label: string, url: string}>}
     */
    public function respond(Session $session, array $details): array
    {
        if (! $this->reservations->enabled()) {
            return $this->reply('Online booking is currently unavailable. Please contact CueLab staff.', 'Contact CueLab', route('contact'));
        }

        $previous = $session->get('cuechat.draft', []);
        $draft = $previous;
        $tables = BilliardTable::where('is_active', true)->orderBy('id')->get(['id', 'name', 'type']);
        $ambiguousHour = $session->get('cuechat.ambiguous_hour');
        $questions = self::QUESTIONS;

        foreach ($details as $field => $value) {
            if ($value === ($previous[$field] ?? null)) {
                continue;
            }
            $value = Str::lower(Str::squish($value));
            $normalized = match ($field) {
                'date_text' => $this->date($value),
                'start_time_text' => $this->time($value, $ambiguousHour),
                'duration_text' => $this->duration($value),
                'table_text' => $this->tablePreference($value, $tables),
            };

            if ($normalized === null) {
                unset($draft[$field]);
            } else {
                $draft[$field] = $normalized;
            }

            if ($field === 'start_time_text') {
                $ambiguousHour = preg_match('/^(?:(?:0?[1-9]|1[0-2])|(?:[1-9]|1[0-2]):[0-5][0-9])$/D', $value) ? $value : null;
                $session->put('cuechat.ambiguous_hour', $ambiguousHour);
            }
        }

        if ($draft !== $previous) {
            $session->forget('cuechat.selection');
        }
        $session->put('cuechat.draft', $draft);
        if ($ambiguousHour !== null) {
            $questions['start_time_text'] = 'Do you mean AM or PM?';
        }

        foreach ($questions as $field => $question) {
            if (! isset($draft[$field])) {
                return $this->reply($question);
            }
        }

        $minutes = (int) $draft['duration_text'];
        $start = CarbonImmutable::parse($draft['date_text'].' '.$draft['start_time_text'], config('app.timezone'));
        $preference = $draft['table_text'];

        try {
            $available = $this->reservations->available($start->toDateTimeString(), $minutes)->sortBy('id');
        } catch (ValidationException $error) {
            return $this->reply($error->errors()['booking'][0] ?? 'Choose another date, time, or duration.');
        }

        $table = $available->first(fn (BilliardTable $table): bool => $preference === 'any'
            || in_array($preference, [$table->name, $table->type], true));
        if ($table !== null) {
            try {
                $quote = $this->reservations->quote($table->id, $start->toDateTimeString(), $minutes);

                return $this->handoff($session, $start, $quote);
            } catch (ValidationException) {
                // The slot changed after the availability read. Check the current alternatives.
                $available = $available->reject(fn (BilliardTable $option): bool => $option->id === $table->id);
            }
        }

        $session->forget('cuechat.selection');
        $alternatives = [];
        for ($offset = 0; $offset <= 120 && count($alternatives) < 3; $offset += 30) {
            $candidate = $start->addMinutes($offset);
            try {
                $options = $offset === 0 ? $available : $this->reservations->available($candidate->toDateTimeString(), $minutes)->sortBy('id');
            } catch (ValidationException) {
                continue;
            }
            foreach ($options->take(3 - count($alternatives)) as $option) {
                $alternatives[] = $option->name.' · '.$candidate->format('M j, g:i A');
            }
        }

        return $alternatives === []
            ? $this->reply('No alternatives are available for that request. Try another date or time.')
            : $this->reply('That slot is unavailable. Try one of these options:'."\n".implode("\n", $alternatives));
    }

    private function date(string $value): ?string
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        if (in_array($value, ['today', 'ngayon'], true)) {
            return $today->toDateString();
        }
        if (in_array($value, ['tomorrow', 'bukas'], true)) {
            return $today->addDay()->toDateString();
        }
        if (in_array($value, ['day after tomorrow', 'sa makalawa', 'makalawa'], true)) {
            return $today->addDays(2)->toDateString();
        }
        if (preg_match('/^(?:next )?(monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/D', $value, $parts)) {
            return $today->next($parts[1])->toDateString();
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/D', $value, $parts)) {
            return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
                ? sprintf('%04d-%02d-%02d', $parts[1], $parts[2], $parts[3]) : null;
        }
        if (preg_match('/^([a-z]{3,9}) (\d{1,2})(?:st|nd|rd|th)?(?:,? (\d{4}))?$/D', $value, $parts)) {
            $months = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
            $month = array_search(substr($parts[1], 0, 3), $months, true);
            $year = (int) ($parts[3] ?? $today->year);
            if ($month !== false && checkdate($month + 1, (int) $parts[2], $year)) {
                $date = $today->setDate($year, $month + 1, (int) $parts[2]);

                return ! isset($parts[3]) && $date->lt($today) ? $date->addYear()->toDateString() : $date->toDateString();
            }
        }

        return null;
    }

    private function time(string $value, ?string $ambiguousHour): ?string
    {
        $value = str_replace('.', '', $value);
        if ($ambiguousHour !== null && in_array($value, ['am', 'pm'], true)) {
            $value = $ambiguousHour.' '.$value;
        }
        if (in_array($value, ['noon', 'tanghali'], true)) {
            return '12:00';
        }
        if (in_array($value, ['midnight', 'hatinggabi'], true)) {
            return '00:00';
        }
        $value = preg_replace('/\s+(?:ng\s+)?(?:umaga)$/u', ' am', $value);
        $value = preg_replace('/\s+(?:ng\s+)?(?:gabi|hapon)$/u', ' pm', $value);
        if (preg_match('/^(0?[1-9]|1[0-2])(?::([0-5][0-9]))?\s*(am|pm)$/D', $value, $parts)) {
            $hour = (int) $parts[1] % 12 + ($parts[3] === 'pm' ? 12 : 0);

            return sprintf('%02d:%02d', $hour, (int) $parts[2]);
        }
        if (preg_match('/^(0[0-9]|1[3-9]|2[0-3]):([0-5][0-9])$/D', $value, $parts)) {
            return sprintf('%02d:%02d', $parts[1], $parts[2]);
        }

        return null;
    }

    private function duration(string $value): ?string
    {
        $value = strtr($value, ['one and a half' => '1.5', 'an hour' => '1 hour', 'half an hour' => '30 minutes', 'half hour' => '30 minutes']);
        $numbers = [
            'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8,
            'isa' => 1, 'isang' => 1, 'dalawa' => 2, 'dalawang' => 2, 'tatlo' => 3, 'tatlong' => 3,
            'apat' => 4, 'lima' => 5, 'limang' => 5, 'anim' => 6, 'pito' => 7, 'pitong' => 7, 'walo' => 8, 'walong' => 8,
        ];
        $value = preg_replace_callback('/\b(?:'.implode('|', array_keys($numbers)).')\b/u', fn (array $match): string => (string) $numbers[$match[0]], $value);
        if (! preg_match('/^(?:(\d{1,2}(?:\.\d{1,2})?)\s*(?:hours?|hrs?|h|oras)(?:\s+(?:and\s+)?)?)?(?:(\d{1,3})\s*(?:minutes?|mins?|m|minuto))?$/Du', $value, $parts)) {
            return null;
        }
        $minutes = (float) ($parts[1] ?? 0) * 60 + (int) ($parts[2] ?? 0);

        return $minutes >= 15 && $minutes <= 480 && fmod($minutes, 15) === 0.0 ? (int) $minutes.' minutes' : null;
    }

    /** @param iterable<BilliardTable> $tables */
    private function tablePreference(string $value, iterable $tables): ?string
    {
        if (in_array($value, self::ANY_TABLE, true)) {
            return 'any';
        }

        $value = preg_replace('/^table\s*#?\s*0*(\d+)$/D', 'table $1', $value);
        foreach ($tables as $table) {
            $name = preg_replace('/^table\s*0*(\d+)$/D', 'table $1', Str::lower($table->name));
            if ($value === $name) {
                return $table->name;
            }
            if (in_array($value, [Str::lower($table->type), Str::lower($table->type).' table'], true)) {
                return $table->type;
            }
        }

        return null;
    }

    /** @param array{billiard_table_id: int, table_name: string, minutes: int, total: int} $quote */
    private function handoff(Session $session, CarbonImmutable $start, array $quote): array
    {
        $token = Str::random(32);
        $session->put('cuechat.selection', [
            'token' => $token,
            'expires_at' => now()->addMinutes(15)->timestamp,
            'starts_at' => $start->format('Y-m-d\TH:i'),
            'minutes' => $quote['minutes'],
            'table_id' => $quote['billiard_table_id'],
        ]);
        $price = number_format($quote['total'] / 100, 2);
        $when = $start->format('D, M j \a\t g:i A');

        return $this->reply(
            "{$quote['table_name']} is available {$when} (Philippine time) for {$quote['minutes']} minutes. Estimated total: PHP {$price}. Continue to the booking form to review and submit for staff approval.",
            'Review booking request',
            route('booking', ['cuechat' => $token]),
        );
    }

    private function reply(string $reply, string $label = '', string $url = ''): array
    {
        return ['reply' => $reply, 'actions' => $url === '' ? [] : [compact('label', 'url')]];
    }
}
