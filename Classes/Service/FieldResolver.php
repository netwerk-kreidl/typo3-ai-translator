<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Service;

use Netwerk\AiTranslator\Configuration\ExtensionSettings;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Schema\Field\FieldTranslationBehaviour;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Schema\VisibleSchemaFieldsCollector;

/**
 * Ermittelt anhand der TCA, welche Felder eines Datensatzes übersetzt werden – für jede Tabelle und jeden
 * Inhaltstyp (auch Content Blocks) ohne eigene Konfiguration. Berücksichtigt werden nur Felder, die für den
 * Typ des Datensatzes im Formular sichtbar sind, inkl. typspezifischer Überschreibungen (columnsOverrides).
 */
final class FieldResolver
{
    /** Felder, die nie übersetzt werden (Konfiguration, Technik, interne Notizen) */
    private const ALWAYS_EXCLUDED = [
        '*.rowDescription', '*.l10n_diffsource', '*.l18n_diffsource', '*.t3_origuid',
        'pages.TSconfig', 'pages.tsconfig_includes', 'pages.backend_layout', 'pages.backend_layout_next_level',
        'pages.url', 'pages.target', 'pages.canonical_link', 'pages.slug', 'sys_file_reference.crop',
        // Namen, Handles und Code
        '*.author', '*.author_email', 'pages.tx_csseo_tw_creator', 'pages.tx_csseo_tw_site', 'pages.tx_csseo_json_ld',
    ];

    /**
     * Felder, die bei bestimmten Tabellen ausschließlich übersetzt werden. Bei Dateireferenzen hängt der
     * Formular-Typ von der Datei ab und lässt sich am Datensatz allein nicht bestimmen – ohne diese Liste
     * würden technische Felder wie „tablenames“ oder „fieldname“ mitübersetzt.
     */
    private const ONLY_FIELDS = [
        'sys_file_reference' => ['title', 'alternative', 'description', 'link'],
        'sys_file_metadata' => ['title', 'alternative', 'description', 'caption', 'copyright'],
    ];

    /** Technische Verknüpfungsfelder von Inline-Kindern */
    private const RELATION_FIELDS = ['tablenames', 'fieldname', 'table_local', 'uid_local', 'uid_foreign', 'foreign_table_parent_uid', 'parenttable', 'parentid', 'parentfield'];

    /** Inline-Kinder, die mitübersetzt werden (Typ inline/file) */
    private const CHILD_TYPES = ['inline', 'file'];

    public function __construct(
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly VisibleSchemaFieldsCollector $visibleFieldsCollector,
        private readonly ExtensionSettings $settings,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    /**
     * @param array<string, mixed> $row Datensatz der Ausgangssprache
     * @return array<string, TranslatableField>
     */
    public function getTranslatableFields(string $table, array $row): array
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return [];
        }
        $excluded = array_merge(self::ALWAYS_EXCLUDED, $this->settings->getList('excludeFields'));
        $languageService = $GLOBALS['LANG'] ?? $this->languageServiceFactory->create('de');
        $result = [];
        $only = self::ONLY_FIELDS[$table] ?? null;
        foreach ($this->visibleFieldsCollector->getFields($table, $row) as $field) {
            $name = $field->getName();
            if (($only !== null && !in_array($name, $only, true)) || in_array($name, self::RELATION_FIELDS, true) || str_contains($name, 'json')
                || $this->isExcluded($table, $name, $excluded) || $field->getTranslationBehaviour() === FieldTranslationBehaviour::Excluded) {
                continue;
            }
            $config = $field->getConfiguration();
            if (($config['readOnly'] ?? false) || in_array($config['renderType'] ?? '', ['codeEditor', 't3editor', 'colorpicker'], true)) {
                continue;
            }
            $kind = match ($field->getType()) {
                'input' => $this->isTextInput($config) ? TranslatableField::KIND_TEXT : null,
                'text' => ($config['format'] ?? '') !== '' ? null : (($config['enableRichtext'] ?? false) ? TranslatableField::KIND_HTML : TranslatableField::KIND_TEXT),
                'link' => TranslatableField::KIND_LINK,
                default => null,
            };
            if ($kind !== null) {
                $result[$name] = new TranslatableField(
                    $name,
                    $kind,
                    trim($languageService->sL($field->getLabel())) ?: $name,
                    (int)($config['max'] ?? 0),
                );
            }
        }
        return $result;
    }

    /**
     * Inline-/Datei-Felder mit Kind-Datensätzen, die zusammen mit dem Datensatz übersetzt werden.
     *
     * @param array<string, mixed> $row
     * @return list<array{field: string, table: string, foreignField: string, matchFields: array<string, string>}>
     */
    public function getChildRelations(string $table, array $row): array
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return [];
        }
        $relations = [];
        foreach ($this->visibleFieldsCollector->getFields($table, $row) as $field) {
            if (!in_array($field->getType(), self::CHILD_TYPES, true)) {
                continue;
            }
            $config = $field->getConfiguration();
            $childTable = $field->getType() === 'file' ? 'sys_file_reference' : (string)($config['foreign_table'] ?? '');
            $foreignField = $field->getType() === 'file' ? 'uid_foreign' : (string)($config['foreign_field'] ?? '');
            if ($childTable === '' || $foreignField === '' || !$this->tcaSchemaFactory->has($childTable)) {
                continue;
            }
            $matchFields = [];
            if ($field->getType() === 'file') {
                $matchFields = ['tablenames' => $table, 'fieldname' => $field->getName()];
            } else {
                if (!empty($config['foreign_table_field'])) {
                    $matchFields[(string)$config['foreign_table_field']] = $table;
                }
                foreach ((array)($config['foreign_match_fields'] ?? []) as $matchField => $value) {
                    $matchFields[(string)$matchField] = (string)$value;
                }
            }
            $relations[] = ['field' => $field->getName(), 'table' => $childTable, 'foreignField' => $foreignField, 'matchFields' => $matchFields];
        }
        return $relations;
    }

    public function getRecordTypeLabel(string $table, array $row): string
    {
        $schema = $this->tcaSchemaFactory->get($table);
        $languageService = $GLOBALS['LANG'] ?? $this->languageServiceFactory->create('de');
        if ($schema->supportsSubSchema()) {
            $type = (string)($row[$schema->getSubSchemaTypeInformation()->getFieldName()] ?? '');
            if ($type !== '' && $schema->hasSubSchema($type)) {
                return trim($languageService->sL($schema->getSubSchema($type)->getTitle())) ?: $type;
            }
        }
        return trim($languageService->sL($schema->getTitle())) ?: $table;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function isTextInput(array $config): bool
    {
        $eval = (string)($config['eval'] ?? '');
        foreach (['int', 'num', 'double2', 'email', 'password', 'md5', 'year', 'alphanum', 'domainname', 'is_in', 'nospace'] as $technical) {
            if (str_contains($eval, $technical)) {
                return false;
            }
        }
        return ($config['renderType'] ?? '') === '';
    }

    /**
     * @param list<string> $excluded
     */
    private function isExcluded(string $table, string $field, array $excluded): bool
    {
        return in_array($table . '.' . $field, $excluded, true) || in_array('*.' . $field, $excluded, true);
    }
}
