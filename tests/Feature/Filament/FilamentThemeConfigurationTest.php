<?php

namespace Tests\Feature\Filament;

use Tests\Support\FilamentThemeAssertions;
use Tests\TestCase;

class FilamentThemeConfigurationTest extends TestCase
{
    use FilamentThemeAssertions;

    public function test_backoffice_theme_is_registered_and_filament_views_are_scanned(): void
    {
        $this->assertFilamentThemeConfiguration();
        $this->assertFilamentViewUsesPanelTheme('filament/pages/admin-permissions.blade.php');
    }

    public function test_compiled_backoffice_theme_contains_required_tailwind_utilities(): void
    {
        $css = $this->buildAndReadFilamentThemeCss();

        foreach ([
            '.grid{display:grid}',
            '.flex{display:flex}',
            '.gap-3{gap:calc(var(--spacing) * 3)}',
            '.gap-x-4{column-gap:calc(var(--spacing) * 4)}',
            '.gap-y-1{row-gap:var(--spacing)}',
            '.mt-10{margin-top:calc(var(--spacing) * 10)}',
            '.px-4{padding-inline:calc(var(--spacing) * 4)}',
            '.grid-cols-\\[auto_minmax\\(0\\,1fr\\)\\]{grid-template-columns:auto minmax(0,1fr)}',
            '.row-span-2{grid-row:span 2/span 2}',
            '.col-start-2{grid-column-start:2}',
        ] as $utility) {
            $this->assertStringContainsString($utility, $css, "Falta la utilidad compilada: {$utility}");
        }
    }
}
