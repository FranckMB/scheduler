<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\PasswordPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

final class PasswordController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly MailerInterface $mailer,
        private readonly RateLimiterFactory $authPasswordForgotLimiter,
        private readonly PasswordPolicy $passwordPolicy,
    ) {}

    #[Route('/api/password/forgot', name: 'api_password_forgot', methods: ['POST'])]
    public function forgot(Request $request): JsonResponse
    {
        if (!$this->authPasswordForgotLimiter->create($request->getClientIp())->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Too many attempts, please try again later'], 429);
        }

        $data = json_decode((string) $request->getContent(), true);
        $email = \is_array($data) && isset($data['email']) && \is_string($data['email']) ? trim($data['email']) : '';

        $user = '' === $email ? null : $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if (null !== $user) {
            try {
                $resetToken = $this->resetPasswordHelper->generateResetToken($user);
                $link = $request->getSchemeAndHttpHost() . '/reset-password/' . $resetToken->getToken();
                $this->mailer->send(
                    (new Email)
                        ->from('no-reply@clubscheduler.app')
                        ->to($user->getEmail())
                        ->subject('Réinitialisation de votre mot de passe ClubScheduler')
                        ->text("Pour réinitialiser votre mot de passe, ouvrez ce lien :\n{$link}\n\nCe lien expire dans 1 heure."),
                );
            } catch (ResetPasswordExceptionInterface) {
                // Throttled or other reset error — swallow to avoid leaking account state.
            }
        }

        // Always 200: never reveal whether an email is registered (no enumeration).
        return $this->json(['status' => 'sent']);
    }

    #[Route('/api/password/reset', name: 'api_password_reset', methods: ['POST'])]
    public function reset(Request $request): JsonResponse
    {
        $data = json_decode((string) $request->getContent(), true);
        $token = \is_array($data) && isset($data['token']) && \is_string($data['token']) ? $data['token'] : '';
        $password = \is_array($data) && isset($data['password']) && \is_string($data['password']) ? $data['password'] : '';

        if ('' === $token) {
            return $this->json(['error' => 'Token is required'], 400);
        }
        if (null !== ($passwordError = $this->passwordPolicy->validate($password))) {
            return $this->json(['error' => $passwordError], 400);
        }

        try {
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface) {
            return $this->json(['error' => 'Invalid or expired reset token'], 400);
        }

        if (!$user instanceof User) {
            return $this->json(['error' => 'Invalid or expired reset token'], 400);
        }

        $this->resetPasswordHelper->removeResetRequest($token);
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));
        $this->entityManager->flush();

        return $this->json(['status' => 'reset']);
    }
}
