<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

/**
 * One thing a reader can do with an offer on a landing page, as the project gives it: view it, favourite it, buy it, ask
 * their company for it, continue with it, see their order, or sign in. A GET action is a link (its href); a POST action is
 * a one-button form posting its fields (the project's own form token among them) to its action, to which the page adds
 * cattophp_landing (the page's reference) and cattophp_return (the page's address). CattoPHP draws it with cl- classes,
 * so it works without JavaScript. Pressed says a favourite is already given.
 */
final class OfferAction
{
    public const KINDS = ['view', 'favourite', 'buy', 'request', 'continue', 'order', 'sign_in'];

    /** The kinds that take the place of buy when the project gives them. */
    public const INSTEAD_OF_BUY = ['continue', 'order', 'request', 'sign_in'];

    /** @param array<string,string> $fields */
    public function __construct(
        public readonly string $kind,
        public readonly string $label,
        public readonly string $icon,
        public readonly string $method = 'GET',
        public readonly string $href = '',
        public readonly string $action = '',
        public readonly array $fields = [],
        public readonly bool $pressed = false,
    ) {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException(sprintf('An offer\'s action is one of %s: "%s" is not.', implode(', ', self::KINDS), $kind));
        }
        if (!in_array($method, ['GET', 'POST'], true)) {
            throw new \InvalidArgumentException('An offer\'s action is a GET link or a POST form.');
        }
    }

    public function isForm(): bool
    {
        return $this->method === 'POST';
    }

    /** @return array{kind:string,label:string,icon:string,method:string,href:string,action:string,fields:array<string,string>,pressed:bool} */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'label' => $this->label, 'icon' => $this->icon, 'method' => $this->method, 'href' => $this->href, 'action' => $this->action,
            'fields' => $this->fields, 'pressed' => $this->pressed];
    }
}
