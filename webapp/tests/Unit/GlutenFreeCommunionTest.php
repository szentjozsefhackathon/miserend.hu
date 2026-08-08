<?php

use PHPUnit\Framework\TestCase;

final class GlutenFreeCommunionTest extends TestCase
{
    public function testHolidayAlwaysOptionsMapToYes(): void
    {
        foreach (['always', 'at_end', 'at_start'] as $value) {
            $this->assertSame('yes', \GlutenFreeCommunion::osmValue($value, 'no'));
        }
    }

    public function testBothImpossibleMapsToNo(): void
    {
        $this->assertSame('no', \GlutenFreeCommunion::osmValue('no', 'no'));
    }

    public function testConditionalOrPartialAvailabilityMapsToLimited(): void
    {
        $this->assertSame('limited', \GlutenFreeCommunion::osmValue('ask_sacristy', 'no'));
        $this->assertSame('limited', \GlutenFreeCommunion::osmValue('no', 'always'));
        $this->assertSame('limited', \GlutenFreeCommunion::osmValue('', 'bring_host'));
    }

    public function testMissingInformationDoesNotCreateOsmClaim(): void
    {
        $this->assertSame('', \GlutenFreeCommunion::osmValue('', ''));
        $this->assertFalse(\GlutenFreeCommunion::details('', '')['hasInformation']);
    }
}
