<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

/**
 * A project's side of sending its campaigns, given to CattoPHP's sender: who a campaign goes to when it starts, who it
 * comes from, how each person's copy is drawn (in the project's own design: blocks, templates, placeholders) and how the
 * copy a person forwards is drawn for their friend, whether a campaign keeps sending as copies are added later (a welcome
 * email), and the sending rules the project keeps.
 */
interface CampaignProject
{
    /**
     * The people a campaign goes to when it starts, each with the list that brings them and why; null when its copies are
     * added some other way (a digest, an update, a welcome email).
     *
     * @param array<string,mixed> $campaign
     * @return list<array{account:string,email:string,list_id:?int,reasons:list<string>}>|null
     */
    public function audience(array $campaign): ?array;

    /** @param array<string,mixed> $campaign */
    public function sender(array $campaign): SenderIdentity;

    /**
     * @param array<string,mixed> $campaign
     * @param array<string,mixed> $copy
     */
    public function draw(array $campaign, array $copy): DrawnCopy;

    /**
     * A person's copy as their friend gets it when they forward it: addressed to the friend (their name, when given), with
     * the person's note, the friend's "Never email me" link (made from the forward's token) and no link that would act
     * for the person.
     *
     * @param array<string,mixed> $campaign
     * @param array<string,mixed> $copy
     */
    public function drawForFriend(array $campaign, array $copy, string $friendName, string $note, string $token): DrawnCopy;

    /**
     * Whether a campaign stays sending when its copies run out, to send to whoever is added next.
     *
     * @param array<string,mixed> $campaign
     */
    public function waitsForMore(array $campaign): bool;

    public function rules(): SendingRules;
}
