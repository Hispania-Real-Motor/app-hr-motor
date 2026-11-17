<?php

namespace App\Filament\Forms\Components;

use App\Filament\Forms\Components\Actions\BulletinLinkAction;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;

class BulletinRichEditor extends RichEditor
{
    /**
     * @return array<Action>
     */
    public function getDefaultActions(): array
    {
        return array_map(
            static fn (Action $action): Action => $action->getName() === 'link'
                ? BulletinLinkAction::make()
                : $action,
            parent::getDefaultActions(),
        );
    }
}
