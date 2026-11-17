<?php

namespace App\Filament\Forms\Components\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\EditorCommand;
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\Width;

class BulletinLinkAction
{
    public static function make(): Action
    {
        return Action::make('link')
            ->label(__('filament-forms::components.rich_editor.actions.link.label'))
            ->modalHeading(__('filament-forms::components.rich_editor.actions.link.modal.heading'))
            ->modalWidth(Width::Large)
            ->fillForm(fn (array $arguments): array => [
                'url' => $arguments['url'] ?? null,
            ])
            ->schema([
                TextInput::make('url')
                    ->label(__('filament-forms::components.rich_editor.actions.link.modal.form.url.label'))
                    ->inputMode('url'),
            ])
            ->action(function (array $arguments, array $data, RichEditor $component): void {
                $isSingleCharacterSelection = ($arguments['editorSelection']['head'] ?? null) === ($arguments['editorSelection']['anchor'] ?? null);

                if (blank($data['url'] ?? null)) {
                    $component->runCommands(
                        [
                            ...($isSingleCharacterSelection ? [EditorCommand::make(
                                'extendMarkRange',
                                arguments: ['link'],
                            )] : []),
                            EditorCommand::make('unsetLink'),
                        ],
                        editorSelection: $arguments['editorSelection'],
                    );

                    return;
                }

                $component->runCommands(
                    [
                        ...($isSingleCharacterSelection ? [EditorCommand::make(
                            'extendMarkRange',
                            arguments: ['link'],
                        )] : []),
                        EditorCommand::make(
                            'setLink',
                            arguments: [[
                                'href' => $data['url'],
                                'target' => '_blank',
                            ]],
                        ),
                    ],
                    editorSelection: $arguments['editorSelection'],
                );
            });
    }
}
