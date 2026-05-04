<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Services\ListingSlackNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

#[Signature('scrape:bmc-leasing')]
#[Description('Scrape leasing cars from bmcleasing.dk')]
class ScrapeBmcLeasing extends Command
{
    private const SOURCE = 'bmcleasing';
    private const API_URL = 'https://www.bmcleasing.dk/wp-json/wp/v2/privatleasing';
    private const LISTING_URL = 'https://www.bmcleasing.dk/privatleasing/';

    public function handle(): int
    {
        $this->info('Fetching listings from bmcleasing.dk...');

        try {
            $cars = $this->fetchAllCars();
        } catch (\Throwable $e) {
            $this->error("Scrape aborted - API failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Found {$cars->count()} cars in API.");

        // Safety net: if we got zero cars, the site is likely broken or rate-limiting us.
        // Don't mark everything as removed - just bail.
        if ($cars->isEmpty()) {
            $this->warn('API returned zero cars - aborting to avoid mass-removal.');

            return self::FAILURE;
        }

        $imageMap = $this->fetchImageMap();
        $this->info("Built image map with {$imageMap->count()} entries.");

        $now = now();
        $seenIds = [];
        $created = 0;
        $reactivated = 0;
        $newlyVisible = collect();

        foreach ($cars as $car) {
            $externalId = $car['id'];
            $seenIds[] = $externalId;
            $acf = $car['acf'] ?? [];
            $bilNr = $acf['bil_nr'] ?? null;

            $existing = Listing::where('source', self::SOURCE)
                ->where('external_id', $externalId)
                ->first();

            $data = [
                'source' => self::SOURCE,
                'external_id' => $externalId,
                'bil_nr' => $bilNr,
                'slug' => $car['slug'] ?? null,
                'url' => $car['link'] ?? '',
                'title' => html_entity_decode($car['title']['rendered'] ?? '', ENT_QUOTES),
                'make' => $acf['maerke'] ?? null,
                'model' => $acf['model'] ?? null,
                'variant' => $acf['Variant'] ?? null,
                'year' => $acf['argang'] ?? null,
                'color' => $acf['farve'] ?? null,
                'fuel' => $acf['braendstof'] ?? null,
                'body_type' => $acf['body_type'] ?? null,
                'state_of_vehicle' => $acf['state_of_vehicle'] ?? null,
                'availability' => $acf['availability'] ?? null,
                'leasing_type' => $acf['leasingtype'] ?? null,
                'monthly_price' => $this->parseInt($acf['pris_pr_md'] ?? null),
                'first_payment' => $this->parseInt($acf['forstegangsydelse'] ?? null),
                'total_36mo' => $this->parseInt($acf['ialt_over_36_mdr'] ?? null),
                'mileage' => $this->parseInt($acf['km'] ?? null),
                'mileage_per_year' => $acf['km_pr_ar'] ?? null,
                'hp' => $this->parseInt($acf['hk'] ?? null),
                'image_url' => $bilNr
                    ? ($imageMap->get($bilNr) ?? $this->fetchDetailImage($car['link'] ?? '', $bilNr))
                    : null,
                'raw' => $car,
                'last_seen_at' => $now,
                'removed_at' => null,
            ];

            if (! $existing) {
                $data['first_seen_at'] = $now;
                $newlyVisible->push(Listing::create($data));
                $created++;
            } else {
                if ($existing->removed_at !== null) {
                    $reactivated++;
                    $existing->update($data);
                    $newlyVisible->push($existing->fresh());
                } else {
                    $existing->update($data);
                }
            }
        }

        $removed = Listing::where('source', self::SOURCE)
            ->whereNull('removed_at')
            ->whereNotIn('external_id', $seenIds)
            ->update(['removed_at' => $now]);

        $this->info("New: {$created} | Reactivated: {$reactivated} | Removed: {$removed}");

        $notified = app(ListingSlackNotifier::class)->notifyNewListings($newlyVisible);
        if ($notified > 0) {
            $this->info("Slack: {$notified} notifikation(er) sendt.");
        }

        return self::SUCCESS;
    }

    private function fetchAllCars(): \Illuminate\Support\Collection
    {
        $cars = collect();
        $page = 1;

        do {
            $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 PlaygroundScraper'])
                ->timeout(30)
                ->retry(3, 2000)
                ->get(self::API_URL, ['per_page' => 100, 'page' => $page]);

            if (! $response->successful()) {
                throw new \RuntimeException("API request failed with status {$response->status()} on page {$page}");
            }

            $cars = $cars->merge($response->json());
            $totalPages = (int) ($response->header('x-wp-totalpages') ?: 1);
            $page++;
        } while ($page <= $totalPages);

        return $cars;
    }

    private function fetchImageMap(): \Illuminate\Support\Collection
    {
        $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 PlaygroundScraper'])
            ->get(self::LISTING_URL);

        if (! $response->successful()) {
            return collect();
        }

        $html = $response->body();
        $map = collect();

        // The bil_nr (6 digits) appears in the image filename, e.g.:
        //   .../uploads/2026/05/001-u26-05-908748-Kia-Rio-...-500x300.jpg
        //   .../uploads/2025/10/911127u112510-500x300.jpg
        // Strategy: extract every image URL under /uploads/, then find the 6-digit bil_nr inside.
        preg_match_all(
            '#https://www\.bmcleasing\.dk/wp-content/uploads/\d+/\d+/[^"\'\s]+\.(?:jpe?g|png|webp)#i',
            $html,
            $urlMatches
        );

        foreach ($urlMatches[0] as $url) {
            $filename = basename($url);

            // Skip site assets (logos, icons, etc.) - car images contain a 6-digit bil_nr.
            if (! preg_match('/(?<![\d])(\d{6})(?![\d])/', $filename, $m)) {
                continue;
            }

            $bilNr = $m[1];

            // Prefer the original (no -WxH suffix) over thumbnails.
            $isThumb = (bool) preg_match('/-\d+x\d+\./', $url);
            if (! $map->has($bilNr) || ! $isThumb) {
                $map->put($bilNr, $url);
            }
        }

        return $map;
    }

    private function fetchDetailImage(string $url, string $bilNr): ?string
    {
        if ($url === '') {
            return null;
        }

        try {
            $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 PlaygroundScraper'])
                ->timeout(15)
                ->get($url);
        } catch (\Throwable $e) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();

        // Primary: <img itemprop="image"> tags - that's the product image markup.
        if (preg_match('#<img[^>]*src="(/wp-content/uploads/[^"]+\.(?:jpe?g|png|webp))"[^>]*itemprop="image"#i', $body, $m)) {
            return 'https://www.bmcleasing.dk' . $m[1];
        }

        // Fallback: any uploaded image whose filename contains the bil_nr.
        preg_match_all(
            '#https?://www\.bmcleasing\.dk/wp-content/uploads/\d+/\d+/[^"\'\s]+\.(?:jpe?g|png|webp)#i',
            $body,
            $matches
        );

        foreach ($matches[0] as $match) {
            if (str_contains(basename($match), $bilNr)) {
                return $match;
            }
        }

        return null;
    }

    private function parseInt(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $cleaned = preg_replace('/[^\d]/', '', $value);

        return $cleaned === '' ? null : (int) $cleaned;
    }
}
