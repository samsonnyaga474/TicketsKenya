<?php
declare(strict_types=1);
namespace SiteActivity;

/**
 * Everything the reusable module needs from the website it is installed in.
 * A new project writes one small class implementing this interface (see adapters/ticketskenya.php).
 */
interface HostAdapter
{
    /** PDO connection to the project's existing database (or null when no SQL database is available). */
    public function pdo(): ?\PDO;

    /** Currently logged-in user as ['id'=>int,'email'=>string,'role'=>string] or null. */
    public function currentUser(): ?array;

    /** True when this user may use the Activity & Insights admin area (owner level). */
    public function isOwner(?array $user): bool;

    /** Validate a CSRF token from a state-changing request using the host's own CSRF system. */
    public function verifyCsrf(?string $token): bool;

    /** Current CSRF token for forms the module renders. */
    public function csrfToken(): string;

    /** Describe a public path: ['path_group'=>string,'event_ref_id'=>?int,'category_ref'=>?string]. */
    public function describePath(string $path): array;

    /** Readable labels for host "event" ids: [id => label]. */
    public function eventLabels(array $ids): array;

    /** Orders the host has verified as paid with id greater than $sinceId: list of ['order_id','total','paid_at','event_id','user_id']. */
    public function paidOrders(int $sinceId, int $limit): array;

    /** Orders created in [fromUtc, toUtc): list of ['id','paid'(bool),'status','total','created_at','event_id']. */
    public function ordersCreatedBetween(string $fromUtc, string $toUtc): array;

    /** Ids of host users who have at least one verified paid order. */
    public function paidCustomerUserIds(): array;

    /** Site base path prefix ('' when served at the web root). */
    public function basePath(): string;

    /** Where to send a visitor who is not logged in. */
    public function loginUrl(): string;
}
