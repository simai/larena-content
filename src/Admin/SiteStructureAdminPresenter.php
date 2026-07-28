<?php

declare(strict_types=1);

namespace Larena\Content\Admin;

use Illuminate\Contracts\Translation\Translator;
use Larena\Ui\Smart;

final readonly class SiteStructureAdminPresenter
{
    public function __construct(private Translator $translator)
    {
    }

    public function button(string $key, string $scheme = 'secondary', string $nativeType = 'submit'): string
    {
        return Smart::render('sf-button', [
            'text' => $this->text($key),
            'scheme' => $scheme,
            'type' => $scheme === 'danger' ? 'tonal' : 'default',
            'native-type' => $nativeType,
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

    private function text(string $key): string
    {
        return (string) $this->translator->get('larena-content::admin.' . $key);
    }
}
