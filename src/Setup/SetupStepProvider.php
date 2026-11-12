<?php

declare(strict_types=1);

namespace CattoPHP\Setup;

/**
 * Supplies a project's setup steps, in order. A service that implements it is found by its tag; several providers are
 * listed by their tag priority, highest first (#[AsTaggedItem(priority: N)]), each one's steps in the order it gives.
 */
interface SetupStepProvider
{
    public const TAG = 'cattophp.setup_steps';

    /**
     * @param SetupMarks $marks what people have marked, for words such as "A test email was sent."
     * @return iterable<SetupStep>
     */
    public function steps(SetupMarks $marks): iterable;
}
