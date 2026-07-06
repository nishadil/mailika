<?php

declare(strict_types=1);

namespace Mailika\Security;

use HTMLPurifier;
use HTMLPurifier_Config;
use Mailika\Config\Config;

final readonly class HtmlSanitizer
{
    private HTMLPurifier $purifier;

    public function __construct(Config $config)
    {
        $purifierConfig = HTMLPurifier_Config::createDefault();
        $purifierConfig->set('Cache.SerializerPath', $config->rootPath('storage/cache'));
        $purifierConfig->set('HTML.Allowed', implode(',', [
            'a[href|title|rel|target]',
            'abbr[title]',
            'blockquote',
            'br',
            'code',
            'div',
            'em',
            'h1',
            'h2',
            'h3',
            'h4',
            'h5',
            'h6',
            'hr',
            'img[src|alt|title|width|height]',
            'li',
            'ol',
            'p',
            'pre',
            'span',
            'strong',
            'table',
            'tbody',
            'td[colspan|rowspan]',
            'tfoot',
            'th[colspan|rowspan]',
            'thead',
            'tr',
            'ul',
        ]));
        $purifierConfig->set('Attr.AllowedFrameTargets', ['_blank']);
        $purifierConfig->set('AutoFormat.RemoveEmpty', true);
        $purifierConfig->set('CSS.AllowTricky', false);
        $purifierConfig->set('HTML.SafeIframe', false);
        $purifierConfig->set('URI.DisableExternalResources', !$config->bool('mail.remote_images'));
        $purifierConfig->set('URI.DisableResources', !$config->bool('mail.remote_images'));

        $this->purifier = new HTMLPurifier($purifierConfig);
    }

    public function sanitize(string $html): string
    {
        $blockedElements = 'script|style|iframe|object|embed|form|meta|link';
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/<\s*(' . $blockedElements . ')\b[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $html) ?? '';
        $html = preg_replace('/<\s*(' . $blockedElements . ')\b[^>]*\/?\s*>/is', '', $html) ?? '';

        return $this->purifier->purify($html);
    }
}
