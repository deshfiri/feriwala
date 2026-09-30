<?php

namespace App\Support\References;

/**
 * Typed prefixes for human-readable business references.
 *
 * Public and user-facing identifiers must never expose database IDs
 * (requirements.txt §34.2, §34.3), and support staff need to be able to read a
 * reference over the phone without ambiguity.
 */
enum ReferencePrefix: string
{
    case Order = 'ORD';
    case Payment = 'PAY';
    case Transaction = 'TXN';
    case Withdrawal = 'WDR';
    case Invoice = 'INV';
    case Settlement = 'STL';
    case Refund = 'RFD';
    case Commission = 'CMS';
    case Fulfillment = 'FFL';
    case Shipment = 'SHP';
    case GoodsReturn = 'RET';
    case Supplier = 'SUP';
    case SupplierListing = 'SPL';
    case SupplierListingLot = 'SLL';
    case SupplierOffer = 'SPO';
    case SupplierPayable = 'SPY';
    case SupplierLedgerEntry = 'SPE';
    case SupplierFulfilmentCommitment = 'SFC';

    public function label(): string
    {
        return match ($this) {
            self::Order => 'Order',
            self::Payment => 'Payment',
            self::Transaction => 'Transaction',
            self::Withdrawal => 'Withdrawal',
            self::Invoice => 'Invoice',
            self::Settlement => 'Settlement',
            self::Refund => 'Refund',
            self::Commission => 'Commission',
            self::Fulfillment => 'Fulfillment',
            self::Shipment => 'Shipment',
            self::GoodsReturn => 'Return',
            self::Supplier => 'Supplier',
            self::SupplierListing => 'Supplier listing',
            self::SupplierListingLot => 'Supplier listing lot',
            self::SupplierOffer => 'Supplier offer',
            self::SupplierPayable => 'Supplier payable',
            self::SupplierLedgerEntry => 'Supplier ledger entry',
            self::SupplierFulfilmentCommitment => 'Supplier fulfilment commitment',
        };
    }
}
