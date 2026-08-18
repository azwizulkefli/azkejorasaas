<?php
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="einvoice_template.csv"');

$headers = [
    'document_type',
    'sale_no',
    'customer_name',
    'customer_address',
    'customer_postcode',
    'customer_phone',
    'customer_email',
    'customer_ic',
    'customer_type',
    'sale_title',
    'sale_amount',
    'sale_tax',
    'total_amount',
    'sale_datetime'
];

echo implode(',', $headers) . "\n";

// Sample row
echo implode(',', [
    '01',
    'INV-2026-001',
    'Ahmad bin Abdullah',
    'No. 123, Jalan Example, Taman Test',
    '50000',
    '+60123456789',
    'ahmad@example.com',
    '850101-01-1234',
    'individual',
    'Consulting Services',
    '1000.00',
    '80.00',
    '1080.00',
    '2026-01-18 14:30:00'
]) . "\n";
