<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Marketing\Landing;

use CattoPHP\Marketing\Landing\CopyRefused;
use CattoPHP\Marketing\Landing\LandingCopy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The plain-text rules of landing page copy, ported from CattoLMS's LandingPagesTest with every assertion kept: a line
 * collapses white space, prose keeps its line breaks and makes runs of empty lines one, control characters are dropped,
 * invalid UTF-8 and over-long text are refused naming the field, a half-filled row is refused, empty lines and rows are
 * dropped, and copy that looks like markup stays text. Given a field's name, a refusal says which field it is about.
 */
final class LandingCopyTest extends TestCase
{
    public function testListsAreOneStatementALineAndRowsNeedBothTheirFields(): void
    {
        self::assertSame('Who', LandingCopy::line('  Who  ', 120, 'The heading'));
        self::assertSame("One\n\nTwo", LandingCopy::prose("One\r\n\r\n\r\nTwo", 1000, 'The introduction'));
        self::assertSame(['Conveyancers', 'Candidate attorneys'], LandingCopy::lines("Conveyancers\n\n  Candidate attorneys  \n", 12, 200, 'The list'));
        $rows = LandingCopy::rows([1 => ['question' => 'How long?', 'answer' => 'A year.'], 2 => ['question' => '', 'answer' => '']], ['question' => ['Question', 200, false], 'answer' => ['Answer', 2000, true]], 20, 'Question');
        self::assertSame([['question' => 'How long?', 'answer' => 'A year.']], $rows, 'An empty row is dropped.');

        $this->refuses(static fn() => LandingCopy::rows([['question' => 'Half a row', 'answer' => '']], ['question' => ['Question', 200, false], 'answer' => ['Answer', 2000, true]], 20, 'Question'), 'needs its answer');
        $this->refuses(static fn() => LandingCopy::rows([['title' => '', 'text' => 'No title']], ['title' => ['Title', 80, false], 'text' => ['Explanation', 400, true]], 12, 'Benefit'), 'needs its title');
        $this->refuses(static fn() => LandingCopy::lines(implode("\n", array_fill(0, 13, 'Someone')), 12, 200, 'The list'), 'at most 12');
        $this->refuses(static fn() => LandingCopy::line(str_repeat('x', 121), 120, 'The headline'), 'longer than 120');
        $this->refuses(static fn() => LandingCopy::rows(array_fill(0, 21, ['question' => 'Q', 'answer' => 'A']), ['question' => ['Question', 200, false], 'answer' => ['Answer', 2000, true]], 20, 'Question'), 'at most 20');
    }

    public function testCopyIsPlainTextThatStaysTextWhateverIsTyped(): void
    {
        self::assertSame('<script>alert(1)</script> Title', LandingCopy::line("<script>alert(1)</script>\x07 Title", 120, 'The headline'), 'Stored as typed, without control characters; the page escapes it.');
        self::assertSame([['<b onclick="x()">Bold</b>']], LandingCopy::paragraphs(LandingCopy::prose('<b onclick="x()">Bold</b>', 600, 'The supporting text')));
        $this->refuses(static fn() => LandingCopy::line("\xC3\x28", 10, 'The heading'), 'could not be read');
        $this->refuses(static fn() => LandingCopy::line(['an array'], 10, 'The heading'), 'must be text');
        self::assertSame([['First line', 'second line'], ['Next paragraph']], LandingCopy::paragraphs("First line\nsecond line\n\n\nNext paragraph"));
        self::assertSame('A lesson about…', LandingCopy::excerpt('<p>A lesson <strong>about</strong> deeds and transfers</p>', 20), 'Cut at a word, never inside one.');
        self::assertSame('', LandingCopy::line(null, 10, 'The heading'));
        self::assertSame('12', LandingCopy::line(12, 10, 'The heading'));
    }

    public function testAFieldNamedIsTheFieldARefusalIsAbout(): void
    {
        try {
            LandingCopy::line(str_repeat('x', 41), 40, 'The second button\'s label', 'secondary_label');
            self::fail('An over-long label was taken.');
        } catch (CopyRefused $refused) {
            self::assertSame(['secondary_label', 'The second button\'s label is longer than 40 characters.'], [$refused->field, $refused->getMessage()]);
        }
        try {
            LandingCopy::rows([['question' => 'Fine', 'answer' => 'Yes.'], ['question' => 'Half', 'answer' => '']], ['question' => ['Question', 200, false], 'answer' => ['Answer', 2000, true]], 20, 'Question', 'items');
            self::fail('Half a row was taken.');
        } catch (CopyRefused $refused) {
            self::assertSame(['items.1.answer', 'Question 2 needs its answer as well.'], [$refused->field, $refused->getMessage()], 'The row and the field left empty.');
        }
        try {
            LandingCopy::rows([['question' => str_repeat('q', 201), 'answer' => 'A']], ['question' => ['Question', 200, false], 'answer' => ['Answer', 2000, true]], 20, 'Question', 'items');
            self::fail('An over-long question was taken.');
        } catch (CopyRefused $refused) {
            self::assertSame('items.0.question', $refused->field);
        }
    }

    private function refuses(callable $call, string $message): void
    {
        try {
            $call();
            self::fail('Expected a refusal mentioning ' . $message);
        } catch (InvalidArgumentException $refused) {
            self::assertStringContainsString($message, $refused->getMessage());
        }
    }
}
