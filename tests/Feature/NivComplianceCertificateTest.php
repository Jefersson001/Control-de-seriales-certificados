<?php

use App\Actions\NivComplianceCertificates\ExtractCompliancePdfText;
use App\Actions\NivComplianceCertificates\ParseComplianceCertificate;
use App\Models\NivComplianceCertificate;
use App\Models\Product;
use App\Models\User;
use App\UserPermission;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('compliance documents extract vehicle company dates and certificate fields', function () {
    $result = app(ParseComplianceCertificate::class)->parse([['page' => 1, 'text' => complianceCertificateText()]]);

    expect($result['fields'])
        ->certificate_number->toBe('28684')
        ->document_reference->toBe('IO CT R056 180 8')
        ->company_name->toBe('CORPORACION EJEMPLO C.A')
        ->company_rif->toBe('J-31447419-7')
        ->refeciv->toBe('AGMD7056')
        ->serial_pattern->toBe('LD3THK5BXV1000001')
        ->brand->toBe('ZONTES')
        ->model->toBe('150 X')
        ->series_version->toBe('150')
        ->manufacturing_years->toBe('2026, 2027')
        ->model_year->toBe(2027)
        ->country_of_origin->toBe('CHINA')
        ->vehicle_class->toBe('MOTO')
        ->vehicle_type->toBe('SCOOTER')
        ->vehicle_use->toBe('PARTICULAR')
        ->wmi->toBe('LD3')
        ->iim->toBe('N/A')
        ->issued_on->toBe('2026-10-26')
        ->expires_on->toBe('2027-12-31')
        ->observations->toBe('N/A')
        ->has_erratum->toBeFalse();
});

test('the erratum takes precedence regardless of page order', function (bool $reverse) {
    $pages = [
        ['page' => 1, 'text' => complianceCertificateText(true)],
        ['page' => 2, 'text' => "FE DE ERRATA PARA LA CONSTANCIA DE CUMPLIMIENTO\nCaracas, 26 de Octubre de 2026\nINTT/GGTT 24479 2026\nEmitida por este despacho en fecha 19 de Octubre del año 2026.\nDonde dice SERIE/VERSION: 368 E, debe decir: 368 (E, M, G). La presente tiene validez.\nSERIE/VERSION 368 (E, M, G) TIPO SCOOTER"],
    ];
    $result = app(ParseComplianceCertificate::class)->parse($reverse ? array_reverse($pages) : $pages);

    expect($result['fields'])
        ->has_erratum->toBeTrue()
        ->model->toBe('368')
        ->series_version->toBe('368 (E, M, G)')
        ->original_series_version->toBe('368E')
        ->serial_pattern->toBe('LD3THR6B0V1000001')
        ->erratum_issued_on->toBe('2026-10-26')
        ->issued_on->toBe('2026-10-19')
        ->erratum_reference->toBe('INTT/GGTT 24479 2026');
})->with([false, true]);

test('unreadable fields remain empty and incomplete dates are not invented', function () {
    $text = str_replace(['Dado en la Ciudad de Caracas, a los 26 dias del mes de OCTUBRE de 2026', 'MARCA ZONTES USO PARTICULAR'], ['Dado en la Ciudad de Caracas, a los 2 dias del mes de OCTUBRE de 2026', 'USO PARTICULAR'], complianceCertificateText());
    $result = app(ParseComplianceCertificate::class)->parse([['page' => 1, 'text' => $text]]);

    expect($result['fields']['issued_on'])->toBeNull()
        ->and($result['fields']['brand'])->toBeNull()
        ->and($result['missing_fields'])->toContain('issued_on', 'brand');
});

test('unrelated documents cannot be mistaken for a compliance certificate', function () {
    expect(fn () => app(ParseComplianceCertificate::class)->parse([['page' => 1, 'text' => 'Factura comercial de una motocicleta']]))->toThrow(RuntimeException::class);
});

test('payment reference numbers are not extracted as a vehicle serial', function () {
    $text = str_replace('LD3THK5BXV1000001', 'NO LEGIBLE', complianceCertificateText())."\n26102113050559785";
    $result = app(ParseComplianceCertificate::class)->parse([['page' => 1, 'text' => $text]]);

    expect($result['fields']['serial_pattern'])->toBeNull()
        ->and($result['missing_fields'])->toContain('serial_pattern');
});

test('OCR letter and number confusions can be resolved with the VIN check digit', function () {
    $text = str_replace('LD3THK5BXV1000001', 'LD3THK5BXVLOOOOOL', complianceCertificateText());
    $result = app(ParseComplianceCertificate::class)->parse([['page' => 1, 'text' => $text]]);

    expect($result['fields']['serial_pattern'])->toBe('LD3THK5BXV1000001');
});

test('authorized users can extract review save and download a compliance certificate', function (string $category) {
    Storage::fake('local');
    $user = User::factory()->create(['permissions' => [UserPermission::ViewNivComplianceCertificates->value, UserPermission::CreateNivComplianceCertificates->value]]);
    $this->actingAs($user);
    $pdf = complianceCertificatePdf(complianceCertificateText());

    Livewire::test('niv-compliance-certificate-form')
        ->assertSeeHtmlInOrder(['id="compliance-category"', 'id="compliance-pdf"', 'role="tablist"', 'role="tabpanel"'])
        ->assertSeeHtml('aria-label="Secciones de la constancia"')
        ->set('category', $category)
        ->set('pdfFile', UploadedFile::fake()->createWithContent('Constancia.pdf', $pdf))
        ->call('analyze')
        ->assertHasNoErrors()
        ->assertSet('hasAnalyzed', true)
        ->assertSet('fields.model', '150 X')
        ->set('fields.company_name', 'EMPRESA REVISADA C.A')
        ->set('fields.observations', 'Información revisada')
        ->set('hasErratum', true)
        ->set('fields.erratum_notes', 'Fe de errata revisada')
        ->call('save')
        ->assertHasNoErrors();

    $certificate = NivComplianceCertificate::query()->sole();
    expect($certificate)->category->toBe($category)->brand->toBe('ZONTES')->observations->toBe('Información revisada')
        ->company_name->toBe('EMPRESA REVISADA C.A')->has_erratum->toBeTrue()->erratum_notes->toBe('Fe de errata revisada')
        ->uploaded_by->toBe($user->id)->extracted_text->toContain('CONSTANCIA DE CUMPLIMIENTO');
    Storage::disk('local')->assertExists($certificate->file_path);
    $this->get(route('niv_compliance_certificates.download', $certificate))->assertSuccessful()->assertDownload('Constancia.pdf');
})->with(['nacional', 'importados']);

test('uploading another PDF requires extraction again', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $upload = UploadedFile::fake()->createWithContent('Constancia.pdf', complianceCertificatePdf(complianceCertificateText()));

    Livewire::test('niv-compliance-certificate-form')->set('pdfFile', $upload)->call('analyze')
        ->assertSet('hasAnalyzed', true)
        ->set('pdfFile', $upload)
        ->assertSet('hasAnalyzed', false)
        ->call('save')->assertHasErrors('pdfFile');

    expect(NivComplianceCertificate::query()->count())->toBe(0);
});

test('duplicate PDF uploads do not create another record', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $pdf = complianceCertificatePdf(complianceCertificateText());
    NivComplianceCertificate::factory()->create(['file_hash' => hash('sha256', $pdf)]);

    Livewire::test('niv-compliance-certificate-form')
        ->set('pdfFile', UploadedFile::fake()->createWithContent('Repetido.pdf', $pdf))
        ->call('analyze')->call('save')->assertHasErrors('pdfFile');

    expect(NivComplianceCertificate::query()->count())->toBe(1);
    Storage::disk('local')->assertDirectoryEmpty('niv-compliance-certificates');
});

test('users can review existing records without replacing the PDF', function () {
    $this->actingAs(User::factory()->create(['permissions' => [UserPermission::ViewNivComplianceCertificates->value, UserPermission::EditNivComplianceCertificates->value]]));
    $certificate = NivComplianceCertificate::factory()->create();

    Livewire::test('niv-compliance-certificate-form', ['certificateId' => $certificate->id])
        ->set('fields.model', '150 X revisado')->call('save')->assertHasNoErrors();

    expect($certificate->refresh()->model)->toBe('150 X revisado');
});

test('view only users cannot create edit delete or analyze documents', function () {
    $this->actingAs(User::factory()->create(['permissions' => [UserPermission::ViewNivComplianceCertificates->value]]));
    $certificate = NivComplianceCertificate::factory()->create();

    $this->get(route('niv_compliance_certificates.index'))->assertSuccessful()->assertDontSee('Nueva constancia');
    $this->get(route('niv_compliance_certificates.create'))->assertForbidden();
    Livewire::test('niv-compliance-certificate-form', ['certificateId' => $certificate->id])->call('save')->assertForbidden();
    Livewire::test('niv-compliance-certificate-form', ['certificateId' => $certificate->id])->call('analyze')->assertForbidden();
    Livewire::test('niv-compliance-certificate-list')->call('confirmDeletion', $certificate->id)->assertForbidden();
});

test('users without permission cannot access documents or their downloads', function () {
    $certificate = NivComplianceCertificate::factory()->create();
    $this->get(route('niv_compliance_certificates.index'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create());
    $this->get(route('niv_compliance_certificates.index'))->assertForbidden();
    $this->get(route('niv_compliance_certificates.edit', $certificate))->assertForbidden();
    $this->get(route('niv_compliance_certificates.download', $certificate))->assertForbidden();
});

test('compliance records are searchable and filterable by category', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $national = NivComplianceCertificate::factory()->create(['category' => 'nacional', 'certificate_number' => 'NACIONAL-001', 'model' => '150 X']);
    $imported = NivComplianceCertificate::factory()->create(['category' => 'importados', 'certificate_number' => 'IMPORTADO-001', 'model' => '368']);

    Livewire::test('niv-compliance-certificate-list')->assertSee($national->certificate_number)->assertSee($imported->certificate_number)
        ->call('setPage', 2)->set('categoryFilter', 'importados')->assertSet('paginators.page', 1)
        ->assertSee($imported->certificate_number)->assertDontSee($national->certificate_number)
        ->set('search', '150 X')->assertDontSee($imported->certificate_number)
        ->set('categoryFilter', 'all')->assertSee($national->certificate_number)
        ->set('categoryFilter', 'invalida')->assertSet('categoryFilter', 'all');
});

test('the product filter loads from the URL and combines with category and search', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $product = Product::factory()->create();
    $otherProduct = Product::factory()->create();
    $national = NivComplianceCertificate::factory()->create(['product_id' => $product->id, 'category' => 'nacional', 'certificate_number' => 'PRODUCTO-NACIONAL']);
    $imported = NivComplianceCertificate::factory()->create(['product_id' => $product->id, 'category' => 'importados', 'certificate_number' => 'PRODUCTO-IMPORTADO']);
    $other = NivComplianceCertificate::factory()->create(['product_id' => $otherProduct->id, 'certificate_number' => 'OTRO-PRODUCTO']);
    $unlinked = NivComplianceCertificate::factory()->create(['certificate_number' => 'SIN-RELACION']);

    Livewire::withQueryParams(['product' => (string) $product->id])->test('niv-compliance-certificate-list')
        ->assertSet('productFilter', (string) $product->id)->assertSee($national->certificate_number)->assertSee($imported->certificate_number)
        ->assertDontSee($other->certificate_number)->assertDontSee($unlinked->certificate_number)
        ->set('categoryFilter', 'importados')->assertSee($imported->certificate_number)->assertDontSee($national->certificate_number)
        ->set('search', 'INEXISTENTE')->assertDontSee($imported->certificate_number)
        ->set('search', '')->set('categoryFilter', 'all')->call('setPage', 2)
        ->set('productFilter', (string) $otherProduct->id)->assertSet('paginators.page', 1)
        ->assertSee($other->certificate_number)->assertDontSee($national->certificate_number)
        ->set('productFilter', '')->assertSee($national->certificate_number)->assertSee($unlinked->certificate_number);
});

test('an invalid or deleted product filter never shows unrelated certificates', function (string $productFilter) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $certificate = NivComplianceCertificate::factory()->create(['product_id' => Product::factory()->create()->id]);

    Livewire::withQueryParams(['product' => $productFilter])->test('niv-compliance-certificate-list')
        ->assertDontSee($certificate->certificate_number)->assertSee('Producto no disponible');
})->with(['invalid', '999999999', '-1', '0']);

test('deleting a compliance record requires confirmation and removes its PDF', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $certificate = NivComplianceCertificate::factory()->create();
    Storage::disk('local')->put($certificate->file_path, '%PDF-1.4');

    Livewire::test('niv-compliance-certificate-list')->call('confirmDeletion', $certificate->id)
        ->assertSee('¿Eliminar esta constancia?')->call('deleteCertificate');

    $this->assertModelMissing($certificate);
    Storage::disk('local')->assertMissing($certificate->file_path);
});

test('the module is visible in navigation and management permissions grant consultation', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $this->get(route('dashboard'))->assertSuccessful()->assertSee(route('niv_compliance_certificates.index'));
    $user = User::factory()->create();

    Livewire::test('user-manager')->call('editUser', $user->id)
        ->set('permissions', [UserPermission::CreateNivComplianceCertificates->value])->call('save')->assertHasNoErrors();

    expect($user->refresh()->permissions)->toContain(UserPermission::ViewNivComplianceCertificates->value);
});

test('scanned PDFs are processed locally when native text is absent', function () {
    if (PHP_OS_FAMILY !== 'Windows') {
        $this->markTestSkipped('Windows OCR only.');
    }
    Storage::fake('local');
    Process::fake(['*' => Process::result(output: json_encode(['pages' => [['page' => 1, 'text' => complianceCertificateText()]]]))]);
    $pdf = new FPDF;
    $pdf->AddPage();
    $file = UploadedFile::fake()->createWithContent('Escaneado.pdf', $pdf->Output('S'));

    $pages = app(ExtractCompliancePdfText::class)->handle($file->getRealPath());

    expect($pages[0]['text'])->toContain('ZONTES');
    Process::assertRan(fn ($process): bool => in_array('powershell.exe', $process->command, true) && str_ends_with(end($process->command), '.pdf'));
    Storage::disk('local')->assertDirectoryEmpty('niv-compliance-ocr');
});

test('unreadable scan errors are shown without creating a record', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $pdf = new FPDF;
    $pdf->AddPage();
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'OCR unavailable')]);

    Livewire::test('niv-compliance-certificate-form')
        ->set('pdfFile', UploadedFile::fake()->createWithContent('Escaneado.pdf', $pdf->Output('S')))
        ->call('analyze')->assertHasErrors('pdfFile')->assertSet('hasAnalyzed', false);

    expect(NivComplianceCertificate::query()->count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('niv-compliance-ocr');
});

test('replacing a PDF keeps the reviewed data and removes the previous file', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $certificate = NivComplianceCertificate::factory()->create();
    $oldPath = $certificate->file_path;
    Storage::disk('local')->put($oldPath, '%PDF-1.4 old');

    Livewire::test('niv-compliance-certificate-form', ['certificateId' => $certificate->id])
        ->set('pdfFile', UploadedFile::fake()->createWithContent('Nueva.pdf', complianceCertificatePdf(complianceCertificateText())))
        ->call('analyze')->call('save')->assertHasNoErrors();

    expect(NivComplianceCertificate::query()->count())->toBe(1)
        ->and($certificate->refresh()->original_file_name)->toBe('Nueva.pdf');
    Storage::disk('local')->assertExists($certificate->file_path);
    Storage::disk('local')->assertMissing($oldPath);
});

test('invalid reviewed fields cannot be saved', function (string $field, string $value) {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    $component = Livewire::test('niv-compliance-certificate-form')
        ->set('pdfFile', UploadedFile::fake()->createWithContent('Constancia.pdf', complianceCertificatePdf(complianceCertificateText())))
        ->call('analyze')->set($field, $value)->call('save')->assertHasErrors($field);

    if (str_starts_with($field, 'fields.')) {
        $component->assertSee('Revisar')->assertSee('Revisa los campos de las pestañas marcadas antes de guardar.');
    }

    expect(NivComplianceCertificate::query()->count())->toBe(0);
})->with([
    ['fields.serial_pattern', 'NIV-INVALIDO'],
    ['fields.expires_on', '2020-12-31'],
    ['category', 'otra'],
]);

test('the Windows OCR engine reads an actual scanned PDF locally', function (bool $webEnvironment) {
    if (PHP_OS_FAMILY !== 'Windows') {
        $this->markTestSkipped('Requires the local Windows OCR engine.');
    }
    Storage::fake('local');
    Storage::disk('local')->makeDirectory('ocr-fixtures');
    $imagePath = Storage::disk('local')->path('ocr-fixtures/constancia.png');
    $encodedPath = base64_encode($imagePath);
    $encodedLines = base64_encode(json_encode(explode("\n", complianceCertificateText()), JSON_UNESCAPED_UNICODE));
    $script = <<<'POWERSHELL'
Add-Type -AssemblyName System.Drawing
$path = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('PATH_DATA'))
$lines = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('LINE_DATA')) | ConvertFrom-Json
$image = [Drawing.Bitmap]::new(1900, 1800)
$graphics = [Drawing.Graphics]::FromImage($image)
$font = [Drawing.Font]::new('Arial', 20)
try {
    $graphics.Clear([Drawing.Color]::White)
    $graphics.TextRenderingHint = [Drawing.Text.TextRenderingHint]::AntiAliasGridFit
    $position = 30
    foreach ($line in $lines) {
        $graphics.DrawString($line, $font, [Drawing.Brushes]::Black, 35, $position)
        $position += 65
    }
    $image.Save($path, [Drawing.Imaging.ImageFormat]::Png)
} finally { $font.Dispose(); $graphics.Dispose(); $image.Dispose() }
POWERSHELL;
    $script = str_replace(['PATH_DATA', 'LINE_DATA'], [$encodedPath, $encodedLines], $script);
    $render = Process::timeout(20)->run(['powershell.exe', '-NoProfile', '-NonInteractive', '-EncodedCommand', base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'))]);
    expect($render->successful())->toBeTrue($render->errorOutput());
    $pdf = new FPDF;
    $pdf->AddPage();
    $pdf->Image($imagePath, 0, 0, 210, 297);
    $upload = UploadedFile::fake()->createWithContent('Constancia escaneada.pdf', $pdf->Output('S'));

    $server = $_SERVER;
    $environment = $_ENV;
    try {
        if ($webEnvironment) {
            $_SERVER = ['APP_ENV' => 'testing', 'REQUEST_METHOD' => 'POST'];
            $_ENV = ['APP_ENV' => 'testing'];
        }

        $result = app(ParseComplianceCertificate::class)->handle($upload->getRealPath());
    } finally {
        $_SERVER = $server;
        $_ENV = $environment;
    }

    expect($result['fields'])->brand->toBe('ZONTES')->model->toBe('150 X')
        ->certificate_number->toBe('28684')->serial_pattern->toBe('LD3THK5BXV1000001', $result['text']);
})->with(['console' => false, 'web request' => true]);

test('oversized and non PDF documents cannot be analyzed', function (string $extension, int $size) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    Livewire::test('niv-compliance-certificate-form')
        ->set('pdfFile', UploadedFile::fake()->create('documento.'.$extension, $size))
        ->call('analyze')->assertHasErrors('pdfFile');

    expect(NivComplianceCertificate::query()->count())->toBe(0);
})->with([['txt', 1], ['pdf', 20481]]);

test('PDF documents with more than ten pages are rejected before OCR', function () {
    $pdf = new FPDF;
    for ($index = 0; $index < 11; $index++) {
        $pdf->AddPage();
    }
    $file = UploadedFile::fake()->createWithContent('Demasiadas paginas.pdf', $pdf->Output('S'));

    expect(fn () => app(ExtractCompliancePdfText::class)->handle($file->getRealPath()))->toThrow(RuntimeException::class, '10 páginas');
});

test('a unique product is detected by category brand and model code and the relationship is saved', function (string $brand, string $category, string $serial, string $code) {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $product = Product::factory()->create(['name' => 'Descripción diferente a la constancia', 'brand' => $brand, 'category' => $category, 'niv' => $code]);
    Product::factory()->create(['category' => $category === 'nacional' ? 'importados' : 'nacional', 'brand' => $brand, 'niv' => $code]);
    Product::factory()->create(['category' => $category, 'brand' => 'morini', 'niv' => $code]);
    $text = str_replace(['MARCA ZONTES', 'LD3THK5BXV1000001'], ['MARCA '.strtoupper($brand), $serial], complianceCertificateText());

    Livewire::test('niv-compliance-certificate-form')
        ->assertSeeHtmlInOrder(['id="compliance-category"', 'id="compliance-product"', 'id="compliance-pdf"'])
        ->set('category', $category)
        ->set('pdfFile', UploadedFile::fake()->createWithContent('Constancia.pdf', complianceCertificatePdf($text)))
        ->call('analyze')->assertHasNoErrors()->assertSet('productId', $product->id)->assertSet('productMatchStatus', 'matched')
        ->call('save')->assertHasNoErrors();

    $certificate = NivComplianceCertificate::query()->sole();
    expect($certificate->product->is($product))->toBeTrue()
        ->and($product->nivComplianceCertificates->sole()->is($certificate))->toBeTrue();
    Livewire::test('niv-compliance-certificate-form', ['certificateId' => $certificate->id])->assertSet('productId', $product->id);
})->with([
    ['zontes', 'importados', 'LD3THK5BXV1000001', 'LD3THK5B'],
    ['bera', 'nacional', '8XGRT1234V1000001', 'RT'],
]);

test('matching names do not override a different Zontes model code', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    Product::factory()->create(['name' => '150 X', 'category' => 'importados', 'brand' => 'zontes', 'niv' => 'LD3THR6B']);

    Livewire::test('niv-compliance-certificate-form')->set('category', 'importados')
        ->set('pdfFile', UploadedFile::fake()->createWithContent('Constancia.pdf', complianceCertificatePdf(complianceCertificateText())))
        ->call('analyze')->assertSet('productId', null)->assertSet('productMatchStatus', 'missing')
        ->assertSee('No se encontró un producto coincidente en esta categoría.');
});

test('ambiguous model codes require an explicit product selection and cannot create duplicates', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $products = Product::factory()->count(2)->create(['category' => 'importados', 'brand' => 'zontes', 'niv' => 'LD3THK5B']);

    Livewire::test('niv-compliance-certificate-form')->set('category', 'importados')
        ->set('pdfFile', UploadedFile::fake()->createWithContent('Constancia.pdf', complianceCertificatePdf(complianceCertificateText())))
        ->call('analyze')->assertSet('productId', null)->assertSet('productMatchStatus', 'ambiguous')
        ->call('createRelatedProduct')->assertSet('productMatchStatus', 'ambiguous')
        ->set('productId', $products->first()->id)->call('save')->assertHasNoErrors();

    expect(Product::query()->count())->toBe(2)
        ->and(NivComplianceCertificate::query()->sole()->product_id)->toBe($products->first()->id);
});

test('a product can be reviewed created and linked from the certificate without duplicate creation', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $component = Livewire::test('niv-compliance-certificate-form')->set('category', 'importados')
        ->set('pdfFile', UploadedFile::fake()->createWithContent('Constancia.pdf', complianceCertificatePdf(complianceCertificateText())))
        ->call('analyze')->assertSet('productMatchStatus', 'missing')
        ->call('prepareProductCreation')->assertSet('showCreateProduct', true)->assertSet('newProductName', '150 X')
        ->set('newProductName', 'Zontes 150 X revisada')->call('createRelatedProduct')->assertHasNoErrors()->assertSet('productMatchStatus', 'created');

    $product = Product::query()->sole();
    expect($product)->name->toBe('Zontes 150 X revisada')->brand->toBe('zontes')->category->toBe('importados')
        ->first_value->toBe('LD3')->second_value->toBe('THK5B')->niv->toBe('LD3THK5B');
    $component->assertSet('productId', $product->id)->call('createRelatedProduct')->assertSet('productId', $product->id)
        ->call('save')->assertHasNoErrors();
    expect(Product::query()->count())->toBe(1)
        ->and(NivComplianceCertificate::query()->sole()->product_id)->toBe($product->id);
});

test('product creation retains the review form when a description already exists', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    Product::factory()->create(['name' => '150 X', 'category' => 'nacional']);

    Livewire::test('niv-compliance-certificate-form')->set('category', 'importados')
        ->set('pdfFile', UploadedFile::fake()->createWithContent('Constancia.pdf', complianceCertificatePdf(complianceCertificateText())))
        ->call('analyze')->call('prepareProductCreation')->call('createRelatedProduct')
        ->assertHasErrors('newProductName')->assertSet('showCreateProduct', true)
        ->set('newProductName', 'Zontes 150 X importada')->call('createRelatedProduct')->assertHasNoErrors();
    expect(Product::query()->count())->toBe(2);
});

test('users without product creation permission cannot create products through a certificate', function () {
    $this->actingAs(User::factory()->create(['permissions' => [UserPermission::CreateNivComplianceCertificates->value]]));
    Livewire::test('niv-compliance-certificate-form')->call('prepareProductCreation')->assertForbidden();
    Livewire::test('niv-compliance-certificate-form')->call('createRelatedProduct')->assertForbidden();
    expect(Product::query()->count())->toBe(0);
});

test('product creation requires extraction for a new certificate', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    Livewire::test('niv-compliance-certificate-form')->call('createRelatedProduct')->assertHasErrors('productId');
    expect(Product::query()->count())->toBe(0);
});

test('incompatible or missing products cannot be linked to a certificate', function (array $attributes, bool $deleted) {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $certificate = NivComplianceCertificate::factory()->create(['category' => 'importados']);
    $product = Product::factory()->create(array_merge(['category' => 'importados', 'brand' => 'zontes', 'niv' => 'LD3THK5B'], $attributes));
    $productId = $product->id;
    if ($deleted) {
        $product->delete();
    }
    Livewire::test('niv-compliance-certificate-form', ['certificateId' => $certificate->id])
        ->set('productId', $productId)->call('save')->assertHasErrors('productId');
    expect($certificate->refresh()->product_id)->toBeNull();
})->with([
    [['category' => 'nacional'], false],
    [['brand' => 'bera'], false],
    [['niv' => 'LD3THR6B'], false],
    [[], true],
]);

test('changing category or serial recomputes the product without losing certificate fields', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $national = Product::factory()->create(['category' => 'nacional', 'brand' => 'zontes', 'niv' => 'LD3THK5B']);
    $imported = Product::factory()->create(['category' => 'importados', 'brand' => 'zontes', 'niv' => 'LD3THK5B']);
    $certificate = NivComplianceCertificate::factory()->create(['category' => 'nacional', 'product_id' => $national->id]);

    Livewire::test('niv-compliance-certificate-form', ['certificateId' => $certificate->id])
        ->set('category', 'importados')->assertSet('productId', $imported->id)->assertSet('fields.serial_pattern', 'LD3THK5BXV1000001')
        ->set('fields.serial_pattern', 'LD3THR6B0V1000001')->assertSet('productId', null)->assertSet('productMatchStatus', 'missing')
        ->assertSet('fields.model', '150 X');
});

test('a related product can be removed and deleting it preserves the certificate', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $product = Product::factory()->create(['category' => 'importados', 'brand' => 'zontes', 'niv' => 'LD3THK5B']);
    $certificate = NivComplianceCertificate::factory()->create(['category' => 'importados', 'product_id' => $product->id]);
    Livewire::test('niv-compliance-certificate-form', ['certificateId' => $certificate->id])
        ->set('productId', null)->call('save')->assertHasNoErrors();
    expect($certificate->refresh()->product_id)->toBeNull();
    $certificate->update(['product_id' => $product->id]);
    $product->delete();
    expect($certificate->refresh()->product_id)->toBeNull();
    $this->assertModelExists($certificate);
});

test('Morini certificates create and identify products using their eight character prefix', function (string $model, string $serial) {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $text = str_replace(['MARCA ZONTES', 'MODELO 150 X', 'LD3THK5BXV1000001'], ['MARCA MORINI', 'MODELO '.$model, $serial], complianceCertificateText());
    $pdf = complianceCertificatePdf($text);
    Livewire::test('niv-compliance-certificate-form')->set('category', 'importados')
        ->set('pdfFile', UploadedFile::fake()->createWithContent('Constancia Morini.pdf', $pdf))->call('analyze')
        ->assertSet('productMatchStatus', 'missing')->call('prepareProductCreation')->assertSet('newProductName', $model)
        ->call('createRelatedProduct')->assertHasNoErrors()->call('save')->assertHasNoErrors();
    $product = Product::query()->sole();
    $certificate = NivComplianceCertificate::query()->sole();
    expect($product)->brand->toBe('morini')->category->toBe('importados')->first_value->toBe('HL4')
        ->second_value->toBe(substr($serial, 3, 5))->niv->toBe(substr($serial, 0, 8));
    expect($certificate->product_id)->toBe($product->id);
    Livewire::test('niv-compliance-certificate-form', ['certificateId' => $certificate->id])
        ->set('pdfFile', UploadedFile::fake()->createWithContent('Constancia Morini.pdf', $pdf))->call('analyze')
        ->assertSet('productMatchStatus', 'matched')->assertSet('productId', $product->id)->call('save')->assertHasNoErrors();
    expect(Product::query()->count())->toBe(1);
})->with([
    ['ALLTRHIKE 450', 'HL4PMSABXVZ000001'], ['CALIBRO 700', 'HL4PKWAB3VZ000001'],
    ['SEIEMMEZZO 650 SCR', 'HL4PMVBBXVZ000001'], ['SEIEMMEZZO 650 STR', 'HL4PMVBA0VZ000001'],
    ['XCAPE 1200', 'HL4PU3AB2VZ000001'], ['XCAPE 650', 'HL4PMVAB8VZ000001'], ['XCAPE 700', 'HL4PMWAD9VZ000001'],
]);

test('Morini descriptions cannot override the SCR and STR variant code', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $product = Product::factory()->create(['name' => 'SEIEMMEZZO 650 SCR', 'category' => 'importados', 'brand' => 'morini', 'niv' => 'HL4PMVBA']);
    $certificate = NivComplianceCertificate::factory()->create(['category' => 'importados', 'brand' => 'MORINI', 'model' => 'SEIEMMEZZO 650 SCR', 'serial_pattern' => 'HL4PMVBBXVZ000001']);
    Livewire::test('niv-compliance-certificate-form', ['certificateId' => $certificate->id])->assertSet('productMatchStatus', 'missing')
        ->set('productId', $product->id)->call('save')->assertHasErrors('productId');
    expect($certificate->refresh()->product_id)->toBeNull();
});

test('Morini OCR uses focused readings to recover model series and manufacturer', function () {
    $text = str_replace(['MARCA ZONTES', 'MODELO 150 X', 'LD3THK5BXV1000001'], ['MARCA MORIM', 'MODELO SEIEMMEZZO SCR', 'HL4PMVBBXVZ000001'], complianceCertificateText());
    $result = app(ParseComplianceCertificate::class)->parse([['page' => 1, 'text' => $text, 'regions' => [
        'vehicle_brand' => "MORINI\nMORIM", 'vehicle_model' => "I SEIEMMEZZO 6\nSEIEMMEZZO 6", 'vehicle_series' => "650 SCN\n650 SCR",
    ]]]);
    expect($result['fields'])->brand->toBe('MORINI')->model->toBe('SEIEMMEZZO 650 SCR')
        ->series_version->toBe('650 SCR')->serial_pattern->toBe('HL4PMVBBXVZ000001');
});

test('a focused serial reading can correct a misread Morini sequence number', function () {
    $text = str_replace(['MARCA ZONTES', 'LD3THK5BXV1000001'], ['MARCA MORINI', 'HL4PMWAD9VZ000003'], complianceCertificateText());
    $result = app(ParseComplianceCertificate::class)->parse([['page' => 1, 'text' => $text, 'regions' => ['serial' => "HL4PMWAD9VZ000001\nHL4PMWAD9VZ000001"]]]);
    expect($result['fields']['serial_pattern'])->toBe('HL4PMWAD9VZ000001');
});

test('conflicting Morini series readings are left empty for review', function () {
    $text = str_replace(['MARCA ZONTES', 'MODELO 150 X', 'LD3THK5BXV1000001'], ['MARCA MORINI', 'MODELO XCAPE 650', 'HL4PMVAB8VZ000001'], complianceCertificateText());
    $result = app(ParseComplianceCertificate::class)->parse([['page' => 1, 'text' => $text, 'regions' => ['vehicle_model' => 'XC APE 650', 'vehicle_series' => '655']]]);
    expect($result['fields'])->model->toBe('XCAPE 650')->series_version->toBeNull();
    expect($result['missing_fields'])->toContain('series_version');
});

test('correcting a preexisting Morini product category makes it recognizable without creating a duplicate', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $product = Product::factory()->create(['name' => 'CALIBRO 700', 'brand' => 'morini', 'category' => 'nacional', 'first_value' => 'HL4', 'second_value' => 'PKWAB', 'niv' => 'HL4PKWAB']);
    $text = str_replace(['MARCA ZONTES', 'MODELO 150 X', 'LD3THK5BXV1000001'], ['MARCA MORINI', 'MODELO CALIBRO 700', 'HL4PKWAB3VZ000001'], complianceCertificateText());
    $component = Livewire::test('niv-compliance-certificate-form')->set('category', 'importados')
        ->set('pdfFile', UploadedFile::fake()->createWithContent('CALIBRO 700.pdf', complianceCertificatePdf($text)))
        ->call('analyze')->assertSet('productMatchStatus', 'missing')->call('prepareProductCreation')
        ->call('createRelatedProduct')->assertHasErrors('newProductName');
    $product->update(['category' => 'importados']);
    $component->call('detectProduct')->assertSet('productMatchStatus', 'matched')->assertSet('productId', $product->id)
        ->call('createRelatedProduct')->call('save')->assertHasNoErrors();
    expect(Product::query()->count())->toBe(1)
        ->and(NivComplianceCertificate::query()->sole()->product_id)->toBe($product->id);
});

test('national Bera certificates match existing products by VIN positions four and five', function (string $model, string $serial, string $code, string $registeredName) {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $product = Product::factory()->create(['name' => $registeredName, 'category' => 'nacional', 'brand' => 'bera', 'niv' => $code]);
    Product::factory()->create(['category' => 'importados', 'brand' => 'bera', 'niv' => $code]);
    Livewire::test('niv-compliance-certificate-form')
        ->set('pdfFile', UploadedFile::fake()->createWithContent('Constancia nacional.pdf', complianceCertificatePdf(nationalComplianceCertificateText($model, $serial))))
        ->call('analyze')->assertHasNoErrors()->assertSet('productId', $product->id)->assertSet('productMatchStatus', 'matched')
        ->assertSee('Código del modelo (posiciones 4 y 5)')->call('save')->assertHasNoErrors();
    expect(NivComplianceCertificate::query()->sole())->product_id->toBe($product->id)->category->toBe('nacional')
        ->document_reference->toBe('IO VT R056 192')->brand->toBe('BERA')->observations->toBeNull();
    expect(Product::query()->count())->toBe(2);
})->with([
    ['BR 150 SBR', '8YZWJMCA7VD000001', 'WJ', 'BR 150 SBR'],
    ['BR 150 BRF', '8YZC7MCC3VD000001', 'C7', 'BR 150 BRF'],
    ['BR 150', '8YZFUMCA8VD000001', 'FU', 'BR 150 SOCIALISTA'],
]);

test('a new Bera product created from a certificate fills both national identification characters', function (string $serial, string $first, string $second) {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    Livewire::test('niv-compliance-certificate-form')
        ->set('pdfFile', UploadedFile::fake()->createWithContent('Constancia nacional.pdf', complianceCertificatePdf(nationalComplianceCertificateText('BR 150', $serial))))
        ->call('analyze')->call('prepareProductCreation')->call('createRelatedProduct')->assertHasNoErrors()
        ->call('save')->assertHasNoErrors();
    $product = Product::query()->sole();
    expect($product)->brand->toBe('bera')->category->toBe('nacional')->first_value->toBe($first)->second_value->toBe($second)->niv->toBe($first.$second);
    expect(NivComplianceCertificate::query()->sole()->product_id)->toBe($product->id);
})->with([['8YZWJMCA7VD000001', 'W', 'J'], ['8YZC7MCC3VD000001', 'C', '7'], ['8YZFUMCA8VD000001', 'F', 'U']]);

test('national reference formats are extracted without taking text from the next line', function (string $reference, string $expected) {
    $text = str_replace('IO VT R056 192', $reference, nationalComplianceCertificateText('BR 150', '8YZFUMCA8VD000001'));
    $result = app(ParseComplianceCertificate::class)->parse([['page' => 1, 'text' => $text]]);
    expect($result['fields']['document_reference'])->toBe($expected)
        ->and($result['fields']['observations'])->toBeNull();
})->with([['IO VT R056 192', 'IO VT R056 192'], ['10 VT R056 193', 'IO VT R056 193'], ['IO VT R056 194', 'IO VT R056 194'], ['IOCTR056 180 8', 'IOCTR056 180 8']]);

test('focused Bera readings recover a truncated model and manufacturing years', function () {
    $text = str_replace(['MODELO BR 150 BRF', '2026/2027'], ['MODELO BR', '2026/20>7'], nationalComplianceCertificateText('BR 150 BRF', '8YZC7MCC3VD000001'));
    $result = app(ParseComplianceCertificate::class)->parse([['page' => 1, 'text' => $text, 'regions' => [
        'vehicle_brand' => 'BERA', 'vehicle_model' => "BR 150 BRF\nBR 150 BRF", 'vehicle_series' => "H50\n150",
        'vehicle_years' => '2026/2027 2027/2027',
    ]]]);
    expect($result['fields'])->model->toBe('BR 150 BRF')->series_version->toBe('150')
        ->manufacturing_years->toBe('2026, 2027')->model_year->toBe(2027);
});

function nationalComplianceCertificateText(string $model, string $serial): string
{
    return str_replace(
        ['N°28684', 'IO CT R056 180 8', 'MARCA ZONTES', 'MODELO 150 X', 'LD3THK5BXV1000001', 'PAIS ORIGEN CHINA', 'TIPO SCOOTER', 'OBSERVACIONES: N/A'],
        ['N°30512', 'IO VT R056 192', 'MARCA BERA', 'MODELO '.$model, $serial, 'PAIS ORIGEN VENEZUELA', 'TIPO MOTOCICLETA', 'OBSERVACIONES:'],
        complianceCertificateText(),
    );
}

function complianceCertificateText(bool $is368 = false): string
{
    $text = <<<'TEXT'
N°28684
IO CT R056 180 8
CONSTANCIA DE CUMPLIMIENTO
COMPOSICION Y UBICACION DEL NUMERO DE IDENTIFICACION VEHICULAR (NIV)
Hace constar que la empresa: CORPORACION EJEMPLO C.A. Identificada con el RIF N° J-31447419-7,
REFECIV N° AGMD7056, SERIAL PATRON N° LD3THK5BXV1000001
MARCA ZONTES USO PARTICULAR
MODELO 150 X CLASE MOTO
SERIE/VERSION 150 TIPO SCOOTER
AÑO FAB. / MOD 2026/2027 2027/2027
PAIS ORIGEN CHINA IIF (IIM) N/A
IMF (WMI) LD3
OBSERVACIONES: N/A
Esta constancia POSEE UNA VIGENCIA A PARTIR DE LA FECHA DE EMISION HASTA EL 31 DE DICIEMBRE
DEL AÑO 2027, siempre y cuando los datos concuerden con el vehículo.
Dado en la Ciudad de Caracas, a los 26 dias del mes de OCTUBRE de 2026
TEXT;

    return $is368 ? str_replace(['28684', 'LD3THK5BXV1000001', 'MODELO 150 X', 'SERIE/VERSION 150', 'a los 26'], ['24479', 'LD3THR6B0V1000001', 'MODELO 368', 'SERIE/VERSION 368E', 'a los 19'], $text) : $text;
}

function complianceCertificatePdf(string $text): string
{
    $pdf = new FPDF;
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 10);
    foreach (explode("\n", $text) as $line) {
        $pdf->MultiCell(0, 6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $line));
    }

    return $pdf->Output('S');
}
