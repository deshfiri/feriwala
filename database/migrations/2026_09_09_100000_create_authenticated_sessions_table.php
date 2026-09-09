<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a person is signed in, and from what (§6).
 *
 * Sessions themselves live in Redis (P1-13) and are gone the moment they expire,
 * which is right for a session and useless as a history. This is the durable
 * record beside them: one row per sign-in, kept after the session ends, so
 * "device and session history" has something to read and "was that me?" has an
 * answer a week later.
 *
 * It carries three jobs at once, deliberately in one table rather than three:
 * the list of live sessions a person can end (§6), the history of the ones that
 * are over, and the set of devices already seen — which is what makes a new one
 * recognisable as new.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authenticated_sessions', function (Blueprint $table) {
            $table->id();

            // Addressed by ULID: ending one session is a URL, and a URL with a
            // row number in it is one somebody can count through.
            $table->ulid('public_id')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * The session identifier, hashed, for lookup. A row is found by this
             * and never by the identifier itself, so nothing has to hold the
             * live value in order to ask "which session is this?".
             */
            $table->string('session_key', 64)->unique();

            /*
             * And the identifier itself, encrypted, because ending a session
             * means reaching into Redis for that exact key.
             *
             * A database dump is a different blast radius from a Redis dump.
             * Plain identifiers here would mean anyone who reads one table can
             * assume any live session; encrypted, they also need the app key.
             */
            $table->text('session_id')->nullable();

            /*
             * A stable hash of the user agent. Not a strong fingerprint — an
             * attacker can copy a user agent — but it does not need to be. It
             * answers "has this person signed in from something like this
             * before?", and the failure mode of a copied one is silence about a
             * sign-in, not a false alarm about a real person.
             */
            $table->string('device_fingerprint', 64);
            $table->string('device_label', 120);

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamp('last_active_at')->useCurrent();

            // Null while the session is live. Set with a reason, so "I was
            // signed out" can be told apart from "it expired".
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason', 40)->nullable();

            $table->timestamps();

            // The two questions asked of this table: this person's sessions
            // newest first, and whether this person has used this device before.
            $table->index(['user_id', 'last_active_at']);
            $table->index(['user_id', 'device_fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authenticated_sessions');
    }
};
