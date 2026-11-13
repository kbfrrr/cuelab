<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tournament_payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_registration_id')->unique()->constrained()->restrictOnDelete();
            $table->uuid('version');
            $table->string('transaction_reference', 40);
            $table->string('approved_reference', 40)->nullable()->unique();
            $table->string('status', 16)->index();
            $table->string('mime_type', 16);
            $table->longText('image_base64')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });

        foreach (['gcash_recipient_name', 'gcash_recipient_number'] as $key) {
            DB::table('business_settings')->insertOrIgnore(['key' => $key, 'is_verified' => false]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tournament_payment_proofs');
        DB::table('business_settings')->whereIn('key', ['gcash_recipient_name', 'gcash_recipient_number'])->delete();
    }
};
