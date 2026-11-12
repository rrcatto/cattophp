<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

use CattoPHP\Setup\SetupMarks;
use CattoPHP\Setup\SetupStep;
use CattoPHP\Setup\SetupStepProvider;

/** A small project's setup steps: one the site checks, one a person marks, one either, one a test email marks. */
final class ExampleSetupSteps implements SetupStepProvider
{
    public bool $detailsFilled = false;
    public bool $verificationConfigured = false;
    public string $mailState = '';

    public function steps(SetupMarks $marks): iterable
    {
        yield new SetupStep('details', 'Your business', 'Your business details', 'The names and address the pages print.',
            $this->detailsFilled ? 'Set: Example Learning.' : 'Still the example details.', $this->detailsFilled, false, '/settings/owner', 'Open Site Owner');
        yield new SetupStep('mail', 'Your business', 'Outgoing email', 'Send yourself a test email: one that arrives marks this step done.',
            $this->mailState !== '' ? $this->mailState : ($marks->isDone('mail') ? 'A test email was sent.' : 'No test email yet.'), null, true, '/settings/mail', 'Open Outgoing mail');
        yield new SetupStep('verification', 'Selling', 'Identity verification', 'Certificates wait until identities can be verified.',
            $this->verificationConfigured ? 'Configured.' : 'Not configured yet.', $this->verificationConfigured, true, '/settings/identity', 'Open Identity verification');
        yield new SetupStep('theme', 'Look', 'Theme', 'Choose how the site looks.', 'Choose one, then mark this step done.', null, true, '/themes', 'Open Themes', 'pick-theme');
    }
}
