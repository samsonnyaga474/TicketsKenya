<?php
declare(strict_types=1);
namespace SiteActivity;

/**
 * The documented event schema. Event names are lowercase snake_case "object_action" phrases.
 * "client" events may arrive from the browser; "server" events are created only by trusted server code
 * (a browser that sends one of these is rejected and the rejection is logged).
 */
final class Registry
{
    /** name => [category, origin, plain-language description] */
    public const EVENTS = [
        'page_view'               => ['navigation', 'client', 'A page was opened.'],
        'navigation_click'        => ['navigation', 'client', 'A header, footer or menu link was used.'],
        'link_click'              => ['navigation', 'client', 'Another internal link was used.'],
        'button_click'            => ['navigation', 'client', 'A button was used.'],
        'outbound_link_clicked'   => ['outbound',   'client', 'A link to another website was used (host only is stored).'],
        'search_submitted'        => ['search',     'client', 'The search form was submitted.'],
        'filter_changed'          => ['search',     'client', 'A listing filter (category, date, city, price, sort) was changed.'],
        'event_card_clicked'      => ['event',      'client', 'An event card or event link was used.'],
        'event_viewed'            => ['event',      'server', 'An event detail page was opened (derived by the server from page_view).'],
        'ticket_quantity_changed' => ['checkout',   'client', 'A ticket quantity field was changed.'],
        'login_attempted'         => ['account',    'client', 'The login form was submitted (success is not implied).'],
        'registration_attempted'  => ['account',    'client', 'The registration form was submitted (success is not implied).'],
        'contact_submitted'       => ['account',    'client', 'The contact form was submitted.'],
        'js_error'                => ['error',      'client', 'A script error happened on the page (short message only).'],
        'checkout_started'        => ['checkout',   'server', 'A real order was created on the server.'],
        'payment_initiated'       => ['checkout',   'server', 'A payment attempt was started for a real order (not wired: payments are disabled in this build).'],
        'payment_failed'          => ['checkout',   'server', 'A payment attempt failed (not wired: payments are disabled in this build).'],
        'purchase_completed'      => ['checkout',   'server', 'A real order was verified as paid by the server.'],
    ];

    public static function category(string $name): ?string { return self::EVENTS[$name][0] ?? null; }
    public static function isClient(string $name): bool { return (self::EVENTS[$name][1] ?? '') === 'client'; }
    public static function isServer(string $name): bool { return (self::EVENTS[$name][1] ?? '') === 'server'; }
    public static function known(string $name): bool { return isset(self::EVENTS[$name]); }

    /** Keys a client event may carry in "meta"; everything else is dropped. */
    public const META_KEYS = ['field', 'value', 'zone', 'host', 'nav', 'qty', 'ticket_type', 'msg', 'form'];
}
