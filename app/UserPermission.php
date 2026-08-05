<?php

namespace App;

enum UserPermission: string
{
    case ViewUsers = 'users.view';
    case CreateUsers = 'users.create';
    case EditUsers = 'users.edit';
    case DeleteUsers = 'users.delete';
    case ViewCertificates = 'certificates.view';
    case ImportCertificates = 'certificates.import';
    case DeleteCertificates = 'certificates.delete';
    case ViewCertificateCorrections = 'certificate-corrections.view';
    case ViewProducts = 'products.view';
    case CreateProducts = 'products.create';
    case EditProducts = 'products.edit';
    case DeleteProducts = 'products.delete';
    case ViewMotorcycleSerialRequests = 'motorcycle-serial-requests.view';
    case CreateMotorcycleSerialRequests = 'motorcycle-serial-requests.create';
    case EditMotorcycleSerialRequests = 'motorcycle-serial-requests.edit';
    case DeleteMotorcycleSerialRequests = 'motorcycle-serial-requests.delete';
    case DeleteCompletedMotorcycleSerialRequests = 'motorcycle-serial-requests.delete-completed';
    case ViewVehicleIdentificationRecord = 'vehicle-identification-record.view';
    case ViewVehicleIdentificationRecordManagement = 'vehicle-identification-record-management.view';
    case EditVehicleIdentificationRecordManagement = 'vehicle-identification-record-management.edit';

    public function label(): string
    {
        return match ($this) {
            self::ViewUsers => 'Consultar usuarios',
            self::CreateUsers => 'Crear usuarios',
            self::EditUsers => 'Editar usuarios',
            self::DeleteUsers => 'Eliminar usuarios',
            self::ViewCertificates => 'Consultar y exportar Maestro Seriales Certificados',
            self::ImportCertificates => 'Importar data en Maestro Seriales Certificados',
            self::DeleteCertificates => 'Eliminar registros de Maestro Seriales Certificados',
            self::ViewCertificateCorrections => 'Corrección Maestro Seriales Certificados',
            self::ViewProducts => 'Consultar productos',
            self::CreateProducts => 'Crear productos',
            self::EditProducts => 'Editar productos',
            self::DeleteProducts => 'Eliminar productos',
            self::ViewMotorcycleSerialRequests => 'Solicitud de seriales de motos',
            self::CreateMotorcycleSerialRequests => 'Crear solicitudes de seriales de motos',
            self::EditMotorcycleSerialRequests => 'Editar solicitudes de seriales de motos',
            self::DeleteMotorcycleSerialRequests => 'Eliminar solicitudes de seriales de motos',
            self::DeleteCompletedMotorcycleSerialRequests => 'Eliminar solicitudes de seriales de motos en estado Hecho',
            self::ViewVehicleIdentificationRecord => 'Constancia de Registro de Número de Identificación de Vehículo',
            self::ViewVehicleIdentificationRecordManagement => 'Gestión de Constancia de Registro de Número de Identificación de Vehículo',
            self::EditVehicleIdentificationRecordManagement => 'Editar Gestión de Constancia de Registro de Número de Identificación de Vehículo',
        };
    }
}
