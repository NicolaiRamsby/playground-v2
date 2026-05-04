<?php

namespace App\Services;

use App\Models\Listing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ListingSlackNotifier
{
    /**
     * Kriterier der udløser en Slack-notifikation når en NY listing dukker op.
     *
     * @var array<int, array{make: string, model?: string, label: string}>
     */
    private const NOTIFY_ON_NEW = [
        ['make' => 'Cupra', 'model' => 'Born', 'label' => 'Cupra Born'],
        ['make' => 'Tesla', 'label' => 'Tesla'],
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
            if (strcasecmp((string) $listing->make, $criteria['make']) !== 0) {
                continue;
            }

            if (isset($criteria['model']) && strcasecmp((string) $listing->model, $criteria['model']) !== 0) {
                continue;
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

        $blocks = [
            [
                'type' => 'header',
                'text' => ['type' => 'plain_text', 'text' => "🚗 Ny {$label} tilgængelig"],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*<{$listing->url}|{$listing->title}>*\n{$price}"
                        . ($listing->year ? ' · ' . $listing->year : '')
                        . ($listing->color ? ' · ' . $listing->color : ''),
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
