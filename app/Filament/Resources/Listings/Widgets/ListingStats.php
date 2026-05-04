<?php

namespace App\Filament\Resources\Listings\Widgets;

use App\Models\Listing;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ListingStats extends StatsOverviewWidget
{
    /**
     * @var array<string, array{make: string, model?: string}>
     */
    private const WATCHED = [
        'Cupra Born' => ['make' => 'Cupra', 'model' => 'Born'],
        'Audi A1' => ['make' => 'Audi', 'model' => 'A1'],
        'Tesla' => ['make' => 'Tesla'],
    ];

    protected function getStats(): array
    {
        return collect(self::WATCHED)
            ->map(function (array $criteria, string $label) {
                $query = Listing::query()
                    ->whereNull('removed_at')
                    ->where('make', $criteria['make']);

                if (isset($criteria['model'])) {
                    $query->where('model', $criteria['model']);
                }

                $count = $query->count();

                return Stat::make($label, $count)
                    ->description($count === 0 ? 'Ingen tilgængelige' : 'tilgængelige')
                    ->color($count > 0 ? 'success' : 'gray');
            })
            ->values()
            ->all();
    }
}
