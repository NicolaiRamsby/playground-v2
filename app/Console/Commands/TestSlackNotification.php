<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Services\ListingSlackNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('slack:test {--make=Tesla}')]
#[Description('Send a test Slack notification using an existing listing of the given make')]
class TestSlackNotification extends Command
{
    public function handle(ListingSlackNotifier $notifier): int
    {
        if (! config('services.slack.webhook_url')) {
            $this->error('SLACK_WEBHOOK_URL er ikke sat i .env');

            return self::FAILURE;
        }

        $make = $this->option('make');
        $listing = Listing::query()->where('make', $make)->first();

        if (! $listing) {
            $this->error("Ingen bil i databasen med mærke {$make} til test.");

            return self::FAILURE;
        }

        $this->info("Sender test-notifikation for: {$listing->title}");
        $sent = $notifier->notifyNewListings(collect([$listing]));

        $this->info("Sendt: {$sent}");

        return self::SUCCESS;
    }
}
