<?php

declare(strict_types=1);

namespace App;

use App\Security\ProdSecretGuard;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * A16: fail closed on committed dev secrets in prod, for EVERY entrypoint
     * (HTTP, console, messenger-worker all boot the kernel) — see ProdSecretGuard.
     */
    public function boot(): void
    {
        parent::boot();

        if ('prod' === $this->environment) {
            ProdSecretGuard::assert($_SERVER + $_ENV);
        }
    }
}
