<?php

namespace Database\Seeders;

use App\Models\AvailabilityBlock;
use App\Models\BilliardTable;
use App\Models\BusinessSetting;
use App\Models\ContactMessage;
use App\Models\OperatingHour;
use App\Models\Reservation;
use App\Models\ServiceRate;
use App\Models\StaffAction;
use App\Models\Tournament;
use App\Models\TournamentRegistration;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CueLabDemoSeeder extends Seeder
{
    public const DATABASE_FILE = 'cuelab-demo.sqlite';

    public const LOCAL_MYSQL_DATABASE = 'cuelab_local';

    public const STAFF_EMAIL = 'demo.staff@example.test';

    public const PASSWORD = 'password';

    public static function supportsLocalMysql(): bool
    {
        $connection = DB::connection();

        return app()->environment('local')
            && in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
            && in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)
            && $connection->getDatabaseName() === self::LOCAL_MYSQL_DATABASE;
    }

    public function run(): void
    {
        $database = DB::connection()->getDatabaseName();
        $isDemoFile = basename($database) === self::DATABASE_FILE;
        $isTestDatabase = app()->environment('testing') && $database === ':memory:';
        $hostedPassword = config('cuelab.hosted_demo_password');
        $isHostedDemo = app()->environment('production') && DB::getDriverName() === 'pgsql'
            && is_string($hostedPassword) && strlen($hostedPassword) >= 24
            && $hostedPassword !== self::PASSWORD;

        $isLocalSqlite = app()->environment('local', 'testing')
            && DB::getDriverName() === 'sqlite' && ($isDemoFile || $isTestDatabase);

        if (! $isHostedDemo && ! $isLocalSqlite && ! self::supportsLocalMysql()) {
            throw new RuntimeException('Use the local demo SQLite file '.self::DATABASE_FILE.' or a localhost MySQL/MariaDB database named '.self::LOCAL_MYSQL_DATABASE.'.');
        }

        $now = CarbonImmutable::now();
        $references = DB::transaction(function () use ($now, $isHostedDemo, $hostedPassword): array {
            if ($isHostedDemo) {
                DB::select('select pg_advisory_xact_lock(8274, 2)');
                if (BusinessSetting::where('key', 'hosted_demo_seeded')->exists()) {
                    $this->cleanHostedLabels();

                    return [];
                }
                if (User::exists() || Reservation::exists() || BilliardTable::exists()) {
                    throw new RuntimeException('Hosted demo data requires a fresh database.');
                }
            }
            $staff = $this->seedAccounts($isHostedDemo ? $hostedPassword : self::PASSWORD);
            $tables = $this->seedVenue();
            $bookings = $this->seedBookings($now, $staff, $tables);
            $tournaments = $this->seedTournaments($now, $staff);
            $this->seedBlocks($now, $staff, $tables);
            $this->seedMessages($now, $staff);

            if ($isHostedDemo) {
                $this->cleanHostedLabels();
                BusinessSetting::create(['key' => 'hosted_demo_seeded', 'value' => true, 'is_verified' => false]);
            }

            return [...$bookings, ...$tournaments];
        });

        if ($isHostedDemo) {
            return;
        }

        $this->command?->info('Staff sign-in: '.self::STAFF_EMAIL.' / '.self::PASSWORD);
        $this->command?->line('Non-staff sign-in: demo.guest@example.test / '.self::PASSWORD);
        foreach ($references as $label => $reference) {
            $this->command?->line("{$label}: {$reference}");
        }
    }

    private function cleanHostedLabels(): void
    {
        BilliardTable::where('name', 'like', 'Demo Table %')
            ->whereNotExists(fn ($query) => $query->from('billiard_tables as existing')
                ->whereRaw('existing.name = substr(billiard_tables.name, 6)'))
            ->update(['name' => DB::raw('substr(name, 6)')]);
        BilliardTable::where('description', 'Demo billiard table.')->update(['description' => 'Billiard table.']);
        Tournament::where('title', 'like', 'Demo: %')
            ->update(['title' => DB::raw('substr(title, 7)')]);
        AvailabilityBlock::where('reason', 'like', 'Demo: %')
            ->update(['reason' => DB::raw('substr(reason, 7)')]);
        ContactMessage::where('subject', 'like', 'Demo: %')
            ->update(['subject' => DB::raw('substr(subject, 7)')]);
        User::whereIn('email', [self::STAFF_EMAIL, 'demo.manager@example.test', 'demo.events@example.test', 'demo.guest@example.test'])
            ->where('name', 'like', '% (Demo)')
            ->update(['name' => DB::raw('left(name, length(name) - 7)')]);
        if (! ServiceRate::where('name', 'Archived rate')->exists()) {
            ServiceRate::where('name', 'Demo retired rate')->update([
                'name' => 'Archived rate',
                'description' => 'Archived table rental rate.',
            ]);
        }
        $rules = BusinessSetting::where('key', 'house_rules')->first();
        if ($rules?->value === 'Demo house rules: keep drinks off the tables and follow staff instructions.') {
            $rules->update(['value' => 'Keep drinks off the tables and follow staff instructions.']);
        }
    }

    private function seedAccounts(string $accountPassword): User
    {
        $accounts = [
            [self::STAFF_EMAIL, 'Nina Reyes (Demo)', true],
            ['demo.manager@example.test', 'Paolo Manalo (Demo)', true],
            ['demo.events@example.test', 'Lara Aquino (Demo)', true],
            ['demo.guest@example.test', 'Mika Santos (Demo)', false],
        ];

        foreach ($accounts as [$email, $name, $isStaff]) {
            $user = User::firstOrNew(['email' => $email]);
            $user->forceFill(['name' => $name, 'password' => $accountPassword, 'is_staff' => $isStaff, 'email_verified_at' => now()])->save();
        }

        return User::where('email', self::STAFF_EMAIL)->firstOrFail();
    }

    /** @return list<BilliardTable> */
    private function seedVenue(): array
    {
        $this->call(BusinessSettingsSeeder::class);
        foreach ([
            'booking_enabled' => true,
            'hold_minutes' => 60,
            'address' => '2/F, Blk 2 L27 MacArthur Hwy, Brgy. Ninoy Aquino, Angeles, Pampanga (in front of Remedian Court)',
            'house_rules' => 'Demo house rules: keep drinks off the tables and follow staff instructions.',
        ] as $key => $value) {
            BusinessSetting::updateOrCreate(['key' => $key], ['value' => $value, 'is_verified' => true]);
        }

        foreach (range(0, 6) as $day) {
            $closed = $day === CarbonImmutable::WEDNESDAY;
            OperatingHour::updateOrCreate(['day_of_week' => $day], [
                'opens_at' => $closed ? null : '16:00:00',
                'closes_at' => $closed ? null : '02:00:00',
                'is_closed' => $closed,
                'is_verified' => true,
            ]);
        }

        $rate = ServiceRate::updateOrCreate(['name' => 'Table rental'], [
            'amount_minor' => 20000, 'description' => 'Hourly rental for any billiard table.', 'is_verified' => true, 'is_active' => true,
        ]);
        ServiceRate::updateOrCreate(['name' => 'Demo retired rate'], [
            'amount_minor' => 15000, 'description' => 'Sample archived rate.', 'is_verified' => false, 'is_active' => false,
        ]);

        $legacyRate = ServiceRate::where('name', 'Demo premium table')->first();
        if ($legacyRate) {
            $legacyRate->tables()->whereIn('name', ['Demo Table 7', 'Demo Table 8'])
                ->where('is_active', false)
                ->whereDoesntHave('reservations')
                ->whereDoesntHave('availabilityBlocks')
                ->delete();
            $legacyRate->tables()->update(['service_rate_id' => $rate->id, 'type' => 'Standard', 'description' => 'Demo billiard table.']);
            $legacyRate->delete();
        }

        $tables = [];
        foreach (range(1, 6) as $index) {
            $tables[] = BilliardTable::updateOrCreate(['name' => "Demo Table {$index}"], [
                'type' => 'Standard',
                'service_rate_id' => $rate->id,
                'is_active' => true,
                'description' => 'Demo billiard table.',
            ]);
        }

        return $tables;
    }

    /** @param list<BilliardTable> $tables */
    private function seedBookings(CarbonImmutable $now, User $staff, array $tables): array
    {
        $firstPlayDay = $this->playDay($now->addDay());
        $secondPlayDay = $this->playDay($firstPlayDay->addDay());
        $history = collect(range(1, 21))
            ->map(fn (int $offset) => $now->subDays($offset))
            ->reject(fn (CarbonImmutable $day) => $day->isWednesday())
            ->take(16)->values();
        $samples = [
            ['Mika Santos', 1, '18:30', 90, 'pending'],
            ['Andre Bautista', 2, '19:00', 120, 'pending'],
            ['Inez Garcia', 3, '20:15', 60, 'pending'],
            ['Carlo Dizon', 4, '23:30', 90, 'pending'],
            ['Lea Mendoza', 1, '21:00', 120, 'confirmed'],
            ['Enzo Ramos', 5, '19:30', 90, 'confirmed'],
            ['Sofia Cruz', 2, '17:00', 60, 'confirmed'],
            ['Jules Flores', 6, '22:00', 120, 'confirmed'],
            ['Nico Navarro', 2, '19:15', 60, 'confirmed'],
            ['Rina Castillo', 3, '20:30', 90, 'confirmed'],
            ['Miguel Lim', 4, '18:00', 120, 'rejected'],
            ['Aya Villanueva', 5, '21:00', 60, 'cancelled'],
            ['Luis Torres', 6, '19:45', 90, 'expired'],
            ['Bea Rivera', 2, '17:30', 60, 'confirmed'],
            ['Marco Tan', 1, '22:30', 120, 'confirmed'],
            ['Jessa Lopez', 3, '20:00', 90, 'rejected'],
            ['Anton Reyes', 4, '18:30', 180, 'cancelled'],
            ['Kat Sarmiento', 5, '16:30', 60, 'expired'],
            ['Alvin Mercado', 6, '20:30', 90, 'confirmed'],
            ['Toni Valdez', 2, '23:00', 120, 'confirmed'],
            ['Dane Pascual', 1, '21:45', 60, 'rejected'],
            ['Mae Salazar', 3, '17:45', 90, 'cancelled'],
            ['Gabriel Diaz', 4, '18:15', 120, 'expired'],
            ['Lia Morales', 5, '16:45', 45, 'confirmed'],
        ];
        $references = [];

        foreach ($samples as $offset => [$name, $tableNumber, $time, $minutes, $status]) {
            $index = $offset + 1;
            $future = $index <= 8;
            $date = match (true) {
                $index <= 6 => $firstPlayDay,
                $index <= 8 => $secondPlayDay,
                default => $history[$index - 9],
            };
            $startsAt = $date->setTimeFromTimeString($time);
            $table = $tables[$tableNumber - 1];
            $walkIn = ! $future && $status === 'confirmed' && $index % 2 === 0;
            $submittedAt = match (true) {
                $status === 'pending' => $now->subMinutes(4 * $index),
                $future => $now->subMinutes(30 + 5 * $index),
                $walkIn => $startsAt,
                default => $startsAt->subHours(3 + $index % 6),
            };
            $reviewed = in_array($status, ['confirmed', 'cancelled', 'rejected'], true);
            $reviewedAt = $reviewed ? $submittedAt->addMinutes($walkIn ? 0 : 8 + $index % 20) : null;
            $hourlyRate = $table->serviceRate->amount_minor;
            $record = Reservation::updateOrCreate(['submission_key' => sprintf('02000000-0000-4000-8000-%012d', $index)], [
                'reference' => sprintf('DEMOBOOKING%021d', $index),
                'billiard_table_id' => $table->id,
                'customer_name' => $name,
                'phone' => $this->demoPhone($name),
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addMinutes($minutes),
                'status' => $status,
                'source' => $walkIn ? 'walk_in' : 'website',
                'quoted_hourly_rate_minor' => $hourlyRate,
                'quoted_total_minor' => intdiv($hourlyRate * $minutes + 59, 60),
                'currency' => 'PHP',
                'hold_expires_at' => $status === 'pending' ? $submittedAt->addHour() : null,
                'reviewed_by' => $reviewed ? $staff->id : null,
                'reviewed_at' => $reviewedAt,
                'late_contacted_at' => null,
                'late_contact_outcome' => null,
                'checked_in_at' => null,
                'session_starts_at' => null,
                'session_ends_at' => null,
                'session_total_minor' => null,
                'staff_notes' => match ($status) {
                    'cancelled' => 'Guest called to reschedule their group visit.',
                    'rejected' => 'Guest requested a different time during confirmation.',
                    default => null,
                },
                'created_at' => $submittedAt,
            ]);

            $references[ucfirst($status).' booking'] ??= $record->reference;
            if ($reviewedAt) {
                $this->audit($staff, $record, $status, $reviewedAt);
            }
        }

        return $references;
    }

    private function seedTournaments(CarbonImmutable $now, User $staff): array
    {
        $events = [
            '8-ball Open' => [
                'status' => 'published', 'days' => 7, 'capacity' => 16, 'fee' => 50000,
                'description' => 'An evening of singles 8-ball. Open to recreational and league players.',
                'rules' => 'Race to three. Check in 30 minutes before the first match.',
                'entries' => ['unpaid', 'paid', 'paid', 'cancelled', 'unpaid', 'paid', 'paid', 'unpaid'],
            ],
            '9-ball Challenge (full)' => [
                'status' => 'published', 'days' => 14, 'capacity' => 6, 'fee' => 35000,
                'description' => 'A compact 9-ball bracket for six players.',
                'rules' => 'Race to four. Lag for the opening break.',
                'entries' => ['paid', 'unpaid', 'paid', 'unpaid', 'paid', 'paid'],
            ],
            'Scotch Doubles' => [
                'status' => 'closed', 'days' => 21, 'capacity' => 8, 'fee' => 60000,
                'description' => 'Teams of two alternate shots in a doubles bracket.',
                'rules' => 'One registration per pair. Both partners must check in together.',
                'entries' => ['paid', 'paid', 'unpaid', 'paid', 'cancelled', 'paid'],
            ],
            'Practice Cup' => [
                'status' => 'draft', 'days' => 28, 'capacity' => 12, 'fee' => 20000,
                'description' => 'A short round robin for newer players to practice match play.',
                'rules' => 'Race to two. Staff will post the round schedule after check-in.',
                'entries' => [],
            ],
            'Weekend Shootout' => [
                'status' => 'cancelled', 'days' => 35, 'capacity' => 12, 'fee' => 40000,
                'description' => 'A weekend singles event cancelled after a scheduling change.',
                'rules' => 'Contact the front desk about an existing entry.',
                'entries' => ['cancelled', 'cancelled'],
            ],
        ];
        $entrants = [
            'Jiro Ocampo', 'Inez Garcia', 'Paolo Robles', 'Bea Rivera', 'Lara Guevarra', 'Mico Pineda',
            'Toni Valdez', 'Anne Soriano', 'Drew Mendoza', 'Rei Angeles', 'Nico Navarro', 'Lian De Leon',
            'Luis Torres', 'Mika Santos', 'Aya Villanueva', 'Carlo Dizon', 'Sofia Cruz', 'Ren Villareal',
            'Mark Sy', 'Lea Mendoza', 'Ana Rosales', 'Enzo Ramos',
        ];
        $index = 0;
        $references = [];

        foreach ($events as $title => $sample) {
            $status = $sample['status'];
            $start = $this->playDay($now->addDays($sample['days']))->setTime(18, 0);
            $event = Tournament::updateOrCreate(['title' => "Demo: {$title}"], [
                'description' => $sample['description'],
                'rules' => $sample['rules'],
                'starts_at' => $start,
                'ends_at' => $start->addHours(3),
                'registration_deadline' => $status === 'closed' ? $now->subDay() : $start->subDay(),
                'capacity' => $sample['capacity'],
                'entry_fee_minor' => $sample['fee'],
                'currency' => 'PHP',
                'status' => $status,
                'created_at' => $now->subWeeks(2),
            ]);

            if (in_array($status, ['published', 'closed'], true)) {
                $this->block($event->title, 'tournament', $start, $start->addHours(3), $staff, event: $event);
                $this->audit($staff, $event, $status, $status === 'closed' ? $now->subDay() : $now->subDays(8));
            }

            foreach ($sample['entries'] as $registrationStatus) {
                $index++;
                $registeredAt = $now->subDays(2 + $index % 4)->setTime(17 + $index % 5, 15);
                $paid = $registrationStatus === 'paid';
                $paidAt = $paid ? $registeredAt->addHours(2 + $index % 8) : null;
                $method = $paid ? ($index % 2 === 0 ? 'cash' : 'gcash') : null;
                $record = TournamentRegistration::updateOrCreate(['submission_key' => sprintf('03000000-0000-4000-8000-%012d', $index)], [
                    'tournament_id' => $event->id,
                    'reference' => sprintf('DEMOTOURNEY%021d', $index),
                    'full_name' => $entrants[$index - 1],
                    'phone' => $this->demoPhone($entrants[$index - 1]),
                    'status' => $registrationStatus,
                    'quoted_fee_minor' => $event->entry_fee_minor,
                    'currency' => 'PHP',
                    'payment_method' => $method,
                    'payment_reference' => $method === 'gcash' ? "DEMO-GCASH-{$index}" : null,
                    'paid_at' => $paidAt,
                    'payment_verified_by' => $paid ? $staff->id : null,
                    'created_at' => $registeredAt,
                ]);
                $references[ucfirst($registrationStatus).' tournament registration'] ??= $record->reference;
                if ($paid || $registrationStatus === 'cancelled') {
                    $this->audit($staff, $record, $registrationStatus, $paidAt ?? $now->subDay());
                }
            }
        }

        return $references;
    }

    /** @param list<BilliardTable> $tables */
    private function seedBlocks(CarbonImmutable $now, User $staff, array $tables): void
    {
        $next = $this->playDay($now->addDay())->setTime(17, 0);
        $closure = $this->playDay($now->addDays(5))->setTime(16, 0);
        $this->block('Demo: Replace table felt', 'maintenance', $next, $next->addHours(2), $staff, $tables[4]);
        $this->block('Demo: Table inspection', 'maintenance', $next, $next->addHour(), $staff, $tables[5]);
        $this->block('Demo: Venue closure', 'closure', $closure, $closure->addHours(10), $staff);
    }

    private function block(string $reason, string $type, CarbonImmutable $start, CarbonImmutable $end, User $staff, ?BilliardTable $table = null, ?Tournament $event = null): void
    {
        AvailabilityBlock::updateOrCreate(['reason' => $reason], [
            'type' => $type,
            'billiard_table_id' => $table?->id,
            'tournament_id' => $event?->id,
            'starts_at' => $start,
            'ends_at' => $end,
            'created_by' => $staff->id,
        ]);
    }

    private function seedMessages(CarbonImmutable $now, User $staff): void
    {
        $messages = [
            ['Can I walk in?', 'Mika Santos', 'We’re a group of three planning to drop by around 7 PM. Can we walk in if a table is free?', 'new'],
            ['Closing time on Tuesday', 'Enzo Ramos', 'If we arrive Tuesday at 11:30 PM, can we play until 1 AM on Wednesday?', 'in_progress'],
            ['Table rental rate', 'Lea Mendoza', 'Is the ₱200 hourly rate for the whole table? There will be four of us.', 'resolved'],
            ['Tournament rules', 'Jiro Ocampo', 'I’m joining the 8-ball Open. What is the race length in the first round?', 'new'],
            ['Where is the entrance?', 'Rina Castillo', 'I know Remedian Court. Is the entrance on MacArthur Highway or around the corner?', 'in_progress'],
            ['Checking my reservation', 'Andre Bautista', 'I requested a table for our group and the status says awaiting approval. Should we wait for confirmation before leaving?', 'resolved'],
            ['Group visit', 'Aya Villanueva', 'Six friends want to play for two hours. Could you suggest a time when two tables may be free?', 'new'],
            ['Equipment question', 'Nico Navarro', 'Do we need to bring our own cues, or are house cues available?', 'resolved'],
            ['Tournament payment', 'Lian De Leon', 'Can I pay my 9-ball entry at the front desk when I check in?', 'in_progress'],
            ['Changing a booking', 'Carlo Dizon', 'We might arrive an hour later than planned. Can staff help us check another slot?', 'new'],
            ['Accessibility inquiry', 'Sofia Cruz', 'Is there elevator access to the second floor? One person in our group has trouble with stairs.', 'in_progress'],
            ['Lost property', 'Toni Valdez', 'I may have left a black cue case beside our table last night. Has anyone turned it in?', 'resolved'],
        ];

        foreach ($messages as $index => [$subject, $name, $body, $status]) {
            $record = ContactMessage::updateOrCreate(['subject' => "Demo: {$subject}"], [
                'full_name' => $name,
                'phone' => $this->demoPhone($name),
                'message' => $body,
                'status' => $status,
                'assigned_to' => $status === 'new' ? null : $staff->id,
                'created_at' => $now->subDays($index + 1),
            ]);
            if ($status !== 'new') {
                $this->audit($staff, $record, $status, $now->subDays($index));
            }
        }
    }

    private function audit(User $staff, Model $record, string $status, CarbonImmutable $at): void
    {
        StaffAction::updateOrCreate([
            'user_id' => $staff->id,
            'action' => $record->getTable().'.updated',
            'subject_type' => $record->getMorphClass(),
            'subject_id' => $record->getKey(),
        ], ['metadata' => ['fields' => ['status'], 'status' => $status], 'created_at' => $at]);
    }

    private function demoPhone(string $name): string
    {
        return '000'.substr(sprintf('%010u', crc32($name)), -8);
    }

    private function playDay(CarbonImmutable $day): CarbonImmutable
    {
        return $day->isWednesday() ? $day->addDay() : $day;
    }
}
