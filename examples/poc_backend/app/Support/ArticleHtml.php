<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Str;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * S8 M05-A article content:
 * - sanitize(): only safe formatting elements (no scripts, styles, event handlers)
 * - fromMarkdown(): .md with an optional YAML front matter (simple subset)
 * - toBlocks(): sanitized HTML → plain blocks the App renders natively
 */
final class ArticleHtml
{
    private ?HtmlSanitizer $sanitizer = null;

    public function sanitize(string $html): string
    {
        $this->sanitizer ??= new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->allowLinkSchemes(['https', 'http', 'mailto'])
                ->allowMediaSchemes(['https', 'http'])
                ->withMaxInputLength(500_000)
        );

        return $this->sanitizer->sanitize($html);
    }

    /**
     * Front matter keys used: title, author, cover, summary, categories (list).
     *
     * @return array{meta: array<string, string|list<string>>, html: string}
     */
    public function fromMarkdown(string $markdown): array
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", ltrim($markdown, "\xEF\xBB\xBF"));
        $meta = [];

        if (preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $markdown, $m) === 1) {
            $meta = $this->frontMatter($m[1]);
            $markdown = $m[2];
        }

        $html = Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        return ['meta' => $meta, 'html' => $this->sanitize($html)];
    }

    /**
     * Minimal YAML subset: `key: value`, `key: [a, b]` and `key:` followed by `- item` lines.
     *
     * @return array<string, string|list<string>>
     */
    public function frontMatter(string $yaml): array
    {
        $meta = [];
        $listKey = null;

        foreach (explode("\n", $yaml) as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            if ($listKey !== null && preg_match('/^\s*-\s*(.+)$/', $line, $m) === 1) {
                $meta[$listKey][] = $this->scalar($m[1]);

                continue;
            }

            $listKey = null;
            if (preg_match('/^([A-Za-z_][\w-]*)\s*:\s*(.*)$/', $line, $m) !== 1) {
                continue;
            }

            $key = strtolower($m[1]);
            $value = trim($m[2]);

            if ($value === '') {
                $meta[$key] = [];
                $listKey = $key;
            } elseif (str_starts_with($value, '[') && str_ends_with($value, ']')) {
                $meta[$key] = array_values(array_filter(array_map(fn ($v) => $this->scalar($v), explode(',', substr($value, 1, -1))), fn ($v) => $v !== ''));
            } else {
                $meta[$key] = $this->scalar($value);
            }
        }

        return $meta;
    }

    /**
     * @return list<array{type: string, text?: string, level?: int, src?: string, href?: string}>
     */
    public function toBlocks(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $doc = new DOMDocument;
        @$doc->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $body = $doc->getElementsByTagName('body')->item(0);
        $blocks = [];

        if ($body !== null) {
            foreach ($body->childNodes as $node) {
                $this->collect($node, $blocks);
            }
        }

        return $blocks;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private function collect(DOMNode $node, array &$blocks): void
    {
        if (! $node instanceof DOMElement) {
            $text = trim((string) $node->textContent);
            if ($text !== '') {
                $blocks[] = ['type' => 'paragraph', 'text' => $text];
            }

            return;
        }

        $tag = strtolower($node->tagName);
        $text = trim(preg_replace('/\s+/u', ' ', (string) $node->textContent) ?? '');

        if (preg_match('/^h[1-6]$/', $tag) === 1) {
            if ($text !== '') {
                $blocks[] = ['type' => 'heading', 'level' => (int) substr($tag, 1), 'text' => $text];
            }
        } elseif ($tag === 'ul' || $tag === 'ol') {
            foreach ($node->childNodes as $item) {
                if ($item instanceof DOMElement && strtolower($item->tagName) === 'li') {
                    $itemText = trim(preg_replace('/\s+/u', ' ', (string) $item->textContent) ?? '');
                    if ($itemText !== '') {
                        $blocks[] = ['type' => 'list_item', 'text' => $itemText, 'ordered' => $tag === 'ol'];
                    }
                }
            }
        } elseif ($tag === 'blockquote') {
            if ($text !== '') {
                $blocks[] = ['type' => 'quote', 'text' => $text];
            }
        } elseif ($tag === 'img') {
            $src = $node->getAttribute('src');
            if (str_starts_with($src, 'https://') || str_starts_with($src, 'http://')) {
                $blocks[] = ['type' => 'image', 'src' => $src, 'text' => $node->getAttribute('alt')];
            }
        } elseif ($tag === 'pre') {
            $blocks[] = ['type' => 'code', 'text' => (string) $node->textContent];
        } elseif (in_array($tag, ['div', 'section', 'article', 'figure'], true)) {
            foreach ($node->childNodes as $child) {
                $this->collect($child, $blocks);
            }
        } else {
            if ($text !== '') {
                $blocks[] = ['type' => 'paragraph', 'text' => $text];
            }
            foreach ($node->getElementsByTagName('img') as $img) {
                $this->collect($img, $blocks);
            }
        }
    }

    private function scalar(string $value): string
    {
        $value = trim($value);

        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }

        return trim($value);
    }
}
