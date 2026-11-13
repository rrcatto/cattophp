<?php

declare(strict_types=1);

namespace CattoPHP\Activity;

/**
 * Where the record an activity entry is about lives, for a page that lists the activity log (Administration > Activity
 * log), which then links the entry's "About" to it. A project adds it with a service that implements this, found by
 * autoconfiguration, beside ActionWords and DetailWords. href() is asked for every entry about something, CattoPHP's
 * own subjects (account, setting, role) among them, so a project may link an account to its own person page too.
 *
 * It answers the record's address, such as /tools/k7qxm2pa, or null where there is none to give: a type it does not know,
 * a record deleted since, or a page the reader may not open. Only a path on the site (starting with one /) or an http or
 * https address is linked; anything else, and null, leaves the entry named in words alone. The first service with an
 * address gives it.
 */
interface SubjectLinks
{
    /** The address of the record of this type and id, such as /tools/k7qxm2pa for a tool; null for none. */
    public function href(string $type, string $id): ?string;
}
