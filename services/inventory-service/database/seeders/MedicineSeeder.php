<?php

namespace Database\Seeders;

use App\Models\Medicine;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;

/**
 * Demo medicine catalog, one row per medicine (stock and price live in branch_inventory).
 * Names were taken from the previous PharmAssist database as a reference only.
 * Counseling texts are short, general, plain-language reminders, not medical advice.
 * Re-runnable: matched by the table's unique key (generic_name, brand_name, strength, dosage_form).
 */
class MedicineSeeder extends Seeder
{
    use RefusesProduction;

    /**
     * Keyed by a short code that BranchInventorySeeder uses to refer to the medicine.
     * brand_name '' means unbranded.
     *
     * @return array<string, array{generic_name: string, brand_name: string, category: string, strength: string, dosage_form: string, prescription_required: bool, counseling_summary: string}>
     */
    public static function medicines(): array
    {
        return [
            'amox500' => ['generic_name' => 'Amoxicillin', 'brand_name' => '', 'category' => 'Antibiotics', 'strength' => '500mg', 'dosage_form' => 'Capsule', 'prescription_required' => true,
                'counseling_summary' => 'An antibiotic for bacterial infections. Take it exactly as prescribed and finish the whole course, even if you feel better.'],
            'azithro500' => ['generic_name' => 'Azithromycin', 'brand_name' => '', 'category' => 'Antibiotics', 'strength' => '500mg', 'dosage_form' => 'Tablet', 'prescription_required' => true,
                'counseling_summary' => 'An antibiotic for bacterial infections. Take it as your doctor directed and complete the full course.'],
            'neozep' => ['generic_name' => 'Phenylephrine + Chlorphenamine + Paracetamol', 'brand_name' => 'Neozep', 'category' => 'Cold and Flu', 'strength' => '10mg/2mg/500mg', 'dosage_form' => 'Tablet', 'prescription_required' => false,
                'counseling_summary' => 'Relieves colds, stuffy nose and mild fever. It can make you sleepy, so avoid driving. Do not take it together with other paracetamol products.'],
            'mefenamic500' => ['generic_name' => 'Mefenamic Acid', 'brand_name' => '', 'category' => 'Pain Relievers', 'strength' => '500mg', 'dosage_form' => 'Capsule', 'prescription_required' => true,
                'counseling_summary' => 'Relieves pain and menstrual cramps. Take it with food to protect your stomach.'],
            'ibuprofen200' => ['generic_name' => 'Ibuprofen', 'brand_name' => 'Advil', 'category' => 'Pain Relievers', 'strength' => '200mg', 'dosage_form' => 'Capsule', 'prescription_required' => false,
                'counseling_summary' => 'Relieves pain, fever and swelling. Take it with food. Ask a pharmacist first if you have stomach ulcers or asthma.'],
            'paracetamol500' => ['generic_name' => 'Paracetamol', 'brand_name' => 'Biogesic', 'category' => 'Pain Relievers', 'strength' => '500mg', 'dosage_form' => 'Tablet', 'prescription_required' => false,
                'counseling_summary' => 'Relieves mild pain and fever. Do not take more than advised, and avoid other medicines that also contain paracetamol.'],
            'losartan50' => ['generic_name' => 'Losartan', 'brand_name' => '', 'category' => 'Hypertension', 'strength' => '50mg', 'dosage_form' => 'Tablet', 'prescription_required' => true,
                'counseling_summary' => 'Lowers blood pressure. Take it at the same time every day, even when you feel well.'],
            'telmisartan80' => ['generic_name' => 'Telmisartan', 'brand_name' => '', 'category' => 'Hypertension', 'strength' => '80mg', 'dosage_form' => 'Tablet', 'prescription_required' => true,
                'counseling_summary' => 'Lowers blood pressure. Take it once a day at the same time, even when you feel well.'],
            'amlodipine5' => ['generic_name' => 'Amlodipine', 'brand_name' => '', 'category' => 'Hypertension', 'strength' => '5mg', 'dosage_form' => 'Tablet', 'prescription_required' => true,
                'counseling_summary' => 'Lowers blood pressure. Take it once a day. It can cause swollen ankles; tell your doctor if that happens.'],
            'metformin500' => ['generic_name' => 'Metformin', 'brand_name' => '', 'category' => 'Diabetes', 'strength' => '500mg', 'dosage_form' => 'Tablet', 'prescription_required' => true,
                'counseling_summary' => 'Helps control blood sugar in type 2 diabetes. Take it with meals to reduce stomach upset.'],
            'atorva20' => ['generic_name' => 'Atorvastatin', 'brand_name' => '', 'category' => 'Cholesterol', 'strength' => '20mg', 'dosage_form' => 'Tablet', 'prescription_required' => true,
                'counseling_summary' => 'Lowers cholesterol. Take it once a day. Tell your doctor if you get unexplained muscle pain.'],
            'salbutamol' => ['generic_name' => 'Salbutamol', 'brand_name' => 'Ventolin', 'category' => 'Respiratory', 'strength' => '100mcg/dose', 'dosage_form' => 'Inhaler', 'prescription_required' => true,
                'counseling_summary' => 'Relieves wheezing and shortness of breath. Shake the inhaler before use. Get urgent help if your breathing does not improve.'],
            'cetirizine10' => ['generic_name' => 'Cetirizine', 'brand_name' => '', 'category' => 'Allergy', 'strength' => '10mg', 'dosage_form' => 'Tablet', 'prescription_required' => false,
                'counseling_summary' => 'Relieves allergy symptoms such as sneezing and itching. It can make you sleepy.'],
            'loperamide2' => ['generic_name' => 'Loperamide', 'brand_name' => '', 'category' => 'Digestive', 'strength' => '2mg', 'dosage_form' => 'Capsule', 'prescription_required' => false,
                'counseling_summary' => 'Slows down diarrhea. Drink plenty of fluids. See a doctor if it lasts more than 2 days or you have a fever or blood in your stool.'],
            'omeprazole20' => ['generic_name' => 'Omeprazole', 'brand_name' => '', 'category' => 'Digestive', 'strength' => '20mg', 'dosage_form' => 'Capsule', 'prescription_required' => true,
                'counseling_summary' => 'Reduces stomach acid for heartburn and ulcers. Take it before a meal, usually in the morning.'],
            'vitc500' => ['generic_name' => 'Ascorbic Acid (Vitamin C)', 'brand_name' => '', 'category' => 'Vitamins', 'strength' => '500mg', 'dosage_form' => 'Tablet', 'prescription_required' => false,
                'counseling_summary' => 'A vitamin supplement that supports your immune system. Take it with water; large doses can upset the stomach.'],
        ];
    }

    public function run(): void
    {
        $this->refuseInProduction();

        foreach (self::medicines() as $row) {
            Medicine::updateOrCreate(
                [
                    'generic_name' => $row['generic_name'],
                    'brand_name' => $row['brand_name'],
                    'strength' => $row['strength'],
                    'dosage_form' => $row['dosage_form'],
                ],
                [
                    'category' => $row['category'],
                    'prescription_required' => $row['prescription_required'],
                    'counseling_summary' => $row['counseling_summary'],
                ]
            );
        }
    }
}
