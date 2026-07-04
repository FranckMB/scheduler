<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class CalendarEntryInput
{
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['event', 'period'])]
    #[Groups(['write'])]
    public ?string $kind = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    #[Groups(['write'])]
    public ?string $title = null;

    #[Assert\NotBlank]
    #[Assert\Date]
    #[Groups(['write'])]
    public ?string $startDate = null;

    #[Assert\NotBlank]
    #[Assert\Date]
    #[Groups(['write'])]
    public ?string $endDate = null;

    #[Groups(['write'])]
    public ?bool $isDisruptive = null;

    #[Assert\Choice(choices: ['closure', 'holiday', 'cutoff', 'mutualisation', 'custom'])]
    #[Groups(['write'])]
    public ?string $periodType = null;

    #[Groups(['write'])]
    public ?string $schoolHolidayId = null;

    #[Assert\Choice(choices: ['proposed', 'active', 'ignored'])]
    #[Groups(['write'])]
    public ?string $status = null;

    #[Groups(['write'])]
    public ?string $createdBy = null;

    #[Assert\Callback]
    public function validateShape(ExecutionContextInterface $context): void
    {
        if (null !== $this->startDate && null !== $this->endDate && $this->endDate < $this->startDate) {
            $context->buildViolation('endDate must be on or after startDate.')
                ->atPath('endDate')
                ->addViolation();
        }

        if ('period' === $this->kind && null === $this->periodType) {
            $context->buildViolation('periodType is required for a period entry.')
                ->atPath('periodType')
                ->addViolation();
        }

        if ('event' === $this->kind) {
            if (null !== $this->periodType) {
                $context->buildViolation('periodType is only allowed on a period entry.')
                    ->atPath('periodType')
                    ->addViolation();
            }
            if (null !== $this->schoolHolidayId) {
                $context->buildViolation('schoolHolidayId is only allowed on a period entry.')
                    ->atPath('schoolHolidayId')
                    ->addViolation();
            }
        }

        if ('period' === $this->kind && null !== $this->isDisruptive) {
            $context->buildViolation('isDisruptive is only allowed on an event entry.')
                ->atPath('isDisruptive')
                ->addViolation();
        }
    }
}
