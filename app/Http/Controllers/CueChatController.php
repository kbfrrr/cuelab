<?php

namespace App\Http\Controllers;

use App\Models\OperatingHour;
use App\Models\ServiceRate;
use App\Models\Tournament;
use App\Services\BookingConversation;
use App\Services\CueChatDify;
use App\Services\ReservationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class CueChatController extends Controller
{
    private const HISTORY_LIMIT = 40;

    public function __construct(
        private CueChatDify $dify,
        private BookingConversation $booking,
        private ReservationService $reservations,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $message = trim($request->validate(['message' => 'required|string|max:1000'])['message']);

        $withoutDates = preg_replace('/\b(?:\d{4}-\d{1,2}-\d{1,2}|\d{1,2}\/\d{1,2}\/\d{2,4})\b/u', '', $message);
        $private = preg_match('/@|\b[a-z0-9]{32}\b|(?<!\d)(?:\d[\s().+-]*){7,}/iu', $withoutDates);

        try {
            $result = $private
                ? $this->reply('For privacy, enter contact details or a private reference on the booking page.', 'Open booking page', route('booking'))
                : $this->respond($request, $message);
        } catch (QueryException) {
            $result = $this->referral('CueLab’s live information is temporarily unavailable. Please contact staff.', true);
        }

        $session = $request->session();
        $history = array_slice([
            ...$session->get('cuechat.history', []),
            ['customer' => true, 'text' => $private ? 'Private details hidden' : $message, 'actions' => []],
            ['customer' => false, 'text' => $result['reply'], 'actions' => $result['actions']],
        ], -self::HISTORY_LIMIT);
        $session->put('cuechat.history', $history);

        return response()->json($result + ['history' => $history]);
    }

    public function history(Request $request): JsonResponse
    {
        return response()->json(['history' => $request->session()->get('cuechat.history', [])]);
    }

    public function reset(Request $request): Response
    {
        $request->session()->forget('cuechat');

        return response()->noContent();
    }

    /** @return array{reply: string, actions: list<array{label: string, url: string}>} */
    private function respond(Request $request, string $message): array
    {
        $session = $request->session();
        if ($message === 'Book a table') {
            $session->forget(['cuechat.draft', 'cuechat.selection', 'cuechat.ambiguous_hour']);

            return $this->booking->respond($session, []);
        }
        if ($message === 'Check booking status'
            || preg_match('/^(?:confirmed na ba (?:ang |yung )?(?:booking|reservation) ko|is my (?:booking|reservation) confirmed|check my booking(?: status)?|what is my booking status)[?!.]*$/iu', Str::squish($message))) {
            return $this->status($request);
        }
        if ($message === 'Ask about CueLab') {
            return $this->reply('Ask me about rates, hours, upcoming tournaments, or house rules.');
        }
        if (preg_match('/\b(?:holds?|grace period|late arrival|15\s*(?:mins?|minutes?)\b)\b/iu', $message) &&
            preg_match('/\b(?:holds?|grace period|late arrival|late|policy|reserve|reservation)\b/iu', $message)) {
            return $this->referral('Please ask CueLab staff about its table-hold policy.');
        }

        $venuePolicy = $this->venuePolicyReply($message);
        if ($venuePolicy !== null) {
            return $venuePolicy;
        }
        $question = Str::lower(Str::squish(rtrim($message, '?! .')));
        if (preg_match('/^(?:(?:do you have|are there|show me|list) (?:any |the )?(?:upcoming )?|(?:what are the )?(?:upcoming|next) |when is (?:the )?next |may (?:upcoming )?|kailan (?:ang )?(?:next )?)(?:billiards? )?tournaments?(?: po)?(?: ba)?$/u', $question)) {
            return $this->tournaments();
        }
        if (($approvedAnswer = $this->approvedQuickAnswer($question)) !== null) {
            return $this->reply($approvedAnswer);
        }

        if (in_array($question, [
            'what is the hourly rate for billiards', 'what is your hourly rate for a billiards table',
            'how much are the tables', 'magkano po per hour', 'magkano per hour',
        ], true)) {
            return $this->rates($message);
        }
        if (in_array($question, [
            'what are your opening hours', 'what time do you open', 'what time do you close',
            'anong oras po kayo bukas', 'anong oras po kayo nagbubukas',
        ], true)) {
            return $this->hours();
        }

        $bookingReply = $this->booking->answerStructuredRequest($session, $question)
            ?? $this->booking->answerPendingQuestion($session, $question);
        if ($bookingReply !== null) {
            return $bookingReply;
        }

        $user = $session->get('cuechat.user') ?? (string) Str::uuid();
        $session->put('cuechat.user', $user);
        if (in_array($question, [
            'where are you located', 'where is cuelab located', 'saan po kayo located',
        ], true)) {
            return $this->faq($message, $user);
        }

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
            'booking_status' => $this->status($request),
            'rates' => $this->rates($message),
            'hours' => $this->hours(),
            'equipment', 'location', 'policy' => $this->faq($message, $user),
            'clarify' => $this->reply('Are you asking about a new reservation, an existing booking, or the venue?'),
            default => $this->referral(),
        };
    }

    /** @return array{reply: string, actions: list<array{label: string, url: string}>}|null */
    private function venuePolicyReply(string $message): ?array
    {
        $vaping = preg_match('/\b(?:vapes?|vaping|magvape|nagvavape|e[\s-]?cigarettes?)\b/iu', $message);
        $bringYourOwn = preg_match('/\b(?:alcohol(?:ic)?|beers?|wine|liquor|alak|serbesa|rice[\s-]+meals?|kanin)\b/iu', $message);
        $foodOrDrink = preg_match('/\b(?:snacks?|drinks?|beverages?|soda|softdrinks?|inumin|tubig|food|pagkain|merienda|meryenda|pulutan|baon|baunin|pizza|meals?)\b/iu', $message);
        $menu = preg_match('/\bmenu\b/iu', $message);

        if (! $vaping && ! $bringYourOwn && ! $foodOrDrink && ! $menu) {
            return null;
        }

        $policy = config('cuelab.venue_policy');
        if ($vaping) {
            return $this->referral($policy);
        }

        $asksPriceOrSelection = preg_match('/\b(?:prices?|costs?|how much|magkano|what kinds? of|what (?:snacks|drinks|food)|anong)\b/iu', $message);
        $riceMeal = preg_match('/\b(?:rice[\s-]+meals?|kanin)\b/iu', $message);
        if ($menu || ($asksPriceOrSelection && ! $riceMeal)) {
            return $this->referral($policy.' Ask CueLab staff for the current menu and prices.');
        }

        if (! $bringYourOwn && preg_match('/\b(?:outside|bring|magdala|dala|baon|baunin|bitbit|magpasok)\b/iu', $message)) {
            return $this->referral($policy.' Check with CueLab staff before bringing other food or drinks.');
        }

        return $this->reply($policy, 'House rules', route('rates').'#house-rules');
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

        return $this->reply("Upcoming tournaments (Philippine time)\n".$schedule->implode("\n"), 'View tournaments and registration details', route('tournaments'));
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

    private function approvedQuickAnswer(string $question): ?string
    {
        $question = str_replace('wi-fi', 'wifi', $question);

        // Approved venue FAQ entries 11, 14, and 15. Update these when the official FAQ changes.
        return match ($question) {
            'do you have parking', 'is parking available', 'may parking po ba kayo', 'may parking ba kayo',
            'where can i park', 'saan puwedeng mag-park', 'may paradahan ba' => 'Yes, parking is available.',
            'do you have wifi', 'is there wifi', 'is wifi available', 'may wifi po ba kayo', 'may wifi ba kayo',
            'do you have internet for guests', 'may wifi ba sa venue', 'may internet po ba' => 'Yes, Wi-Fi is available.',
            'do you accept gcash', 'tumatanggap po ba kayo ng gcash', 'what payment methods do you accept',
            'can i pay using bank transfer', 'do you take cash' => 'Cue Lab accepts cash, GCash, and bank transfer for drinks and billiards.',
            default => null,
        };
    }

    private function faq(string $message, string $user): array
    {
        $answer = $this->dify->faq($message, $user);

        return $answer === null ? $this->referral() : $this->reply($answer);
    }

    private function status(Request $request): array
    {
        $record = $this->reservations->findForStatus($request->session()->get('booking_reference'));
        if ($record !== null) {
            return $this->reply($record->statusHeading(), 'View booking details', route('booking.status'));
        }

        return $this->reply('Use the private lookup form to open your booking first. Then ask me again for its status.', 'Check booking status', route('booking').'#booking-lookup');
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
