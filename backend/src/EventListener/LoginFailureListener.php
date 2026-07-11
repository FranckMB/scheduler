<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Enum\AuditAction;
use App\Service\AuditTrail;
use ReflectionClass;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * RGPD/sécurité — trace les échecs d'authentification dans le journal d'audit
 * (accountability + matière première de la détection brute-force pour la
 * console superadmin SA1). RÈGLE NO-PII : ni email tenté ni IP — uniquement le
 * firewall et le type d'échec ; le rate-limiter (SEC-11 / login_throttling)
 * reste la défense active, ceci n'est que la trace.
 */
#[AsEventListener(event: LoginFailureEvent::class)]
final class LoginFailureListener
{
    public function __construct(
        private readonly AuditTrail $auditTrail,
    ) {}

    public function __invoke(LoginFailureEvent $event): void
    {
        // Uniquement le firewall de login interactif : les 401 du firewall api
        // (JWT expiré au fil de l'eau) noieraient le signal.
        if ('login' !== $event->getFirewallName()) {
            return;
        }
        // Les rejets DU throttle ne s'écrivent pas : sinon un attaquant non
        // authentifié ferait grossir la table append-only sans borne (revue
        // PR-4) — le volume audité est ainsi plafonné par login_throttling.
        if ($event->getException() instanceof TooManyLoginAttemptsAuthenticationException) {
            return;
        }

        $this->auditTrail->record(AuditAction::AUTH_LOGIN_FAILED, null, null, null, null, [
            'firewall' => $event->getFirewallName(),
            'exception' => new ReflectionClass($event->getException())->getShortName(),
        ]);
    }
}
