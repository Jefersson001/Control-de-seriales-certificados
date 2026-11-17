<?php

namespace App\Models;

use Database\Factories\NivComplianceCertificateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['uploaded_by', 'product_id', 'category', 'certificate_number', 'document_reference', 'company_name', 'company_rif', 'refeciv', 'serial_pattern', 'brand', 'model', 'series_version', 'manufacturing_years', 'model_year', 'country_of_origin', 'vehicle_class', 'vehicle_type', 'vehicle_use', 'wmi', 'iim', 'observations', 'issued_on', 'expires_on', 'has_erratum', 'original_series_version', 'erratum_reference', 'erratum_issued_on', 'erratum_notes', 'original_file_name', 'file_path', 'file_hash', 'extracted_text'])]
class NivComplianceCertificate extends Model
{
    /** @use HasFactory<NivComplianceCertificateFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = ['category' => 'nacional', 'has_erratum' => false];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return array<string, string> */
    public static function fieldLabels(): array
    {
        return [
            'certificate_number' => 'Número de constancia',
            'document_reference' => 'Referencia del documento',
            'company_name' => 'Empresa',
            'company_rif' => 'RIF de la empresa',
            'refeciv' => 'REFECIV',
            'serial_pattern' => 'Serial patrón (NIV)',
            'brand' => 'Marca',
            'model' => 'Modelo',
            'series_version' => 'Serie / versión',
            'manufacturing_years' => 'Años de fabricación',
            'model_year' => 'Año del modelo',
            'country_of_origin' => 'País de origen',
            'vehicle_class' => 'Clase',
            'vehicle_type' => 'Tipo',
            'vehicle_use' => 'Uso',
            'wmi' => 'IMF (WMI)',
            'iim' => 'IIF (IIM)',
            'issued_on' => 'Fecha de emisión',
            'expires_on' => 'Vigente hasta',
            'observations' => 'Observaciones',
            'original_series_version' => 'Serie / versión original',
            'erratum_reference' => 'Referencia de la fe de errata',
            'erratum_issued_on' => 'Fecha de la fe de errata',
            'erratum_notes' => 'Corrección indicada en la fe de errata',
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['model_year' => 'integer', 'has_erratum' => 'boolean', 'issued_on' => 'date', 'expires_on' => 'date', 'erratum_issued_on' => 'date'];
    }
}
