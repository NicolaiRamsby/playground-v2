<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Services\ListingSlackNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

#[Signature('scrape:oscar')]
#[Description('Scrape Tesla bilabonnementer fra hejoscar.dk')]
class ScrapeOscar extends Command
{
    private const SOURCE = 'oscar';
    private const BASE_URL = 'https://hejoscar.dk';
    private const ALGOLIA_APP_ID = '5OFNRROMVU';
    private const ALGOLIA_API_KEY = 'f6115967c30776bca41da0ca86b84c6f';
    private const ALGOLIA_INDEX = 'prod_dk_cars_subscription';

    public function handle(): int
    {
        $this->info('Henter Tesla-abonnementer fra hejoscar.dk...');

        try {
            $hits = $this->fetchAllHits();
        } catch (\Throwable $e) {
            $this->error("Scrape afbrudt - fetch fejlede: {$e->getMessage()}");

            return self::FAILURE;
        }

        $allHits = collect($hits);
        $this->info("Fandt {$allHits->count()} Tesla-rækker i Algolia.");

        if ($allHits->isEmpty()) {
            $this->warn('Algolia returnerede nul - afbryder for at undgå mass-removal.');

            return self::FAILURE;
        }

        // Relevans: kun rækker der reelt kan lejes som abonnement med en pris.
        $cars = $allHits->filter(function (array $hit) {
            $rentalTypes = $hit['rental_types'] ?? [];
            if (empty($rentalTypes['car_subscription'])) {
                return false;
            }

            return (int) ($hit['subscription_base_price'] ?? 0) > 0;
        });
        $skipped = $allHits->count() - $cars->count();
        $this->info("Med abonnement-pris: {$cars->count()} (skippet {$skipped}).");

        $now = now();
        $seenIds = [];
        $created = 0;
        $reactivated = 0;
        $newlyVisible = collect();

        foreach ($cars as $hit) {
            $externalId = (int) ($hit['id'] ?? 0);
            if ($externalId === 0) {
                continue;
            }

            $seenIds[] = $externalId;

            $brand = $this->stringOrNull($hit['brand']['name'] ?? null);
            $model = $this->stringOrNull($hit['model']['name'] ?? null);
            $year = $hit['year'] ?? ($hit['props']['year'] ?? null);
            $year = $year !== null ? (string) $year : null;
            $title = trim(implode(' ', array_filter([$brand, $model, $year])));

            $isVisible = (int) ($hit['is_visible'] ?? 0) === 1
                && empty($hit['deleted_at']);

            $availableFrom = $this->parseAvailableFrom($hit['available_from'] ?? null);
            $isAvailableNow = $availableFrom === null || $availableFrom->lessThanOrEqualTo($now);

            $availability = match (true) {
                ! $isVisible => 'UNAVAILABLE',
                ! $isAvailableNow => 'UPCOMING',
                default => 'AVAILABLE',
            };

            $data = [
                'source' => self::SOURCE,
                'external_id' => $externalId,
                'bil_nr' => $this->stringOrNull($hit['props']['internal_number'] ?? null),
                'slug' => null,
                'url' => $this->buildDetailUrl($externalId, $hit['departments'][0]['id'] ?? null),
                'title' => $title !== '' ? $title : ('Oscar ' . $externalId),
                'make' => $brand,
                'model' => $model,
                'variant' => null,
                'year' => $year,
                'color' => $this->stringOrNull($hit['color'] ?? null),
                'fuel' => $this->stringOrNull($hit['fuel_type']['da'] ?? ($hit['fuel_type_original'] ?? null)),
                'body_type' => null,
                'state_of_vehicle' => 'used',
                'availability' => $availability,
                'available_from' => $availableFrom?->toDateString(),
                'leasing_type' => $this->stringOrNull($hit['transmission']['da'] ?? ($hit['transmission_original'] ?? null)),
                'monthly_price' => (int) $hit['subscription_base_price'],
                'first_payment' => null,
                'total_36mo' => null,
                'cash_price' => null,
                'mileage' => isset($hit['km']) ? (int) $hit['km'] : null,
                'mileage_per_year' => isset($hit['subscription_included_km'])
                    ? (string) ((int) $hit['subscription_included_km'] * 12)
                    : null,
                'hp' => null,
                'image_url' => $this->stringOrNull($hit['images'][0]['main'] ?? null),
                'raw' => $hit,
                'last_seen_at' => $now,
                'removed_at' => null,
            ];

            $existing = Listing::where('source', self::SOURCE)
                ->where('external_id', $externalId)
                ->first();

            if (! $existing) {
                $data['first_seen_at'] = $now;
                $createdListing = Listing::create($data);
                // Push alle nyoprettede (både AVAILABLE og UPCOMING) - notifikatoren
                // beslutter selv om den vil sende baseret på max_days_until_available.
                if ($isVisible) {
                    $newlyVisible->push($createdListing);
                }
                $created++;
            } else {
                $wasInvisible = $existing->removed_at !== null
                    || $existing->availability !== 'AVAILABLE';
                $existing->update($data);
                if ($wasInvisible && $availability === 'AVAILABLE') {
                    $reactivated++;
                    $newlyVisible->push($existing->fresh());
                }
            }
        }

        $removed = Listing::where('source', self::SOURCE)
            ->whereNull('removed_at')
            ->whereNotIn('external_id', $seenIds)
            ->update(['removed_at' => $now]);

        $this->info("Nye: {$created} | Reaktiveret: {$reactivated} | Fjernet: {$removed}");

        $notified = app(ListingSlackNotifier::class)->notifyNewListings($newlyVisible);
        if ($notified > 0) {
            $this->info("Slack: {$notified} notifikation(er) sendt.");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllHits(): array
    {
        $hits = [];
        $page = 0;
        $hitsPerPage = 100;

        do {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Algolia-Application-Id' => self::ALGOLIA_APP_ID,
                'X-Algolia-API-Key' => self::ALGOLIA_API_KEY,
                'User-Agent' => 'Mozilla/5.0 PlaygroundScraper',
            ])
                ->timeout(30)
                ->retry(3, 2000)
                ->post(
                    'https://' . strtolower(self::ALGOLIA_APP_ID) . '-dsn.algolia.net/1/indexes/' . self::ALGOLIA_INDEX . '/query',
                    [
                        'query' => '',
                        'filters' => 'brand.name:Tesla',
                        'hitsPerPage' => $hitsPerPage,
                        'page' => $page,
                    ],
                );

            if (! $response->successful()) {
                throw new \RuntimeException("Algolia request failed with status {$response->status()}");
            }

            $body = $response->json();
            if (! is_array($body)) {
                throw new \RuntimeException('Algolia returnerede ikke JSON.');
            }

            $pageHits = $body['hits'] ?? [];
            $hits = array_merge($hits, $pageHits);

            $nbPages = (int) ($body['nbPages'] ?? 1);
            $page++;
        } while ($page < $nbPages && $page < 20);

        return $hits;
    }

    private function buildDetailUrl(int $carId, mixed $departmentId): string
    {
        $url = self::BASE_URL . '/subscription/options?car_id=' . $carId;
        if (is_numeric($departmentId)) {
            $url .= '&department_id=' . (int) $departmentId;
        }

        return $url;
    }

    private function parseAvailableFrom(mixed $value): ?\Illuminate\Support\Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
