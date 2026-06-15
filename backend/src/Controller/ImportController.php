<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Service\FfbbExcelImporter;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final class ImportController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FfbbExcelImporter $importer,
    ) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $club = $this->entityManager->getRepository(Club::class)->find($id);
        if (!$club instanceof Club) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->json(['error' => 'No file uploaded.'], Response::HTTP_BAD_REQUEST);
        }

        if ('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' !== $file->getMimeType()
            && !str_ends_with(strtolower($file->getClientOriginalName()), '.xlsx')
        ) {
            return $this->json(['error' => 'Invalid file format. Only .xlsx files are accepted.'], Response::HTTP_BAD_REQUEST);
        }

        $seasonId = $request->request->get('seasonId');
        if (!\is_string($seasonId) || '' === $seasonId) {
            return $this->json(['error' => 'seasonId is required.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->importer->import($file->getRealPath(), $id, $seasonId);
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'message' => 'Import completed.',
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'errors' => $result['errors'],
        ], Response::HTTP_OK);
    }
}
