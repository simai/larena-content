<?php

declare(strict_types=1);

namespace Larena\Content\Admin;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Str;
use Larena\Ui\Smart;

final readonly class ContentAdminPresenter
{
    public function __construct(private Translator $translator)
    {
    }

    public function button(string $key, string $scheme = 'secondary'): string
    {
        return Smart::render('sf-button', [
            'text' => $this->text($key),
            'scheme' => $scheme,
            'type' => $scheme === 'danger' ? 'tonal' : 'default',
            'native-type' => 'submit',
        ])->html;
    }

    public function badge(string $text, string $scheme = 'neutral'): string
    {
        return Smart::render('sf-badge', [
            'text' => $text,
            'scheme' => $scheme,
            'type' => 'tonal',
            'size' => '1/2',
        ])->html;
    }

    public function alert(string $text, string $type = 'info'): string
    {
        return Smart::render('sf-alert', [
            'type' => $type,
            'supporting-text' => $text,
        ])->html;
    }

    public function fieldLabel(string $key): string
    {
        $translation = 'larena-content::admin.field_labels.' . $key;

        $label = (string) $this->translator->get($translation);

        return $label === $translation ? Str::headline($key) : $label;
    }

    private function text(string $key): string
    {
        return (string) $this->translator->get('larena-content::admin.' . $key);
    }
}
