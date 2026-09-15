<?php

use App\Concerns\AppendOnlyStatusHistory;
use App\Concerns\HasStateMachine;
use App\Concerns\RecordsStatusHistory;
use App\Models\User;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use App\Support\StateMachine\TransitionableState;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The shared status-history recording contract (P0-16).
 *
 * Exercised on a subject of its own, so the contract is proven apart from any
 * one module: previous, new, actor, at, reason, internal note and public note
 * recorded with the move and never after it; a subject's own columns kept
 * beside them; and rows that cannot be edited or removed from Eloquent or from
 * the database.
 */

enum StatusHistoryFixtureState: string implements TransitionableState
{
    case Open = 'open';
    case Closed = 'closed';
    case Archived = 'archived';

    public function transitionsTo(): array
    {
        return match ($this) {
            self::Open => [self::Closed],
            self::Closed => [self::Archived],
            self::Archived => [],
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isTerminal(): bool
    {
        return $this === self::Archived;
    }
}

class StatusHistoryFixtureSubject extends Model
{
    use HasStateMachine, RecordsStatusHistory;

    protected $table = 'status_history_fixture_subjects';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => StatusHistoryFixtureState::class];
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(StatusHistoryFixtureChange::class, 'subject_id');
    }
}

class StatusHistoryFixtureChange extends Model
{
    use AppendOnlyStatusHistory;

    protected $table = 'status_history_fixture_changes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'previous_status' => StatusHistoryFixtureState::class,
            'new_status' => StatusHistoryFixtureState::class,
        ];
    }
}

beforeEach(function () {
    Schema::create('status_history_fixture_subjects', function (Blueprint $table) {
        $table->id();
        $table->string('status', 20);
        $table->timestamps();
    });

    Schema::create('status_history_fixture_changes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('subject_id')->constrained('status_history_fixture_subjects');
        $table->string('previous_status', 20)->nullable();
        $table->string('new_status', 20);
        $table->foreignId('changed_by')->nullable()->constrained('users');
        $table->timestamp('changed_at');
        $table->text('reason')->nullable();
        $table->text('internal_note')->nullable();
        $table->text('public_note')->nullable();
        // A column only this history keeps.
        $table->string('source', 20);
    });

    DB::unprepared(<<<'SQL'
        CREATE TRIGGER status_history_fixture_changes_no_update
            BEFORE UPDATE ON status_history_fixture_changes
            FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();
        CREATE TRIGGER status_history_fixture_changes_no_delete
            BEFORE DELETE ON status_history_fixture_changes
            FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();
    SQL);

    $this->subject = StatusHistoryFixtureSubject::create(['status' => StatusHistoryFixtureState::Open]);
});

it('records previous, new, actor, time, reason and both notes together with the move', function () {
    $actor = User::factory()->create();
    $at = now()->toImmutable()->subMinutes(3)->startOfSecond();

    $entry = $this->subject->transitionWithHistory(StatusHistoryFixtureState::Closed, new StatusChange(
        actorId: $actor->id,
        reason: 'Customer asked to close it',
        internalNote: 'Checked with finance',
        publicNote: 'Your request has been closed.',
        at: $at,
    ), ['source' => 'staff']);

    expect($this->subject->refresh()->status)->toBe(StatusHistoryFixtureState::Closed)
        ->and($entry)->toBeInstanceOf(StatusHistoryFixtureChange::class)
        ->and($entry->previous_status)->toBe(StatusHistoryFixtureState::Open)
        ->and($entry->new_status)->toBe(StatusHistoryFixtureState::Closed)
        ->and($entry->changed_by)->toBe($actor->id)
        ->and($entry->changed_at->equalTo($at))->toBeTrue()
        ->and($entry->reason)->toBe('Customer asked to close it')
        ->and($entry->internal_note)->toBe('Checked with finance')
        ->and($entry->public_note)->toBe('Your request has been closed.')
        ->and($entry->source)->toBe('staff')
        ->and($entry->wasAutomatic())->toBeFalse();
});

it('records the first status, which has no previous one, and a change the system made', function () {
    $first = $this->subject->recordStatusChange(null, StatusHistoryFixtureState::Open, StatusChange::bySystem('Created'), ['source' => 'system']);

    expect($first->previous_status)->toBeNull()
        ->and($first->new_status)->toBe(StatusHistoryFixtureState::Open)
        ->and($first->changed_by)->toBeNull()
        ->and($first->wasAutomatic())->toBeTrue()
        ->and($first->changed_at)->not->toBeNull();
});

it('refuses a move the state machine does not allow, and records nothing', function () {
    expect(fn () => $this->subject->transitionWithHistory(StatusHistoryFixtureState::Archived, StatusChange::bySystem(), ['source' => 'system']))
        ->toThrow(IllegalStateTransition::class);

    expect($this->subject->refresh()->status)->toBe(StatusHistoryFixtureState::Open)
        ->and(StatusHistoryFixtureChange::query()->count())->toBe(0);
});

it('never lets a subject\'s own columns overwrite the shared ones', function () {
    $entry = $this->subject->transitionWithHistory(StatusHistoryFixtureState::Closed, new StatusChange(reason: 'The real reason'), [
        'source' => 'staff',
        'reason' => 'A different reason',
        'new_status' => 'archived',
        'changed_by' => 999,
    ]);

    expect($entry->reason)->toBe('The real reason')
        ->and($entry->new_status)->toBe(StatusHistoryFixtureState::Closed)
        ->and($entry->changed_by)->toBeNull();
});

it('makes the move and the record together or not at all', function () {
    // The history row cannot be written — this history requires a source — so
    // the status must not have moved either.
    try {
        DB::transaction(fn () => $this->subject->transitionWithHistory(StatusHistoryFixtureState::Closed, StatusChange::bySystem()));
    } catch (QueryException) {
    }

    expect($this->subject->fresh()?->status)->toBe(StatusHistoryFixtureState::Open)
        ->and(StatusHistoryFixtureChange::query()->count())->toBe(0);
});

it('refuses to edit or remove a recorded change through Eloquent', function () {
    $entry = $this->subject->transitionWithHistory(StatusHistoryFixtureState::Closed, new StatusChange(reason: 'Closed'), ['source' => 'staff']);

    expect(fn () => $entry->forceFill(['reason' => 'Rewritten'])->save())->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);

    expect(StatusHistoryFixtureChange::query()->sole()->reason)->toBe('Closed');
});

it('refuses to edit a recorded change in the database, whatever issues the statement', function () {
    $this->subject->transitionWithHistory(StatusHistoryFixtureState::Closed, new StatusChange(reason: 'Closed'), ['source' => 'staff']);

    expect(fn () => DB::table('status_history_fixture_changes')->update(['reason' => 'Rewritten']))
        ->toThrow(QueryException::class, 'append-only');
});

it('refuses to remove a recorded change in the database, whatever issues the statement', function () {
    $this->subject->transitionWithHistory(StatusHistoryFixtureState::Closed, new StatusChange(reason: 'Closed'), ['source' => 'staff']);

    expect(fn () => DB::table('status_history_fixture_changes')->delete())
        ->toThrow(QueryException::class, 'append-only');
});
