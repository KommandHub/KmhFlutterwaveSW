import template from './kmh-flutterwave-detail.html.twig';
import './kmh-flutterwave-detail.scss';
import icon from './icon.png';
import { FLUTTERWAVE_HANDLER_IDENTIFIER, FLUTTERWAVE_REFERENCE_FIELD, isAbortError } from '../../util/flutterwave';
import {
    maxRefundableAmount as calcMaxRefundable,
    minRefundableAmount as calcMinRefundable,
    currencyDecimals,
} from '../../service/refund-calculator';

const { Mixin } = Shopware;
const { Criteria } = Shopware.Data;

const CF = {
    reference: 'flutterwave_reference',
    transactionId: 'flutterwave_transaction_id',
    paymentType: 'flutterwave_payment_type',
    fee: 'flutterwave_transaction_fee',
    amountCharged: 'flutterwave_amount_charged',
    amountSettled: 'flutterwave_amount_settled',
    currency: 'flutterwave_currency',
    verifiedAt: 'flutterwave_verified_at',
};

Shopware.Component.register('kmh-flutterwave-detail', {
    template,

    mixins: [Mixin.getByName('notification')],

    metaInfo() {
        return { title: this.$t('kmh-flutterwave-detail.title') };
    },

    inject: [
        'repositoryFactory',
        'flutterwaveRefundService',
        'systemConfigApiService',
        'acl',
    ],

    data() {
        return {
            showRefundModal: false,
            showRefundsListModal: false,
            activeCapture: null,
            refundAmount: null,
            refundCurrency: null,
            comments: null,
            isRefundLoading: false,
            isRefundSuccess: false,
            captures: [],
            refunds: [],
            liveTransactionState: null,
            isLoading: false,
            config: {},
        };
    },

    computed: {
        orderRepository() {
            return this.repositoryFactory.create('order');
        },

        /**
         * Repository for the local Shopware capture records. Captures and their
         * refunds are read straight from the database here — the same approach
         * the Paystack plugin uses — rather than through the Flutterwave API, so
         * the panel reflects exactly what the plugin has persisted for this
         * transaction (created on refund initiation, completed by the
         * `refund.completed` webhook).
         *
         * @returns {Repository}
         */
        transactionCaptureRepository() {
            return this.repositoryFactory.create('order_transaction_capture');
        },

        transactionRepository() {
            return this.repositoryFactory.create('order_transaction');
        },

        order() {
            return Shopware.Store.get('swOrderDetail')?.order;
        },

        /**
         * The Flutterwave transaction on the order, matched by payment handler or
         * — as a fallback when the association is absent — the reference field.
         *
         * @returns {Object|null}
         */
        flutterwaveTransaction() {
            if (!this.order?.transactions) {
                return null;
            }

            const transactions = this.order.transactions;

            return transactions.find((transaction) =>
                transaction.paymentMethod?.handlerIdentifier === FLUTTERWAVE_HANDLER_IDENTIFIER
                || (transaction.customFields && transaction.customFields[FLUTTERWAVE_REFERENCE_FIELD])) ?? null;
        },

        customFields() {
            return this.flutterwaveTransaction?.customFields ?? {};
        },

        /**
         * Only a FULL refund closes the transaction; a partially refunded one
         * still has balance left (maxRefundableAmount guards the amount).
         */
        isTransactionRefunded() {
            return this.transactionState?.technicalName === 'refunded';
        },

        /**
         * The order detail page edits a version copy of the order, created when
         * the page opened, so its transaction state goes stale the moment a
         * refund settles. Prefer the live state loaded alongside the captures.
         *
         * @returns {Object|null}
         */
        transactionState() {
            return this.liveTransactionState ?? this.flutterwaveTransaction?.stateMachineState ?? null;
        },

        /**
         * Single-row data source for the transaction grid.
         *
         * @returns {Array<Object>}
         */
        flutterwaveTransactionData() {
            if (!this.flutterwaveTransaction?.id) {
                return [];
            }

            const cf = this.customFields;

            return [{
                id: this.flutterwaveTransaction.id,
                amount: cf[CF.amountCharged],
                settled: cf[CF.amountSettled],
                currency: cf[CF.currency],
                channel: cf[CF.paymentType],
                reference: cf[CF.reference],
                transactionId: cf[CF.transactionId],
                fee: cf[CF.fee],
                verifiedAt: cf[CF.verifiedAt],
                state: this.transactionState,
                paymentMethod: this.flutterwaveTransaction.paymentMethod,
            }];
        },

        /**
         * Transaction grid columns. Exposes Flutterwave's settled amount, which
         * the Paystack preview does not have.
         *
         * @returns {Array<Object>}
         */
        flutterwaveTransactionColumns() {
            return [
                { property: 'amount', label: 'kmh-flutterwave-detail.grid.chargedAmount', primary: true },
                { property: 'settled', label: 'kmh-flutterwave-detail.grid.settledAmount' },
                { property: 'reference', label: 'kmh-flutterwave-detail.grid.reference' },
                { property: 'transactionId', label: 'kmh-flutterwave-detail.grid.transactionId' },
                { property: 'fee', label: 'kmh-flutterwave-detail.grid.processingFee' },
                { property: 'channel', label: 'kmh-flutterwave-detail.grid.channel' },
                { property: 'paymentMethod', label: 'kmh-flutterwave-detail.grid.paymentMethod' },
                { property: 'state', label: 'kmh-flutterwave-detail.grid.state' },
                { property: 'verifiedAt', label: 'kmh-flutterwave-detail.grid.verifiedAt' },
            ];
        },

        /**
         * Capture grid columns (local Shopware records).
         *
         * @returns {Array<Object>}
         */
        flutterwaveCaptureColumns() {
            return [
                { property: 'amount', label: 'kmh-flutterwave-detail.captures.grid.amount', primary: true },
                { property: 'state', label: 'kmh-flutterwave-detail.captures.grid.state' },
                { property: 'createdAt', label: 'kmh-flutterwave-detail.captures.grid.createdAt' },
            ];
        },

        /**
         * Refund grid columns (local Shopware records).
         *
         * @returns {Array<Object>}
         */
        flutterwaveRefundColumns() {
            return [
                { property: 'amount', label: 'kmh-flutterwave-detail.refunds.grid.amount', primary: true },
                { property: 'state', label: 'kmh-flutterwave-detail.refunds.grid.state' },
                { property: 'externalReference', label: 'kmh-flutterwave-detail.refunds.grid.externalReference' },
                { property: 'createdAt', label: 'kmh-flutterwave-detail.refunds.grid.createdAt' },
            ];
        },

        /**
         * The refunds shown in the refunds-list modal: those belonging to the
         * capture whose row opened it. Mirrors the Paystack plugin — refund
         * history lives inside the associated capture's modal, not a standalone
         * card.
         *
         * @returns {Array<Object>}
         */
        flutterwaveRefunds() {
            if (this.activeCapture?.id) {
                return this.refunds.filter((refund) => refund.captureId === this.activeCapture.id);
            }

            return this.refunds;
        },

        activeCurrency() {
            return this.refundCurrency || this.customFields[CF.currency];
        },

        chargedAmount() {
            return Number(this.customFields[CF.amountCharged] ?? 0);
        },

        maxRefundableAmount() {
            if (!this.flutterwaveTransaction) {
                return 0;
            }

            return calcMaxRefundable({
                transactionAmount: this.chargedAmount,
                refunds: this.refunds,
                currency: this.activeCurrency,
            });
        },

        refundEnabled() {
            return this.config['KmhFlutterwaveSW.config.refundEnabled'] !== false;
        },

        configuredMinRefund() {
            const value = Number(this.config['KmhFlutterwaveSW.config.minimumRefundAmount']);

            return Number.isFinite(value) && value > 0 ? value : 0;
        },

        minRefundableAmount() {
            return calcMinRefundable(this.configuredMinRefund, this.activeCurrency);
        },

        /**
         * A refund can start when the feature is on, the user is permitted, the
         * transaction is not fully refunded, and a positive refundable balance
         * remains that clears the minimum.
         *
         * @returns {Boolean}
         */
        canRefund() {
            return this.refundEnabled
                && this.acl.can('flutterwave.refund')
                && !this.isTransactionRefunded
                && this.maxRefundableAmount > 0
                && this.maxRefundableAmount >= this.minRefundableAmount;
        },

        refundAmountDigits() {
            return currencyDecimals(this.activeCurrency);
        },

        currencyFilter() {
            return Shopware.Filter.getByName('currency');
        },

        flutterwaveIcon() {
            return icon;
        },
    },

    watch: {
        flutterwaveTransaction: {
            immediate: true,
            handler(transaction) {
                if (transaction?.id) {
                    void this.loadCapturesAndRefunds();
                }
            },
        },
        'order.salesChannelId': {
            immediate: true,
            handler(salesChannelId) {
                if (salesChannelId) {
                    void this.loadConfig(salesChannelId);
                }
            },
        },
    },

    methods: {
        async loadConfig(salesChannelId) {
            this.config = await this.systemConfigApiService.getValues(
                'KmhFlutterwaveSW.config',
                salesChannelId
            );
        },

        liveTransactionCriteria() {
            const criteria = new Criteria();
            criteria.addAssociation('stateMachineState');

            return criteria;
        },

        /**
         * Criteria for the captures belonging to one order transaction, with the
         * associations the grids need: each capture's state, its refunds, and
         * each refund's state.
         *
         * @param {String} transactionId
         * @returns {Criteria}
         */
        transactionCaptureCriteria(transactionId) {
            const criteria = new Criteria();

            criteria.addFilter(Criteria.equals('orderTransactionId', transactionId));
            criteria.addAssociation('stateMachineState');
            criteria.addAssociation('refunds');
            criteria.addAssociation('refunds.stateMachineState');

            return criteria;
        },

        /**
         * Loads the captures and refunds for the current transaction directly
         * from the Shopware repositories, filtered to this transaction. Refunds
         * are flattened out of their captures for the refund grid. Re-entrancy
         * guarded so the immediate watcher cannot start two loads at once.
         *
         * @returns {Promise<void>}
         */
        async loadCapturesAndRefunds() {
            if (!this.flutterwaveTransaction?.id || this.isLoading) {
                return;
            }

            this.isLoading = true;

            try {
                const liveTransaction = await this.transactionRepository.get(
                    this.flutterwaveTransaction.id,
                    Shopware.Context.api,
                    this.liveTransactionCriteria()
                );
                this.liveTransactionState = liveTransaction?.stateMachineState ?? null;

                const captureResult = await this.transactionCaptureRepository.search(
                    this.transactionCaptureCriteria(this.flutterwaveTransaction.id),
                    Shopware.Context.api
                );

                const captures = captureResult.map((capture) => ({
                    ...capture,
                    state: capture.stateMachineState?.translated?.name
                        || capture.stateMachineState?.name
                        || '',
                }));

                const refunds = [];

                captures.forEach((capture) => {
                    capture.refunds?.forEach((refund) => {
                        refunds.push({
                            ...refund,
                            state: refund.stateMachineState?.translated?.name
                                || refund.stateMachineState?.name
                                || '',
                        });
                    });
                });

                this.captures = captures;
                this.refunds = refunds;
            } catch (error) {
                if (isAbortError(error)) {
                    return;
                }

                this.createNotificationError({ message: error?.message ?? String(error) });
            } finally {
                this.isLoading = false;
            }
        },

        onOpenRefundModal() {
            this.refundCurrency = this.customFields[CF.currency];
            this.refundAmount = this.maxRefundableAmount;
            this.showRefundModal = true;
        },

        /**
         * Opens the refunds-list modal for the given capture row.
         *
         * @param {Object} capture
         */
        onOpenRefundsListModal(capture) {
            this.activeCapture = capture;
            this.showRefundsListModal = true;
        },

        onCloseRefundsListModal() {
            this.activeCapture = null;
            this.showRefundsListModal = false;
        },

        onCloseRefundModal() {
            this.showRefundModal = false;
            this.refundAmount = null;
            this.refundCurrency = null;
            this.comments = null;
        },

        onConfirmRefund() {
            // The button keeps showing its success tick until process-finish
            // closes the modal; a click in that window must not refund again.
            if (this.isRefundLoading || this.isRefundSuccess) {
                return;
            }

            if (this.refundAmount < this.minRefundableAmount) {
                this.createNotificationError({
                    message: this.$t('kmh-flutterwave-detail.refund.errorAmountTooLow', {
                        minAmount: this.currencyFilter(this.minRefundableAmount, this.activeCurrency),
                    }),
                });
                return;
            }

            if (this.refundAmount > this.maxRefundableAmount) {
                this.createNotificationError({
                    message: this.$t('kmh-flutterwave-detail.refund.errorAmountTooHigh', {
                        maxAmount: this.currencyFilter(this.maxRefundableAmount, this.activeCurrency),
                    }),
                });
                return;
            }

            this.isRefundLoading = true;

            const payload = {
                orderTransactionId: this.flutterwaveTransaction.id,
                // Major units; Flutterwave charges what it is given.
                amount: this.refundAmount,
                comments: this.comments,
            };

            this.flutterwaveRefundService.refund(payload)
                .then(async () => {
                    this.isRefundSuccess = true;
                    this.createNotificationSuccess({
                        message: this.$t('kmh-flutterwave-detail.refund.success'),
                    });
                    await this.loadCapturesAndRefunds();
                })
                .catch((error) => {
                    if (isAbortError(error)) {
                        return;
                    }

                    const message = error.response?.data?.error || error.message;
                    this.createNotificationError({ message });
                })
                .finally(() => {
                    this.isRefundLoading = false;
                });
        },

        onRefundFinished() {
            this.isRefundSuccess = false;
            this.onCloseRefundModal();
        },
    },
});
