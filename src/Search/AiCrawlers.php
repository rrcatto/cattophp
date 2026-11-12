<?php

declare(strict_types=1);

namespace CattoPHP\Search;

/**
 * The AI crawlers robots.txt names (every one let in until Settings keeps some or all of them out, SearchSettings), each
 * with what its company says it is for. Kept in this one list so it is easy to bring up to date: add, remove or
 * reclassify a line here and robots.txt follows.
 *
 * The list as of 2026-11-06, each checked that day against its company's own documentation:
 * - OpenAI, https://developers.openai.com/api/docs/bots (GPTBot, OAI-SearchBot, ChatGPT-User)
 * - Anthropic, https://support.claude.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler
 *   (ClaudeBot, Claude-SearchBot, Claude-User; anthropic-ai and Claude-Web are no longer documented)
 * - Google, https://developers.google.com/crawling/docs/crawlers-fetchers/google-common-crawlers (Google-Extended) and
 *   https://developers.google.com/crawling/docs/crawlers-fetchers/google-user-triggered-fetchers (Google-Agent,
 *   Google-GeminiNotebook)
 * - Perplexity, https://docs.perplexity.ai/docs/resources/perplexity-crawlers (PerplexityBot, Perplexity-User)
 * - Apple, https://support.apple.com/en-us/119829 (Applebot-Extended)
 * - Meta, https://developers.facebook.com/docs/sharing/webmasters/web-crawlers (meta-externalagent, meta-webindexer,
 *   meta-externalfetcher; FacebookBot is no longer documented)
 * - Common Crawl, https://commoncrawl.org/ccbot (CCBot)
 * - Amazon, https://developer.amazon.com/docs/amazonbot/amazonbot.html (Amazonbot, Amzn-SearchBot, Amzn-User)
 * - DuckDuckGo, https://duckduckgo.com/duckduckgo-help-pages/results/duckassistbot (DuckAssistBot)
 * - Mistral, https://docs.mistral.ai/robots (MistralAI-Training, MistralAI-Index, MistralAI-User)
 * - You.com, https://you.com/docs/youbot (YouBot)
 * - Diffbot, https://www.diffbot.com/docs/crawl/faq/robots-txt (Diffbot)
 * - Ai2, https://allenai.org/crawler (AI2Bot)
 * - Webz.io, https://webz.io/bot.html (Webzio-Extended)
 * - SB Intuitions, https://www.sbintuitions.co.jp/bot/ (SBIntuitionsBot)
 *
 * Each is one of three kinds, from that documentation:
 * - TRAINING: collects pages to train models or build training data, and nothing a person sees in an answer.
 * - SEARCH: builds an index, or reads pages, for an AI search or answer assistant that shows and cites them.
 * - USER: fetches a page because a person asked an assistant for it at that moment.
 * Two that are not plain cases:
 * - Google-Extended is a control token, not a crawler (Googlebot does the fetching). It decides both Gemini's training and
 *   Gemini's grounding, its answers from Google Search's pages, in Gemini Apps and Vertex AI, so it is not training-only:
 *   it is SEARCH here and stays allowed when only training crawlers are kept out. It has no effect on Google Search or its
 *   AI Overviews, which only Googlebot's rules decide.
 * - meta-externalagent and Amazonbot are documented as training and "improving products"; each company has its own
 *   search token (meta-webindexer, Amzn-SearchBot), so they are TRAINING here.
 * Applebot (Spotlight, Siri) and Googlebot and Bingbot (Google Search, AI Overviews, Copilot) are search engines' crawlers,
 * not AI ones, and are never named: keeping them out would take the site out of search. Microsoft has no AI-only token.
 *
 * Left out because no page of the company's own documents them on 2026-11-06: Bytespider (ByteDance), xAI's Grok,
 * cohere-ai, Timpibot, Kangaroo Bot, ImagesiftBot and PetalBot (not AI crawlers by their own pages). Link previews
 * (facebookexternalhit, Twitterbot, LinkedInBot, Slackbot, WhatsApp, TelegramBot) are never named, so they follow the
 * general rules and share cards keep working.
 *
 * Some USER fetchers say robots.txt may not apply to a request a person made (ChatGPT-User, Perplexity-User,
 * meta-externalfetcher, Amzn-User, Google-Agent, Google-GeminiNotebook); they are still named, so the file says what the
 * site wants.
 */
final class AiCrawlers
{
    public const TRAINING = 'training';
    public const SEARCH = 'search';
    public const USER = 'user';

    /** The date this list was last checked against each company's documentation. */
    public const CHECKED = '2026-11-06';

    /**
     * Each robots.txt token as its company writes it (RFC 9309 matches tokens without regard to case): its company, its
     * kind and what it is for.
     *
     * @var array<string,array{company:string,kind:string,purpose:string}>
     */
    private const CRAWLERS = [
        'GPTBot' => ['company' => 'OpenAI', 'kind' => self::TRAINING, 'purpose' => 'collects pages to train OpenAI\'s models'],
        'OAI-SearchBot' => ['company' => 'OpenAI', 'kind' => self::SEARCH, 'purpose' => 'finds pages for ChatGPT search'],
        'ChatGPT-User' => ['company' => 'OpenAI', 'kind' => self::USER, 'purpose' => 'reads a page a ChatGPT user asked about'],
        'ClaudeBot' => ['company' => 'Anthropic', 'kind' => self::TRAINING, 'purpose' => 'collects pages to train Claude'],
        'Claude-SearchBot' => ['company' => 'Anthropic', 'kind' => self::SEARCH, 'purpose' => 'finds pages for Claude\'s search results'],
        'Claude-User' => ['company' => 'Anthropic', 'kind' => self::USER, 'purpose' => 'reads a page a Claude user asked about'],
        'Google-Extended' => ['company' => 'Google', 'kind' => self::SEARCH, 'purpose' => 'decides Gemini\'s training and Gemini\'s answers from Search pages (not Google Search itself)'],
        'Google-Agent' => ['company' => 'Google', 'kind' => self::USER, 'purpose' => 'reads a page for an agent acting on a user\'s request'],
        'Google-GeminiNotebook' => ['company' => 'Google', 'kind' => self::USER, 'purpose' => 'reads a page a Gemini Notebook user added as a source'],
        'PerplexityBot' => ['company' => 'Perplexity', 'kind' => self::SEARCH, 'purpose' => 'finds pages for Perplexity\'s answers, not for training'],
        'Perplexity-User' => ['company' => 'Perplexity', 'kind' => self::USER, 'purpose' => 'reads a page a Perplexity user asked about'],
        'Applebot-Extended' => ['company' => 'Apple', 'kind' => self::TRAINING, 'purpose' => 'decides whether Apple trains its models on pages (Applebot\'s search is not affected)'],
        'meta-externalagent' => ['company' => 'Meta', 'kind' => self::TRAINING, 'purpose' => 'collects pages to train Meta\'s models'],
        'meta-webindexer' => ['company' => 'Meta', 'kind' => self::SEARCH, 'purpose' => 'finds pages Meta AI cites in its answers'],
        'meta-externalfetcher' => ['company' => 'Meta', 'kind' => self::USER, 'purpose' => 'reads a page a Meta AI user asked about'],
        'CCBot' => ['company' => 'Common Crawl', 'kind' => self::TRAINING, 'purpose' => 'builds the open web archive models are trained on'],
        'Amazonbot' => ['company' => 'Amazon', 'kind' => self::TRAINING, 'purpose' => 'collects pages to improve Amazon\'s services and train its models'],
        'Amzn-SearchBot' => ['company' => 'Amazon', 'kind' => self::SEARCH, 'purpose' => 'finds pages for Alexa\'s answers, not for training'],
        'Amzn-User' => ['company' => 'Amazon', 'kind' => self::USER, 'purpose' => 'reads a page for an Alexa question'],
        'DuckAssistBot' => ['company' => 'DuckDuckGo', 'kind' => self::SEARCH, 'purpose' => 'reads pages for DuckDuckGo\'s AI answers, not for training'],
        'MistralAI-Training' => ['company' => 'Mistral', 'kind' => self::TRAINING, 'purpose' => 'collects pages to train Mistral\'s models'],
        'MistralAI-Index' => ['company' => 'Mistral', 'kind' => self::SEARCH, 'purpose' => 'finds pages for Mistral\'s search, not for training'],
        'MistralAI-User' => ['company' => 'Mistral', 'kind' => self::USER, 'purpose' => 'reads a page a Le Chat user asked about'],
        'YouBot' => ['company' => 'You.com', 'kind' => self::SEARCH, 'purpose' => 'finds pages for You.com\'s AI search'],
        'Diffbot' => ['company' => 'Diffbot', 'kind' => self::SEARCH, 'purpose' => 'builds Diffbot\'s knowledge graph and search, not for training'],
        'AI2Bot' => ['company' => 'Ai2', 'kind' => self::TRAINING, 'purpose' => 'collects pages to train open language models'],
        'Webzio-Extended' => ['company' => 'Webz.io', 'kind' => self::TRAINING, 'purpose' => 'decides whether Webz.io offers pages for AI training'],
        'SBIntuitionsBot' => ['company' => 'SB Intuitions', 'kind' => self::TRAINING, 'purpose' => 'collects pages to develop SB Intuitions\' models'],
    ];

    /** @return array<string,array{company:string,kind:string,purpose:string}> */
    public static function all(): array
    {
        return self::CRAWLERS;
    }

    /** Whether a crawler of this kind may come in under the choice (SearchSettings::CHOICES). */
    public static function allowed(string $kind, string $choice): bool
    {
        return match ($choice) {
            SearchSettings::BLOCK_ALL => false,
            SearchSettings::SEARCH_ONLY => $kind !== self::TRAINING,
            default => true,
        };
    }

    /**
     * How many of each kind the list holds, for Settings' words.
     *
     * @return array<string,int>
     */
    public static function counts(): array
    {
        $counts = [self::TRAINING => 0, self::SEARCH => 0, self::USER => 0];
        foreach (self::CRAWLERS as $crawler) $counts[$crawler['kind']]++;
        return $counts;
    }
}
