<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
        });
        Schema::create('operating_hours', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('day_of_week')->unique();
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
        });
        Schema::create('service_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('amount_minor');
            $table->char('currency', 3)->default('PHP');
            $table->string('unit')->default('table/hour');
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('billiard_tables', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('type');
            $table->foreignId('service_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('rules')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('registration_deadline')->nullable();
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('entry_fee_minor')->default(0);
            $table->char('currency', 3)->default('PHP');
            $table->string('status')->default('draft')->index();
            $table->timestamps();
        });
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->uuid('submission_key')->unique();
            $table->foreignId('billiard_table_id')->constrained()->restrictOnDelete();
            $table->string('customer_name');
            $table->string('phone', 32);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status')->default('pending')->index();
            $table->string('source')->default('website');
            $table->unsignedInteger('quoted_hourly_rate_minor');
            $table->unsignedInteger('quoted_total_minor');
            $table->char('currency', 3)->default('PHP');
            $table->dateTime('hold_expires_at')->nullable()->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('staff_notes')->nullable();
            $table->timestamps();
            $table->index(['billiard_table_id', 'starts_at', 'ends_at']);
        });
        Schema::create('availability_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billiard_table_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('tournament_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('reason');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['billiard_table_id', 'starts_at', 'ends_at']);
        });
        Schema::create('tournament_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->restrictOnDelete();
            $table->string('reference', 64)->unique();
            $table->uuid('submission_key')->unique();
            $table->string('full_name');
            $table->string('phone', 32);
            $table->string('status')->default('unpaid')->index();
            $table->unsignedInteger('quoted_fee_minor');
            $table->char('currency', 3)->default('PHP');
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->foreignId('payment_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tournament_id', 'status']);
        });
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('phone', 32);
            $table->string('subject');
            $table->text('message');
            $table->string('status')->default('new')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('staff_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->nullableMorphs('subject');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['staff_actions', 'contact_messages', 'tournament_registrations', 'availability_blocks', 'reservations', 'tournaments', 'billiard_tables', 'service_rates', 'operating_hours', 'business_settings'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
