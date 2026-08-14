<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ImplicitRuleSettingResource;
use App\Enum\ImplicitRuleKey;
use App\Repository\ImplicitRuleSettingRepository;
use App\Service\ImplicitRuleResolver;
use App\Service\SeasonResolver;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Sert les 4 règles implicites RÉSOLUES (stocké OU défaut).
 *
 * @implements ProviderInterface<ImplicitRuleSettingResource>
 */
final class ImplicitRuleSettingStateProvider implements ProviderInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ImplicitRuleResolver $resolver,
        private readonly ImplicitRuleSettingRepository $repository,
        private readonly SeasonResolver $seasonResolver,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        [$clubId, $seasonId] = $this->resolveScope();
        if (null === $clubId || null === $seasonId) {
            return $operation instanceof CollectionOperationInterface ? [] : null;
        }

        $resolved = $this->resolver->resolve($clubId, $seasonId);
        $stored = $this->repository->findByClubSeasonIndexed($clubId, $seasonId);

        if ($operation instanceof CollectionOperationInterface) {
            $out = [];
            foreach (ImplicitRuleKey::cases() as $ruleKey) {
                $out[] = ImplicitRuleSettingResource::fromResolved(
                    $ruleKey,
                    $resolved[$ruleKey->value],
                    !isset($stored[$ruleKey->value]),
                );
            }

            return $out;
        }

        $ruleKey = ImplicitRuleKey::tryFrom((string) ($uriVariables['ruleKey'] ?? ''));
        if (null === $ruleKey) {
            return null;
        }

        return ImplicitRuleSettingResource::fromResolved(
            $ruleKey,
            $resolved[$ruleKey->value],
            !isset($stored[$ruleKey->value]),
        );
    }

    /**
     * Le club + la saison du contexte de la requête. La saison est celle stampée par le
     * listener (X-Season-Id ou saison courante) ; à défaut, on retombe sur la saison courante
     * du club, comme AbstractStateProcessor.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveScope(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id') ?? $request?->headers->get('X-Club-Id');
        if (!\is_string($clubId) || '' === $clubId) {
            return [null, null];
        }

        $seasonId = $request?->attributes->get('_season_id') ?? $request?->headers->get('X-Season-Id');
        if (!\is_string($seasonId) || '' === $seasonId) {
            $seasonId = $this->seasonResolver->currentSeason($clubId)?->getId();
        }

        return [$clubId, \is_string($seasonId) && '' !== $seasonId ? $seasonId : null];
    }
}
