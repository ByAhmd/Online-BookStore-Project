<?php

declare(strict_types=1);

namespace BookWise\Tests\Support;

/**
 * A <form> found in a page: where it submits to and the values a browser
 * would send without user input (hidden fields, defaults, selected options,
 * checked boxes). Buttons and file inputs are not included in $fields;
 * $fileFields names the form's (enabled) file inputs.
 */
final class HtmlForm
{
    /**
     * @param array<string, string> $fields
     * @param list<string> $fileFields
     */
    public function __construct(
        public readonly string $action,
        public readonly string $method,
        public readonly string $enctype,
        public readonly array $fields,
        public readonly array $fileFields = [],
    ) {
    }

    public function field(string $name): ?string
    {
        return $this->fields[$name] ?? null;
    }
}
