<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SuperAdmin;
use App\Security\TotpService;
use App\Service\PasswordPolicy;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

#[AsCommand(name: 'app:superadmin:create', description: 'Create a separate MFA-protected super-admin account.')]
final class CreateSuperAdminCommand extends Command
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly TotpService $totp,
        private readonly PasswordPolicy $passwordPolicy,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Super-admin email address');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = strtolower(trim((string) $input->getArgument('email')));
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $output->writeln('<error>Invalid email address.</error>');

            return Command::INVALID;
        }
        $question = new Question('Password: ')->setHidden(true)->setHiddenFallback(false);
        $questionHelper = $this->getHelper('question');
        \assert($questionHelper instanceof QuestionHelper);
        $password = (string) $questionHelper->ask($input, $output, $question);
        $passwordError = $this->passwordPolicy->validate($password);
        if (null !== $passwordError) {
            $output->writeln('<error>' . $passwordError . '</error>');

            return Command::INVALID;
        }
        $confirmationQuestion = new Question('Confirm password: ');
        $confirmationQuestion->setHidden(true)->setHiddenFallback(false);
        $confirmation = (string) $questionHelper->ask($input, $output, $confirmationQuestion);
        if (!hash_equals($password, $confirmation)) {
            $output->writeln('<error>Passwords do not match.</error>');

            return Command::INVALID;
        }
        $secret = $this->totp->generateSecret();
        $id = Uuid::v4()->toRfc4122();
        $identity = new SuperAdmin($id, $email, '', $this->totp->encrypt($secret));
        $identity->setPasswordHash($this->hasher->hashPassword($identity, $password));
        $connection = $this->registry->getConnection('admin');
        \assert($connection instanceof Connection);
        if (false !== $connection->fetchOne('SELECT 1 FROM super_admin WHERE LOWER(email) = LOWER(:email)', ['email' => $email])) {
            $output->writeln('<error>This account cannot be created (email already used or database unavailable).</error>');

            return Command::FAILURE;
        }
        try {
            $connection->executeStatement(
                'INSERT INTO super_admin (id, email, password_hash, totp_secret, enabled, created_at) VALUES (:id, :email, :password, :totp, TRUE, NOW())',
                ['id' => $id, 'email' => $email, 'password' => $identity->getPassword(), 'totp' => $identity->getTotpSecret()],
            );
        } catch (Throwable) {
            $output->writeln('<error>This account cannot be created (email already used or database unavailable).</error>');

            return Command::FAILURE;
        }
        $output->writeln('<info>Super-admin created.</info>');
        $output->writeln('Google Authenticator setup key: <comment>' . $secret . '</comment>');
        $output->writeln('Provisioning URI: <comment>' . $this->totp->provisioningUri($email, $secret) . '</comment>');
        $output->writeln('<comment>Store this key now: it will not be displayed again.</comment>');

        return Command::SUCCESS;
    }
}
