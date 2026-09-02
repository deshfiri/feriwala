<?php

namespace App\Support\Rules;

/**
 * The facts a rule is resolved against — which user, package, product, category,
 * website, or campaign the decision is being made for.
 *
 * Built once at the point of decision and handed to {@see RuleResolver}.
 */
class RuleContext
{
    /**
     * @param  array<string, int|string>  $scopes  keyed by RuleScope value
     */
    protected function __construct(
        protected array $scopes = [],
    ) {}

    public static function make(): self
    {
        return new self;
    }

    public function for(RuleScope $scope, int|string|null $id): self
    {
        $clone = clone $this;

        if ($id === null) {
            unset($clone->scopes[$scope->value]);
        } else {
            $clone->scopes[$scope->value] = $id;
        }

        return $clone;
    }

    public function forUser(int|string|null $id): self
    {
        return $this->for(RuleScope::User, $id);
    }

    public function forPackage(int|string|null $id): self
    {
        return $this->for(RuleScope::Package, $id);
    }

    public function forProduct(int|string|null $id): self
    {
        return $this->for(RuleScope::Product, $id);
    }

    public function forCategory(int|string|null $id): self
    {
        return $this->for(RuleScope::Category, $id);
    }

    public function forWebsite(int|string|null $id): self
    {
        return $this->for(RuleScope::Website, $id);
    }

    public function forCampaign(int|string|null $id): self
    {
        return $this->for(RuleScope::Campaign, $id);
    }

    /**
     * The id this context carries for the given scope, if any.
     */
    public function idFor(RuleScope $scope): int|string|null
    {
        return $this->scopes[$scope->value] ?? null;
    }

    /**
     * Whether a rule in this scope, against this id, is relevant here.
     *
     * A global rule always is. Any other scope must match both the scope and the
     * exact id the context carries — a rule for someone else's package is not a
     * weaker match, it is simply not applicable.
     */
    public function matches(RuleScope $scope, int|string|null $id): bool
    {
        if ($scope->isGlobal()) {
            return true;
        }

        $contextId = $this->idFor($scope);

        if ($contextId === null || $id === null) {
            return false;
        }

        return (string) $contextId === (string) $id;
    }

    /**
     * @return array<string, int|string>
     */
    public function all(): array
    {
        return $this->scopes;
    }
}
