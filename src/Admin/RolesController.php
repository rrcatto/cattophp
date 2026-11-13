<?php

declare(strict_types=1);

namespace CattoPHP\Admin;

use CattoPHP\Access\AccessRefused;
use CattoPHP\Access\Permission;
use CattoPHP\Access\Role;
use CattoPHP\Access\Roles;
use CattoPHP\Http\StockPages;
use CattoPHP\Http\TypedForm;
use CattoPHP\SignIn\SignInAccounts;
use CattoPHP\Ui\IconLibrary;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Administration > Roles (`config/routes/admin.php`): CattoPHP's roles and permissions. A role grants permissions; a person
 * holds roles. The Administrator role holds every permission, now and as new ones are added; a permission starting
 * SYSTEM. is for administrators alone. Every change is in the activity log. Seeing them needs PLATFORM.ROLE.VIEW, changing
 * them SYSTEM.ROLE.MANAGE.
 */
final class RolesController
{
    /** A role's key as an address carries it. */
    public const KEY = '[A-Z][A-Z0-9_]{1,39}';

    public function __construct(
        private readonly StockPages $pages,
        private readonly Roles $roles,
        private readonly AccountNames $names,
        private readonly IconLibrary $icons,
        private readonly ?SignInAccounts $accounts = null,
    ) {
    }

    public function list(Request $request): Response
    {
        $this->pages->deny('PLATFORM.ROLE.VIEW');
        $rows = [];
        foreach ($this->roles->all() as $role) {
            $holders = array_map(fn (string $id): string => $this->names->name($id), $this->roles->holders($role->key));
            $rows[] = [
                'key' => $role->key,
                'name' => $role->name,
                'description' => $role->description,
                'allows' => $role->isAdministrator() ? 'Every permission' : match (count($role->permissions)) {
                    0 => 'Nothing yet',
                    1 => '1 permission',
                    default => count($role->permissions) . ' permissions',
                },
                'holders' => $holders === [] ? 'Nobody' : implode(', ', $holders),
                'href' => $this->pages->path('cattophp_admin_role', ['key' => $role->key]),
            ];
        }

        return $this->pages->page($request, 'admin-roles', '@CattoPHP/admin/roles.html.twig', [
            'title' => 'Roles',
            'lead' => 'A role allows what its permissions say, and a person holds the roles they are given.',
            'roles' => $rows,
            'may_change' => $this->mayChange(),
        ]);
    }

    public function new(Request $request): Response
    {
        $this->pages->deny('SYSTEM.ROLE.MANAGE');
        $typed = TypedForm::take($request, $this->pages->path('cattophp_admin_role_make'));

        return $this->pages->page($request, 'admin-role-new', '@CattoPHP/admin/role-new.html.twig', [
            'title' => 'New role',
            'lead' => 'A key the code may name it by, a name people read, and what it allows.',
            'typed' => [
                'key' => self::text($typed['key'] ?? ''),
                'name' => self::text($typed['name'] ?? ''),
                'description' => self::text($typed['description'] ?? ''),
            ],
            'permission_groups' => $this->permissionGroups(self::strings($typed['permissions'] ?? [])),
        ]);
    }

    public function make(Request $request): RedirectResponse
    {
        $this->pages->deny('SYSTEM.ROLE.MANAGE');
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_admin_role_new');
        }
        $key = strtoupper(trim(self::text($request->request->get('key', ''))));
        if (isset($this->roles->all()[$key])) {
            return $this->pages->refused($request, sprintf('Another role already has the key %s: choose another.', $key), 'cattophp_admin_role_new');
        }
        try {
            $this->roles->save($key, self::text($request->request->get('name', '')), self::text($request->request->get('description', '')), self::strings($request->request->all()['permissions'] ?? []), $this->pages->actor());
        } catch (AccessRefused $refused) {
            return $this->pages->refused($request, 'The role was not made: ' . $refused->getMessage(), 'cattophp_admin_role_new');
        }

        return $this->pages->done($request, 'The role ' . $this->roles->get($key)->name . ' is made. Give it to the people who need it.', 'cattophp_admin_role', ['key' => $key]);
    }

    public function show(Request $request, string $key): Response
    {
        $this->pages->deny('PLATFORM.ROLE.VIEW');
        $role = $this->role($key);
        $typed = TypedForm::take($request, $this->pages->path('cattophp_admin_role_save', ['key' => $key]));
        $reader = $this->pages->actor();
        $holders = [];
        foreach ($this->roles->holders($key) as $id) {
            $account = $this->names->account($id);
            $holders[] = [
                'id' => $id,
                'name' => $this->names->name($id),
                'email' => $account->email ?? '',
                'you' => $id === $reader->id,
            ];
        }

        return $this->pages->page($request, 'admin-role', '@CattoPHP/admin/role.html.twig', [
            'title' => $role->name,
            'lead' => $role->isAdministrator() ? 'Holds every permission, now and as new ones are added. It cannot be changed or deleted.' : ($role->description !== '' ? $role->description : 'What this role allows, and who holds it.'),
            'role' => [
                'key' => $role->key,
                'name' => self::text($typed['name'] ?? $role->name),
                'description' => self::text($typed['description'] ?? $role->description),
                'administrator' => $role->isAdministrator(),
            ],
            'permission_groups' => $role->isAdministrator() ? [] : $this->permissionGroups($typed === [] ? $role->permissions : self::strings($typed['permissions'] ?? [])),
            'holders' => $holders,
            'give_email' => self::text(TypedForm::take($request, $this->pages->path('cattophp_admin_role_give', ['key' => $key]))['email'] ?? ''),
            'may_change' => $this->mayChange(),
            'may_give' => $this->mayChange() && $this->accounts !== null,
        ]);
    }

    public function save(Request $request, string $key): RedirectResponse
    {
        $this->pages->deny('SYSTEM.ROLE.MANAGE');
        $role = $this->role($key);
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_admin_role', ['key' => $key]);
        }
        try {
            $this->roles->save($key, self::text($request->request->get('name', '')), self::text($request->request->get('description', '')), self::strings($request->request->all()['permissions'] ?? []), $this->pages->actor());
        } catch (AccessRefused $refused) {
            return $this->pages->refused($request, 'The role was not saved: ' . $refused->getMessage(), 'cattophp_admin_role', ['key' => $key]);
        }

        return $this->pages->done($request, 'The role ' . $this->roles->get($key)->name . ' is saved.', 'cattophp_admin_role', ['key' => $role->key]);
    }

    public function delete(Request $request, string $key): RedirectResponse
    {
        $this->pages->deny('SYSTEM.ROLE.MANAGE');
        $role = $this->role($key);
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_admin_role', ['key' => $key]);
        }
        try {
            $this->roles->delete($key, $this->pages->actor());
        } catch (AccessRefused $refused) {
            return $this->pages->refused($request, 'The role was not deleted: ' . $refused->getMessage(), 'cattophp_admin_role', ['key' => $key]);
        }

        return $this->pages->done($request, 'The role ' . $role->name . ' is deleted, and nobody holds it now.', 'cattophp_admin_roles');
    }

    public function give(Request $request, string $key): RedirectResponse
    {
        $this->pages->deny('SYSTEM.ROLE.MANAGE');
        $role = $this->role($key);
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_admin_role', ['key' => $key], 'holders');
        }
        $email = trim(self::text($request->request->get('email', '')));
        $account = filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $this->accounts?->forEmail($email);
        if ($account === null) {
            return $this->pages->refused($request, 'No account has that email address, so nobody was given the role. Check the address, or ask them to sign in once first.', 'cattophp_admin_role', ['key' => $key], 'holders');
        }
        try {
            $this->roles->grant($account->id, $key, $this->pages->actor());
        } catch (AccessRefused $refused) {
            return $this->pages->refused($request, $refused->getMessage(), 'cattophp_admin_role', ['key' => $key], 'holders');
        }

        return $this->pages->done($request, $account->name . ' holds the role ' . $role->name . ' now.', 'cattophp_admin_role', ['key' => $key], 'holders');
    }

    public function takeAway(Request $request, string $key): RedirectResponse
    {
        $this->pages->deny('SYSTEM.ROLE.MANAGE');
        $role = $this->role($key);
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_admin_role', ['key' => $key], 'holders');
        }
        $account = self::text($request->request->get('account', ''));
        if (!in_array($account, $this->roles->holders($key), true)) {
            return $this->pages->refused($request, 'That person does not hold the role ' . $role->name . ' now.', 'cattophp_admin_role', ['key' => $key], 'holders');
        }
        $name = $this->names->name($account);
        try {
            $this->roles->revoke($account, $key, $this->pages->actor());
        } catch (AccessRefused $refused) {
            return $this->pages->refused($request, $refused->getMessage(), 'cattophp_admin_role', ['key' => $key], 'holders');
        }

        return $this->pages->done($request, $name . ' no longer holds the role ' . $role->name . '.', 'cattophp_admin_role', ['key' => $key], 'holders');
    }

    private function mayChange(): bool
    {
        return $this->pages->may('SYSTEM.ROLE.MANAGE') && $this->roles->canChange();
    }

    private function role(string $key): Role
    {
        try {
            return $this->roles->get($key);
        } catch (AccessRefused) {
            throw new NotFoundHttpException('There is no such role.');
        }
    }

    /**
     * Every permission, by its group, as feature cards to tick: one a role cannot hold (SYSTEM.) shown but not tickable.
     *
     * @param list<string> $ticked
     * @return list<array{group:string,id:string,items:list<array<string,mixed>>,selected:list<string>}>
     */
    private function permissionGroups(array $ticked): array
    {
        $groups = [];
        foreach ($this->roles->catalogue()->grouped() as $group => $permissions) {
            $icon = $this->icons->svg($this->icons->bestFor($group, 'key-round')) ?? '';
            $items = [];
            foreach ($permissions as $permission) {
                $item = ['value' => $permission->key, 'label' => $permission->description, 'icon' => $icon, 'note' => $permission->key . ($permission->isAdministratorsOnly() ? ': administrators alone' : '')];
                if ($permission->isAdministratorsOnly()) {
                    $item['disabled'] = true;
                }
                $items[] = $item;
            }
            $groups[] = [
                'group' => $group,
                'id' => 'permissions-' . strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $group)),
                'items' => $items,
                'selected' => array_values(array_intersect($ticked, array_map(static fn (Permission $permission): string => $permission->key, $permissions))),
            ];
        }

        return $groups;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** @return list<string> */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }
}
