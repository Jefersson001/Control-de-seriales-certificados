<?php

namespace App;

enum VehicleIdentificationRecordCertificateSerialClassification: string
{
    case Certified = 'certified';
    case Duplicate = 'duplicate';
    case Unexpected = 'unexpected';
    case Invalid = 'invalid';
}
