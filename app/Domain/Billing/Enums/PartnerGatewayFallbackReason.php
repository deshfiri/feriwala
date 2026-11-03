<?php

namespace App\Domain\Billing\Enums;

/**
 * Why a Storefront that has a Partner gateway still took an order through
 * Feriwala's own (D36).
 *
 * Only a Storefront that actually has an active Partner gateway ever gets one of
 * these: a Storefront with none simply uses the Platform gateway and there is
 * nothing to explain. Recorded on the payment's log as a structured value — a
 * name from this list, never a credential, a message from a provider or a detail
 * of the order.
 *
 * The decision is made **before** any payment is created or any gateway is
 * contacted, and it is final for that payment: a Partner payment that later
 * fails to start is never moved to the Platform gateway.
 */
enum PartnerGatewayFallbackReason: string
{
    /** Admin has switched Partner gateways off. */
    case FeatureDisabled = 'feature_disabled';

    /** Admin has taken this provider off the allow-list. */
    case ProviderNotAllowed = 'provider_not_allowed';

    /** The saved configuration is missing, or does not belong to this Storefront. */
    case ConfigurationUnavailable = 'configuration_unavailable';

    /** A sandbox configuration cannot take real orders in production. */
    case SandboxNotAllowedInProduction = 'sandbox_not_allowed_in_production';

    /** The provider has no driver, cannot verify a payment, or cannot be given separate credentials. */
    case ProviderUnsupported = 'provider_unsupported';

    /** The configuration lacks a credential the provider needs. */
    case CredentialsIncomplete = 'credentials_incomplete';

    /** The provider cannot take the order's currency. */
    case CurrencyUnsupported = 'currency_unsupported';

    /** The Storefront asked for a different provider by name. */
    case RequestedOtherProvider = 'requested_other_provider';

    /** Only a Non-Conditional account sells with a platform receivable. */
    case AccountNotNonConditional = 'account_not_non_conditional';

    /** A line is not filled from central stock, or the order mixes lines that are and are not. */
    case LineNotCentralStock = 'line_not_central_stock';

    /** A line has no frozen platform amount or product cost, so Feriwala's share of it is undefined. */
    case LineAmountUndefined = 'line_amount_undefined';

    /** A line is in a different currency from the order. */
    case CurrencyMismatch = 'currency_mismatch';

    /** The immutable receivable snapshot could not be created completely. */
    case SnapshotIncomplete = 'snapshot_incomplete';
}
