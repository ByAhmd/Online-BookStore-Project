<?php

declare(strict_types=1);

namespace BookWise\Tests\Support;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

/**
 * Parsed HTML page with the queries the HTTP suite needs.
 */
final class HtmlDocument
{
    /** Attributes whose value is fetched or navigated to by the browser. */
    private const URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'srcset', 'poster', 'data', 'xlink:href'];

    private DOMDocument $dom;
    private DOMXPath $xpath;

    public function __construct(public readonly string $html)
    {
        $this->dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            // The XML declaration makes libxml read the markup as UTF-8.
            $this->dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $this->xpath = new DOMXPath($this->dom);
    }

    /**
     * XPath predicate for an element carrying a CSS class.
     */
    public static function hasClass(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' $class ')";
    }

    /**
     * @return list<DOMElement>
     */
    public function elements(string $expression, ?DOMNode $context = null): array
    {
        $nodes = $this->xpath->query($expression, $context);
        if ($nodes === false) {
            throw new RuntimeException("Invalid XPath expression: $expression");
        }
        $elements = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }
        return $elements;
    }

    public function first(string $expression, ?DOMNode $context = null): ?DOMElement
    {
        return $this->elements($expression, $context)[0] ?? null;
    }

    public function count(string $expression): int
    {
        return count($this->elements($expression));
    }

    /**
     * Whitespace-normalized text of the whole body, or of the first element
     * matching the XPath expression ('' when nothing matches).
     */
    public function text(?string $expression = null): string
    {
        $node = $expression === null ? ($this->dom->getElementsByTagName('body')->item(0) ?? $this->dom->documentElement) : $this->first($expression);
        if ($node === null) {
            return '';
        }
        return self::normalize($node->textContent);
    }

    /**
     * Normalized text of every element matching the expression.
     *
     * @return list<string>
     */
    public function texts(string $expression): array
    {
        return array_map(static fn (DOMElement $element): string => self::normalize($element->textContent), $this->elements($expression));
    }

    public static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public function title(): string
    {
        return $this->text('//title');
    }

    public function csrfToken(): ?string
    {
        $meta = $this->first('//meta[@name="csrf-token"]');
        return $meta?->getAttribute('content');
    }

    /**
     * Flash messages shown on the page.
     *
     * @return list<array{type: string, message: string}>
     */
    public function flashes(): array
    {
        $flashes = [];
        foreach ($this->elements('//div[' . self::hasClass('flash') . ']') as $element) {
            preg_match('/\bflash-([a-z]+)\b/', $element->getAttribute('class'), $match);
            $flashes[] = [
                'type' => $match[1] ?? '',
                'message' => self::normalize($this->first('.//p', $element)?->textContent ?? $element->textContent),
            ];
        }
        return $flashes;
    }

    /**
     * Text of the inline validation message with the given element id ('' if absent).
     */
    public function fieldError(string $id): string
    {
        return $this->text('//*[@id="' . $id . '"]');
    }

    /**
     * Finds the form submitting to $action whose fields include every $match pair.
     *
     * @param array<string, string> $match
     */
    public function form(string $action, array $match = []): HtmlForm
    {
        $candidates = [];
        foreach ($this->elements('//form') as $form) {
            $formAction = $form->getAttribute('action');
            $candidates[] = $formAction . ' ' . json_encode($this->formFields($form));
            if ($formAction !== $action) {
                continue;
            }
            $fields = $this->formFields($form);
            foreach ($match as $name => $value) {
                if (($fields[$name] ?? null) !== $value) {
                    continue 2;
                }
            }
            return $this->htmlForm($form);
        }
        throw new RuntimeException(sprintf(
            "No form posting to \"%s\" with %s on this page. Forms found:\n%s",
            $action,
            json_encode($match),
            implode("\n", $candidates)
        ));
    }

    /**
     * Every form of the page, in document order.
     *
     * @return list<HtmlForm>
     */
    public function forms(): array
    {
        return array_map(fn (DOMElement $form): HtmlForm => $this->htmlForm($form), $this->elements('//form'));
    }

    private function htmlForm(DOMElement $form): HtmlForm
    {
        $fileFields = [];
        foreach ($this->elements('.//input[@type="file"][@name]', $form) as $input) {
            if (!$input->hasAttribute('disabled')) {
                $fileFields[] = $input->getAttribute('name');
            }
        }
        return new HtmlForm(
            $form->getAttribute('action'),
            strtoupper($form->getAttribute('method') ?: 'GET'),
            strtolower($form->getAttribute('enctype') ?: 'application/x-www-form-urlencoded'),
            $this->formFields($form),
            $fileFields
        );
    }

    /**
     * @param array<string, string> $match
     */
    public function hasForm(string $action, array $match = []): bool
    {
        try {
            $this->form($action, $match);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * Source of every <script> element without a src attribute.
     *
     * @return list<string>
     */
    public function inlineScripts(): array
    {
        return array_map(
            static fn (DOMElement $script): string => trim($script->textContent),
            $this->elements('//script[not(@src)]')
        );
    }

    /**
     * Every on* event-handler attribute, as "tag[name=value]".
     *
     * @return list<string>
     */
    public function eventHandlerAttributes(): array
    {
        $found = [];
        foreach ($this->attributes() as $attribute) {
            if (str_starts_with(strtolower($attribute->nodeName), 'on')) {
                $found[] = sprintf('<%s %s="%s">', $attribute->ownerElement?->nodeName, $attribute->nodeName, $attribute->value);
            }
        }
        return $found;
    }

    /**
     * URL-bearing attributes that use the javascript: scheme.
     *
     * @return list<string>
     */
    public function javascriptUrls(): array
    {
        $found = [];
        foreach ($this->attributes() as $attribute) {
            if (!in_array(strtolower($attribute->nodeName), self::URL_ATTRIBUTES, true)) {
                continue;
            }
            // Browsers ignore leading whitespace/control characters and embedded tabs/newlines.
            $value = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $attribute->value));
            if (str_contains($value, 'javascript:')) {
                $found[] = sprintf('<%s %s="%s">', $attribute->ownerElement?->nodeName, $attribute->nodeName, $attribute->value);
            }
        }
        return $found;
    }

    /**
     * Same-origin scripts, stylesheets and images referenced by the page,
     * including every candidate of srcset attributes (<picture> sources).
     *
     * @return list<string>
     */
    public function localAssets(): array
    {
        $urls = [];
        foreach ($this->elements('//script[@src] | //link[@rel="stylesheet"][@href] | //img[@src]') as $element) {
            $urls[] = $element->hasAttribute('src') ? $element->getAttribute('src') : $element->getAttribute('href');
        }
        foreach ($this->elements('//*[@srcset]') as $element) {
            array_push($urls, ...self::srcsetUrls($element->getAttribute('srcset')));
        }
        $assets = [];
        foreach ($urls as $url) {
            if ($url === '' || preg_match('#^(?:[a-z][a-z0-9+.\-]*:|//)#i', $url)) {
                continue; // external (https://fonts...) or data: URLs
            }
            $assets[] = $url;
        }
        return array_values(array_unique($assets));
    }

    /**
     * URLs of a srcset value ("a.webp 190w, b.webp 380w" => [a.webp, b.webp]).
     *
     * @return list<string>
     */
    public static function srcsetUrls(string $srcset): array
    {
        $urls = [];
        foreach (explode(',', $srcset) as $candidate) {
            $url = (string) strtok(trim($candidate), " \t\n");
            if ($url !== '') {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    /**
     * @return array<string, string>
     */
    private function formFields(DOMElement $form): array
    {
        $fields = [];
        foreach ($this->elements('.//input | .//select | .//textarea', $form) as $control) {
            $name = $control->getAttribute('name');
            if ($name === '' || $control->hasAttribute('disabled')) {
                continue;
            }
            switch (strtolower($control->nodeName)) {
                case 'input':
                    $type = strtolower($control->getAttribute('type') ?: 'text');
                    if (in_array($type, ['submit', 'button', 'image', 'reset', 'file'], true)) {
                        continue 2;
                    }
                    if (in_array($type, ['checkbox', 'radio'], true)) {
                        if (!$control->hasAttribute('checked')) {
                            continue 2;
                        }
                        $fields[$name] = $control->hasAttribute('value') ? $control->getAttribute('value') : 'on';
                        continue 2;
                    }
                    $fields[$name] = $control->getAttribute('value');
                    break;
                case 'select':
                    $options = $this->elements('.//option', $control);
                    $chosen = null;
                    foreach ($options as $option) {
                        if ($option->hasAttribute('selected')) {
                            $chosen = $option;
                        }
                    }
                    $chosen ??= $options[0] ?? null;
                    if ($chosen !== null) {
                        $fields[$name] = $chosen->hasAttribute('value') ? $chosen->getAttribute('value') : self::normalize($chosen->textContent);
                    }
                    break;
                case 'textarea':
                    $fields[$name] = $control->textContent;
                    break;
            }
        }
        return $fields;
    }

    /**
     * @return list<DOMAttr>
     */
    private function attributes(): array
    {
        $nodes = $this->xpath->query('//@*');
        $attributes = [];
        foreach ($nodes === false ? [] : $nodes as $node) {
            if ($node instanceof DOMAttr) {
                $attributes[] = $node;
            }
        }
        return $attributes;
    }
}
