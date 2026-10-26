<?php

namespace Tests\Unit;

use App\Services\Ocr\MedicineLabelParser;
use PHPUnit\Framework\TestCase;

class MedicineLabelParserTest extends TestCase
{
    private MedicineLabelParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new MedicineLabelParser;
    }

    public function test_it_extracts_common_medicine_label_fields(): void
    {
        $result = $this->parser->parse(
            <<<'TEXT'
BIOGESIC
Paracetamol
500 mg
BATCH NO: BG24152
EXP: 08/2028
For relief of fever and mild pain
TEXT
        );

        $this->assertSame('BIOGESIC', $result['medicine_name']);
        $this->assertSame('500 mg', $result['dosage']);
        $this->assertSame('BG24152', $result['batch_no']);
        $this->assertSame('08/2028', $result['expiration_date']);
    }

    public function test_it_extracts_a_liquid_strength(): void
    {
        $result = $this->parser->parse(
            <<<'TEXT'
AMOXICILLIN
Powder for oral suspension
250 mg / 5 mL
LOT: AX-9002
EXPIRY DATE: 12-2027
TEXT
        );

        $this->assertSame('AMOXICILLIN', $result['medicine_name']);
        $this->assertSame('250 mg / 5 mL', $result['dosage']);
        $this->assertSame('AX-9002', $result['batch_no']);
        $this->assertSame('12-2027', $result['expiration_date']);
    }

    public function test_missing_fields_are_null_and_warned(): void
    {
        $result = $this->parser->parse('BIOGESIC');

        $this->assertSame('BIOGESIC', $result['medicine_name']);
        $this->assertNull($result['dosage']);
        $this->assertNull($result['batch_no']);
        $this->assertNull($result['expiration_date']);

        $this->assertContains('DOSAGE_NOT_FOUND', $result['warnings']);
        $this->assertContains('BATCH_NO_NOT_FOUND', $result['warnings']);
        $this->assertContains(
            'EXPIRATION_DATE_NOT_FOUND',
            $result['warnings'],
        );
    }

    public function test_packaging_warnings_are_not_used_as_the_medicine_name(): void
    {
        $result = $this->parser->parse(
            <<<'TEXT'
DO NOT ACCEPT IF SEAL IS BROKEN
IBUPROFEN
200 mg
LOT NO: IB2026A
EXP 2028-05
TEXT
        );

        $this->assertSame('IBUPROFEN', $result['medicine_name']);
    }
}