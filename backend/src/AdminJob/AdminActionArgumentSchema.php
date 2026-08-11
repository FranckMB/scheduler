<?php

declare(strict_types=1);

namespace App\AdminJob;

/**
 * The CLOSED argument schema of a support action (SA4): an ordered list of
 * {@see AdminActionArgument}. It is the ENTIRE grammar of what a request may add
 * to the fixed catalogue command — validated fail-closed. The controller applies
 * the schema; the schema carries the rules (including the conditional presence of
 * set-plan's `paidSeason`).
 */
final readonly class AdminActionArgumentSchema
{
    /** @param list<AdminActionArgument> $arguments Ordered — a gate argument MUST precede the argument it governs. */
    public function __construct(public array $arguments = []) {}

    /**
     * Validate a decoded JSON body against the schema and return the CLI mapping
     * (`option => value`) to append to the fixed catalogue arguments. Order follows
     * the schema declaration. Throws on any violation — unknown key, value outside
     * the enum, missing required argument, or a forbidden argument that is present.
     *
     * @param array<array-key, mixed> $body
     *
     * @throws AdminActionArgumentException
     *
     * @return array<string, string>
     */
    public function validate(array $body): array
    {
        $known = [];
        foreach ($this->arguments as $argument) {
            $known[$argument->key] = true;
        }
        foreach (array_keys($body) as $key) {
            if (!isset($known[$key])) {
                throw new AdminActionArgumentException(\sprintf('Unknown argument "%s".', (string) $key));
            }
        }

        $result = [];
        foreach ($this->arguments as $argument) {
            $present = \array_key_exists($argument->key, $body);

            if ($argument->isConditional()) {
                $gateValue = \array_key_exists((string) $argument->gateArgument, $body) ? $body[$argument->gateArgument] : null;
                $forbidden = \is_string($gateValue) && \in_array($gateValue, $argument->forbiddenWhenGateIn, true);

                if ($forbidden) {
                    if ($present) {
                        throw new AdminActionArgumentException(\sprintf('Argument "%s" is not allowed for this "%s".', $argument->key, (string) $argument->gateArgument));
                    }

                    continue;
                }
            }

            if (!$present) {
                throw new AdminActionArgumentException(\sprintf('Argument "%s" is required.', $argument->key));
            }

            $value = $body[$argument->key];
            if (!\is_string($value) || !$argument->allows($value)) {
                throw new AdminActionArgumentException(\sprintf('Value "%s" is not allowed for argument "%s".', \is_scalar($value) ? (string) $value : \gettype($value), $argument->key));
            }

            $result[$argument->option] = $value;
        }

        return $result;
    }
}
