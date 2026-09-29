<?php

namespace Tests\Support;

use Symfony\Component\Process\Process;

trait FilamentThemeAssertions
{
    protected function assertFilamentThemeConfiguration(): void
    {
        $themePath = base_path('resources/css/filament/backoffice/theme.css');
        $providerPath = app_path('Providers/Filament/AdminPanelProvider.php');
        $vitePath = base_path('vite.config.js');

        $this->assertFileExists($themePath);
        $this->assertStringContainsString("@import '../../../../vendor/filament/filament/resources/css/theme.css';", file_get_contents($themePath));
        $this->assertStringContainsString("@source '../../../../app/Filament/**/*';", file_get_contents($themePath));
        $this->assertStringContainsString("@source '../../../../resources/views/filament/**/*';", file_get_contents($themePath));
        $this->assertStringContainsString("->viteTheme('resources/css/filament/backoffice/theme.css')", file_get_contents($providerPath));
        $this->assertStringContainsString("'resources/css/filament/backoffice/theme.css'", file_get_contents($vitePath));
    }

    protected function assertFilamentViewUsesPanelTheme(string $view): void
    {
        $relativePath = str_replace('\\', '/', $view);

        $this->assertStringStartsWith('filament/', $relativePath);
        $this->assertFileExists(resource_path('views/' . $relativePath));
        $this->assertFilamentThemeConfiguration();
    }

    protected function buildAndReadFilamentThemeCss(): string
    {
        $process = new Process(
            PHP_OS_FAMILY === 'Windows' ? ['npm.cmd', 'run', 'build'] : ['npm', 'run', 'build'],
            base_path(),
        );
        $process->setTimeout(120);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            "No se pudo compilar el tema de Filament:\n" . $process->getErrorOutput() . $process->getOutput(),
        );

        $manifestPath = public_path('build/manifest.json');
        $this->assertFileExists($manifestPath);

        $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $themeEntry = $manifest['resources/css/filament/backoffice/theme.css']['file'] ?? null;

        $this->assertNotEmpty($themeEntry, 'El tema de Filament no está registrado en el manifest de Vite.');

        $compiledThemePath = public_path('build/' . $themeEntry);
        $this->assertFileExists($compiledThemePath);

        return file_get_contents($compiledThemePath);
    }
}
