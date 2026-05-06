<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Services\ListingSlackNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

#[Signature('scrape:ayvens')]
#[Description('Scrape used cars from brugtebiler.ayvens.dk')]
class ScrapeAyvens extends Command
{
    private const SOURCE = 'ayvens';
    private const BASE_URL = 'https://brugtebiler.ayvens.dk';
    private const FILTER_CACHE_URL = 'https://files.aldcarmarket.dk/filter_cache/ayvens-prod-filter-cache.json';

    public function handle(): int
    {
        $this->info('Fetching listings from brugtebiler.ayvens.dk...');

        try {
            $payload = $this->fetchFilterCache();
        } catch (\Throwable $e) {
            $this->error("Scrape aborted - fetch failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $allCars = collect($payload['cars'] ?? []);
        $this->info("Found {$allCars->count()} cars in feed.");

        if ($allCars->isEmpty()) {
            $this->warn('Feed returned zero cars - aborting to avoid mass-removal.');

            return self::FAILURE;
        }

        $brandById = $this->buildBrandIndex($payload['index']['brands'] ?? []);
        $fuelById = $this->buildValueIndex($payload['index']['fuel_type'] ?? []);
        $gearById = $this->buildValueIndex($payload['index']['gear'] ?? []);

        // Relevans for Ayvens: kun Tesla Model 3 årgang ≥ 2024 med leasing-pris.
        $cars = $allCars->filter(function ($car) use ($brandById) {
            if (empty($car['leasing_price'])) {
                return false;
            }
            $id = (int) ($car['id'] ?? 0);
            if (strcasecmp($brandById[$id] ?? '', 'tesla') !== 0) {
                return false;
            }
            $parsed = $this->parseCardHtml($car['html'] ?? '');
            $model = $this->deriveModel($parsed['meta_titel'], 'tesla');
            if (strcasecmp((string) $model, 'Model 3') !== 0) {
                return false;
            }

            return (int) ($parsed['year'] ?? 0) >= 2024;
        });
        $skipped = $allCars->count() - $cars->count();
        $this->info("Relevante (Tesla Model 3 ≥ 2024 m. leasing): {$cars->count()} (skippet {$skipped}).");

        $now = now();
        $seenIds = [];
        $created = 0;
        $reactivated = 0;
        $soldCount = 0;
        $newlyVisible = collect();

        foreach ($cars as $car) {
            $externalId = (int) ($car['id'] ?? 0);
            if ($externalId === 0) {
                continue;
            }

            $seenIds[] = $externalId;

            $parsed = $this->parseCardHtml($car['html'] ?? '');
            $brand = $brandById[$externalId] ?? $parsed['brand'];
            $model = $this->deriveModel($parsed['meta_titel'], $brand);
            $detailPath = $parsed['detail_path'];
            $url = $detailPath ? self::BASE_URL . $detailPath : self::BASE_URL . '/cars';
            $isSold = $this->htmlIndicatesSold($car['html'] ?? '');
            if ($isSold) {
                $soldCount++;
            }

            $data = [
                'source' => self::SOURCE,
                'external_id' => $externalId,
                'bil_nr' => null,
                'slug' => $detailPath ? trim($detailPath, '/') : null,
                'url' => $url,
                'title' => trim((string) ($car['name'] ?? '')),
                'make' => $brand ? ucwords($brand) : null,
                'model' => $model,
                'variant' => $parsed['variant'],
                'year' => $parsed['year'],
                'color' => null,
                'fuel' => $fuelById[$externalId] ?? null,
                'body_type' => null,
                'state_of_vehicle' => 'used',
                'availability' => $isSold ? 'SOLD' : 'AVAILABLE',
                'leasing_type' => isset($gearById[$externalId]) ? $gearById[$externalId] : null,
                'monthly_price' => isset($car['leasing_price']) ? (int) $car['leasing_price'] : null,
                'first_payment' => null,
                'total_36mo' => null,
                'cash_price' => isset($car['price']) ? (int) $car['price'] : null,
                'mileage' => $parsed['mileage'],
                'mileage_per_year' => null,
                'hp' => null,
                'image_url' => $car['image'] ?? null,
                'raw' => $car,
                'last_seen_at' => $now,
                'removed_at' => null,
            ];

            $existing = Listing::where('source', self::SOURCE)
                ->where('external_id', $externalId)
                ->first();

            if (! $existing) {
                $data['first_seen_at'] = $now;
                $created_listing = Listing::create($data);
                if (! $isSold) {
                    $newlyVisible->push($created_listing);
                }
                $created++;
            } else {
                $wasInvisible = $existing->removed_at !== null || $existing->availability === 'SOLD';
                $existing->update($data);
                if ($wasInvisible && ! $isSold) {
                    $reactivated++;
                    $newlyVisible->push($existing->fresh());
                }
            }
        }

        $removed = Listing::where('source', self::SOURCE)
            ->whereNull('removed_at')
            ->whereNotIn('external_id', $seenIds)
            ->update(['removed_at' => $now]);

        $this->info("New: {$created} | Reactivated: {$reactivated} | Solgt: {$soldCount} | Removed: {$removed}");

        $notified = app(ListingSlackNotifier::class)->notifyNewListings($newlyVisible);
        if ($notified > 0) {
            $this->info("Slack: {$notified} notifikation(er) sendt.");
        }

        return self::SUCCESS;
    }

    private function fetchFilterCache(): array
    {
        $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 PlaygroundScraper'])
            ->timeout(30)
            ->retry(3, 2000)
            ->get(self::FILTER_CACHE_URL);

        if (! $response->successful()) {
            throw new \RuntimeException("Filter cache request failed with status {$response->status()}");
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new \RuntimeException('Filter cache did not return JSON object.');
        }

        return $json;
    }

    /**
     * @param  array<string, array<int, int>>  $brandIndex
     * @return array<int, string>
     */
    private function buildBrandIndex(array $brandIndex): array
    {
        $map = [];
        foreach ($brandIndex as $brand => $ids) {
            foreach ((array) $ids as $id) {
                $map[(int) $id] = (string) $brand;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, array<int, int>>  $valueIndex
     * @return array<int, string>
     */
    private function buildValueIndex(array $valueIndex): array
    {
        $map = [];
        foreach ($valueIndex as $value => $ids) {
            foreach ((array) $ids as $id) {
                $map[(int) $id] = (string) $value;
            }
        }

        return $map;
    }

    /**
     * @return array{brand: ?string, meta_titel: ?string, variant: ?string, year: ?string, mileage: ?int, detail_path: ?string}
     */
    private function parseCardHtml(string $html): array
    {
        $result = [
            'brand' => null,
            'meta_titel' => null,
            'variant' => null,
            'year' => null,
            'mileage' => null,
            'detail_path' => null,
        ];

        if ($html === '') {
            return $result;
        }

        if (preg_match('#<p[^>]*class="[^"]*meta-titel[^"]*"[^>]*>(.*?)</p>#is', $html, $m)) {
            $titel = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES));
            $result['meta_titel'] = $titel;
            $parts = preg_split('/\s+/', $titel, 2);
            $result['brand'] = $parts[0] ?? null;
        }

        if (preg_match('#<p[^>]*class="[^"]*meta-type[^"]*"[^>]*>(.*?)</p>#is', $html, $m)) {
            $rawType = $m[1];
            $segments = preg_split('#<br\s*/?>#i', $rawType);
            $variant = isset($segments[0]) ? trim(html_entity_decode(strip_tags($segments[0]), ENT_QUOTES)) : null;
            $result['variant'] = $variant !== '' ? $variant : null;

            if (isset($segments[1])) {
                $details = trim(html_entity_decode(strip_tags($segments[1]), ENT_QUOTES));
                if (preg_match('/(\d{4})\s*,\s*([\d.]+)\s*km/i', $details, $dm)) {
                    $result['year'] = $dm[1];
                    $result['mileage'] = (int) str_replace('.', '', $dm[2]);
                } elseif (preg_match('/(\d{4})/', $details, $ym)) {
                    $result['year'] = $ym[1];
                }
            }
        }

        if (preg_match('#data-carlink="([^"]+)"#i', $html, $m)) {
            $result['detail_path'] = $m[1];
        }

        return $result;
    }

    private function htmlIndicatesSold(string $html): bool
    {
        // Solgte biler har "card-occupied" id og en <span>Solgt</span> i deres feed-card.
        return str_contains($html, 'id="card-occupied"')
            || str_contains($html, 'card-car--cta-price occupied');
    }

    private function deriveModel(?string $metaTitel, ?string $brand): ?string
    {
        if (! $metaTitel) {
            return null;
        }

        if ($brand && stripos($metaTitel, $brand) === 0) {
            $model = trim(substr($metaTitel, strlen($brand)));

            return $model !== '' ? $model : null;
        }

        $parts = preg_split('/\s+/', $metaTitel, 2);

        return $parts[1] ?? null;
    }
}
