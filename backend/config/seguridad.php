<?php

return [
    'aws_region' => env('SECURITY_AWS_REGION', 'us-east-1'),
    'kms_key_id' => env('SECURITY_KMS_KEY_ID'),
    'backup_kms_key_id' => env('BACKUP_KMS_KEY_ID'),
    'kms_rotation_days' => 90,
    'secrets_manager_id' => env('SECURITY_SECRETS_MANAGER_ID'),
    'backup' => [
        'retention_days' => 30,
        'interval_minutes' => 60,
        'rpo_minutes' => 60,
        'rto_minutes' => 240,
        'owner' => 'DevOps',
    ],
    // Requisitos de infraestructura; estos valores no acreditan su implementación.
    'volume_encryption' => 'LUKS',
    'sensitive_fields' => ['usuarios.numero_doc_identidad', 'familiares.numero_doc_identidad'],
];
