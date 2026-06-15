<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Constraint;

final class ConstraintSerializer
{
    /**
     * Serialize a Constraint entity into the engine payload format.
     *
     * @return array<string, mixed>
     */
    public function serialize(Constraint $constraint): array
    {
        $config = $constraint->getConfig();
        $result = [
            'id' => $constraint->getId(),
            'scope' => $constraint->getScope()->value,
            'family' => $constraint->getFamily()->value,
            'ruleType' => $constraint->getRuleType()->value,
        ];

        if (null !== $constraint->getScopeTargetId()) {
            $result['scopeTargetId'] = $constraint->getScopeTargetId();
        }

        // Add family-specific config fields
        $result = array_merge($result, $this->serializeConfig($constraint->getFamily()->value, $config));

        return $result;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function serializeConfig(string $family, array $config): array
    {
        $result = [];

        // Common fields
        if (isset($config['targetTag'])) {
            $result['targetTag'] = $config['targetTag'];
        }

        switch ($family) {
            case 'TIME':
                if (isset($config['maxStartTime'])) {
                    $result['maxStartTime'] = $config['maxStartTime'];
                }
                if (isset($config['minStartTime'])) {
                    $result['minStartTime'] = $config['minStartTime'];
                }
                break;

            case 'DAY':
                if (isset($config['preferredDays'])) {
                    $result['preferredDays'] = $config['preferredDays'];
                }
                if (isset($config['forbiddenDays'])) {
                    $result['forbiddenDays'] = $config['forbiddenDays'];
                }
                break;

            case 'FACILITY':
                if (isset($config['preferredVenueId'])) {
                    $result['preferredVenueId'] = $config['preferredVenueId'];
                }
                if (isset($config['forbiddenVenueId'])) {
                    $result['forbiddenVenueId'] = $config['forbiddenVenueId'];
                }
                if (isset($config['dateStart'])) {
                    $result['dateStart'] = $config['dateStart'];
                }
                if (isset($config['dateEnd'])) {
                    $result['dateEnd'] = $config['dateEnd'];
                }
                break;

            case 'COACH_AVAILABILITY':
                if (isset($config['coachId'])) {
                    $result['coachId'] = $config['coachId'];
                }
                if (isset($config['availableDays'])) {
                    $result['availableDays'] = $config['availableDays'];
                }
                if (isset($config['unavailableDays'])) {
                    $result['unavailableDays'] = $config['unavailableDays'];
                }
                break;

            case 'FACILITY_CAPACITY':
                if (isset($config['venueId'])) {
                    $result['venueId'] = $config['venueId'];
                }
                if (isset($config['maxTeams'])) {
                    $result['maxTeams'] = $config['maxTeams'];
                }
                break;
        }

        return $result;
    }
}
