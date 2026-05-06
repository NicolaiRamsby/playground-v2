<?php

namespace App\Services;

use App\Models\Listing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ListingSlackNotifier
{
    /**
     * Kriterier der udløser en Slack-notifikation når en NY listing dukker op.
     * source = null betyder alle kilder.
     *
     * @var array<int, array{source?: string, make: string, model?: string, min_year?: int, label: string}>
     */
    private const NOTIFY_ON_NEW = [
        ['source' => 'bmcleasing', 'make' => 'Cupra', 'model' => 'Born', 'label' => 'Cupra Born'],
        ['source' => 'bmcleasing', 'make' => 'Tesla', 'label' => 'Tesla'],
        ['source' => 'ayvens', 'make' => 'Tesla', 'model' => 'Model 3', 'min_year' => 2024, 'label' => 'Tesla Model 3 (ny)'],
        ['source' => 'oscar', 'make' => 'Tesla', 'min_year' => 2024, 'label' => 'Tesla (Oscar abonnement)', 'max_days_until_available' => 45],
    ];

    public function notifyNewListings(iterable $listings): int
    {
        $webhook = config('services.slack.webhook_url');
        if (! $webhook) {
            return 0;
        }

        $sent = 0;
        foreach ($listings as $listing) {
            $match = $this->matchCriteria($listing);
            if (! $match) {
                continue;
            }

            if ($this->postToSlack($webhook, $listing, $match['label'])) {
                $sent++;
            }
        }

        return $sent;
    }

    private function matchCriteria(Listing $listing): ?array
    {
        foreach (self::NOTIFY_ON_NEW as $criteria) {
            if (isset($criteria['source']) && $criteria['source'] !== $listing->source) {
                continue;
            }

            if (strcasecmp((string) $listing->make, $criteria['make']) !== 0) {
                continue;
            }

            if (isset($criteria['model']) && strcasecmp((string) $listing->model, $criteria['model']) !== 0) {
                continue;
            }

            if (isset($criteria['min_year']) && (int) $listing->year < $criteria['min_year']) {
                continue;
            }

            if (isset($criteria['max_days_until_available'])) {
                if (! $listing->available_from) {
                    continue;
                }
                $daysAway = now()->startOfDay()->diffInDays($listing->available_from->startOfDay(), false);
                if ($daysAway > (int) $criteria['max_days_until_available']) {
                    continue;
                }
            }

            return $criteria;
        }

        return null;
    }

    private function postToSlack(string $webhook, Listing $listing, string $label): bool
    {
        $price = $listing->monthly_price
            ? number_format($listing->monthly_price, 0, ',', '.') . ' kr/md'
            : 'Pris ukendt';

        $isUpcoming = $listing->availability === 'UPCOMING' && $listing->available_from;
        $headerText = $isUpcoming
            ? "🗓️ Ny {$label} ledig {$listing->available_from->translatedFormat('j. M Y')}"
            : "🚗 Ny {$label} tilgængelig";

        $detailsLine = $price
            . ($listing->year ? ' · ' . $listing->year : '')
            . ($listing->color ? ' · ' . $listing->color : '')
            . ($isUpcoming
                ? ' · ledig ' . $listing->available_from->translatedFormat('j. M Y')
                : '');

        $blocks = [
            [
                'type' => 'header',
                'text' => ['type' => 'plain_text', 'text' => $headerText],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*<{$listing->url}|{$listing->title}>*\n{$detailsLine}",
                ],
            ],
        ];

        if ($listing->image_url) {
            $blocks[] = [
                'type' => 'image',
                'image_url' => $listing->image_url,
                'alt_text' => $listing->title,
            ];
        }

        try {
            $response = Http::timeout(10)
                ->retry(2, 1000)
                ->post($webhook, [
                    'text' => "Ny {$label}: {$listing->title}",
                    'blocks' => $blocks,
                ]);

            if (! $response->successful()) {
                Log::warning('Slack webhook failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Slack webhook exception', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
