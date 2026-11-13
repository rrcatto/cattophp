<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Ui\Contract;

use CattoPHP\Foundation\ReadableCode;
use CattoPHP\Ui\Contract\AddressContract;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/** No row number in an address: a route taking {id}, {*_id} or digits alone is refused, unless allowed with a reason. */
final class AddressContractTest extends TestCase
{
    public function testARouteTakingAnIdIsRefused(): void
    {
        $problems = AddressContract::rowNumbers(self::routes(['note' => new Route('/notes/{id}', methods: ['GET'])]));

        self::assertSame(['GET /notes/{id} (route note) takes {id}, a row number: name the record by a code, a reference, a slug or a random public id, or allow "note id" with the reason.'], $problems);
    }

    public function testANameEndingInIdOrARequirementOfDigitsIsARowNumberToo(): void
    {
        $problems = AddressContract::rowNumbers(self::routes([
            'loan' => new Route('/tools/{tool_id}/loans'),
            'page' => new Route('/notes/{note}', requirements: ['note' => '\d+']),
            'slip' => new Route('/slips/{slip}', requirements: ['slip' => '[1-9][0-9]{0,8}']),
        ]));

        self::assertCount(3, $problems);
        self::assertStringStartsWith('ANY /tools/{tool_id}/loans (route loan) takes {tool_id}, a row number', $problems[0]);
        self::assertStringStartsWith('ANY /notes/{note} (route page) takes {note} (\d+), a row number', $problems[1]);
        self::assertStringStartsWith('ANY /slips/{slip} (route slip) takes {slip} ([1-9][0-9]{0,8}), a row number', $problems[2]);
    }

    public function testCodesReferencesSlugsAndPublicIdsPass(): void
    {
        self::assertSame([], AddressContract::rowNumbers(self::routes([
            'role' => new Route('/admin/roles/{key}', requirements: ['key' => '[A-Z][A-Z0-9_]{1,39}']),
            'account' => new Route('/admin/accounts/{reference}', requirements: ['reference' => ReadableCode::CHARACTER . '{8}']),
            'tool' => new Route('/tools/{slug}', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*']),
            'link' => new Route('/sign-in/link/{token}', requirements: ['token' => '[0-9a-f]{64}']),
            'order' => new Route('/orders/{number}', requirements: ['number' => 'CL-[0-9]{6}']),
            'feed' => new Route('/notes.{_format}', requirements: ['_format' => '\d+']),
            '_preview_error' => new Route('/_error/{code}', requirements: ['code' => '\d+']),
        ])), 'A parameter of Symfony\'s own (_format) and a route of Symfony\'s own (named from _) are left alone.');
    }

    public function testAnAllowanceNeedsAReasonAndMustStillBeNeeded(): void
    {
        $routes = self::routes(['entry' => new Route('/admin/activity/{id}')]);

        self::assertSame([], AddressContract::rowNumbers($routes, ['entry id' => 'An activity entry is named by nothing else yet.']));
        self::assertSame(['The allowance "entry id" gives no reason: say why the address may keep a row number for now.'], AddressContract::rowNumbers($routes, ['entry id' => ' ']));
        self::assertSame(['The allowance "gone id" matches no route parameter that takes a row number: take it off the list.'], AddressContract::rowNumbers(self::routes([]), ['gone id' => 'Was needed once.']));
    }

    public function testOnlyARequirementOfDigitsAloneCounts(): void
    {
        foreach (['\d+', '\d{1,9}', '[0-9]+', '[1-9][0-9]*', '[0-9]{4}', '(\d+)'] as $digits) {
            self::assertTrue(AddressContract::onlyDigits($digits), $digits);
        }
        foreach (['', '.+', '[^/]+', '[a-z0-9-]+', '\d+|new', ReadableCode::CHARACTER . '{8}', '[A-Z]{2}-\d+'] as $other) {
            self::assertFalse(AddressContract::onlyDigits($other), $other);
        }
    }

    /** @param array<string,Route> $routes */
    private static function routes(array $routes): RouteCollection
    {
        $collection = new RouteCollection();
        foreach ($routes as $name => $route) {
            $collection->add($name, $route);
        }

        return $collection;
    }
}
