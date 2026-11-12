<?php

declare(strict_types=1);

namespace CattoPHP\Inbox;

use CattoPHP\Settings\Setting;
use CattoPHP\Settings\SettingGroup;
use CattoPHP\Settings\SettingsProvider;
use CattoPHP\Settings\SettingType;

/**
 * The Inbox's settings group, as the settings catalogue reads it: a provider with no dependencies, since the catalogue is
 * built before Settings and a provider must never need Settings itself. InboxSettings reads and saves them.
 */
final class InboxSettingGroup implements SettingsProvider
{
    public const GROUP = 'inbox';
    private const ON_OFF = ['on' => 'On', 'off' => 'Off'];

    public function settingGroups(): iterable
    {
        yield new SettingGroup(self::GROUP, 'Inbox', 'Keeping messages, the spam check, replies, telling you about new messages and receiving answers.', [
            new Setting('INBOX_TRASH_KEPT_FOR', 'Empty the Trash after', SettingType::Duration, '30d', 'A message in the Trash for longer is deleted for good by the daily clean-up.', shortest: '1d'),
            new Setting('INBOX_SPAM_KEPT_FOR', 'Empty Spam after', SettingType::Duration, '30d', shortest: '1d'),
            new Setting('INBOX_KEPT_FOR', 'Keep conversations for', SettingType::Duration, '5y', 'As the Privacy Policy says.', shortest: '1y'),
            new Setting('INBOX_SPAM_LINE', 'Send a message to Spam when it scores', SettingType::Number, '60', 'or more, out of 100. Lower catches more spam and more real messages with it.', min: 1, max: 100),
            new Setting('INBOX_HOLD_BLOCKED_COUNTRIES', 'Hold messages from the blocked countries', SettingType::Choice, 'on', choices: self::ON_OFF),
            new Setting('INBOX_LEARN', 'Learn from what I mark', SettingType::Choice, 'on', choices: self::ON_OFF),
            new Setting('INBOX_NEVER_SPAM', 'Never send to Spam: these addresses and domains', SettingType::Paragraph, '', optional: true, longest: 5000),
            new Setting('INBOX_FROM_NAME', 'From name', optional: true, longest: 120),
            new Setting('INBOX_FROM_ADDRESS', 'From address', SettingType::Email, '', 'Empty: the site\'s own From address.', optional: true),
            new Setting('INBOX_REPLY_TO', 'Reply-to address', SettingType::Email, '', 'The mailbox answers come to. Empty: the site\'s own reply-to.', optional: true),
            new Setting('INBOX_REPLY_FORMAT', 'Write replies as', SettingType::Choice, 'html', choices: ['html' => 'HTML', 'plain' => 'Plain text']),
            new Setting('INBOX_SIGNATURE_TEXT', 'Signature for plain text replies', SettingType::Paragraph, '', optional: true, longest: 2000),
            new Setting('INBOX_SIGNATURE_HTML', 'Signature for HTML replies', SettingType::Paragraph, '', optional: true, longest: 10000),
            new Setting('INBOX_NOTIFY_EMAIL', 'Email me each new message', SettingType::Choice, 'on', choices: self::ON_OFF),
            new Setting('INBOX_NOTIFY_TELEGRAM', 'Send me a Telegram message', SettingType::Choice, 'off', choices: self::ON_OFF),
            new Setting('INBOX_ACKNOWLEDGE', 'Acknowledge each message to its sender', SettingType::Choice, 'on', choices: self::ON_OFF),
            new Setting('INBOX_IMAP_HOST', 'Mail server (IMAP)', optional: true, longest: 255),
            new Setting('INBOX_IMAP_PORT', 'Port', SettingType::Number, '993', min: 1, max: 65535),
            new Setting('INBOX_IMAP_SECURITY', 'Security', SettingType::Choice, 'ssl', choices: ['ssl' => 'SSL', 'tls' => 'STARTTLS', 'none' => 'None']),
            new Setting('INBOX_IMAP_USER', 'Mailbox user', optional: true, longest: 255),
            new Setting('INBOX_IMAP_PASSWORD', 'Password', optional: true, secret: true, longest: 500),
        ]);
    }
}
