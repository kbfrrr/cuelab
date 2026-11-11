<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dateTime('late_contacted_at')->nullable();
            $table->string('late_contact_outcome', 16)->nullable();
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('session_starts_at')->nullable();
            $table->dateTime('session_ends_at')->nullable();
            $table->unsignedInteger('session_total_minor')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn([
                'late_contacted_at', 'late_contact_outcome', 'checked_in_at',
                'session_starts_at', 'session_ends_at', 'session_total_minor',
            ]);
        });
    }
};
