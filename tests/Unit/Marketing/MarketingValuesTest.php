<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Marketing;

use CattoPHP\Marketing\Advert;
use CattoPHP\Marketing\AdvertInput;
use CattoPHP\Marketing\Pictures;
use CattoPHP\Marketing\Attention;
use CattoPHP\Marketing\Channel;
use CattoPHP\Marketing\FigureRow;
use CattoPHP\Marketing\FigureTotals;
use CattoPHP\Marketing\MarketingRefused;
use CattoPHP\Marketing\NoProject;
use CattoPHP\Marketing\Offer;
use CattoPHP\Marketing\Publication;
use CattoPHP\Marketing\PublicationInput;
use CattoPHP\Marketing\Rows;
use CattoPHP\Marketing\Visitor;
use CattoPHP\Tests\Support\SamplePictures;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** The module's small classes: what each holds and the little each works out. */
final class MarketingValuesTest extends TestCase
{
    public function testWithoutAProjectNothingIsOfferedAndMoneyIsAPlainNumber(): void
    {
        $none = new NoProject();
        self::assertSame(['Offers', [], [], null, null, [], null, null, ''], [$none->offersHeading(), $none->offerKinds(), $none->offers(), $none->offer('product', 'a'),
            $none->account('1'), $none->promotions(), $none->saleHref('ORD-1'), $none->promotionHref('X'), $none->currency()]);
        self::assertSame(['1,200.50', '-0.05', '0.00'], [$none->money(120050), $none->money(-5), $none->money(0)]);
        self::assertSame('product:tea-100', (new Offer('product', 'tea-100', 'Rooibos tea'))->key());
    }

    public function testFiguresGrowWithoutLosingWhatTheyHold(): void
    {
        $totals = new FigureTotals(clicks: 10, uniqueVisitors: 7, repeatVisits: 2, linkPreviews: 3, otherSetAside: 1, spendMinor: 5000);
        self::assertSame([4, 0], [$totals->setAside(), (new FigureTotals())->setAside()]);
        $row = (new FigureRow('fb', 'Facebook', '', 4, 3, 1, 2))->withSpend(2500);
        self::assertSame(['fb', 'Facebook', 4, 3, 1, 2, 2500], [$row->key, $row->label, $row->clicks, $row->uniqueVisitors, $row->repeatVisits, $row->setAside, $row->spendMinor]);
        self::assertSame('no_clicks', (new Attention(Attention::NO_CLICKS, 'spring-sale', 'Spring sale', 'No click.', 'fb1'))->kind);
    }

    public function testRecordsSayWhetherTheyAreInUseOrRetired(): void
    {
        $at = new DateTimeImmutable('2026-11-01');
        self::assertSame([true, false], [(new Channel('facebook', 'Facebook', 'fb', 'users', 10, true, 3))->inUse(), (new Channel('print', 'Print', 'pr', 'printer', 20, true))->inUse()]);
        $advert = new Advert(1, 'k7qxm2pa', 1, 'Spring picture', 'picture', '', '', null, null, '', $at, $at, $at, 2);
        self::assertSame([true, true], [$advert->isRetired(), $advert->inUse()]);
        $publication = new Publication(1, 1, 'facebook', null, 'fb1', 'Cape Town readers', $at, null, 25000, null, '', null, null, null, $at, $at);
        self::assertFalse($publication->isRetired());
        $input = PublicationInput::of($publication, $advert);
        self::assertSame(['facebook', 'k7qxm2pa', 'fb1', '2026-11-01', '250'], [$input->channel, $input->advert, $input->code, $input->publishedOn, $input->cost]);
        self::assertFalse((new Visitor(1, null, $at, $at, null, '', 'unknown', null))->isAnonymous());
    }

    public function testAFormIsReadAsTyped(): void
    {
        $publication = PublicationInput::fromForm(['channel' => ' facebook ', 'code' => 'FB2', 'cost' => '250', 'notes' => ['not text']]);
        self::assertSame(['facebook', 'fb2', '250', ''], [$publication->channel, $publication->code, $publication->cost, $publication->notes]);
        $advert = AdvertInput::fromForm(['name' => 'Spring words', 'kind' => 'words', 'remove_picture' => 'on']);
        self::assertSame(['Spring words', true, null, null], [$advert->name, $advert->removePicture, $advert->picturePath, $advert->uploadProblem]);
        $folder = sys_get_temp_dir();
        $path = SamplePictures::file($folder, SamplePictures::PNG, 'cattophp-advert-' . bin2hex(random_bytes(4)) . '.tmp');
        try {
            $uploaded = AdvertInput::fromForm(['name' => 'Spring picture', 'kind' => 'picture'], new UploadedFile($path, 'spring.png', null, null, true));
            self::assertSame($path, $uploaded->picturePath);
            $tooLarge = AdvertInput::fromForm(['name' => 'Spring picture'], new UploadedFile($path, 'spring.png', null, UPLOAD_ERR_INI_SIZE, true));
            self::assertStringContainsString('larger than the site takes', (string) $tooLarge->uploadProblem);
            self::assertNull(AdvertInput::fromForm([], new UploadedFile($path, '', null, UPLOAD_ERR_NO_FILE, true))->uploadProblem, 'No picture chosen is no problem.');
        } finally {
            @unlink($path);
        }
    }

    public function testAPictureIsJudgedByWhatItHolds(): void
    {
        self::assertSame(['png', 'jpg', 'webp'], [Pictures::kindOf((string) base64_decode(SamplePictures::PNG)), Pictures::kindOf((string) base64_decode(SamplePictures::JPEG)),
            Pictures::kindOf((string) base64_decode(SamplePictures::WEBP))]);
        self::assertNull(Pictures::kindOf((string) base64_decode(SamplePictures::GIF)), 'A GIF is not taken.');
        self::assertNull(Pictures::kindOf("\x89PNG\r\n\x1A\n" . 'not really a picture'), 'A file that only starts like a picture is not one.');
        self::assertNull(Pictures::kindOf('<svg xmlns="http://www.w3.org/2000/svg"/>'));
        self::assertSame(['820 KB', '4.5 MB', '1 KB'], [Pictures::size(820 * 1024), Pictures::size((int) (4.5 * 1048576)), Pictures::size(10)]);
    }

    public function testRowsAreReadAndWrittenWithTheirOffsetAndCodesAreMadeFromNames(): void
    {
        $zone = new DateTimeZone('Africa/Johannesburg');
        self::assertSame('2026-11-09 12:00', Rows::instant('2026-11-09 10:00:00+00', $zone)?->format('Y-m-d H:i'));
        self::assertNull(Rows::instant(null, $zone));
        self::assertSame('2026-11-09 10:00:00.000000+00:00', Rows::sql(new DateTimeImmutable('2026-11-09 10:00:00', new DateTimeZone('UTC'))));
        self::assertSame([true, true, false], [Rows::bool('t'), Rows::bool(true), Rows::bool('f')]);
        self::assertSame(['spring-sale-2026', ''], [Rows::slug('Spring Sale, 2026!', 40), Rows::slug('!!!', 40)]);
        if (class_exists(\Normalizer::class)) {
            self::assertSame('cafe-creme', Rows::slug('Café crème', 40));
        }
        self::assertSame('abc', Rows::slug('abc-def', 4), 'No hyphen left at the end.');
        self::assertSame('%50\%\_off%', Rows::like('50%_off'));
        self::assertMatchesRegularExpression('/^[a-hjkmnp-z2-9]{8}$/', Rows::reference());
        self::assertSame([['' => 'Why.'], 'Why.'], [MarketingRefused::because('Why.')->errors(), MarketingRefused::because('Why.')->getMessage()]);
    }
}
