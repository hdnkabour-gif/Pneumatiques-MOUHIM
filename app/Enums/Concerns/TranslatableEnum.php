<?php

namespace App\Enums\Concerns;

/**
 * Labels come from lang/{fr,ar}/enums.php under the enum's TRANSLATION_KEY.
 * Implements Filament's HasLabel contract through getLabel().
 */
trait TranslatableEnum
{
    public function label(): string
    {
        return __('enums.'.self::TRANSLATION_KEY.'.'.$this->value);
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
