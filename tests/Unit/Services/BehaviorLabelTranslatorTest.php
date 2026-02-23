<?php

namespace Tests\Unit\Services;

use App\Services\BehaviorLabelTranslator;
use PHPUnit\Framework\TestCase;

class BehaviorLabelTranslatorTest extends TestCase
{
    public function test_translate_returns_full_translation_for_known_label(): void
    {
        $result = BehaviorLabelTranslator::translate('Speeding');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('description', $result);
        $this->assertArrayHasKey('category', $result);
        $this->assertArrayHasKey('severity', $result);
        $this->assertSame('Exceso de velocidad', $result['name']);
        $this->assertSame('El vehículo excedió el límite de velocidad permitido', $result['description']);
        $this->assertSame('Velocidad', $result['category']);
        $this->assertSame('warning', $result['severity']);
    }

    public function test_translate_returns_fallback_for_unknown_label(): void
    {
        $result = BehaviorLabelTranslator::translate('UnknownLabel123');

        $this->assertSame('UnknownLabel123', $result['name']);
        $this->assertSame('Evento de seguridad: UnknownLabel123', $result['description']);
        $this->assertSame('Otro', $result['category']);
        $this->assertSame('info', $result['severity']);
    }

    public function test_translate_handles_both_near_collision_spellings(): void
    {
        $this->assertSame('Casi colisión', BehaviorLabelTranslator::translate('NearCollison')['name']);
        $this->assertSame('Casi colisión', BehaviorLabelTranslator::translate('NearCollision')['name']);
    }

    public function test_get_name_returns_translated_name_for_known_label(): void
    {
        $this->assertSame('Colisión detectada', BehaviorLabelTranslator::getName('Crash'));
        $this->assertSame('Uso de celular', BehaviorLabelTranslator::getName('MobileUsage'));
    }

    public function test_get_name_returns_label_for_unknown(): void
    {
        $this->assertSame('UnknownLabel', BehaviorLabelTranslator::getName('UnknownLabel'));
    }

    public function test_get_description_returns_translated_description_for_known_label(): void
    {
        $this->assertSame(
            'Se detectó un impacto o colisión del vehículo',
            BehaviorLabelTranslator::getDescription('Crash')
        );
    }

    public function test_get_description_returns_fallback_for_unknown(): void
    {
        $this->assertSame('Evento de seguridad: FooBar', BehaviorLabelTranslator::getDescription('FooBar'));
    }

    public function test_get_severity_returns_severity_for_known_label(): void
    {
        $this->assertSame('critical', BehaviorLabelTranslator::getSeverity('Crash'));
        $this->assertSame('warning', BehaviorLabelTranslator::getSeverity('Speeding'));
        $this->assertSame('info', BehaviorLabelTranslator::getSeverity('LightSpeeding'));
    }

    public function test_get_severity_returns_info_for_unknown(): void
    {
        $this->assertSame('info', BehaviorLabelTranslator::getSeverity('UnknownLabel'));
    }

    public function test_get_category_returns_category_for_known_label(): void
    {
        $this->assertSame('Colisión', BehaviorLabelTranslator::getCategory('Crash'));
        $this->assertSame('Distracción', BehaviorLabelTranslator::getCategory('MobileUsage'));
    }

    public function test_get_category_returns_otro_for_unknown(): void
    {
        $this->assertSame('Otro', BehaviorLabelTranslator::getCategory('UnknownLabel'));
    }

    public function test_translate_many_handles_string_labels(): void
    {
        $labels = ['Speeding', 'Braking'];
        $result = BehaviorLabelTranslator::translateMany($labels);

        $this->assertCount(2, $result);
        $this->assertSame('Speeding', $result[0]['original']);
        $this->assertSame('Exceso de velocidad', $result[0]['name']);
        $this->assertSame('automated', $result[0]['source']);
    }

    public function test_translate_many_handles_array_labels_with_label_key(): void
    {
        $labels = [['label' => 'Crash', 'source' => 'dashcam']];
        $result = BehaviorLabelTranslator::translateMany($labels);

        $this->assertCount(1, $result);
        $this->assertSame('Crash', $result[0]['original']);
        $this->assertSame('Colisión detectada', $result[0]['name']);
        $this->assertSame('dashcam', $result[0]['source']);
    }

    public function test_translate_many_handles_array_labels_with_name_key(): void
    {
        $labels = [['name' => 'HarshTurn']];
        $result = BehaviorLabelTranslator::translateMany($labels);

        $this->assertCount(1, $result);
        $this->assertSame('HarshTurn', $result[0]['original']);
        $this->assertSame('Giro brusco', $result[0]['name']);
    }

    public function test_translate_many_skips_empty_labels(): void
    {
        $labels = ['Speeding', [], ['label' => ''], null];
        $result = BehaviorLabelTranslator::translateMany($labels);

        $this->assertCount(1, $result);
        $this->assertSame('Speeding', $result[0]['original']);
    }

    public function test_get_primary_translated_returns_first_label_name(): void
    {
        $labels = ['Speeding', 'Braking'];
        $this->assertSame('Exceso de velocidad', BehaviorLabelTranslator::getPrimaryTranslated($labels));
    }

    public function test_get_primary_translated_handles_array_labels(): void
    {
        $labels = [['label' => 'Crash']];
        $this->assertSame('Colisión detectada', BehaviorLabelTranslator::getPrimaryTranslated($labels));
    }

    public function test_get_primary_translated_returns_null_for_empty_array(): void
    {
        $this->assertNull(BehaviorLabelTranslator::getPrimaryTranslated([]));
    }

    public function test_get_primary_translated_returns_null_for_empty_label_in_array(): void
    {
        $labels = [['label' => '']];
        $this->assertNull(BehaviorLabelTranslator::getPrimaryTranslated($labels));
    }

    public function test_translate_state_returns_state_translation(): void
    {
        $result = BehaviorLabelTranslator::translateState('needsReview');

        $this->assertSame('Necesita revisión', $result['name']);
        $this->assertSame('El evento necesita ser revisado por un supervisor', $result['description']);
    }

    public function test_translate_state_returns_fallback_for_unknown(): void
    {
        $result = BehaviorLabelTranslator::translateState('unknownState');

        $this->assertSame('unknownState', $result['name']);
        $this->assertSame('Estado: unknownState', $result['description']);
    }

    public function test_get_state_name_returns_translated_name(): void
    {
        $this->assertSame('Revisado', BehaviorLabelTranslator::getStateName('reviewed'));
        $this->assertSame('Descartado', BehaviorLabelTranslator::getStateName('dismissed'));
    }

    public function test_get_state_name_returns_state_for_unknown(): void
    {
        $this->assertSame('unknownState', BehaviorLabelTranslator::getStateName('unknownState'));
    }

    public function test_get_all_by_category_groups_labels_by_category(): void
    {
        $grouped = BehaviorLabelTranslator::getAllByCategory();

        $this->assertIsArray($grouped);
        $this->assertArrayHasKey('Velocidad', $grouped);
        $this->assertArrayHasKey('Colisión', $grouped);
        $this->assertArrayHasKey('Conducción', $grouped);
        $this->assertArrayHasKey('Speeding', $grouped['Velocidad']);
        $this->assertSame('Exceso de velocidad', $grouped['Velocidad']['Speeding']['name']);
    }

    public function test_get_by_severity_returns_only_matching_severity(): void
    {
        $critical = BehaviorLabelTranslator::getBySeverity('critical');

        $this->assertNotEmpty($critical);
        foreach ($critical as $data) {
            $this->assertSame('critical', $data['severity']);
        }
    }

    public function test_is_critical_returns_true_for_critical_labels(): void
    {
        $this->assertTrue(BehaviorLabelTranslator::isCritical('Crash'));
        $this->assertTrue(BehaviorLabelTranslator::isCritical('NoSeatbelt'));
    }

    public function test_is_critical_returns_false_for_non_critical(): void
    {
        $this->assertFalse(BehaviorLabelTranslator::isCritical('Speeding'));
        $this->assertFalse(BehaviorLabelTranslator::isCritical('LightSpeeding'));
    }

    public function test_is_warning_returns_true_for_warning_labels(): void
    {
        $this->assertTrue(BehaviorLabelTranslator::isWarning('Speeding'));
        $this->assertTrue(BehaviorLabelTranslator::isWarning('Braking'));
    }

    public function test_is_warning_returns_false_for_non_warning(): void
    {
        $this->assertFalse(BehaviorLabelTranslator::isWarning('Crash'));
        $this->assertFalse(BehaviorLabelTranslator::isWarning('LightSpeeding'));
    }

    public function test_constants_are_defined(): void
    {
        $this->assertNotEmpty(BehaviorLabelTranslator::TRANSLATIONS);
        $this->assertNotEmpty(BehaviorLabelTranslator::CATEGORIES);
        $this->assertNotEmpty(BehaviorLabelTranslator::EVENT_STATES);
    }
}
