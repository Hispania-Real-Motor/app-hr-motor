<?php

namespace App\Filament\Resources\Bulletins;

use App\Filament\Forms\Components\BulletinRichEditor;
use App\Filament\Resources\Bulletins\Pages\CreateBulletinPost;
use App\Filament\Resources\Bulletins\Pages\EditBulletinPost;
use App\Filament\Resources\Bulletins\Pages\ListBulletinLogs;
use App\Filament\Resources\Bulletins\Pages\ListBulletinPosts;
use App\Models\BulletinActivityLog;
use App\Models\BulletinPost;
use App\Models\BulletinPostAttachment;
use App\Models\User;
use App\Notifications\AdminPriorityNotification;
use App\Services\BulletinActivityLogWriter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor\ToolbarButtonGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class BulletinPostResource extends Resource
{
    protected static ?string $model = BulletinPost::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationLabel = 'Tablón';

    protected static ?string $modelLabel = 'publicación';

    protected static ?string $pluralModelLabel = 'publicaciones';

    protected static ?string $slug = 'tablon';

    protected static string|\UnitEnum|null $navigationGroup = 'Contenido';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return app_user_has_admin_permission(auth()->user(), 'bulletin.manage');
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny();
    }

    public static function canDeleteAny(): bool
    {
        return static::canViewAny();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['creator:id,name,avatar_path', 'audienceRoles'])
            ->withCount('attachments');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->label('Título')
                ->required()
                ->maxLength(140),
            BulletinRichEditor::make('body')
                ->label('Contenido')
                ->required()
                ->maxLength(10000)
                ->toolbarButtons([
                    ['bold', 'italic', 'underline'],
                    [ToolbarButtonGroup::make('Listas', ['bulletList', 'orderedList'])->textualButtons()],
                    ['link', 'textColor'],
                    ['undo', 'redo'],
                ])
                ->textColors([
                    '#dc2626' => 'Rojo',
                    '#2563eb' => 'Azul',
                    '#15803d' => 'Verde',
                    '#475569' => 'Gris',
                ])
                ->placeholder('Escribe aquí el anuncio...')
                ->columnSpanFull(),
            Select::make('audience_type')
                ->label('Público destinatario')
                ->options([
                    BulletinPost::AUDIENCE_ALL => 'Toda la empresa',
                    BulletinPost::AUDIENCE_ROLES => 'Roles concretos',
                ])
                ->default(BulletinPost::AUDIENCE_ALL)
                ->required()
                ->rule(Rule::in([BulletinPost::AUDIENCE_ALL, BulletinPost::AUDIENCE_ROLES]))
                ->live()
                ->columnSpanFull(),
            CheckboxList::make('audience_roles')
                ->label('Roles destinatarios')
                ->options(User::roleLabels())
                ->columns(3)
                ->visible(fn (Get $get): bool => $get('audience_type') === BulletinPost::AUDIENCE_ROLES)
                ->required(fn (Get $get): bool => $get('audience_type') === BulletinPost::AUDIENCE_ROLES)
                ->rules(fn (Get $get): array => $get('audience_type') === BulletinPost::AUDIENCE_ROLES
                    ? ['array', 'min:1', 'distinct']
                    : ['nullable', 'array'])
                ->nestedRecursiveRules(
                    [Rule::in(array_keys(User::roleLabels()))],
                    fn (Get $get): bool => $get('audience_type') === BulletinPost::AUDIENCE_ROLES,
                )
                ->dehydrated()
                ->columnSpanFull(),
            FileUpload::make('images')
                ->label('Imágenes')
                ->multiple()
                ->image()
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                ->maxSize(4096)
                ->maxFiles(6)
                ->disk('public')
                ->directory('bulletin-posts')
                ->visibility('public')
                ->columnSpanFull()
                ->helperText('Puedes subir hasta 6 imágenes JPG, PNG, WEBP o GIF. Se mostrarán en la publicación.'),
            CheckboxList::make('keep_attachment_ids')
                ->label('Imágenes actuales')
                ->options(function (?BulletinPost $record): array {
                    if (! $record) {
                        return [];
                    }

                    return $record->attachments
                        ->values()
                        ->mapWithKeys(function (BulletinPostAttachment $attachment, int $index): array {
                            return [
                                $attachment->id => sprintf(
                                    'Imagen %d (%s)',
                                    $index + 1,
                                    basename($attachment->image_path),
                                ),
                            ];
                        })
                        ->all();
                })
                ->columns(2)
                ->columnSpanFull()
                ->helperText('Desmarca las imágenes que quieras eliminar al guardar.')
                ->visible(function (?BulletinPost $record): bool {
                    return $record?->exists && $record->attachments()->exists();
                }),
            Toggle::make('is_published')
                ->label('Publicar ahora')
                ->helperText('Si activas esta opción, la publicación aparecerá visible al guardar.')
                ->default(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Bold)
                    ->wrap()
                    ->grow(true)
                    ->width('24rem'),
                TextColumn::make('is_published')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (BulletinPost $record): string => $record->is_published ? 'Publicado' : 'Borrador')
                    ->color(fn (string $state): string => $state === 'Publicado' ? 'success' : 'gray')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('is_published', $direction);
                    })
                    ->grow(false)
                    ->width('11rem'),
                TextColumn::make('audience_type')
                    ->label('Público destinatario')
                    ->badge()
                    ->state(fn (BulletinPost $record): string => $record->audience_type === BulletinPost::AUDIENCE_ROLES
                        ? 'Roles concretos'
                        : 'Toda la empresa')
                    ->color(fn (BulletinPost $record): string => $record->audience_type === BulletinPost::AUDIENCE_ROLES
                        ? 'info'
                        : 'gray')
                    ->tooltip(function (BulletinPost $record): ?string {
                        if ($record->audience_type !== BulletinPost::AUDIENCE_ROLES) {
                            return null;
                        }

                        return $record->audienceRoles
                            ->map(fn ($audienceRole): string => User::roleLabels()[$audienceRole->role] ?? $audienceRole->role)
                            ->implode(', ');
                    })
                    ->wrap()
                    ->grow(false)
                    ->width('15rem'),
                TextColumn::make('published_at')
                    ->label('Fecha')
                    ->state(function (BulletinPost $record): string {
                        if ($record->published_at) {
                            return $record->published_at->format('d/m/Y H:i');
                        }

                        return $record->created_at?->format('d/m/Y H:i') ?? 'Sin fecha';
                    })
                    ->description(fn (BulletinPost $record): ?string => $record->attachments_count > 0
                        ? ($record->attachments_count === 1 ? '1 imagen' : $record->attachments_count . ' imágenes')
                        : 'Sin imágenes')
                    ->sortable()
                    ->grow(false)
                    ->width('14rem'),
                TextColumn::make('creator.name')
                    ->label('Creado por')
                    ->searchable()
                    ->placeholder('Sin autor')
                    ->grow(false)
                    ->width('16rem'),
            ])
            ->defaultSort('is_published', 'desc')
            ->striped()
            ->toolbarActions([
                Action::make('viewPublic')
                    ->label('Ver tablón público')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (): string => route('tablon.index'))
                    ->openUrlInNewTab(),
                Action::make('viewLogs')
                    ->label('Ver logs')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->color('gray')
                    ->url(static::getUrl('logs')),
                CreateAction::make()
                    ->label('Nueva publicación')
                    ->url(static::getUrl('create'))
                    ->modal(false),
            ])
            ->actions([
                Action::make('publish')
                    ->label('Publicar')
                    ->icon('heroicon-o-megaphone')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Publicar publicación')
                    ->modalDescription('La publicación pasará a estar visible para toda la plantilla.')
                    ->visible(fn (BulletinPost $record): bool => ! $record->is_published)
                    ->action(function (BulletinPost $record): void {
                        static::publish($record);
                    }),
                EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square'),
                DeleteAction::make()
                    ->label('Borrar')
                    ->icon('heroicon-o-trash')
                    ->modalHeading('Borrar publicación')
                    ->modalDescription('¿Seguro que quieres borrar esta publicación? Esta acción no se puede deshacer.')
                    ->using(function (BulletinPost $record): bool {
                        return static::deleteBulletin($record);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBulletinPosts::route('/'),
            'logs' => ListBulletinLogs::route('/logs'),
            'create' => CreateBulletinPost::route('/create'),
            'edit' => EditBulletinPost::route('/{record}/edit'),
        ];
    }

    public static function publish(BulletinPost $post): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        $wasPublished = $post->is_published;

        $post->fill([
            'is_published' => true,
            'published_at' => $post->published_at ?? now(),
            'updated_by_user_id' => $actor->id,
        ]);
        $post->save();

        if (! $wasPublished) {
            static::recordActivity(
                actor: $actor,
                action: BulletinActivityLog::ACTION_UPDATED,
                post: $post,
                changes: [
                    'is_published' => ['from' => 'false', 'to' => 'true'],
                    'published_at' => ['from' => null, 'to' => $post->published_at?->format('Y-m-d H:i:s')],
                ],
            );

            static::notifyPublishedPost($post, $actor);
        }
    }

    public static function deleteBulletin(BulletinPost $post): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return false;
        }

        static::recordActivity(
            actor: $actor,
            action: BulletinActivityLog::ACTION_DELETED,
            post: $post,
            changes: [
                'title' => ['from' => $post->title, 'to' => null],
                'body_excerpt' => ['from' => static::excerpt($post->body), 'to' => null],
                'is_published' => ['from' => $post->is_published ? 'true' : 'false', 'to' => null],
                'published_at' => ['from' => $post->published_at?->format('Y-m-d H:i:s'), 'to' => null],
                'images' => ['from' => (string) $post->attachments()->count(), 'to' => null],
            ],
        );

        return (bool) $post->delete();
    }

    public static function recordActivity(User $actor, string $action, BulletinPost $post, array $changes): void
    {
        app(BulletinActivityLogWriter::class)->record(
            actor: $actor,
            action: $action,
            bulletin: $post,
            changes: $changes,
        );
    }

    public static function notifyPublishedPost(BulletinPost $post, User $actor): void
    {
        $recipients = User::query()
            ->where('is_active', true)
            ->when($post->audience_type === BulletinPost::AUDIENCE_ROLES, function (Builder $query) use ($post): void {
                $roles = $post->audienceRoles()->pluck('role')->all();

                $query->where(function (Builder $recipientQuery) use ($roles): void {
                    $recipientQuery
                        ->whereIn('role', $roles)
                        ->orWhereIn('extra_role', $roles);
                });
            })
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send(
            $recipients,
            new AdminPriorityNotification(
                title: html_entity_decode('Nuevo anuncio en el tabl&oacute;n'),
                description: $post->title,
                linkUrl: route('tablon.index'),
                actor: $actor,
            )
        );
    }

    public static function buildChangeSet(BulletinPost $post, array $newValues): array
    {
        $labels = [
            'title' => 'Título',
            'body' => 'Contenido',
            'is_published' => 'Estado',
            'published_at' => 'Fecha de publicación',
            'audience_type' => 'Público destinatario',
            'audience_roles' => 'Roles destinatarios',
        ];

        return collect($newValues)
            ->filter(fn ($value, $field) => static::compareFieldValue($post, (string) $field, $value))
            ->mapWithKeys(fn ($value, $field) => [
                $labels[$field] ?? $field => [
                    'from' => static::displayFieldValue($post, (string) $field),
                    'to' => static::displayNewFieldValue((string) $field, $value),
                ],
            ])
            ->all();
    }

    public static function excerpt(string $body): string
    {
        return Str::limit(Str::squish($body), 120);
    }

    /**
     * @param array<int, string> $imagePaths
     */
    public static function storeAttachments(BulletinPost $post, array $imagePaths, int $startingSortOrder = 0): void
    {
        $sortOrder = $startingSortOrder;

        foreach ($imagePaths as $imagePath) {
            if (! is_string($imagePath) || trim($imagePath) === '') {
                continue;
            }

            $post->attachments()->create([
                'image_path' => $imagePath,
                'sort_order' => $sortOrder,
            ]);

            $sortOrder++;
        }
    }

    /**
     * @param array<int, string> $roles
     */
    public static function syncAudienceRoles(BulletinPost $post, array $roles): void
    {
        $roles = collect($roles)
            ->filter(fn ($role): bool => is_string($role) && array_key_exists($role, User::roleLabels()))
            ->unique()
            ->values();

        $post->audienceRoles()->delete();

        if ($post->audience_type !== BulletinPost::AUDIENCE_ROLES) {
            return;
        }

        $post->audienceRoles()->createMany($roles->map(fn (string $role): array => ['role' => $role])->all());
    }

    public static function validateAudienceData(array $data): void
    {
        Validator::make($data, [
            'audience_type' => ['required', Rule::in([BulletinPost::AUDIENCE_ALL, BulletinPost::AUDIENCE_ROLES])],
            'audience_roles' => ['nullable', Rule::requiredIf(($data['audience_type'] ?? null) === BulletinPost::AUDIENCE_ROLES), 'array'],
            'audience_roles.*' => ['string', 'distinct', Rule::in(array_keys(User::roleLabels()))],
        ])->validate();
    }

    protected static function compareFieldValue(BulletinPost $post, string $field, mixed $newValue): bool
    {
        return static::displayFieldValue($post, $field) !== static::displayNewFieldValue($field, $newValue);
    }

    protected static function displayFieldValue(BulletinPost $post, string $field): mixed
    {
        return match ($field) {
            'title' => $post->title,
            'body' => static::excerpt($post->body),
            'is_published' => $post->is_published ? 'true' : 'false',
            'published_at' => $post->published_at?->format('Y-m-d H:i:s'),
            'audience_type' => $post->audience_type,
            'audience_roles' => $post->audienceRoles()->pluck('role')->sort()->values()->all(),
            default => $post->{$field},
        };
    }

    protected static function displayNewFieldValue(string $field, mixed $value): mixed
    {
        return match ($field) {
            'body' => static::excerpt((string) $value),
            'is_published' => (bool) $value ? 'true' : 'false',
            'published_at' => $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d H:i:s') : null,
            'audience_roles' => collect($value)->sort()->values()->all(),
            default => $value,
        };
    }
}
