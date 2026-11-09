<?php

declare(strict_types=1);

namespace CattoPHP\Mail;

use RuntimeException;

/** An email that could not be sent, with why in plain words. */
final class MailNotSent extends RuntimeException
{
}
