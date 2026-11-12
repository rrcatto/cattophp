<?php

declare(strict_types=1);

namespace CattoPHP\Setup;

use InvalidArgumentException;

/**
 * One step a new installation needs, as a project supplies it (SetupStepProvider). A step is checked automatically when
 * the site can tell from what it holds whether it is done, and needs you when a person must judge it and mark it done
 * (choosing the theme, reading the legal pages, a test only a person can confirm). A step can be both: the site may find
 * it done, and until then a person may mark it.
 */
final class SetupStep
{
    public const KEY = '/^[a-z][a-z0-9_-]{0,63}$/';

    /**
     * @param string $group the part of the setup it belongs to, such as "Your business"
     * @param string $purpose what it is for and where it is done, in a sentence or two
     * @param string $state where it stands now, in words: what the site found, or what to do
     * @param bool|null $checked what the site found: true done, false not yet, null when only a person can tell
     * @param bool $needsYou a person marks it done, or skips it for now
     * @param string $href the page where it is done
     * @param string $link that link's words, such as "Open Site Owner"
     * @param string $action a button of the project's own shown with the step, such as loading starter data
     */
    public function __construct(
        public readonly string $key,
        public readonly string $group,
        public readonly string $title,
        public readonly string $purpose,
        public readonly string $state,
        public readonly ?bool $checked,
        public readonly bool $needsYou,
        public readonly string $href,
        public readonly string $link,
        public readonly string $action = '',
    ) {
        if (preg_match(self::KEY, $key) !== 1) {
            throw new InvalidArgumentException(sprintf('A setup step is named in small letters, digits, hyphens and underscores, starting with a letter: "%s" is not.', $key));
        }
        if (trim($title) === '') {
            throw new InvalidArgumentException(sprintf('The setup step "%s" needs a title.', $key));
        }
        if ($checked === null && !$needsYou) {
            throw new InvalidArgumentException(sprintf('The site cannot tell whether the setup step "%s" is done, so a person must mark it: it needs you.', $key));
        }
    }
}
