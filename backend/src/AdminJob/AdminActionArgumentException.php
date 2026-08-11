<?php

declare(strict_types=1);

namespace App\AdminJob;

use RuntimeException;

/**
 * A runtime argument body violated the closed schema (SA4). Always fail-closed:
 * the controller maps this to a 400 without running the command. The message
 * names the offending key/value for the console, never a stack trace.
 */
final class AdminActionArgumentException extends RuntimeException {}
