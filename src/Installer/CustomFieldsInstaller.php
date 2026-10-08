<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\Installer;

use Kommandhub\FlutterwaveSW\Util\FlutterwaveConstants;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\CustomField\CustomFieldTypes;

/**
 * Installs the customer bank-profile custom fields (bank name/code, account
 * number/name, BVN) collected by the storefront bank-verification feature.
 */
class CustomFieldsInstaller
{
    private const CUSTOM_FIELDSET_NAME = 'kmh_flutterwave_fieldset';

    /**
     * @return array<string, mixed>
     */
    private static function fieldset(): array
    {
        return [
            'name' => self::CUSTOM_FIELDSET_NAME,
            'config' => [
                'label' => [
                    'en-GB' => 'Flutterwave Bank Fields',
                    'de-DE' => 'Flutterwave Bankfelder',
                    'fr-FR' => 'Champs bancaires Flutterwave',
                    Defaults::LANGUAGE_SYSTEM => 'Flutterwave Fields',
                ],
            ],
            'customFields' => [
                self::textField(FlutterwaveConstants::CUSTOMER_FIELD_BANK_NAME, 1, [
                    'en-GB' => 'Bank Name',
                    'de-DE' => 'Name der Bank',
                    'fr-FR' => 'Nom de la banque',
                    Defaults::LANGUAGE_SYSTEM => 'Bank Name',
                ]),
                self::textField(FlutterwaveConstants::CUSTOMER_FIELD_BANK_CODE, 2, [
                    'en-GB' => 'Bank Code',
                    'de-DE' => 'Bankleitzahl',
                    'fr-FR' => 'Code de la banque',
                    Defaults::LANGUAGE_SYSTEM => 'Bank Code',
                ]),
                self::textField(FlutterwaveConstants::CUSTOMER_FIELD_ACCOUNT_NUMBER, 3, [
                    'en-GB' => 'Account Number',
                    'de-DE' => 'Kontonummer',
                    'fr-FR' => 'Numéro de compte',
                    Defaults::LANGUAGE_SYSTEM => 'Account Number',
                ]),
                self::textField(FlutterwaveConstants::CUSTOMER_FIELD_ACCOUNT_NAME, 4, [
                    'en-GB' => 'Account Name',
                    'de-DE' => 'Kontoinhaber',
                    'fr-FR' => 'Nom du titulaire',
                    Defaults::LANGUAGE_SYSTEM => 'Account Name',
                ]),
                self::textField(FlutterwaveConstants::CUSTOMER_FIELD_BVN, 5, [
                    'en-GB' => 'BVN',
                    'de-DE' => 'BVN',
                    'fr-FR' => 'BVN',
                    Defaults::LANGUAGE_SYSTEM => 'BVN',
                ], [
                    'en-GB' => 'Bank Verification Number',
                    'de-DE' => 'Bank Verification Number',
                    'fr-FR' => 'Numéro de vérification bancaire',
                    Defaults::LANGUAGE_SYSTEM => 'Bank Verification Number',
                ]),
            ],
        ];
    }

    /**
     * @param array<string, string> $label
     * @param array<string, string>|null $helpText
     *
     * @return array<string, mixed>
     */
    private static function textField(string $name, int $position, array $label, ?array $helpText = null): array
    {
        $config = [
            'label' => $label,
            'customFieldPosition' => $position,
        ];

        if ($helpText !== null) {
            $config['helpText'] = $helpText;
        }

        return [
            'name' => $name,
            'type' => CustomFieldTypes::TEXT,
            'config' => $config,
        ];
    }

    public function __construct(
        private readonly EntityRepository $customFieldSetRepository,
        private readonly EntityRepository $customFieldSetRelationRepository
    ) {
    }

    public function install(Context $context): void
    {
        if ($this->customFieldSetExists($context)) {
            return;
        }

        $this->customFieldSetRepository->upsert([self::fieldset()], $context);
    }

    public function addRelations(Context $context): void
    {
        $relationsToInsert = [];

        foreach ($this->getCustomFieldSetIds($context) as $customFieldSetId) {
            if ($this->customFieldSetRelationExists($context, $customFieldSetId, CustomerDefinition::ENTITY_NAME)) {
                continue;
            }

            $relationsToInsert[] = [
                'customFieldSetId' => $customFieldSetId,
                'entityName' => CustomerDefinition::ENTITY_NAME,
            ];
        }

        if ($relationsToInsert === []) {
            return;
        }

        $this->customFieldSetRelationRepository->upsert($relationsToInsert, $context);
    }

    public function uninstall(Context $context): void
    {
        $ids = $this->getCustomFieldSetIds($context);

        if ($ids === []) {
            return;
        }

        $ids = array_map(static fn (string $id) => ['id' => $id], $ids);

        $this->customFieldSetRepository->delete($ids, $context);
    }

    /**
     * @return string[]
     */
    private function getCustomFieldSetIds(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::CUSTOM_FIELDSET_NAME));

        return $this->customFieldSetRepository->searchIds($criteria, $context)->getIds();
    }

    private function customFieldSetExists(Context $context): bool
    {
        return $this->getCustomFieldSetIds($context) !== [];
    }

    private function customFieldSetRelationExists(Context $context, string $customFieldSetId, string $entityName): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFieldSetId', $customFieldSetId));
        $criteria->addFilter(new EqualsFilter('entityName', $entityName));

        return $this->customFieldSetRelationRepository->searchIds($criteria, $context)->getTotal() > 0;
    }
}
