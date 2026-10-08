<?php

namespace Tests\Unit;

use App\Services\Reports\Calls\CallInterestPortalResolver;
use App\Services\Reports\Calls\CallPortalNormalizer;
use Tests\TestCase;

class CallInterestPortalResolverTest extends TestCase
{
    private CallInterestPortalResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new CallInterestPortalResolver(new CallPortalNormalizer);
    }

    public function test_task_portal_is_authoritative_over_conflicting_interest_source(): void
    {
        $result = $this->resolver->resolve('Web Alcobendas', 'a01AAA000000000AAA', [
            'salesforce_id' => 'a01AAA000000000AAA',
            'source' => 'Coches.net',
            'is_deleted' => false,
        ]);

        $this->assertSame('Web', $result['operational']['portal']);
        $this->assertSame('portales_field', $result['visible']['source']);
        $this->assertFalse($result['debug']['interest_source_used']);
    }

    public function test_null_task_portal_remains_commercial_direct_with_interest(): void
    {
        $result = $this->resolver->resolve(null, 'a01AAA000000000AAA', [
            'salesforce_id' => 'a01AAA000000000AAA',
            'source' => 'Coches.net',
            'is_deleted' => false,
        ]);

        $this->assertSame('commercial_direct', $result['visible']['source']);
        $this->assertSame('commercial_direct', $result['visible']['origin']);
    }

    public function test_unclassified_task_uses_exact_interest_source_even_when_deleted(): void
    {
        $result = $this->resolver->resolve('3CX', 'a01AAA000000000AAA', [
            'salesforce_id' => 'a01AAA000000000AAA',
            'source' => 'Coches.net',
            'is_deleted' => true,
        ]);

        $this->assertSame('Coches.net', $result['visible']['portal']);
        $this->assertSame('interest', $result['visible']['source']);
        $this->assertSame('exact_interest', $result['debug']['relationship_status']);
        $this->assertTrue($result['debug']['interest_is_deleted']);
    }

    public function test_who_id_never_attributes_without_exact_what_id(): void
    {
        $result = $this->resolver->resolve('3CX', null, null);

        $this->assertSame(CallPortalNormalizer::UNCLASSIFIED, $result['visible']['portal']);
        $this->assertSame('no_reference', $result['debug']['relationship_status']);
        $this->assertFalse($result['debug']['interest_matched']);
    }

    public function test_empty_or_unknown_interest_source_remains_unclassified(): void
    {
        foreach ([null, '', 'Fuente desconocida'] as $source) {
            $result = $this->resolver->resolve('3CX', 'a01AAA000000000AAA', [
                'salesforce_id' => 'a01AAA000000000AAA',
                'source' => $source,
                'is_deleted' => false,
            ], [
                'portal' => 'Coches.net',
                'origin' => 'portal',
                'source' => 'lead',
            ]);

            $this->assertSame(CallPortalNormalizer::UNCLASSIFIED, $result['visible']['portal']);
            $this->assertSame('unclassified', $result['visible']['source']);
            $this->assertFalse($result['debug']['preserved_historical']);
        }
    }

    public function test_missing_local_interest_preserves_existing_visible_classification_explicitly(): void
    {
        $result = $this->resolver->resolve('3CX', 'a01AAA000000000AAA', null, [
            'portal' => 'Coches.net',
            'origin' => 'portal',
            'source' => 'interest',
        ]);

        $this->assertSame('Coches.net', $result['visible']['portal']);
        $this->assertSame('historical_preserved', $result['visible']['source']);
        $this->assertSame('interest_not_local', $result['debug']['relationship_status']);
        $this->assertTrue($result['debug']['preserved_historical']);
    }

    public function test_supplied_interest_with_different_id_is_auditable_mismatch_and_never_used(): void
    {
        $result = $this->resolver->resolve('3CX', 'a01AAA000000000AAA', [
            'salesforce_id' => 'a01BBB000000000AAA',
            'source' => 'Coches.net',
            'is_deleted' => false,
        ]);

        $this->assertSame('interest_mismatch', $result['debug']['relationship_status']);
        $this->assertFalse($result['debug']['interest_matched']);
        $this->assertFalse($result['debug']['interest_source_used']);
        $this->assertSame(CallPortalNormalizer::UNCLASSIFIED, $result['visible']['portal']);
        $this->assertSame('unclassified', $result['visible']['source']);
    }
}
