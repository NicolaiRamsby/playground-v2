<?php

namespace App\Filament\Resources\Listings\Pages;

use App\Filament\Resources\Listings\ListingResource;
use App\Filament\Resources\Listings\Widgets\ListingStats;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Artisan;

class ListListings extends ListRecords
{
    protected static string $resource = ListingResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            ListingStats::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('scrape')
                ->label('Scrape nu')
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    Artisan::call('scrape:bmc-leasing');

                    Notification::make()
                        ->title('Scraping kørt')
                        ->body(Artisan::output())
                        ->success()
                        ->send();
                }),
        ];
    }
}
