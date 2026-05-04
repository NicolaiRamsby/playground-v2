<?php

namespace App\Filament\Resources\Listings;

use App\Filament\Resources\Listings\Pages\ListListings;
use App\Filament\Resources\Listings\Tables\ListingsTable;
use App\Models\Listing;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ListingResource extends Resource
{
    protected static ?string $model = Listing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'BMC biler';

    protected static ?string $modelLabel = 'BMC bil';

    protected static ?string $pluralModelLabel = 'BMC biler';

    /**
     * Mærker der skjules fra listen som standard (case-insensitive). De synkes stadig til databasen.
     */
    public const HIDDEN_MAKES = [
        'suzuki',
        'fiat',
        'peugeot',
        'mini',
        'hyundai',
        'citroën',
        'citroen',
        'mazda',
        'kia',
        'škoda',
        'skoda',
    ];

    public static function table(Table $table): Table
    {
        return ListingsTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        $query = Listing::query()->whereNull('removed_at');

        if (! empty(self::HIDDEN_MAKES)) {
            $query->whereRaw(
                'LOWER(make) NOT IN (' . implode(',', array_fill(0, count(self::HIDDEN_MAKES), '?')) . ')',
                array_map('strtolower', self::HIDDEN_MAKES),
            );
        }

        return (string) $query->count();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListListings::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
