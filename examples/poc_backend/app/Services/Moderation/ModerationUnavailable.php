<?php

namespace App\Services\Moderation;

use RuntimeException;

/** The AI review could not run (the comment is hidden for an admin instead). */
final class ModerationUnavailable extends RuntimeException {}
