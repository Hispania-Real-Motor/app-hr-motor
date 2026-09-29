<?php

namespace App\Filament\Pages;

use App\Http\Controllers\AdminChatRetentionHoldController;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class ChatRetentionHoldsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';
    protected static ?string $navigationLabel = 'Conservación excepcional';
    protected static ?string $title = 'Conservación excepcional';
    protected static ?string $slug = 'conservacion-excepcional';
    protected static string|\UnitEnum|null $navigationGroup = 'Administración';
    protected static ?int $navigationSort = 5;
    protected static ?string $breadcrumb = 'Conservación excepcional';
    protected string $view = 'filament.pages.chat-retention-holds';

    public EloquentCollection $activeHolds;
    public mixed $availableConversations;
    public EloquentCollection $activeUserHolds;
    public mixed $availableUsers;
    public bool $missingTable = false;

    public static function canAccess(): bool
    {
        return app_user_has_admin_permission(auth()->user(), 'chat-retention-holds.manage');
    }

    public function mount(): void
    {
        $data = app(AdminChatRetentionHoldController::class)->pageData(request());

        $this->activeHolds = new EloquentCollection($data['activeHolds']->items());
        $this->availableConversations = $data['availableConversations'];
        $this->activeUserHolds = new EloquentCollection($data['activeUserHolds']->items());
        $this->availableUsers = $data['availableUsers'];
        $this->missingTable = $data['missingTable'];
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewLogs')
                ->label('Ver logs')
                ->icon('heroicon-o-clipboard-document-list')
                ->color('gray')
                ->url(ChatRetentionHoldLogsPage::getUrl()),
        ];
    }
}
