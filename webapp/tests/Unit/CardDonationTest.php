<?php

use PHPUnit\Framework\TestCase;

final class CardDonationTest extends TestCase
{
    /** @dataProvider donationValues */
    public function testOsmValueGetsPublicMessage(string $value, bool $available, string $message): void
    {
        $church = new \Eloquent\Church();
        $church->setAttribute('payment:credit_cards', $value);

        $this->assertSame($value, $church->cardDonation['value']);
        $this->assertSame($available, $church->cardDonation['available']);
        $this->assertSame($message, $church->cardDonation['message']);
    }

    public static function donationValues(): array
    {
        return [
            ['yes', true, 'Bankkártyás, digitális persely is elérhető.'],
            ['limited', true, 'Bankkártyás adományozás a sekrestyében vagy külön kérésre lehetséges.'],
            ['no', false, 'Csak készpénzes adományozás lehetséges.'],
        ];
    }

    public function testMissingOsmValueDoesNotCreatePublicClaim(): void
    {
        $church = new \Eloquent\Church();

        $this->assertNull($church->cardDonation['message']);
        $this->assertFalse($church->cardDonation['available']);
    }

    public function testEmptyOsmValueDoesNotCreatePublicClaim(): void
    {
        $church = new \Eloquent\Church();
        $church->setAttribute('payment:credit_cards', '');

        $this->assertNull($church->cardDonation['message']);
        $this->assertFalse($church->cardDonation['available']);
    }

    /*
     * #284: a szerkesztő legördülőjének címkéje és a templomlap nyilvános mondata
     * ugyanabból a listából jön. Ez a teszt bukik, ha valaki újra szétmásolja őket.
     */
    public function testEditorLabelsAreTheSameSentencesAsThePublicMessages(): void
    {
        $labels = \Eloquent\Church::CARD_DONATION_OPTIONS;

        $this->assertSame(['', 'yes', 'limited', 'no'], array_keys($labels));

        foreach (['yes', 'limited', 'no'] as $value) {
            $church = new \Eloquent\Church();
            $church->setAttribute('payment:credit_cards', $value);

            $this->assertSame($labels[$value], $church->cardDonation['message']);
        }
    }
}
