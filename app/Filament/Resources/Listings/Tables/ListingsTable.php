<?php

namespace App\Filament\Resources\Listings\Tables;

use App\Filament\Resources\Listings\ListingResource;
use Filament\Actions\Action;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('first_seen_at', 'desc')
            ->modifyQueryUsing(function (Builder $query) {
                if (! empty(ListingResource::HIDDEN_MAKES)) {
                    $query->whereRaw(
                        'LOWER(make) NOT IN (' . implode(',', array_fill(0, count(ListingResource::HIDDEN_MAKES), '?')) . ')',
                        array_map('strtolower', ListingResource::HIDDEN_MAKES),
                    );
                }
            })
            ->columns([
                ImageColumn::make('image_url')
                    ->label('')
                    ->size(80)
                    ->extraImgAttributes(['style' => 'object-fit: cover; border-radius: 6px;']),

                TextColumn::make('title')
                    ->label('Titel')
                    ->searchable(['title', 'make', 'model', 'variant'])
                    ->wrap()
                    ->description(fn ($record) => trim(($record->year ? $record->year . ' · ' : '') . ($record->color ?? '')) ?: null),

                TextColumn::make('source')
                    ->label('Kilde')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'bmcleasing' => 'warning',
                        'ayvens' => 'info',
                        'oscar' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'bmcleasing' => 'BMC',
                        'ayvens' => 'Ayvens',
                        'oscar' => 'Oscar',
                        default => $state,
                    }),

                TextColumn::make('availability')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'AVAILABLE' => 'success',
                        'UPCOMING' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state, $record) => match ($state) {
                        'AVAILABLE' => 'Tilgængelig',
                        'UPCOMING' => $record->available_from
                            ? 'Ledig ' . $record->available_from->translatedFormat('j. M Y')
                            : 'Kommende',
                        'OUT_OF_STOCK' => 'Udsolgt',
                        default => $state,
                    })
                    ->toggleable(),

                TextColumn::make('available_from')
                    ->label('Ledig fra')
                    ->formatStateUsing(fn ($state) => $state
                        ? \Illuminate\Support\Carbon::parse($state)->translatedFormat('j. M Y')
                        : null)
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('monthly_price')
                    ->label('Pris/md')
                    ->formatStateUsing(fn ($state) => $state
                        ? number_format((int) $state, 0, ',', '.') . ' kr'
                        : null)
                    ->placeholder('—')
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('first_seen_at')
                    ->label('Først set')
                    ->formatStateUsing(fn ($state) => self::formatDay($state))
                    ->sortable(),

                TextColumn::make('removed_at')
                    ->label('Fjernet')
                    ->formatStateUsing(fn ($state) => self::formatDay($state))
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('removed_at')
                    ->label('Status')
                    ->placeholder('Aktive')
                    ->trueLabel('Kun fjernede/solgte')
                    ->falseLabel('Inkluder alle')
                    ->queries(
                        true: fn (Builder $q) => $q->where(fn ($q) => $q->whereNotNull('removed_at')->orWhere('availability', 'SOLD')),
                        false: fn (Builder $q) => $q,
                        blank: fn (Builder $q) => $q->whereNull('removed_at')->where(fn ($q) => $q->whereNull('availability')->orWhere('availability', '!=', 'SOLD')),
                    ),

                SelectFilter::make('source')
                    ->label('Kilde')
                    ->options([
                        'bmcleasing' => 'BMC',
                        'ayvens' => 'Ayvens',
                        'oscar' => 'Oscar',
                    ]),

                SelectFilter::make('make')
                    ->label('Mærke')
                    ->options(fn () => \App\Models\Listing::query()
                        ->whereNotNull('make')
                        ->distinct()
                        ->orderBy('make')
                        ->pluck('make', 'make')
                        ->all())
                    ->searchable(),

                SelectFilter::make('fuel')
                    ->label('Brændstof')
                    ->options(fn () => \App\Models\Listing::query()
                        ->whereNotNull('fuel')
                        ->distinct()
                        ->orderBy('fuel')
                        ->pluck('fuel', 'fuel')
                        ->all()),

                Filter::make('new_recently')
                    ->label('Nye sidste 24t')
                    ->query(fn (Builder $q) => $q->where('first_seen_at', '>=', now()->subDay())),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Åbn')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn ($record) => $record->url, shouldOpenInNewTab: true),
            ])
            ->toolbarActions([]);
    }

    private static function formatDay($state): ?string
    {
        if (! $state) {
            return null;
        }

        $date = \Illuminate\Support\Carbon::parse($state);

        if ($date->isToday()) {
            return 'I dag';
        }

        if ($date->isYesterday()) {
            return 'I går';
        }

        return $date->translatedFormat('j. M');
    }
}
