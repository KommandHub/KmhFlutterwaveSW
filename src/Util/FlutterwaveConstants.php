<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\Util;

/**
 * FlutterwaveConstants contains all fixed keys and string constants used throughout the Flutterwave plugin.
 */
class FlutterwaveConstants
{
    /**
     * `tx_ref` is the merchant-generated reference we send on initialize and the
     * key webhooks are looked up by.
     */
    public const FIELD_REFERENCE = 'flutterwave_reference';

    /**
     * `id` is Flutterwave's own transaction id. Refunds and verification are
     * addressed by this, not by `tx_ref`.
     */
    public const FIELD_TRANSACTION_ID = 'flutterwave_transaction_id';

    public const FIELD_PAYMENT_TYPE = 'flutterwave_payment_type';
    public const FIELD_TRANSACTION_FEE = 'flutterwave_transaction_fee';

    /**
     * The amount Flutterwave actually charged, in major units.
     *
     * Named `_charged` to match the stored key. A previous `FIELD_AMOUNT` here
     * mapped to `flutterwave_amount`, which nothing ever writes — reading through
     * it silently yielded null.
     */
    public const FIELD_AMOUNT_CHARGED = 'flutterwave_amount_charged';

    public const FIELD_AMOUNT_SETTLED = 'flutterwave_amount_settled';
    public const FIELD_CURRENCY = 'flutterwave_currency';
    public const FIELD_VERIFIED_AT = 'flutterwave_verified_at';
    public const FIELD_CUSTOMER = 'flutterwave_customer';

    /**
     * Ids of webhook events already applied to this transaction.
     *
     * Flutterwave's `verif-hash` is a static shared secret rather than a
     * per-payload signature, so a replayed body is indistinguishable from a
     * genuine redelivery. Processed ids are recorded here and re-checked before
     * a webhook is allowed to mutate an order.
     *
     * @see \Kommandhub\FlutterwaveSW\Webhook\Service\WebhookDeduplicator
     */
    public const FIELD_PROCESSED_EVENTS = 'flutterwave_processed_events';

    /**
     * Flutterwave rejects refunds below these thresholds.
     *
     * @see https://developer.flutterwave.com/v3.0/docs/refunds
     */
    public const MINIMUM_REFUND_AMOUNT = [
        'NGN' => 100.0,
        'KES' => 10.0,
    ];

    /**
     * Customer custom-field keys for the stored bank profile. Kept here so the
     * installer, controller and templates share one definition.
     */
    public const CUSTOMER_FIELD_BANK_NAME = 'kmh_flutterwave_bank_name';
    public const CUSTOMER_FIELD_BANK_CODE = 'kmh_flutterwave_bank_code';
    public const CUSTOMER_FIELD_ACCOUNT_NUMBER = 'kmh_flutterwave_account_number';
    public const CUSTOMER_FIELD_ACCOUNT_NAME = 'kmh_flutterwave_account_name';
    public const CUSTOMER_FIELD_BVN = 'kmh_flutterwave_bvn';

    /**
     * Nigerian bank account numbers are exactly 10 digits; BVNs exactly 11.
     */
    public const ACCOUNT_NUMBER_LENGTH = 10;
    public const BVN_LENGTH = 11;

    /**
     * In test mode Flutterwave's account-resolution endpoint accepts only one
     * bank code — 044 (Access Bank) — and rejects any other with
     * "destbankcode/account_bank must be numeric and only 044 is allowed".
     * The code the customer actually selected is still stored on save; this
     * override only lets verification succeed against the sandbox.
     *
     * @see https://developer.flutterwave.com/docs/integration-guides/testing-helpers
     */
    public const SANDBOX_ACCOUNT_BANK = '044';
}
