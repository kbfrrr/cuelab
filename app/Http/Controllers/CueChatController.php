<?php

namespace App\Http\Controllers;

use App\Models\OperatingHour;
use App\Models\ServiceRate;
use App\Models\Tournament;
use App\Services\BookingConversation;
use App\Services\CueChatDify;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CueChatController extends Controller
{
    public function __construct(private CueChatDify $dify, private BookingConversation $booking) {}

    public function __invoke(Request $request): JsonResponse
    {
        $message = trim($request->validate(['message' => 'required|string|max:1000'])['message']);

        try {
            $result = $this->respond($request, $message);
        } catch (QueryException) {
            $result = $this->referral('CueLab’s live information is temporarily unavailable. Please contact staff.', true);
        }

        return response()->json($result);
    }

    /** @return array{reply: string, actions: list<array{label: string, url: string}>} */
    private function respond(Request $request, string $message): array
    {
        $session = $request->session();
        if ($message === 'Book a table') {
            $session->forget(['cuechat.draft', 'cuechat.selection', 'cuechat.ambiguous_hour']);

            return $this->booking->respond($session, []);
        }
        if ($message === 'Check booking status') {
            return $this->status();
        }
        if ($message === 'Ask about CueLab') {
            return $this->reply('Ask me about rates, hours, upcoming tournaments, or visiting CueLab.');
        }
        $withoutDates = preg_replace('/\b(?:\d{4}-\d{1,2}-\d{1,2}|\d{1,2}\/\d{1,2}\/\d{2,4})\b/u', '', $message);
        if (preg_match('/@|\b[a-z0-9]{32}\b|(?<!\d)(?:\d[\s().+-]*){7,}/iu', $withoutDates)) {
            return $this->reply('For privacy, enter contact details or a private reference on the booking page.', 'Open booking page', route('booking'));
        }
        if (preg_match('/\b(?:holds?|grace period|late arrival|15\s*(?:mins?|minutes?)\b)\b/iu', $message) &&
            preg_match('/\b(?:holds?|grace period|late arrival|late|policy|reserve|reservation)\b/iu', $message)) {
            return $this->referral('Please ask CueLab staff about its table-hold policy.');
        }
        if (preg_match('/^(?:(?:do you have|are there|show me|list) (?:any |the )?(?:upcoming )?|(?:what are the )?(?:upcoming|next) |when is (?:the )?next |may (?:upcoming )?|kailan (?:ang )?(?:next )?)(?:billiards? )?tournaments?(?: po)?(?: ba)?[?!.]*$/iu', Str::squish($message))) {
            return $this->tournaments();
        }

        $user = $session->get('cuechat.user') ?? (string) Str::uuid();
        $session->put('cuechat.user', $user);
        $draft = $session->get('cuechat.draft', []);
        if ($session->has('cuechat.ambiguous_hour')) {
            $draft['start_time_text'] = $session->get('cuechat.ambiguous_hour');
        }
        $intake = $this->dify->classify($message, $user, $draft);
        if ($intake === null) {
            return $this->referral('CueChat is temporarily unavailable. Please try the booking page or contact CueLab staff.', true);
        }

        return match ($intake['intent']) {
            'reservation' => $this->booking->respond($session, $intake['details']),
            'booking_status' => $this->status(),
            'rates' => $this->rates($message),
            'hours' => $this->hours(),
            'equipment', 'location', 'policy' => $this->faq($message, $user),
            'clarify' => $this->reply('Are you asking about a new reservation, an existing booking, or the venue?'),
            default => $this->referral(),
        };
    }

    private function rates(string $message): array
    {
        if (preg_match('/\b(?:promo(?:tion)?|discount|whole venue|entire venue|private event)\b/iu', $message)) {
            return $this->referral('Please ask CueLab staff for current promo or venue rental rates.');
        }

        $rates = ServiceRate::where('is_verified', true)->where('is_active', true)->where('unit', 'table/hour')
            ->where('currency', 'PHP')->orderBy('name')->get(['name', 'amount_minor']);
        if ($rates->isEmpty()) {
            return $this->referral();
        }

        return $this->reply($rates->map(fn (ServiceRate $rate): string => $rate->name.' · PHP '.number_format($rate->amount_minor / 100, 2).' per hour')->implode("\n"));
    }

    private function tournaments(): array
    {
        $events = Tournament::publicSchedule()->where('starts_at', '>', now())->limit(3)->get();
        if ($events->isEmpty()) {
            return $this->reply('CueLab has no upcoming tournaments listed right now.', 'View tournaments', route('tournaments'));
        }

        $schedule = $events->map(fn (Tournament $event): string => $event->title.' · '.$event->starts_at->format('M j, Y g:i A'));

        return $this->reply(
            "Upcoming tournaments (Philippine time)\n".$schedule->implode("\n"),
            'View tournaments and registration details',
            route('tournaments'),
        );
    }

    private function hours(): array
    {
        $hours = OperatingHour::where('is_verified', true)->whereBetween('day_of_week', [0, 6])->orderBy('day_of_week')->get();
        if ($hours->count() !== 7 || $hours->contains(fn (OperatingHour $hour): bool => ! $hour->is_closed && (! $hour->opens_at || ! $hour->closes_at))) {
            return $this->referral();
        }

        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $schedule = $hours->map(function (OperatingHour $hour) use ($days): string {
            if ($hour->is_closed) {
                return $days[$hour->day_of_week].' · Closed';
            }
            $open = substr($hour->opens_at, 0, 5);
            $close = substr($hour->closes_at, 0, 5);

            return $days[$hour->day_of_week].' · '.$open.'–'.$close.($close <= $open ? ' next day' : '');
        });

        return $this->reply("Operating hours (Philippine time)\n".$schedule->implode("\n"));
    }

    private function faq(string $message, string $user): array
    {
        $answer = $this->dify->faq($message, $user);

        return $answer === null ? $this->referral() : $this->reply($answer);
    }

    private function status(): array
    {
        return $this->reply('Enter your private reference on the booking page to check your status.', 'Check booking status', route('booking').'#booking-lookup');
    }

    private function referral(string $message = 'I can’t verify that here. Please contact CueLab staff.', bool $includeBooking = false): array
    {
        $result = $this->reply($message, 'Contact CueLab', route('contact'));
        if ($includeBooking) {
            $result['actions'][] = ['label' => 'Open booking page', 'url' => route('booking')];
        }

        return $result;
    }

    private function reply(string $reply, string $label = '', string $url = ''): array
    {
        return ['reply' => $reply, 'actions' => $url === '' ? [] : [compact('label', 'url')]];
    }
}
