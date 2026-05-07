<?php

namespace App\Filament\Resources\Listings\Widgets;

use App\Models\Listing;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ListingStats extends StatsOverviewWidget
{
    /**
     * @var array<string, array{make: string, model?: string, model_prefix?: string}>
     */
    private const WATCHED = [
        'Cupra Born' => ['make' => 'Cupra', 'model' => 'Born'],
        'Renault 5' => ['make' => 'Renault', 'model_prefix' => '5 '],
        'Tesla' => ['make' => 'Tesla'],
    ];

    protected function getStats(): array
    {
        return collect(self::WATCHED)
            ->map(function (array $criteria, string $label) {
                $query = Listing::query()
                    ->whereNull('removed_at')
                    ->where(function ($q) {
                        $q->whereNull('availability')
                            ->orWhere('availability', 'AVAILABLE');
                    })
                    ->where('make', $criteria['make']);

                if (isset($criteria['model'])) {
                    $query->where('model', $criteria['model']);
                }

                if (isset($criteria['model_prefix'])) {
                    $query->where('model', 'like', $criteria['model_prefix'].'%');
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
