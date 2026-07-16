<?php

declare(strict_types=1);

namespace App\AdminJob;

use RuntimeException;

final class AdminJobAlreadyRunning extends RuntimeException {}
