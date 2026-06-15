<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ImplicitConstraintConfig;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:constraint:export-implicit',
    description: 'Export implicit constraints configuration as JSON',
)]
final class ExportImplicitConstraintsCommand extends Command
{
    private const ENGINE_URL = 'http://engine:8000/implicit-constraints';

    public function __construct(
        private readonly ImplicitConstraintConfig $implicitConstraintConfig,
        private readonly HttpClientInterface $httpClient,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $payload = $this->buildPayload();

        try {
            $response = $this->httpClient->request('POST', self::ENGINE_URL, [
                'json' => $payload,
            ]);
            $statusCode = $response->getStatusCode();
            $responseBody = $response->getContent(false);
            $decodedBody = $this->decodeResponseBody($responseBody);
        } catch (TransportExceptionInterface $exception) {
            $output->writeln(
                \sprintf('✗ Implicit constraints sync failed: %s', $exception->getMessage())
            );

            return Command::FAILURE;
        } catch (JsonException $exception) {
            $output->writeln(\sprintf('✗ Implicit constraints sync failed: invalid JSON response (%s)', $exception->getMessage()));

            return Command::FAILURE;
        }

        if (200 === $statusCode) {
            $rulesCount = $decodedBody['rules_count'] ?? null;

            if (($decodedBody['status'] ?? null) !== 'synchronized' || !is_numeric($rulesCount) || (int) $rulesCount !== \count($payload['rules'])) {
                $output->writeln('✗ Implicit constraints sync failed: unexpected engine response');
                $output->writeln($this->formatJson($decodedBody));

                return Command::FAILURE;
            }

            $output->writeln(
                \sprintf('✓ Implicit constraints synchronized (%d rules)', \count($payload['rules']))
            );

            return Command::SUCCESS;
        }

        if (409 === $statusCode) {
            $output->writeln('✗ Implicit constraints desynchronized');
            $output->writeln($this->formatJson($decodedBody));

            return Command::FAILURE;
        }

        $output->writeln(\sprintf('✗ Implicit constraints sync failed: unexpected HTTP %d', $statusCode));
        $output->writeln($this->formatJson($decodedBody));

        return Command::FAILURE;
    }

    /**
     * @return array{version: string, rules: list<array{name: string, enabled: bool, description: string}>}
     */
    private function buildPayload(): array
    {
        $rules = [];

        foreach ($this->implicitConstraintConfig->getConfig() as $rule) {
            $rules[] = [
                'name' => (string) $rule['type'],
                'enabled' => (bool) $rule['enabled'],
                'description' => (string) $rule['description'],
            ];
        }

        return [
            'version' => '2.0',
            'rules' => $rules,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponseBody(string $responseBody): array
    {
        if ('' === trim($responseBody)) {
            return [];
        }

        $decoded = json_decode($responseBody, true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatJson(array $data): string
    {
        if ([] === $data) {
            return '(empty response body)';
        }

        return json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }
}
