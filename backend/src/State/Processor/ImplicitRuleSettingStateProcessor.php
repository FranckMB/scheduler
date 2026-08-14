<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\ImplicitRuleSettingResource;
use App\Dto\ImplicitRuleSettingInput;
use App\Entity\ImplicitRuleSetting;
use App\Enum\ImplicitRuleIntensity;
use App\Enum\ImplicitRuleKey;
use App\Repository\ImplicitRuleSettingRepository;
use App\Service\ImplicitRuleResolver;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Upsert (PUT) et réinitialisation (DELETE) d'un réglage de règle implicite, PAR `ruleKey`.
 *
 * Écriture = management (403 AVANT le 409 de saison archivée, idiome AbstractStateProcessor).
 * Les seuils sont bornés et le couple règle↔seuil vérifié ici (message 422 français
 * actionnable, sans identifiant interne) : ce que le moteur accepte a UNE maison côté backend,
 * l'enum `ImplicitRuleKey`.
 *
 * @implements ProcessorInterface<mixed, ImplicitRuleSettingResource|null>
 */
final class ImplicitRuleSettingStateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly ImplicitRuleSettingRepository $repository,
        private readonly ImplicitRuleResolver $resolver,
        private readonly SeasonResolver $seasonResolver,
        private readonly SeasonAccessGuard $seasonAccessGuard,
        private readonly ManagementAccessGuard $managementAccessGuard,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?ImplicitRuleSettingResource
    {
        $request = $this->requestStack->getCurrentRequest();

        // SEC-07 — management (403) AVANT saison archivée (409) : l'autorisation gagne.
        $this->managementAccessGuard->assertManager();
        $this->seasonAccessGuard->assertWritable($request);

        [$clubId, $seasonId] = $this->resolveScope();
        if (null === $clubId || null === $seasonId) {
            throw new NotFoundHttpException('Resource not found');
        }

        $ruleKey = ImplicitRuleKey::tryFrom((string) ($uriVariables['ruleKey'] ?? ''));
        if (null === $ruleKey) {
            throw new NotFoundHttpException('Resource not found');
        }

        if ($operation instanceof DeleteOperationInterface) {
            $this->reset($clubId, $seasonId, $ruleKey);

            return null;
        }

        \assert($data instanceof ImplicitRuleSettingInput);

        return $this->upsert($clubId, $seasonId, $ruleKey, $data);
    }

    private function reset(string $clubId, string $seasonId, ImplicitRuleKey $ruleKey): void
    {
        // Idempotent : réinitialiser une règle déjà au défaut n'a rien à supprimer.
        $existing = $this->repository->findOneByClubSeasonKey($clubId, $seasonId, $ruleKey);
        if ($existing instanceof ImplicitRuleSetting) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();
        }
    }

    private function upsert(string $clubId, string $seasonId, ImplicitRuleKey $ruleKey, ImplicitRuleSettingInput $input): ImplicitRuleSettingResource
    {
        $intensity = ImplicitRuleIntensity::tryFrom($input->intensity ?? '')
            ?? throw new UnprocessableEntityHttpException(\sprintf('« %s » n\'est pas une intensité connue. Valeurs acceptées : %s.', $input->intensity ?? '(absente)', implode(', ', ImplicitRuleIntensity::values())));

        $params = $this->validateAndBuildParams($ruleKey, $input);

        $entity = $this->repository->findOneByClubSeasonKey($clubId, $seasonId, $ruleKey)
            ?? (new ImplicitRuleSetting)
                ->setClubId($clubId)
                ->setSeasonId($seasonId)
                ->setRuleKey($ruleKey);

        $entity->setIntensity($intensity);
        $entity->setParams($params);

        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return ImplicitRuleSettingResource::fromResolved(
            $ruleKey,
            $this->resolver->resolve($clubId, $seasonId)[$ruleKey->value],
            false,
        );
    }

    /**
     * Vérifie le couple règle↔seuil et les bornes, puis compose le `params` stocké.
     *
     * @return array<string, mixed>|null
     */
    private function validateAndBuildParams(ImplicitRuleKey $ruleKey, ImplicitRuleSettingInput $input): ?array
    {
        // Un seuil envoyé à une règle qui n'en porte pas est un contresens : on le NOMME,
        // plutôt que de l'ignorer en silence (le gestionnaire croirait l'avoir réglé).
        if (ImplicitRuleKey::COACH_REST_DAY !== $ruleKey && null !== $input->minRestDays) {
            throw new UnprocessableEntityHttpException('Le seuil de repos coach ne concerne que la règle « repos des coachs ».');
        }
        if (ImplicitRuleKey::MAX_CONSECUTIVE_SESSIONS !== $ruleKey && null !== $input->maxConsecutive) {
            throw new UnprocessableEntityHttpException('Le plafond de créneaux consécutifs ne concerne que la règle « créneaux consécutifs ».');
        }

        $paramKey = $ruleKey->paramKey();
        if (null === $paramKey) {
            return null;
        }

        $value = ImplicitRuleKey::COACH_REST_DAY === $ruleKey ? $input->minRestDays : $input->maxConsecutive;
        if (null === $value) {
            // Seuil laissé au défaut : aucune valeur stockée, le résolveur pourvoit le défaut.
            return null;
        }

        $min = (int) $ruleKey->paramMin();
        $max = (int) $ruleKey->paramMax();
        if ($value < $min || $value > $max) {
            throw new UnprocessableEntityHttpException(\sprintf('Cette valeur doit être comprise entre %d et %d.', $min, $max));
        }

        return [$paramKey => $value];
    }

    /**
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
