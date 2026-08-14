<?php

use App\Actions\Dispatches\PrintDispatchCertificates;
use App\CertificateStatus;
use App\DispatchStatus;
use App\Models\CertificateDocument;
use App\Models\Dispatch;
use App\Models\MsCertificado;
use App\Models\ProductReturn;
use App\Models\User;
use App\ReturnStatus;
use App\UserPermission;
use App\UserRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PdfDecompressor\Normalizer;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use Smalot\PdfParser\Parser;

/**
 * @param  list<string>  $pages
 */
function makeCertificatePdf(array $pages): string
{
    $pdf = new FPDF;

    foreach ($pages as $page) {
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Cell(0, 10, $page);
    }

    return $pdf->Output('S');
}

/**
 * @param  list<string>  $pages
 * @return list<string>
 */
function pdfPageTexts(string $content): array
{
    $document = (new Parser)->parseContent($content);
    $texts = [];

    foreach ($document->getPages() as $page) {
        $texts[] = trim($page->getText());
    }

    return $texts;
}

/**
 * @param  list<string>  $pages
 */
function makeCompressedXrefPdf(array $pages): string
{
    $count = count($pages);
    $fontId = 3 + (2 * $count);
    $pdf = "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    $write = function (int $id, string $body) use (&$pdf, &$offsets): void {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id.' 0 obj'."\n".$body."\nendobj\n";
    };

    $write(1, '<< /Type /Catalog /Pages 2 0 R >>');

    $kids = implode(' ', array_map(fn (int $i): string => (3 + $i).' 0 R', range(0, $count - 1)));
    $write(2, "<< /Type /Pages /Kids [$kids] /Count $count >>");

    for ($i = 0; $i < $count; $i++) {
        $pageId = 3 + $i;
        $contentId = 3 + $count + $i;
        $write($pageId, "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents $contentId 0 R /Resources << /Font << /F1 $fontId 0 R >> >> >>");
    }

    for ($i = 0; $i < $count; $i++) {
        $contentId = 3 + $count + $i;
        $stream = 'BT /F1 12 Tf 50 750 Td ('.$pages[$i].') Tj ET'."\n";
        $compressed = gzcompress($stream, 9);
        $write($contentId, '<< /Length '.strlen($compressed).' /Filter /FlateDecode >>'."\nstream\n".$compressed."\nendstream");
    }

    $write($fontId, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');

    $entries = pack('CNn', 0, 0, 65535);

    for ($id = 1; $id < $fontId; $id++) {
        $entries .= pack('CNn', 1, $offsets[$id], 0);
    }

    $compressedEntries = gzcompress($entries, 9);
    $write($fontId + 1, '<< /Type /XRef /Size '.$fontId.' /Root 1 0 R /W [1 4 2] /Index [0 '.$fontId.'] /Filter /FlateDecode /Length '.strlen($compressedEntries).' >>'."\nstream\n".$compressedEntries."\nendstream");
    $pdf .= "startxref\n".$offsets[$fontId + 1]."\n%%EOF";

    return $pdf;
}

test('administrators can access dispatch and return modules', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($administrator)
        ->get(route('dispatches.index'))
        ->assertSuccessful()
        ->assertSee('Gestión de despachos');

    $this->actingAs($administrator)
        ->get(route('returns.index'))
        ->assertSuccessful()
        ->assertSee('Gestión de devoluciones');
});

test('dispatch menu appears after requests and before configuration', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($administrator)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('data-requests-menu', false)
        ->assertSee('data-dispatch-menu', false)
        ->assertSee('data-configuration-menu', false)
        ->assertSee('group order-[998]', false)
        ->assertSee(route('dispatches.index'))
        ->assertSee(route('returns.index'));
});

test('users only see and access authorized dispatch modules', function () {
    $user = User::factory()->create([
        'permissions' => [UserPermission::ViewDispatches->value],
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Despacho')
        ->assertSee(route('dispatches.index'))
        ->assertDontSee(route('returns.index'));

    $this->actingAs($user)
        ->get(route('dispatches.index'))
        ->assertSuccessful();

    $this->actingAs($user)
        ->get(route('returns.index'))
        ->assertForbidden();
});

test('dispatch form shows the three-step status bar', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($administrator)
        ->get(route('dispatches.create'))
        ->assertSuccessful()
        ->assertSee('Estado del despacho')
        ->assertSee('Borrador')
        ->assertSee('En proceso')
        ->assertSee('Hecho');
});

test('returns only list dispatched certificates and mark them as returned when finalized', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $dispatched = MsCertificado::factory()->create([
        'niv' => '8YZC7MCC0TD000101',
        'status' => CertificateStatus::Dispatched,
    ]);
    $pending = MsCertificado::factory()->create([
        'niv' => '8YZC7MCC0TD000102',
        'status' => CertificateStatus::PendingDispatch,
    ]);

    $this->actingAs($administrator);

    Livewire::test('return-form')
        ->assertSee('Estado de la devolución')
        ->assertSee('Borrador')
        ->assertSee('En proceso')
        ->assertSee('Hecho')
        ->set('name', 'WH/OUT/00055')
        ->call('addCertificate', $dispatched->id)
        ->call('save')
        ->assertRedirect();

    $return = ProductReturn::query()->sole();

    expect($return->creator->is($administrator))->toBeTrue()
        ->and($return->lines()->count())->toBe(1)
        ->and($return->status)->toBe(ReturnStatus::Draft);

    Livewire::test('return-form', ['returnId' => $return->id])
        ->call('openFinalizeConfirmation')
        ->assertSet('showFinalizeConfirmation', true)
        ->call('finalize')
        ->assertRedirect(route('returns.edit', $return));

    expect($return->refresh()->status)->toBe(ReturnStatus::Done)
        ->and($return->finalized_at)->not->toBeNull()
        ->and($return->return_date?->toDateString())->toBe(now()->toDateString())
        ->and($dispatched->refresh()->status)->toBe(CertificateStatus::Returned)
        ->and($pending->refresh()->status)->toBe(CertificateStatus::PendingDispatch);
});

test('finalizing a dispatch does not create a return automatically', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $first = MsCertificado::factory()->create(['niv' => '8YZC7MCC0TD000001']);

    $this->actingAs($administrator);

    Livewire::test('dispatch-form')
        ->set('name', 'WH/OUT/00045')
        ->call('addCertificate', $first->id)
        ->call('save')
        ->assertRedirect();

    $dispatch = Dispatch::query()->sole();

    Livewire::test('dispatch-form', ['dispatchId' => $dispatch->id])
        ->call('finalize')
        ->assertRedirect(route('dispatches.edit', $dispatch));

    expect($first->refresh()->status)->toBe(CertificateStatus::Dispatched)
        ->and(ProductReturn::query()->count())->toBe(0);
});

test('returns list only shows manually created returns', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    MsCertificado::factory()->create([
        'niv' => '8YZC7MCC0TD000101',
        'status' => CertificateStatus::Dispatched,
    ]);

    $this->actingAs($administrator);

    Livewire::test('return-list')
        ->assertSuccessful()
        ->assertSee('No se encontraron devoluciones')
        ->assertDontSee('8YZC7MCC0TD000101');

    ProductReturn::factory()->create([
        'name' => 'RET-0001',
        'created_by' => $administrator->id,
    ])->lines()->create([
        'ms_certificado_id' => MsCertificado::query()->sole()->id,
    ]);

    Livewire::test('return-list')
        ->assertSee('RET-0001')
        ->assertDontSee('8YZC7MCC0TD000101');
});

test('a dispatch imports pending nivs and the odoo name from a PDF', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $first = MsCertificado::factory()->create(['niv' => '8YZBXMEA1TD008280']);
    $second = MsCertificado::factory()->create(['niv' => '8YZBXMEA7TD008283']);
    $dispatched = MsCertificado::factory()->create([
        'niv' => '8YZBXMEA5TD008265',
        'status' => CertificateStatus::Dispatched,
    ]);
    $content = makeCertificatePdf([
        'PLM-PT-LM/OUT/166202',
        'Producto Lote/N de serie Entregado',
        '[BR200BR212604] BR 200 (NEGRO) 8YZBXMEA1TD008280 1,00 Unidades',
        '[BR200BR212604] BR 200 (NEGRO) 8YZBXMEA7TD008283 1,00 Unidades',
        '[BR200BR212604] BR 200 (NEGRO) 8YZBXMEA5TD008265 1,00 Unidades',
    ]);

    $this->actingAs($administrator);

    Livewire::test('dispatch-form')
        ->set('importPdf', UploadedFile::fake()->createWithContent('carga.pdf', $content))
        ->call('importDispatchPdf')
        ->assertHasNoErrors()
        ->assertSet('name', 'PLM-PT-LM/OUT/166202')
        ->assertSet('selectedIds', [$first->id, $second->id])
        ->assertSee('8YZBXMEA1TD008280')
        ->assertSee('8YZBXMEA7TD008283')
        ->assertDontSee('8YZBXMEA5TD008265');

    expect($dispatched->refresh()->status)->toBe(CertificateStatus::Dispatched);
});

test('a dispatch pdf import keeps an existing odoo name', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    MsCertificado::factory()->create(['niv' => '8YZBXMEA1TD008280']);
    $content = makeCertificatePdf([
        'PLM-PT-LM/OUT/166202',
        'Producto Lote/N de serie Entregado',
        '[BR200BR212604] BR 200 (NEGRO) 8YZBXMEA1TD008280 1,00 Unidades',
    ]);

    $this->actingAs($administrator);

    Livewire::test('dispatch-form')
        ->set('name', 'WH/OUT/00099')
        ->set('importPdf', UploadedFile::fake()->createWithContent('carga.pdf', $content))
        ->call('importDispatchPdf')
        ->assertHasNoErrors()
        ->assertSet('name', 'WH/OUT/00099');
});

test('a dispatch pdf import reports serials not found in the master', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $content = makeCertificatePdf([
        'PLM-PT-LM/OUT/166202',
        'Producto Lote/N de serie Entregado',
        '[BR200BR212604] BR 200 (NEGRO) 8YZBXMEA1TD008280 1,00 Unidades',
    ]);

    $this->actingAs($administrator);

    Livewire::test('dispatch-form')
        ->set('importPdf', UploadedFile::fake()->createWithContent('carga.pdf', $content))
        ->call('importDispatchPdf')
        ->assertHasNoErrors()
        ->assertSet('selectedIds', [])
        ->assertSee('1 no existen en el maestro de seriales')
        ->assertSee('8YZBXMEA1TD008280');
});

test('a dispatch pdf import reports serials that were already dispatched', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $dispatched = MsCertificado::factory()->create([
        'niv' => '8YZBXMEA1TD008280',
        'status' => CertificateStatus::Dispatched,
    ]);
    $content = makeCertificatePdf([
        'PLM-PT-LM/OUT/166202',
        'Producto Lote/N de serie Entregado',
        '[BR200BR212604] BR 200 (NEGRO) 8YZBXMEA1TD008280 1,00 Unidades',
    ]);

    $this->actingAs($administrator);

    Livewire::test('dispatch-form')
        ->set('importPdf', UploadedFile::fake()->createWithContent('carga.pdf', $content))
        ->call('importDispatchPdf')
        ->assertHasNoErrors()
        ->assertSet('selectedIds', [])
        ->assertSee('1 ya fueron despachados')
        ->assertSee('8YZBXMEA1TD008280');

    expect($dispatched->refresh()->status)->toBe(CertificateStatus::Dispatched);
});

test('a dispatch keeps the selected pdf file after processing', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    MsCertificado::factory()->create(['niv' => '8YZBXMEA1TD008280']);
    $content = makeCertificatePdf([
        'PLM-PT-LM/OUT/166202',
        'Producto Lote/N de serie Entregado',
        '[BR200BR212604] BR 200 (NEGRO) 8YZBXMEA1TD008280 1,00 Unidades',
    ]);

    $this->actingAs($administrator);

    Livewire::test('dispatch-form')
        ->set('importPdf', UploadedFile::fake()->createWithContent('carga.pdf', $content))
        ->call('importDispatchPdf')
        ->assertHasNoErrors()
        ->assertNotSet('importPdf', null)
        ->assertSee('carga.pdf');
});

test('a dispatch selects pending nivs and marks them as dispatched when finalized', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $first = MsCertificado::factory()->create(['niv' => '8YZC7MCC0TD000001']);
    $second = MsCertificado::factory()->create(['niv' => '8YZC7MCC0TD000002']);
    $untouched = MsCertificado::factory()->create(['niv' => '8YZC7MCC0TD000003']);

    $this->actingAs($administrator);

    Livewire::test('dispatch-form')
        ->set('name', 'WH/OUT/00045')
        ->call('addCertificate', $first->id)
        ->call('addCertificate', $second->id)
        ->call('save')
        ->assertRedirect();

    $dispatch = Dispatch::query()->sole();

    expect($dispatch->creator->is($administrator))->toBeTrue()
        ->and($dispatch->lines()->count())->toBe(2)
        ->and($dispatch->status)->toBe(DispatchStatus::Draft);

    Livewire::test('dispatch-form', ['dispatchId' => $dispatch->id])
        ->call('openFinalizeConfirmation')
        ->assertSet('showFinalizeConfirmation', true)
        ->call('finalize')
        ->assertRedirect(route('dispatches.edit', $dispatch));

    expect($dispatch->refresh()->status)->toBe(DispatchStatus::Done)
        ->and($dispatch->finalized_at)->not->toBeNull()
        ->and($dispatch->dispatch_date?->toDateString())->toBe(now()->toDateString())
        ->and($first->refresh()->status)->toBe(CertificateStatus::Dispatched)
        ->and($second->refresh()->status)->toBe(CertificateStatus::Dispatched)
        ->and($untouched->refresh()->status)->toBe(CertificateStatus::PendingDispatch);
});

test('a completed dispatch cannot be modified', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $record = MsCertificado::factory()->create();
    $dispatch = Dispatch::query()->create([
        'name' => 'WH/OUT/00046',
        'dispatch_date' => '2026-08-10',
        'created_by' => $administrator->id,
        'status' => DispatchStatus::Done,
        'finalized_at' => now(),
    ]);
    $dispatch->lines()->create(['ms_certificado_id' => $record->id]);

    $this->actingAs($administrator);

    Livewire::test('dispatch-form', ['dispatchId' => $dispatch->id])
        ->call('removeCertificate', $record->id)
        ->assertForbidden();
});

test('deleting a dispatch removes it with its lines', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $dispatch = Dispatch::query()->create([
        'name' => 'WH/OUT/00047',
        'created_by' => $administrator->id,
    ]);
    $dispatch->lines()->create(['ms_certificado_id' => MsCertificado::factory()->create()->id]);

    $this->actingAs($administrator);

    Livewire::test('dispatch-list')
        ->call('openDeleteConfirmation', $dispatch->id)
        ->assertSet('showDeleteConfirmation', true)
        ->call('deleteDispatch')
        ->assertSet('showDeleteConfirmation', false);

    expect(Dispatch::query()->find($dispatch->id))->toBeNull()
        ->and($dispatch->lines()->count())->toBe(0);
});

test('deleting a return removes it with its lines', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $return = ProductReturn::query()->create([
        'name' => 'RET-0002',
        'created_by' => $administrator->id,
    ]);
    $return->lines()->create(['ms_certificado_id' => MsCertificado::factory()->create()->id]);

    $this->actingAs($administrator);

    Livewire::test('return-list')
        ->call('openDeleteConfirmation', $return->id)
        ->assertSet('showDeleteConfirmation', true)
        ->call('deleteReturn')
        ->assertSet('showDeleteConfirmation', false);

    expect(ProductReturn::query()->find($return->id))->toBeNull()
        ->and($return->lines()->count())->toBe(0);
});

test('users without delete permission cannot delete dispatches or returns', function () {
    $viewer = User::factory()->create([
        'permissions' => [UserPermission::ViewDispatches->value, UserPermission::ViewReturns->value],
    ]);
    $dispatch = Dispatch::query()->create(['name' => 'WH/OUT/00048']);
    $return = ProductReturn::query()->create(['name' => 'RET-0003']);

    $this->actingAs($viewer);

    Livewire::test('dispatch-list')
        ->call('openDeleteConfirmation', $dispatch->id)
        ->assertForbidden();

    Livewire::test('return-list')
        ->call('openDeleteConfirmation', $return->id)
        ->assertForbidden();

    expect(Dispatch::query()->find($dispatch->id))->not->toBeNull()
        ->and(ProductReturn::query()->find($return->id))->not->toBeNull();
});

test('printing dispatch certificates includes the first page and each serial page', function () {
    Storage::fake('local');
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $serialOne = MsCertificado::factory()->create([
        'niv' => '8YZC7MCC0TD000101',
        'codigo' => 'DG-NIV-RG5-0175-PC',
    ]);
    $serialTwo = MsCertificado::factory()->create([
        'niv' => '8YZC7MCC0TD000102',
        'codigo' => 'DG-NIV-RG5-0175-PC',
    ]);
    $content = makeCertificatePdf([
        'PRIMERA PAGINA',
        'SEGUNDA PAGINA 8YZC7MCC0TD000101',
        'TERCERA PAGINA 8YZC7MCC0TD000102',
    ]);
    Storage::disk('local')->put('certificate-documents/dg-niv-rg5-0175-pc.pdf', $content);
    CertificateDocument::query()->create([
        'control_number' => 'DG-NIV-RG5-0175-PC',
        'file_name' => 'DG-NIV-RG5-0175-PC.pdf',
        'original_file_name' => 'certificado.pdf',
        'file_path' => 'certificate-documents/dg-niv-rg5-0175-pc.pdf',
    ]);
    $dispatch = Dispatch::query()->create(['name' => 'WH/OUT/00050']);
    $dispatch->lines()->create(['ms_certificado_id' => $serialOne->id]);
    $dispatch->lines()->create(['ms_certificado_id' => $serialTwo->id]);

    $this->actingAs($administrator);

    $result = app(PrintDispatchCertificates::class)->handle($dispatch);
    $texts = pdfPageTexts($result);

    expect($texts)->toHaveCount(3)
        ->and($texts[0])->toContain('PRIMERA PAGINA')
        ->and($texts[1])->toContain('SEGUNDA PAGINA')
        ->and($texts[2])->toContain('TERCERA PAGINA');
});

test('printing dispatch certificates groups serials per certificate without repeating pages', function () {
    Storage::fake('local');
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $serialOne = MsCertificado::factory()->create([
        'niv' => '8YZC7MCC0TD000201',
        'codigo' => 'DG-NIV-RG5-0175-PC',
    ]);
    $serialTwo = MsCertificado::factory()->create([
        'niv' => '8YZC7MCC0TD000202',
        'codigo' => 'DG-NIV-RG5-0175-PC',
    ]);
    $content = makeCertificatePdf([
        'PRIMERA PAGINA',
        'PAGINA CON DOS SERIALES 8YZC7MCC0TD000201 8YZC7MCC0TD000202',
    ]);
    Storage::disk('local')->put('certificate-documents/dg-niv-rg5-0175-pc.pdf', $content);
    CertificateDocument::query()->create([
        'control_number' => 'DG-NIV-RG5-0175-PC',
        'file_name' => 'DG-NIV-RG5-0175-PC.pdf',
        'original_file_name' => 'certificado.pdf',
        'file_path' => 'certificate-documents/dg-niv-rg5-0175-pc.pdf',
    ]);
    $dispatch = Dispatch::query()->create(['name' => 'WH/OUT/00051']);
    $dispatch->lines()->create(['ms_certificado_id' => $serialOne->id]);
    $dispatch->lines()->create(['ms_certificado_id' => $serialTwo->id]);

    $this->actingAs($administrator);

    $result = app(PrintDispatchCertificates::class)->handle($dispatch);
    $texts = pdfPageTexts($result);

    expect($texts)->toHaveCount(2)
        ->and($texts[0])->toContain('PRIMERA PAGINA')
        ->and($texts[1])->toContain('PAGINA CON DOS SERIALES');
});

test('printing dispatch certificates concatenates different certificates in order', function () {
    Storage::fake('local');
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $serialOne = MsCertificado::factory()->create([
        'niv' => '8YZC7MCC0TD000301',
        'codigo' => 'DG-NIV-RG5-0175-PC',
    ]);
    $serialTwo = MsCertificado::factory()->create([
        'niv' => '8YZC7MCC0TD000302',
        'codigo' => 'DG-NIV-RG8-0005-PC',
    ]);
    $firstContent = makeCertificatePdf(['PRIMER CERTIFICADO PAG 1', 'PRIMER CERTIFICADO SERIAL 8YZC7MCC0TD000301']);
    $secondContent = makeCertificatePdf(['SEGUNDO CERTIFICADO PAG 1', 'SEGUNDO CERTIFICADO SERIAL 8YZC7MCC0TD000302']);
    Storage::disk('local')->put('certificate-documents/dg-niv-rg5-0175-pc.pdf', $firstContent);
    Storage::disk('local')->put('certificate-documents/dg-niv-rg8-0005-pc.pdf', $secondContent);
    CertificateDocument::query()->create([
        'control_number' => 'DG-NIV-RG5-0175-PC',
        'file_name' => 'DG-NIV-RG5-0175-PC.pdf',
        'original_file_name' => 'primero.pdf',
        'file_path' => 'certificate-documents/dg-niv-rg5-0175-pc.pdf',
    ]);
    CertificateDocument::query()->create([
        'control_number' => 'DG-NIV-RG8-0005-PC',
        'file_name' => 'DG-NIV-RG8-0005-PC.pdf',
        'original_file_name' => 'segundo.pdf',
        'file_path' => 'certificate-documents/dg-niv-rg8-0005-pc.pdf',
    ]);
    $dispatch = Dispatch::query()->create(['name' => 'WH/OUT/00052']);
    $dispatch->lines()->create(['ms_certificado_id' => $serialOne->id]);
    $dispatch->lines()->create(['ms_certificado_id' => $serialTwo->id]);

    $this->actingAs($administrator);

    $result = app(PrintDispatchCertificates::class)->handle($dispatch);
    $texts = pdfPageTexts($result);

    expect($texts)->toHaveCount(4)
        ->and($texts[0])->toContain('PRIMER CERTIFICADO PAG 1')
        ->and($texts[1])->toContain('PRIMER CERTIFICADO SERIAL')
        ->and($texts[2])->toContain('SEGUNDO CERTIFICADO PAG 1')
        ->and($texts[3])->toContain('SEGUNDO CERTIFICADO SERIAL');
});

test('printing dispatch certificates normalizes certificates with a compressed xref stream', function () {
    Storage::fake('local');
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $serial = MsCertificado::factory()->create([
        'niv' => '8YZC7MCC0TD000701',
        'codigo' => 'DG-NIV-RG5-0175-PC',
    ]);
    $content = makeCompressedXrefPdf([
        'PRIMERA PAGINA COMPRIMIDA',
        'SEGUNDA PAGINA COMPRIMIDA 8YZC7MCC0TD000701',
    ]);

    expect(Normalizer::isCompressed($content))->toBeTrue();

    $rawFile = tempnam(sys_get_temp_dir(), 'compressed_xref_');
    file_put_contents($rawFile, $content);

    expect(fn () => (new Fpdi)->setSourceFile($rawFile))
        ->toThrow(CrossReferenceException::class);

    Storage::disk('local')->put('certificate-documents/dg-niv-rg5-0175-pc.pdf', $content);
    CertificateDocument::query()->create([
        'control_number' => 'DG-NIV-RG5-0175-PC',
        'file_name' => 'DG-NIV-RG5-0175-PC.pdf',
        'original_file_name' => 'certificado.pdf',
        'file_path' => 'certificate-documents/dg-niv-rg5-0175-pc.pdf',
    ]);
    $dispatch = Dispatch::query()->create(['name' => 'WH/OUT/00056']);
    $dispatch->lines()->create(['ms_certificado_id' => $serial->id]);

    $this->actingAs($administrator);

    $result = app(PrintDispatchCertificates::class)->handle($dispatch);
    $texts = pdfPageTexts($result);

    expect($texts)->toHaveCount(2)
        ->and($texts[0])->toContain('PRIMERA PAGINA COMPRIMIDA')
        ->and($texts[1])->toContain('SEGUNDA PAGINA COMPRIMIDA');
});

test('printing dispatch certificates throws when the certificate document is missing', function () {
    Storage::fake('local');
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $serial = MsCertificado::factory()->create([
        'niv' => '8YZC7MCC0TD000401',
        'codigo' => 'DG-NIV-RG5-0175-PC',
    ]);
    $dispatch = Dispatch::query()->create(['name' => 'WH/OUT/00053']);
    $dispatch->lines()->create(['ms_certificado_id' => $serial->id]);

    $this->actingAs($administrator);

    expect(fn () => app(PrintDispatchCertificates::class)->handle($dispatch))
        ->toThrow(RuntimeException::class, 'No se encontró el certificado DG-NIV-RG5-0175-PC');
});

test('the certificates print route requires permission and downloads the combined pdf', function () {
    Storage::fake('local');
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $unauthorized = User::factory()->create();
    $serial = MsCertificado::factory()->create([
        'niv' => '8YZC7MCC0TD000501',
        'codigo' => 'DG-NIV-RG5-0175-PC',
    ]);
    $content = makeCertificatePdf(['PRIMERA PAGINA', 'SERIAL 8YZC7MCC0TD000501']);
    Storage::disk('local')->put('certificate-documents/dg-niv-rg5-0175-pc.pdf', $content);
    CertificateDocument::query()->create([
        'control_number' => 'DG-NIV-RG5-0175-PC',
        'file_name' => 'DG-NIV-RG5-0175-PC.pdf',
        'original_file_name' => 'certificado.pdf',
        'file_path' => 'certificate-documents/dg-niv-rg5-0175-pc.pdf',
    ]);
    $dispatch = Dispatch::query()->create(['name' => 'WH/OUT/00054']);
    $dispatch->lines()->create(['ms_certificado_id' => $serial->id]);

    $this->actingAs($unauthorized)
        ->get(route('dispatches.certificates.print', $dispatch))
        ->assertForbidden();

    $this->actingAs($administrator)
        ->get(route('dispatches.certificates.print', $dispatch))
        ->assertSuccessful()
        ->assertDownload('certificados-whout00054.pdf');
});

test('the dispatch form shows the print certificates button for saved dispatches with serials', function () {
    $administrator = User::factory()->create(['role' => UserRole::Admin]);
    $serial = MsCertificado::factory()->create([
        'niv' => '8YZC7MCC0TD000601',
        'codigo' => 'DG-NIV-RG5-0175-PC',
    ]);
    $dispatch = Dispatch::query()->create(['name' => 'WH/OUT/00055']);
    $dispatch->lines()->create(['ms_certificado_id' => $serial->id]);

    $this->actingAs($administrator);

    Livewire::test('dispatch-form', ['dispatchId' => $dispatch->id])
        ->assertSee('Imprimir certificados');

    Livewire::test('dispatch-form')
        ->assertDontSee('Imprimir certificados');
});
