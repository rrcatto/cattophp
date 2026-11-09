<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

/** What kind of value a setting holds, which decides how it is checked when it is saved. */
enum SettingType: string
{
    /** One line of words. */
    case Text = 'text';
    /** Several lines of words. */
    case Paragraph = 'paragraph';
    case Email = 'email';
    /** A web address, http or https (https only when the setting says so). */
    case Address = 'address';
    case Number = 'number';
    /** On or off, saved as true or false. */
    case Switch = 'switch';
    /** A length of time in Duration's notation, such as 30m or 1d. */
    case Duration = 'duration';
    /** One of the setting's choices. */
    case Choice = 'choice';
    /** A time zone by its name, such as Africa/Johannesburg. */
    case TimeZone = 'time-zone';
    /** How mail is sent: an address such as smtp://user:password@mail.example.com:587. */
    case MailTransport = 'mail-transport';
}
