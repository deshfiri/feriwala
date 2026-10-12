<?php

namespace App\Http\Controllers\Api\Internal;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Website\Models\WebsiteDomain;
use App\Domain\Website\Queries\ResolveWebsiteHost;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Caddy's question before it issues a certificate: may I? (D30 §4)
 *
 * Caddy's on-demand TLS calls `GET ?domain=<name>` on the address it is given;
 * that address carries the shared token, because Caddy cannot send a header.
 * Any answer but 200 means no.
 *
 *   - No token configured: nothing answers, so the endpoint is off by default.
 *   - Wrong token, or an address outside the allowed list: 404, the same as a
 *     route that does not exist.
 *   - Otherwise yes only for a name {@see ResolveWebsiteHost} gives to a
 *     website — its subdomain, or a verified and active custom domain of a
 *     website that is not closed. Every other name is refused, so pointing a
 *     domain at the server never earns a certificate for it.
 *
 * The first authorisation of each custom domain is audited.
 */
class AuthorizeCertificateController extends Controller
{
    public function __construct(
        protected ResolveWebsiteHost $hosts,
        protected RecordAuditLog $audit,
    ) {}

    public function __invoke(Request $request): Response
    {
        $expected = (string) config('website.tls.ask_token');
        $given = $request->query('token');

        if ($expected === '' || ! is_string($given) || ! hash_equals($expected, $given)) {
            abort(404);
        }

        /** @var list<string> $allowed */
        $allowed = config('website.tls.ask_allowed_ips', []);

        if ($allowed !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            abort(404);
        }

        $name = $request->query('domain');
        $resolved = is_string($name) ? $this->hosts->resolve($name) : null;

        if ($resolved === null) {
            return response('', 403);
        }

        if ($resolved->domain !== null) {
            $this->remember($resolved->domain);
        }

        return response('', 200);
    }

    /**
     * Note when a custom domain was last authorised — at most once a day, so a
     * renewal does not write on every ask — and audit the first time.
     */
    protected function remember(WebsiteDomain $domain): void
    {
        $now = CarbonImmutable::now();
        $first = $domain->tls_authorized_at === null;

        if (! $first && $domain->tls_authorized_at->greaterThan($now->subDay())) {
            return;
        }

        $domain->forceFill(['tls_authorized_at' => $now])->save();

        if ($first) {
            $this->audit->handle(new AuditEntry(
                action: 'website.domain_certificate_authorized',
                actorType: 'system',
                auditableType: WebsiteDomain::class,
                auditableId: $domain->id,
                after: ['domain' => $domain->domain],
                accountId: $domain->website->business_account_id,
                module: 'website',
            ));
        }
    }
}
