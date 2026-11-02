<?php

namespace App\Console\Commands;

use App\Erp\Client;
use App\Erp\ErpException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('storefront:doctor')]
#[Description("Check the database, the connection to the ERP and the website's status; exit code 1 when something needs attention")]
class DoctorStorefront extends Command
{
    private bool $failed = false;

    public function handle(): int
    {
        $this->line('Storefront doctor');
        $this->newLine();

        $this->report(extension_loaded('pdo_mysql'), 'PHP pdo_mysql');
        $this->report(extension_loaded('curl'), 'PHP curl');
        $this->report(extension_loaded('mbstring'), 'PHP mbstring');

        try {
            DB::select('SELECT 1');
            $this->report(true, 'Database connection');

            $missing = array_filter(['carts', 'checkouts', 'orders', 'sessions'], fn (string $table): bool => ! \Illuminate\Support\Facades\Schema::hasTable($table));
            $this->report($missing === [], 'Tables migrated', $missing === [] ? '' : 'run: php artisan migrate');
        } catch (Throwable $throwable) {
            $this->report(false, 'Database connection', $throwable->getMessage());
        }

        $url = (string) config('app.url');
        $this->report(str_starts_with($url, 'https://') || app()->environment('local'), 'APP_URL is https', $url);

        // Optional: without it the catalogue refreshes as its cache expires instead of
        // the moment the ERP says something changed.
        $webhook = (string) config('storefront.webhook_secret');
        $this->report($webhook === '' || strlen($webhook) >= 16, 'Webhook secret', $webhook === '' ? 'not set (optional — the cache refreshes the catalogue)' : 'set');

        try {
            $erp = app(Client::class);
            $site = $erp->get('/site')['data'] ?? [];
            $this->report(true, 'ERP accepts the credentials');
            $this->report(($site['status'] ?? '') === 'open', 'Website is open', (string) ($site['status'] ?? 'unknown'));
            $this->report(($site['accepting_orders'] ?? false) === true, 'Website is accepting orders');
            $methods = (array) ($site['checkout']['payment_methods'] ?? []);
            $this->report($methods !== [], 'Payment methods available', implode(', ', $methods));

            $connection = $erp->get('/connection')['data'] ?? [];

            if ($connection !== []) {
                $this->line('       scopes: '.implode(', ', (array) ($connection['scopes'] ?? [])));
            }
        } catch (ErpException $exception) {
            $this->report(false, 'ERP', $exception->errorCode.' — '.$exception->getMessage());
        } catch (Throwable $throwable) {
            $this->report(false, 'ERP', $throwable->getMessage());
        }

        $this->newLine();
        $this->line($this->failed ? 'Something needs attention.' : 'All good.');

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function report(bool $ok, string $label, string $detail = ''): void
    {
        $this->failed = $this->failed || ! $ok;
        $this->line(($ok ? '  ok   ' : '  FAIL ').$label.($detail !== '' ? ' — '.$detail : ''));
    }
}
