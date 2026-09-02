<?php

use App\Concerns\HasStateMachine;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use App\Support\StateMachine\TransitionableState;
use Illuminate\Database\Eloquent\Model;

enum StubStatus: string implements TransitionableState
{
    case Draft = 'draft';
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function transitionsTo(): array
    {
        return match ($this) {
            self::Draft => [self::Active, self::Cancelled],
            self::Active => [self::Cancelled, self::Refunded],
            self::Cancelled, self::Refunded => [],
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isTerminal(): bool
    {
        return $this->transitionsTo() === [];
    }
}

class StubStateModel extends Model
{
    use HasStateMachine;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => StubStatus::class];
    }
}

function stubModel(StubStatus $status): StubStateModel
{
    return new StubStateModel(['status' => $status]);
}

it('allows a declared transition', function () {
    $model = stubModel(StubStatus::Draft);

    expect($model->canTransitionTo(StubStatus::Active))->toBeTrue();

    $model->transitionTo(StubStatus::Active);

    expect($model->status)->toBe(StubStatus::Active);
});

it('refuses an undeclared transition', function () {
    $model = stubModel(StubStatus::Draft);

    expect($model->canTransitionTo(StubStatus::Refunded))->toBeFalse()
        ->and(fn () => $model->transitionTo(StubStatus::Refunded))
        ->toThrow(IllegalStateTransition::class);
});

it('will not move out of a terminal state', function () {
    $model = stubModel(StubStatus::Refunded);

    expect($model->currentState()->isTerminal())->toBeTrue()
        ->and(fn () => $model->transitionTo(StubStatus::Active))
        ->toThrow(IllegalStateTransition::class, 'this is an end state');
});

it('treats a transition to the same state as a no-op that is not allowed', function () {
    $model = stubModel(StubStatus::Active);

    expect($model->canTransitionTo(StubStatus::Active))->toBeFalse();
});

it('leaves the state untouched when a transition is rejected', function () {
    $model = stubModel(StubStatus::Draft);

    try {
        $model->transitionTo(StubStatus::Refunded);
    } catch (IllegalStateTransition) {
        // expected
    }

    expect($model->status)->toBe(StubStatus::Draft);
});

it('names the legal moves in the exception message', function () {
    $model = stubModel(StubStatus::Draft);

    expect(fn () => $model->transitionTo(StubStatus::Refunded))
        ->toThrow(IllegalStateTransition::class, 'Allowed from here: Active, Cancelled');
});

it('lists available transitions for building action menus', function () {
    expect(stubModel(StubStatus::Active)->availableTransitions())
        ->toBe([StubStatus::Cancelled, StubStatus::Refunded]);
});

it('does not persist the transition itself', function () {
    $model = stubModel(StubStatus::Draft);
    $model->syncOriginal();

    $model->transitionTo(StubStatus::Active);

    expect($model->isDirty('status'))->toBeTrue()
        ->and($model->exists)->toBeFalse();
});
